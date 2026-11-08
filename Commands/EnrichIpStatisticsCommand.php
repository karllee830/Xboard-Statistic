<?php

declare(strict_types=1);

namespace Plugin\XboardStatistic\Commands;

use App\Services\Plugin\PluginManager;
use Illuminate\Console\Command;
use Plugin\XboardStatistic\Services\GeoIpDatabaseManager;
use Plugin\XboardStatistic\Services\IpEnrichmentService;
use Plugin\XboardStatistic\Support\PluginInfo;
use Plugin\XboardStatistic\Support\Settings;
use Throwable;

final class EnrichIpStatisticsCommand extends Command
{
    public const SIGNATURE = 'xboard-statistic:enrich-ips';
    /** Leaves headroom before the next ten-minute scheduler run. */
    private const TIME_BUDGET_SECONDS = 480;

    protected $signature = self::SIGNATURE . ' {--limit=100000 : 本次最多处理的 IP 数}';
    protected $description = '使用插件自动维护的离线 MMDB 补充 IP 国家、ASN 和组织信息';

    public function handle(
        PluginManager $pluginManager,
        GeoIpDatabaseManager $databaseManager,
        IpEnrichmentService $enrichment,
    ): int {
        $settings = Settings::fromPlugin($pluginManager->getEnabledPlugins()[PluginInfo::CODE] ?? null);

        try {
            $databases = $databaseManager->ensure(
                $settings->countryMmdbOverride(),
                $settings->asnMmdbOverride(),
                false,
                fn (string $message) => $this->line($message),
            );
            $countryReader = $databases['country_path'] !== null ? $databaseManager->reader($databases['country_path']) : null;
            $asnReader = $databases['asn_path'] !== null ? $databaseManager->reader($databases['asn_path']) : null;
        } catch (Throwable $exception) {
            $this->error('无法准备 MMDB：' . $exception->getMessage());
            return self::FAILURE;
        }
        if ($countryReader === null && $asnReader === null) {
            $this->error('Country 和 ASN MMDB 均不可用');
            return self::FAILURE;
        }

        try {
            $processed = $enrichment->enrich(
                $countryReader === null ? null : static fn (string $ip): mixed => $countryReader->get($ip),
                $databases['country_version'],
                $asnReader === null ? null : static fn (string $ip): mixed => $asnReader->get($ip),
                $databases['asn_version'],
                max(1, min(1000000, (int) $this->option('limit'))),
                self::TIME_BUDGET_SECONDS,
            );
        } finally {
            $countryReader?->close();
            $asnReader?->close();
        }

        $this->info("已检查 {$processed} 个 IP，国家与 ASN 仅来自本地 MMDB");
        return self::SUCCESS;
    }
}
