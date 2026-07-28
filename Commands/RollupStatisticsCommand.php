<?php

declare(strict_types=1);

namespace Plugin\XboardStatistic\Commands;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

final class RollupStatisticsCommand extends Command
{
    public const SIGNATURE = 'xboard-statistic:rollup';

    protected $signature = self::SIGNATURE . ' {--date= : UTC 日期，格式 YYYY-MM-DD，默认昨天}';
    protected $description = '重建 Xboard Statistics 指定 UTC 日期的常用二维汇总';

    public function handle(): int
    {
        $utc = new DateTimeZone('UTC');
        $dateOption = trim((string) $this->option('date'));
        try {
            $day = $dateOption === ''
                ? new DateTimeImmutable('yesterday', $utc)
                : DateTimeImmutable::createFromFormat('!Y-m-d', $dateOption, $utc);
        } catch (Throwable) {
            $day = false;
        }
        if (!$day instanceof DateTimeImmutable || ($dateOption !== '' && $day->format('Y-m-d') !== $dateOption)) {
            $this->error('--date 必须是有效的 YYYY-MM-DD');
            return self::INVALID;
        }

        $date = $day->format('Y-m-d');
        $from = $date . ' 00:00:00';
        $to = $day->modify('+1 day')->format('Y-m-d') . ' 00:00:00';
        $definitions = [
            'node_user' => ['node_id', 'user_id'],
            'node_domain' => ['node_id', 'domain_id'],
            'user_node' => ['user_id', 'node_id'],
            'user_domain' => ['user_id', 'domain_id'],
            'source_ip_user' => ['source_ip_id', 'user_id'],
            'destination_ip_domain' => ['destination_ip_id', 'domain_id'],
        ];

        DB::transaction(function () use ($definitions, $date, $from, $to): void {
            DB::table('xbs_traffic_daily')->where('day', $date)->delete();
            foreach ($definitions as $type => [$left, $right]) {
                $query = $this->rollupQuery($date, $type, $left, $right, $from, $to);
                DB::table('xbs_traffic_daily')->insertUsing([
                    'day', 'summary_type', 'left_id', 'right_id',
                    'upload_bytes', 'download_bytes', 'connection_count', 'active_seconds',
                    'created_at', 'updated_at',
                ], $query);
            }
        }, 3);

        $count = DB::table('xbs_traffic_daily')->where('day', $date)->count();
        $this->info("{$date} 日汇总已重建，共 {$count} 行");
        return self::SUCCESS;
    }

    private function rollupQuery(
        string $date,
        string $type,
        string $left,
        string $right,
        string $from,
        string $to,
    ): Builder {
        return DB::table('xbs_traffic_hourly')
            ->where('bucket_start', '>=', $from)
            ->where('bucket_start', '<', $to)
            ->whereNotNull($left)
            ->whereNotNull($right)
            ->selectRaw('? AS day, ? AS summary_type', [$date, $type])
            ->selectRaw("{$left} AS left_id, {$right} AS right_id")
            ->selectRaw('SUM(upload_bytes) AS upload_bytes, SUM(download_bytes) AS download_bytes')
            ->selectRaw('SUM(connection_count) AS connection_count, SUM(active_seconds) AS active_seconds')
            ->selectRaw('CURRENT_TIMESTAMP AS created_at, CURRENT_TIMESTAMP AS updated_at')
            ->groupBy([$left, $right]);
    }
}
