<?php

declare(strict_types=1);

namespace Plugin\XboardStatistic\Services\Query;

use InvalidArgumentException;
use Plugin\XboardStatistic\Support\Tables;
use Plugin\XboardStatistic\Support\UtcTime;

/**
 * The fact table a query reads from and the effective range it covers.
 *
 * Ranges longer than a day that only touch node, user and domain are served
 * from the hourly rollups, aligned to whole UTC hours. Everything else reads
 * the minute table at the exact requested range.
 */
final class TrafficSource
{
    public const ROLLUP_THRESHOLD_SECONDS = 86400;
    public const MAX_MINUTE_SPAN_SECONDS = 90 * 86400;
    /** Group dimensions that both rollups can answer (domain additionally needs the domain rollup). */
    public const ROLLUP_GROUPS = ['time', 'node', 'user', 'domain'];

    private function __construct(
        public readonly string $table,
        public readonly string $timeColumn,
        public readonly int $from,
        public readonly int $to,
    ) {
    }

    public static function choose(TrafficFilters $filters, ?string $groupBy, bool $needsDomain = false): self
    {
        $rollupEligible = $filters->span() > self::ROLLUP_THRESHOLD_SECONDS
            && !$filters->hasDetailFilters()
            && ($groupBy === null || in_array($groupBy, self::ROLLUP_GROUPS, true));

        if ($rollupEligible) {
            $withDomain = $needsDomain || $groupBy === 'domain' || $filters->domain !== null;
            return new self(
                $withDomain ? Tables::HOUR_NODE_USER_DOMAIN : Tables::HOUR_NODE_USER,
                'hour_unix',
                UtcTime::floorHour($filters->from),
                UtcTime::ceilHour($filters->to),
            );
        }

        if ($filters->span() > self::MAX_MINUTE_SPAN_SECONDS) {
            throw new InvalidArgumentException('包含 IP、端口、协议、出站、国家或 ASN 的查询范围不能超过 90 天');
        }
        return self::minute($filters);
    }

    public static function minute(TrafficFilters $filters): self
    {
        return new self(Tables::MINUTE, 'bucket_start_unix', $filters->from, $filters->to);
    }

    public function isMinute(): bool
    {
        return $this->table === Tables::MINUTE;
    }

    public function hasDomain(): bool
    {
        return $this->table !== Tables::HOUR_NODE_USER;
    }
}
