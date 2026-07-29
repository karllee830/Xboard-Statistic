<?php

declare(strict_types=1);

namespace Plugin\XboardStatistic\Commands;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Plugin\XboardStatistic\Support\UtcTime;
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
        $from = $day->getTimestamp();
        $to = $day->modify('+1 day')->getTimestamp();
        $now = UtcTime::database(UtcTime::now());
        $definitions = [
            'node_user' => ['node_id', 'user_id'],
            'node_domain' => ['node_id', 'domain_id'],
            'user_node' => ['user_id', 'node_id'],
            'user_domain' => ['user_id', 'domain_id'],
            'source_ip_user' => ['source_ip_id', 'user_id'],
            'destination_ip_domain' => ['destination_ip_id', 'domain_id'],
        ];

        DB::transaction(function () use ($definitions, $date, $from, $to, $now): void {
            DB::table('xbs_traffic_daily')->where('day', $date)->delete();
            foreach ($definitions as $type => [$left, $right]) {
                $query = $this->rollupQuery($date, $type, $left, $right, $from, $to, $now);
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
        int $from,
        int $to,
        string $now,
    ): Builder {
        return DB::table('xbs_traffic_hourly')
            ->where('bucket_start_unix', '>=', $from)
            ->where('bucket_start_unix', '<', $to)
            ->whereNotNull($left)
            ->whereNotNull($right)
            ->selectRaw('? AS day, ? AS summary_type', [$date, $type])
            ->selectRaw("{$left} AS left_id, {$right} AS right_id")
            ->selectRaw('SUM(upload_bytes) AS upload_bytes, SUM(download_bytes) AS download_bytes')
            ->selectRaw('SUM(connection_count) AS connection_count, SUM(active_seconds) AS active_seconds')
            ->selectRaw('? AS created_at, ? AS updated_at', [$now, $now])
            ->groupBy([$left, $right]);
    }
}
