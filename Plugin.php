<?php

declare(strict_types=1);

namespace Plugin\XboardStatistic;

use App\Services\Plugin\AbstractPlugin;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Routing\Router;
use Plugin\XboardStatistic\Commands\CleanupStatisticsCommand;
use Plugin\XboardStatistic\Commands\RollupStatisticsCommand;
use Plugin\XboardStatistic\Commands\EnrichIpStatisticsCommand;
use Plugin\XboardStatistic\Middleware\InjectAdminDashboardNavigation;

final class Plugin extends AbstractPlugin
{
    public function boot(): void
    {
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
            ->withoutOverlapping();
        $schedule->command(RollupStatisticsCommand::SIGNATURE)
            ->dailyAt('02:10')
            ->withoutOverlapping();
        $schedule->command(EnrichIpStatisticsCommand::SIGNATURE)
            ->hourlyAt(35)
            ->withoutOverlapping();
        $schedule->command(CleanupStatisticsCommand::SIGNATURE)
            ->dailyAt('03:20')
            ->withoutOverlapping();
    }
}
