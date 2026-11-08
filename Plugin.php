<?php

declare(strict_types=1);

namespace Plugin\XboardStatistic;

use App\Services\Plugin\AbstractPlugin;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Routing\Router;
use Plugin\XboardStatistic\Commands\EnrichIpStatisticsCommand;
use Plugin\XboardStatistic\Commands\MaintainStatisticsCommand;
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
        if (!class_exists(InjectAdminDashboardNavigation::class)) {
            require_once __DIR__ . '/Middleware/InjectAdminDashboardNavigation.php';
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
        $schedule->command(EnrichIpStatisticsCommand::SIGNATURE)
            ->everyTenMinutes()
            ->timezone('UTC')
            ->withoutOverlapping();
        $schedule->command(MaintainStatisticsCommand::SIGNATURE)
            ->dailyAt('03:20')
            ->timezone('UTC')
            ->withoutOverlapping();
        $schedule->command(UpdateGeoIpDatabasesCommand::SIGNATURE)
            ->dailyAt('04:05')
            ->timezone('UTC')
            ->withoutOverlapping();
    }
}
