<?php

declare(strict_types=1);

namespace Plugin\XboardStatistic\Commands;

use App\Services\Plugin\PluginManager;
use Illuminate\Console\Command;
use Plugin\XboardStatistic\Services\StatisticsMaintenance;
use Plugin\XboardStatistic\Support\PluginInfo;
use Plugin\XboardStatistic\Support\Settings;

final class MaintainStatisticsCommand extends Command
{
    public const SIGNATURE = 'xboard-statistic:maintain';

    protected $signature = self::SIGNATURE . ' {--dry-run : 只统计将被删除的数据，不创建或删除分区}';
    protected $description = '准备未来的分钟分区，并清理超过保留期的统计数据';

    public function handle(PluginManager $pluginManager, StatisticsMaintenance $maintenance): int
    {
        $settings = Settings::fromPlugin($pluginManager->getEnabledPlugins()[PluginInfo::CODE] ?? null);
        $report = $maintenance->run($settings, time(), (bool) $this->option('dry-run'));

        foreach ($report['cutoffs'] as $kind => $cutoff) {
            $this->line("{$kind}_cutoff={$cutoff}");
        }
        $this->line('created_partitions=' . (implode(',', $report['created_partitions']) ?: '-'));
        $this->line('dropped_partitions=' . (implode(',', $report['dropped_partitions']) ?: '-'));
        foreach ($report['deleted'] as $table => $count) {
            $this->line("{$table}={$count}");
        }
        $this->info($this->option('dry-run') ? '预览完成，未修改数据' : '统计数据维护完成');
        return self::SUCCESS;
    }
}
