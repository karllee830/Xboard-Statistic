<?php

declare(strict_types=1);

namespace Plugin\XboardStatistic\Commands;

use App\Services\Plugin\PluginManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use MaxMind\Db\Reader;
use Plugin\XboardStatistic\Services\GeoIpDatabaseManager;
use Plugin\XboardStatistic\Support\GeoIpRecord;
use Plugin\XboardStatistic\Support\IpAddress;
use Plugin\XboardStatistic\Support\UtcTime;
use Throwable;

final class EnrichIpStatisticsCommand extends Command
{
    public const SIGNATURE = 'xboard-statistic:enrich-ips';

    protected $signature = self::SIGNATURE . ' {--limit=10000 : 本次最多处理的 IP 数}';
    protected $description = '使用插件自动维护的离线 MMDB 补充 IP 国家、ASN 和组织信息';

    public function handle(PluginManager $pluginManager, GeoIpDatabaseManager $databaseManager): int
    {
        $plugin = $pluginManager->getEnabledPlugins()['xboard_statistic'] ?? null;

        try {
            $databases = $databaseManager->ensure(
                (string) ($plugin?->getConfig('geoip_country_mmdb', '') ?? ''),
                (string) ($plugin?->getConfig('geoip_asn_mmdb', '') ?? ''),
                false,
                fn (string $message) => $this->line($message),
            );
            $countryReader = $databases['country_path'] !== null
                ? $databaseManager->reader($databases['country_path'])
                : null;
            $asnReader = $databases['asn_path'] !== null
                ? $databaseManager->reader($databases['asn_path'])
                : null;
        } catch (Throwable $exception) {
            $this->error('无法准备 MMDB：' . $exception->getMessage());
            return self::FAILURE;
        }
        if ($countryReader === null && $asnReader === null) {
            $this->error('Country 和 ASN MMDB 均不可用');
            return self::FAILURE;
        }

        $limit = max(1, min(100000, (int) $this->option('limit')));
        $query = DB::table('xbs_ip_addresses')->orderBy('id')->limit($limit);
        $query->where(function ($nested) use ($countryReader, $asnReader, $databases): void {
            if ($countryReader !== null) {
                $nested->orWhereNull('country_database_version')
                    ->orWhere('country_database_version', '<>', $databases['country_version']);
            }
            if ($asnReader !== null) {
                $nested->orWhereNull('asn_database_version')
                    ->orWhere('asn_database_version', '<>', $databases['asn_version']);
            }
        });

        $processed = 0;
        foreach ($query->get([
            'id', 'address', 'country_code', 'asn',
            'country_database_version', 'asn_database_version',
        ]) as $row) {
            $ip = IpAddress::display($row->address);
            $changes = [];
            if ($countryReader !== null && $row->country_database_version !== $databases['country_version']) {
                $changes['country_code'] = null;
                try {
                    $countryCode = GeoIpRecord::countryCode($countryReader->get($ip));
                    if ($countryCode !== null) {
                        $changes['country_code'] = $countryCode;
                    }
                } catch (Throwable) {
                    // Private, reserved and absent ranges deliberately remain unknown.
                }
                $changes['country_database_version'] = $databases['country_version'];
            }
            if ($asnReader !== null && $row->asn_database_version !== $databases['asn_version']) {
                $changes['asn'] = null;
                $changes['asn_organization'] = null;
                try {
                    $asn = GeoIpRecord::asn($asnReader->get($ip));
                    if ($asn !== null) {
                        $changes['asn'] = $asn['number'];
                        $changes['asn_organization'] = $asn['organization'];
                    }
                } catch (Throwable) {
                    // Keep unknown instead of using an online lookup or guessing.
                }
                $changes['asn_database_version'] = $databases['asn_version'];
            }
            if ($changes !== []) {
                $changes['updated_at'] = UtcTime::database(UtcTime::now());
                DB::table('xbs_ip_addresses')->where('id', $row->id)->update($changes);
            }
            $processed++;
        }

        $countryReader?->close();
        $asnReader?->close();
        $this->info("已检查 {$processed} 个 IP，国家与 ASN 仅来自本地 MMDB");
        return self::SUCCESS;
    }
}
