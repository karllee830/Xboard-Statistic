<?php

declare(strict_types=1);

namespace Plugin\XboardStatistic;

use App\Services\Plugin\AbstractPlugin;
use Illuminate\Console\Scheduling\Schedule;
use Plugin\XboardStatistic\Commands\CleanupStatisticsCommand;
use Plugin\XboardStatistic\Commands\RollupStatisticsCommand;
use Plugin\XboardStatistic\Commands\EnrichIpStatisticsCommand;

final class Plugin extends AbstractPlugin
{
    public function boot(): void
    {
        // Routes, migrations and commands are loaded by Xboard's PluginManager.
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
