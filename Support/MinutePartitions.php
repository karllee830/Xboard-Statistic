<?php

declare(strict_types=1);

namespace Plugin\XboardStatistic\Support;

use Illuminate\Support\Facades\DB;

/**
 * Daily RANGE partitions of the minute fact table.
 *
 * Partition pYYYYMMDD holds the UTC day YYYY-MM-DD. A trailing pmax partition
 * catches rows beyond the prepared days, so ingest never fails when the
 * maintenance job falls behind.
 */
final class MinutePartitions
{
    public const FUTURE_DAYS = 7;
    private const MAX_PARTITION = 'pmax';
    private const LOCK_WAIT_SECONDS = 10;

    /** Partition clause used when the table is created. */
    public static function initialClause(int $nowUnix, int $futureDays = self::FUTURE_DAYS): string
    {
        $today = UtcTime::floorDay($nowUnix);
        return 'PARTITION BY RANGE (bucket_start_unix) ('
            . implode(', ', array_merge(
                self::definitions($today + 86400, $today + ($futureDays + 1) * 86400),
                ['PARTITION ' . self::MAX_PARTITION . ' VALUES LESS THAN MAXVALUE'],
            ))
            . ')';
    }

    /**
     * Splits pmax so that every day up to $futureDays ahead has its own partition.
     *
     * @return list<string> created partition names
     */
    public static function ensureFuture(int $nowUnix, int $futureDays = self::FUTURE_DAYS): array
    {
        $bounds = array_filter(self::bounds(), static fn (?int $bound): bool => $bound !== null);
        $today = UtcTime::floorDay($nowUnix);
        $firstBound = $bounds === [] ? $today + 86400 : max($bounds) + 86400;
        $lastBound = $today + ($futureDays + 1) * 86400;
        if ($firstBound > $lastBound) {
            return [];
        }

        $definitions = self::definitions($firstBound, $lastBound);
        $definitions[] = 'PARTITION ' . self::MAX_PARTITION . ' VALUES LESS THAN MAXVALUE';
        self::alter(sprintf(
            'ALTER TABLE %s REORGANIZE PARTITION %s INTO (%s)',
            Tables::MINUTE,
            self::MAX_PARTITION,
            implode(', ', $definitions),
        ));

        return array_map(
            static fn (int $bound): string => self::name($bound),
            range($firstBound, $lastBound, 86400),
        );
    }

    /**
     * Drops every daily partition whose days all lie before $cutoffUnix.
     *
     * @return list<string> dropped partition names
     */
    public static function dropBefore(int $cutoffUnix, bool $dryRun = false): array
    {
        $bounds = self::bounds();
        $finite = array_filter($bounds, static fn (?int $bound): bool => $bound !== null);
        $expired = array_keys(array_filter($finite, static fn (int $bound): bool => $bound <= $cutoffUnix));
        // Keep at least one finite partition so older late rows still have a home.
        if ($expired !== [] && count($expired) === count($finite)) {
            array_pop($expired);
        }
        if ($expired === [] || $dryRun) {
            return $expired;
        }

        self::alter(sprintf('ALTER TABLE %s DROP PARTITION %s', Tables::MINUTE, implode(', ', $expired)));
        return $expired;
    }

    /** @return array<string, ?int> partition name => exclusive upper bound, null for MAXVALUE */
    public static function bounds(): array
    {
        $rows = DB::select(
            'SELECT PARTITION_NAME AS name, PARTITION_DESCRIPTION AS bound
             FROM information_schema.PARTITIONS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND PARTITION_NAME IS NOT NULL
             ORDER BY PARTITION_ORDINAL_POSITION',
            [Tables::MINUTE],
        );
        $bounds = [];
        foreach ($rows as $row) {
            $bounds[(string) $row->name] = ctype_digit((string) $row->bound) ? (int) $row->bound : null;
        }
        return $bounds;
    }

    /** @return list<string> */
    private static function definitions(int $firstBound, int $lastBound): array
    {
        $definitions = [];
        for ($bound = $firstBound; $bound <= $lastBound; $bound += 86400) {
            $definitions[] = sprintf('PARTITION %s VALUES LESS THAN (%d)', self::name($bound), $bound);
        }
        return $definitions;
    }

    private static function name(int $exclusiveBound): string
    {
        return 'p' . gmdate('Ymd', $exclusiveBound - 86400);
    }

    /**
     * Partition DDL needs an exclusive metadata lock. A short lock wait keeps a
     * long-running report query from queueing every ingest request behind the DDL.
     */
    private static function alter(string $sql): void
    {
        $previous = DB::selectOne('SELECT @@SESSION.lock_wait_timeout AS value')->value;
        DB::statement('SET SESSION lock_wait_timeout = ' . self::LOCK_WAIT_SECONDS);
        try {
            DB::statement($sql);
        } finally {
            DB::statement('SET SESSION lock_wait_timeout = ' . (int) $previous);
        }
    }
}
