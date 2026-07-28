<?php

declare(strict_types=1);

namespace Plugin\XboardStatistic\Commands;

use App\Services\Plugin\PluginManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class CleanupStatisticsCommand extends Command
{
    public const SIGNATURE = 'xboard-statistic:cleanup';

    protected $signature = self::SIGNATURE . ' {--dry-run : 只统计将被删除的数据}';
    protected $description = '清理 Xboard Statistics 过期小时数据和孤立维度';

    public function handle(PluginManager $pluginManager): int
    {
        $plugin = $pluginManager->getEnabledPlugins()['xboard_statistic'] ?? null;
        $retentionDays = max(7, min(90, (int) ($plugin?->getConfig('hourly_retention_days', 30) ?? 30)));
        $dailyRetentionDays = max(30, min(730, (int) ($plugin?->getConfig('daily_retention_days', 365) ?? 365)));
        $batchRetentionDays = max(30, min(365, (int) ($plugin?->getConfig('batch_retention_days', 90) ?? 90)));
        $utcNow = now('UTC');
        $cutoff = $utcNow->copy()->subDays($retentionDays)->startOfHour();
        $dailyCutoff = $utcNow->copy()->subDays($dailyRetentionDays)->startOfDay();
        $batchCutoff = $utcNow->copy()->subDays($batchRetentionDays)->startOfHour();

        $factCount = DB::table('xbs_traffic_hourly')->where('bucket_start', '<', $cutoff)->count();
        $dailyCount = DB::table('xbs_traffic_daily')->where('day', '<', $dailyCutoff->toDateString())->count();
        $batchCount = DB::table('xbs_report_batches')->where('bucket_start', '<', $batchCutoff)->count();
        $this->line("hourly_cutoff={$cutoff->toIso8601String()} facts={$factCount}");
        $this->line("daily_cutoff={$dailyCutoff->toDateString()} summaries={$dailyCount}");
        $this->line("batch_cutoff={$batchCutoff->toIso8601String()} batches={$batchCount}");

        if ((bool) $this->option('dry-run')) {
            return self::SUCCESS;
        }

        DB::transaction(function () use ($cutoff, $dailyCutoff, $batchCutoff): void {
            DB::table('xbs_traffic_hourly')->where('bucket_start', '<', $cutoff)->delete();
            DB::table('xbs_traffic_daily')->where('day', '<', $dailyCutoff->toDateString())->delete();
            DB::table('xbs_report_batches')->where('bucket_start', '<', $batchCutoff)->delete();

            DB::table('xbs_domains')->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('xbs_traffic_hourly')
                    ->whereColumn('xbs_traffic_hourly.domain_id', 'xbs_domains.id');
            })->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('xbs_traffic_daily')
                    ->whereIn('summary_type', ['node_domain', 'user_domain', 'destination_ip_domain'])
                    ->whereColumn('xbs_traffic_daily.right_id', 'xbs_domains.id');
            })->delete();
            DB::table('xbs_ip_addresses')->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('xbs_traffic_hourly')
                    ->whereColumn('xbs_traffic_hourly.source_ip_id', 'xbs_ip_addresses.id')
                    ->orWhereColumn('xbs_traffic_hourly.destination_ip_id', 'xbs_ip_addresses.id');
            })->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('xbs_traffic_daily')
                    ->whereIn('summary_type', ['source_ip_user', 'destination_ip_domain'])
                    ->whereColumn('xbs_traffic_daily.left_id', 'xbs_ip_addresses.id');
            })->delete();
            DB::table('xbs_outbounds')->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('xbs_traffic_hourly')
                    ->whereColumn('xbs_traffic_hourly.outbound_id', 'xbs_outbounds.id');
            })->delete();
        });

        $this->info('过期统计数据清理完成');
        return self::SUCCESS;
    }
}
