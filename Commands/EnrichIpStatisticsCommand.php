<?php

declare(strict_types=1);

namespace Plugin\XboardStatistic\Commands;

use App\Services\Plugin\PluginManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Plugin\XboardStatistic\Support\IpAddress;
use Throwable;

final class EnrichIpStatisticsCommand extends Command
{
    public const SIGNATURE = 'xboard-statistic:enrich-ips';

    protected $signature = self::SIGNATURE . ' {--limit=10000 : 本次最多处理的 IP 数}';
    protected $description = '使用本地 MaxMind MMDB 离线补充 IP 国家、ASN 和组织信息';

    public function handle(PluginManager $pluginManager): int
    {
        $plugin = $pluginManager->getEnabledPlugins()['xboard_statistic'] ?? null;
        $countryPath = trim((string) ($plugin?->getConfig('geoip_country_mmdb', '') ?? ''));
        $asnPath = trim((string) ($plugin?->getConfig('geoip_asn_mmdb', '') ?? ''));
        if ($countryPath === '' && $asnPath === '') {
            $this->line('未配置 GeoIP/ASN MMDB，跳过离线派生维度');
            return self::SUCCESS;
        }
        if (!class_exists('GeoIp2\\Database\\Reader')) {
            $this->error('缺少 geoip2/geoip2；请在 Xboard 根目录执行 composer require geoip2/geoip2');
            return self::FAILURE;
        }

        try {
            $readerClass = 'GeoIp2\\Database\\Reader';
            $countryReader = $countryPath !== '' ? new $readerClass($countryPath) : null;
            $asnReader = $asnPath !== '' ? new $readerClass($asnPath) : null;
        } catch (Throwable $exception) {
            $this->error('无法打开 MMDB: ' . $exception->getMessage());
            return self::FAILURE;
        }

        $limit = max(1, min(100000, (int) $this->option('limit')));
        $query = DB::table('xbs_ip_addresses')->orderBy('id')->limit($limit);
        $query->where(function ($nested) use ($countryReader, $asnReader): void {
            if ($countryReader !== null) {
                $nested->orWhereNull('country_code');
            }
            if ($asnReader !== null) {
                $nested->orWhereNull('asn');
            }
        });

        $processed = 0;
        foreach ($query->get(['id', 'address', 'country_code', 'asn']) as $row) {
            $ip = IpAddress::display($row->address);
            $changes = [];
            if ($countryReader !== null && $row->country_code === null) {
                try {
                    $country = $countryReader->country($ip);
                    $isoCode = strtoupper((string) ($country->country->isoCode ?? ''));
                    if (preg_match('/^[A-Z]{2}$/', $isoCode)) {
                        $changes['country_code'] = $isoCode;
                    }
                } catch (Throwable) {
                    // Private, reserved and absent ranges deliberately remain unknown.
                }
            }
            if ($asnReader !== null && $row->asn === null) {
                try {
                    $asn = $asnReader->asn($ip);
                    if (($asn->autonomousSystemNumber ?? null) !== null) {
                        $changes['asn'] = (int) $asn->autonomousSystemNumber;
                        $changes['asn_organization'] = mb_substr(
                            (string) ($asn->autonomousSystemOrganization ?? ''),
                            0,
                            255,
                        );
                    }
                } catch (Throwable) {
                    // Keep unknown instead of using an online lookup or guessing.
                }
            }
            if ($changes !== []) {
                $changes['updated_at'] = now();
                DB::table('xbs_ip_addresses')->where('id', $row->id)->update($changes);
            }
            $processed++;
        }

        $countryReader?->close();
        $asnReader?->close();
        $this->info("已检查 {$processed} 个 IP，派生维度仅来自本地 MMDB");
        return self::SUCCESS;
    }
}
