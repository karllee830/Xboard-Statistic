<?php

declare(strict_types=1);

namespace Plugin\XboardStatistic\Services;

use Illuminate\Support\Facades\DB;
use Plugin\XboardStatistic\Support\MinutePartitions;
use Plugin\XboardStatistic\Support\Settings;
use Plugin\XboardStatistic\Support\Tables;
use Plugin\XboardStatistic\Support\UtcTime;

/**
 * Prepares upcoming minute partitions and removes data past its retention period.
 */
final class StatisticsMaintenance
{
    public const DELETE_CHUNK = 5000;
    /** Dimension rows outlive the facts that reference them by this margin. */
    private const DIMENSION_GRACE_SECONDS = 86400;

    /**
     * @return array{
     *     created_partitions: list<string>,
     *     dropped_partitions: list<string>,
     *     cutoffs: array<string, string>,
     *     deleted: array<string, int>
     * }
     */
    public function run(Settings $settings, int $nowUnix, bool $dryRun = false): array
    {
        $today = UtcTime::floorDay($nowUnix);
        $minuteCutoff = $today - $settings->minuteRetentionDays() * 86400;
        $rollupCutoff = $today - $settings->rollupRetentionDays() * 86400;
        $batchCutoff = $today - $settings->batchRetentionDays() * 86400;

        $created = $dryRun ? [] : MinutePartitions::ensureFuture($nowUnix);
        $dropped = MinutePartitions::dropBefore($minuteCutoff, $dryRun);

        $deleted = [];
        foreach ([Tables::HOUR_NODE_USER, Tables::HOUR_NODE_USER_DOMAIN] as $table) {
            $deleted[$table] = $this->purge($table, 'hour_unix', $rollupCutoff, $dryRun);
        }
        $deleted[Tables::BATCHES] = $this->purge(Tables::BATCHES, 'bucket_start_unix', $batchCutoff, $dryRun);
        $deleted[Tables::IP_ADDRESSES] = $this->purge(Tables::IP_ADDRESSES, 'last_seen_unix', $minuteCutoff - self::DIMENSION_GRACE_SECONDS, $dryRun);
        $deleted[Tables::OUTBOUNDS] = $this->purge(Tables::OUTBOUNDS, 'last_seen_unix', $minuteCutoff - self::DIMENSION_GRACE_SECONDS, $dryRun);
        $deleted[Tables::DOMAINS] = $this->purge(Tables::DOMAINS, 'last_seen_unix', $rollupCutoff - self::DIMENSION_GRACE_SECONDS, $dryRun);

        return [
            'created_partitions' => $created,
            'dropped_partitions' => $dropped,
            'cutoffs' => [
                'minute' => UtcTime::iso($minuteCutoff),
                'rollup' => UtcTime::iso($rollupCutoff),
                'batch' => UtcTime::iso($batchCutoff),
            ],
            'deleted' => $deleted,
        ];
    }

    /** Deletes in small autocommitted chunks so no long transaction blocks report ingest. */
    private function purge(string $table, string $column, int $cutoff, bool $dryRun): int
    {
        if ($dryRun) {
            return DB::table($table)->where($column, '<', $cutoff)->count();
        }

        $total = 0;
        do {
            $deleted = DB::table($table)->where($column, '<', $cutoff)->limit(self::DELETE_CHUNK)->delete();
            $total += $deleted;
        } while ($deleted === self::DELETE_CHUNK);
        return $total;
    }
}
