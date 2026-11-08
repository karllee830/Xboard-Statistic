<?php

declare(strict_types=1);

namespace Plugin\XboardStatistic\Commands;

use App\Services\Plugin\PluginManager;
use Illuminate\Console\Command;
use Plugin\XboardStatistic\Services\GeoIpDatabaseManager;
use Plugin\XboardStatistic\Support\PluginInfo;
use Plugin\XboardStatistic\Support\Settings;
use Throwable;

final class UpdateGeoIpDatabasesCommand extends Command
{
    public const SIGNATURE = 'xboard-statistic:update-geoip';

    protected $signature = self::SIGNATURE . ' {--force : 强制检查并重新下载最新数据库}';
    protected $description = '自动下载和更新插件托管的 DB-IP Country/ASN Lite MMDB';

    public function handle(PluginManager $pluginManager, GeoIpDatabaseManager $manager): int
    {
        $settings = Settings::fromPlugin($pluginManager->getEnabledPlugins()[PluginInfo::CODE] ?? null);
        try {
            $databases = $manager->ensure(
                $settings->countryMmdbOverride(),
                $settings->asnMmdbOverride(),
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
