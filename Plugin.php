<?php

declare(strict_types=1);

namespace Plugin\XboardStatistic;

use App\Services\Plugin\AbstractPlugin;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Routing\Router;
use Plugin\XboardStatistic\Commands\CleanupStatisticsCommand;
use Plugin\XboardStatistic\Commands\EnrichIpStatisticsCommand;
use Plugin\XboardStatistic\Commands\RollupStatisticsCommand;
use Plugin\XboardStatistic\Commands\UpdateGeoIpDatabasesCommand;
use Plugin\XboardStatistic\Middleware\InjectAdminDashboardNavigation;

final class Plugin extends AbstractPlugin
{
    public function boot(): void
    {
        $pluginAutoload = __DIR__ . '/vendor/autoload.php';
        if (is_file($pluginAutoload)) {
            require_once $pluginAutoload;
        }

        $middlewareFile = __DIR__ . '/Middleware/InjectAdminDashboardNavigation.php';
        if (!class_exists(InjectAdminDashboardNavigation::class, false)) {
            require_once $middlewareFile;
        }

        $router = app('router');
        if (!$router instanceof Router) {
            return;
        }

        $webMiddleware = $router->getMiddlewareGroups()['web'] ?? [];
        if (!in_array(InjectAdminDashboardNavigation::class, $webMiddleware, true)) {
            $router->pushMiddlewareToGroup('web', InjectAdminDashboardNavigation::class);
        }
    }

    public function schedule(Schedule $schedule): void
    {
        $schedule->command(RollupStatisticsCommand::SIGNATURE . ' --date=' . gmdate('Y-m-d'))
            ->hourlyAt(20)
            ->timezone('UTC')
            ->withoutOverlapping();
        $schedule->command(RollupStatisticsCommand::SIGNATURE)
            ->dailyAt('00:10')
            ->timezone('UTC')
            ->withoutOverlapping();
        $schedule->command(EnrichIpStatisticsCommand::SIGNATURE)
            ->everyTenMinutes()
            ->timezone('UTC')
            ->withoutOverlapping();
        $schedule->command(UpdateGeoIpDatabasesCommand::SIGNATURE)
            ->dailyAt('04:05')
            ->timezone('UTC')
            ->withoutOverlapping();
        $schedule->command(CleanupStatisticsCommand::SIGNATURE)
            ->dailyAt('03:20')
            ->timezone('UTC')
            ->withoutOverlapping();
    }
}
