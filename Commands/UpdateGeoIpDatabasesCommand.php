<?php

declare(strict_types=1);

namespace Plugin\XboardStatistic\Commands;

use App\Services\Plugin\PluginManager;
use Illuminate\Console\Command;
use Plugin\XboardStatistic\Services\GeoIpDatabaseManager;
use Throwable;

final class UpdateGeoIpDatabasesCommand extends Command
{
    public const SIGNATURE = 'xboard-statistic:update-geoip';

    protected $signature = self::SIGNATURE . ' {--force : 强制检查并重新下载最新数据库}';
    protected $description = '自动下载和更新插件托管的 DB-IP Country/ASN Lite MMDB';

    public function handle(PluginManager $pluginManager, GeoIpDatabaseManager $manager): int
    {
        $plugin = $pluginManager->getEnabledPlugins()['xboard_statistic'] ?? null;
        try {
            $databases = $manager->ensure(
                (string) ($plugin?->getConfig('geoip_country_mmdb', '') ?? ''),
                (string) ($plugin?->getConfig('geoip_asn_mmdb', '') ?? ''),
                (bool) $this->option('force'),
                fn (string $message) => $this->line($message),
            );
        } catch (Throwable $exception) {
            $this->error('GeoIP 数据库更新失败：' . $exception->getMessage());
            return self::FAILURE;
        }

        if ($databases['country_path'] === null && $databases['asn_path'] === null) {
            $this->error('Country 和 ASN 数据库均不可用');
            return self::FAILURE;
        }
        $this->info('GeoIP 数据库已就绪：' . $databases['managed_directory']);
        return self::SUCCESS;
    }
}
