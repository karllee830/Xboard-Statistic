<?php

declare(strict_types=1);

namespace Plugin\XboardStatistic\Services\Query;

use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Plugin\XboardStatistic\Exceptions\QueryTimeoutException;
use Plugin\XboardStatistic\Support\Tables;
use Plugin\XboardStatistic\Support\UtcTime;

final class TrafficQueryService
{
    public const MAX_PAGE_SIZE = 500;
    /** Ranges longer than this switch the trend from hourly to daily points. */
    public const HOURLY_TREND_MAX_SECONDS = 31 * 86400;
    /** Detail counts such as distinct IPs are only computed for short ranges. */
    public const DETAIL_COUNT_MAX_SECONDS = 86400;
    /** The unknown-domain hint scans minute rows, so it is limited to ranges that stay cheap. */
    private const REPRESENTATIVE_MAX_SECONDS = 7 * 86400;
    private const REPRESENTATIVE_SCOPED_MAX_SECONDS = 31 * 86400;
    private const REPRESENTATIVE_TIMEOUT_MS = 3000;
    private const SORT_COLUMNS = [
        'upload' => 'upload_bytes', 'upload_bytes' => 'upload_bytes',
        'download' => 'download_bytes', 'download_bytes' => 'download_bytes',
        'total' => 'total_bytes', 'total_bytes' => 'total_bytes',
        'connections' => 'connection_count', 'connection_count' => 'connection_count',
        'active_seconds' => 'active_seconds',
    ];

    public function __construct(
        private readonly TrafficQueryBuilder $builder,
        private readonly TrafficRowDecorator $decorator,
    ) {
    }

    public function query(Request $request): array
    {
        $filters = TrafficFilters::fromRequest($request);
        $groupBy = strtolower((string) $request->query('group_by', 'user'));
        if (!in_array($groupBy, TrafficQueryBuilder::GROUPS, true)) {
            throw new InvalidArgumentException('不支持的 group_by');
        }
        $source = TrafficSource::choose($filters, $groupBy);

        return QueryGuard::run(fn (): array => $groupBy === 'time'
            ? $this->trend($filters, $source)
            : $this->ranking($filters, $source, $groupBy, $request));
    }

    public function overview(Request $request): array
    {
        $filters = TrafficFilters::fromRequest($request);
        $source = TrafficSource::choose($filters, null);

        return QueryGuard::run(function () use ($filters, $source): array {
            $detailCounts = $source->isMinute() && $filters->span() <= self::DETAIL_COUNT_MAX_SECONDS;
            $totals = $this->builder->base($filters, $source)
                ->selectRaw('COALESCE(SUM(traffic.upload_bytes), 0) AS upload_bytes')
                ->selectRaw('COALESCE(SUM(traffic.download_bytes), 0) AS download_bytes')
                ->selectRaw('COALESCE(SUM(traffic.upload_bytes + traffic.download_bytes), 0) AS total_bytes')
                ->selectRaw('COALESCE(SUM(traffic.connection_count), 0) AS connection_count')
                ->selectRaw('COALESCE(SUM(traffic.active_seconds), 0) AS active_seconds')
                ->selectRaw('COUNT(DISTINCT traffic.node_id) AS node_count')
                ->selectRaw('COUNT(DISTINCT traffic.user_id) AS user_count')
                ->when($detailCounts, fn (Builder $query) => $query
                    ->selectRaw('COUNT(DISTINCT traffic.domain_id) AS domain_count')
                    ->selectRaw('COUNT(DISTINCT traffic.source_ip_id) AS source_ip_count')
                    ->selectRaw('COUNT(DISTINCT traffic.destination_ip_id) AS destination_ip_count'))
                ->first();

            $domainCount = $detailCounts ? (int) $totals->domain_count : null;
            if ($domainCount === null && !$source->isMinute()) {
                $domainSource = TrafficSource::choose($filters, null, true);
                $domainCount = (int) $this->builder->base($filters, $domainSource)
                    ->where('traffic.domain_id', '<>', Tables::UNKNOWN_DOMAIN_ID)
                    ->selectRaw('COUNT(DISTINCT traffic.domain_id) AS domain_count')
                    ->value('domain_count');
            }

            $batches = $this->batchStatistics($source->from, $source->to, $filters->nodeId);
            $total = (string) $totals->total_bytes;
            $totalFloat = (float) $total;
            $rate = static fn (int $bytes, bool $inverse): ?float => $totalFloat > 0
                ? round($inverse ? max(0.0, 1.0 - $bytes / $totalFloat) : $bytes / $totalFloat, 6)
                : null;

            return [
                'from' => UtcTime::iso($source->from),
                'to' => UtcTime::iso($source->to),
                'upload_bytes' => (string) $totals->upload_bytes,
                'download_bytes' => (string) $totals->download_bytes,
                'total_bytes' => $total,
                'connection_count' => (int) $totals->connection_count,
                'active_seconds' => (int) $totals->active_seconds,
                'node_count' => (int) $totals->node_count,
                'user_count' => (int) $totals->user_count,
                'domain_count' => $domainCount,
                'source_ip_count' => $detailCounts ? (int) $totals->source_ip_count : null,
                'destination_ip_count' => $detailCounts ? (int) $totals->destination_ip_count : null,
                'batch_count' => $batches['batch_count'],
                'last_received_at' => UtcTime::isoOrNull(DB::table(Tables::BATCHES)->max('received_at_unix')),
                'late_batch_count' => $batches['late_batch_count'],
                'missing_bucket_estimate' => $batches['missing_bucket_estimate'],
                'reported_pending_batches' => $batches['reported_pending_batches'],
                'maximum_retry_attempt' => $batches['maximum_retry_attempt'],
                'quality' => $batches['quality'] + [
                    'domain_recognition_rate' => $rate($batches['quality']['unknown_domain_bytes'], true),
                    'destination_ip_recognition_rate' => $rate($batches['quality']['unknown_destination_ip_bytes'], true),
                    'collapsed_bytes_rate' => $rate($batches['quality']['collapsed_bytes'], false),
                ],
            ];
        });
    }

    public function health(Request $request): array
    {
        $filters = TrafficFilters::fromRequest($request);

        return QueryGuard::run(function () use ($filters): array {
            $latest = $this->latestBatches($filters->from, $filters->to, null);
            $names = DB::table('v2_server')->whereIn('id', array_keys($latest))->pluck('name', 'id')->all();

            $items = [];
            foreach ($latest as $nodeId => $row) {
                $items[] = [
                    'node_id' => $nodeId,
                    'node_name' => isset($names[$nodeId]) ? (string) $names[$nodeId] : '已删除 #' . $nodeId,
                    'last_bucket_start' => UtcTime::iso((int) $row->bucket_start_unix),
                    'last_received_at' => UtcTime::iso((int) $row->received_at_unix),
                    'report_delay_seconds' => max(0, (int) $row->received_at_unix - (int) $row->bucket_start_unix - 60),
                    'reported_pending_batches' => $row->reported_pending_batches === null ? null : (int) $row->reported_pending_batches,
                    'reported_retry_attempt' => $row->reported_retry_attempt === null ? null : (int) $row->reported_retry_attempt,
                    'batch_count' => (int) $row->batch_count,
                    'quality' => [
                        'collapsed_dimensions' => (int) $row->collapsed_dimensions,
                        'collapsed_bytes' => (int) $row->collapsed_bytes,
                        'unknown_domain_bytes' => (int) $row->unknown_domain_bytes,
                        'unknown_destination_ip_bytes' => (int) $row->unknown_destination_ip_bytes,
                    ],
                ];
            }
            usort($items, static fn (array $left, array $right): int => ($right['reported_pending_batches'] ?? -1)
                <=> ($left['reported_pending_batches'] ?? -1));

            return [
                'from' => UtcTime::iso($filters->from),
                'to' => UtcTime::iso($filters->to),
                'items' => $items,
            ];
        });
    }

    private function ranking(TrafficFilters $filters, TrafficSource $source, string $groupBy, Request $request): array
    {
        $page = max(1, (int) $request->query('current', '1'));
        $limit = max(1, min(self::MAX_PAGE_SIZE, (int) $request->query('pageSize', (string) $request->query('limit', '50'))));
        $offset = ($page - 1) * $limit;
        $sort = strtolower((string) $request->query('sort_field', $request->query('sort', 'total')));
        $sortColumn = self::SORT_COLUMNS[$sort] ?? 'total_bytes';
        $direction = strtolower((string) $request->query('sort_order', $request->query('direction', 'desc'))) === 'asc' ? 'asc' : 'desc';
        $search = trim((string) $request->query('search', ''));

        $base = $this->builder->base($filters, $source, $groupBy, $search);
        [$selects, $groups] = $this->builder->groupColumns($groupBy, 3600, $source->timeColumn);

        $query = $this->withMetrics((clone $base)->select($selects))
            ->selectRaw('SUM(SUM(traffic.upload_bytes + traffic.download_bytes)) OVER () AS grand_total_bytes')
            ->selectRaw('COUNT(*) OVER () AS group_count')
            ->groupBy($groups)
            ->orderBy($sortColumn, $direction);
        foreach ($groups as $group) {
            $query->orderBy($group);
        }
        $rows = $query->offset($offset)->limit($limit)->get()->all();

        if ($rows !== []) {
            $grandTotal = (string) $rows[0]->grand_total_bytes;
            $groupCount = (int) $rows[0]->group_count;
        } elseif ($offset > 0) {
            // Window totals are unavailable on an empty page past the end; count the groups directly.
            $summary = DB::query()
                ->fromSub($this->withMetrics((clone $base)->select($selects))->groupBy($groups), 'grouped')
                ->selectRaw('COUNT(*) AS group_count, COALESCE(SUM(total_bytes), 0) AS grand_total_bytes')
                ->first();
            $grandTotal = (string) $summary->grand_total_bytes;
            $groupCount = (int) $summary->group_count;
        } else {
            $grandTotal = '0';
            $groupCount = 0;
        }

        $unknownDomainDestination = $groupBy === 'domain'
            && array_filter($rows, static fn (object $row): bool => (int) ($row->group_id ?? 0) === 0) !== []
                ? $this->representativeUnknownDomainDestination($filters)
                : null;

        return [
            'from' => UtcTime::iso($source->from),
            'to' => UtcTime::iso($source->to),
            'group_by' => $groupBy,
            'source' => $source->isMinute() ? 'minute' : 'hourly',
            'grand_total_bytes' => $grandTotal,
            'total' => $groupCount,
            'items' => $this->decorator->decorate($groupBy, $rows, $grandTotal, $offset, $unknownDomainDestination),
        ];
    }

    private function trend(TrafficFilters $filters, TrafficSource $source): array
    {
        $bucketSeconds = $filters->span() > self::HOURLY_TREND_MAX_SECONDS ? 86400 : 3600;
        [$selects, $groups] = $this->builder->groupColumns('time', $bucketSeconds, $source->timeColumn);
        $rows = $this->withMetrics($this->builder->base($filters, $source)->select($selects))
            ->selectRaw('SUM(SUM(traffic.upload_bytes + traffic.download_bytes)) OVER () AS grand_total_bytes')
            ->groupBy($groups)
            ->orderBy('group_time_unix')
            ->get()
            ->all();
        $grandTotal = $rows === [] ? '0' : (string) $rows[0]->grand_total_bytes;

        return [
            'from' => UtcTime::iso($source->from),
            'to' => UtcTime::iso($source->to),
            'group_by' => 'time',
            'source' => $source->isMinute() ? 'minute' : 'hourly',
            'bucket_seconds' => $bucketSeconds,
            'grand_total_bytes' => $grandTotal,
            'total' => count($rows),
            'items' => $this->decorator->decorate('time', $rows, $grandTotal, 0),
        ];
    }

    private function withMetrics(Builder $query): Builder
    {
        return $query
            ->selectRaw('SUM(traffic.upload_bytes) AS upload_bytes')
            ->selectRaw('SUM(traffic.download_bytes) AS download_bytes')
            ->selectRaw('SUM(traffic.upload_bytes + traffic.download_bytes) AS total_bytes')
            ->selectRaw('SUM(traffic.connection_count) AS connection_count')
            ->selectRaw('SUM(traffic.active_seconds) AS active_seconds');
    }

    /**
     * Display hint for the unrecognized-domain row: its busiest destination IP.
     * The row keeps value=unknown so drilling in still covers every destination.
     * Long ranges may exceed the short time budget, in which case the hint is omitted.
     */
    private function representativeUnknownDomainDestination(TrafficFilters $filters): ?array
    {
        $maxSpan = $filters->userId !== null ? self::REPRESENTATIVE_SCOPED_MAX_SECONDS : self::REPRESENTATIVE_MAX_SECONDS;
        if ($filters->span() > $maxSpan) {
            return null;
        }
        $minuteFilters = new TrafficFilters($filters->from, $filters->to, $filters->nodeId, $filters->userId, 'unknown', $filters->detail);
        $source = TrafficSource::minute($minuteFilters);

        try {
            return QueryGuard::run(function () use ($minuteFilters, $source): ?array {
                $scope = $this->builder->base($minuteFilters, $source)
                    ->where('traffic.destination_ip_state', 1);
                $row = (clone $scope)
                    ->selectRaw('traffic.destination_ip_id AS group_id')
                    ->selectRaw('SUM(traffic.upload_bytes + traffic.download_bytes) AS total_bytes')
                    ->selectRaw('COUNT(*) OVER () AS destination_ip_count')
                    ->groupBy('traffic.destination_ip_id')
                    ->orderByDesc('total_bytes')
                    ->orderBy('traffic.destination_ip_id')
                    ->first();
                if ($row === null) {
                    return null;
                }
                $ip = $this->decorator->ipMap([(int) $row->group_id])[(int) $row->group_id] ?? null;
                if ($ip === null) {
                    return null;
                }
                $asnCount = (int) (clone $scope)
                    ->join(Tables::IP_ADDRESSES . ' as unknown_destination_geo', 'unknown_destination_geo.id', '=', 'traffic.destination_ip_id')
                    ->selectRaw('COUNT(DISTINCT unknown_destination_geo.asn) AS asn_count')
                    ->value('asn_count');

                return [
                    'ip' => $ip['value'],
                    'country_code' => $ip['country_code'],
                    'asn' => $ip['asn'],
                    'asn_organization' => $ip['asn_organization'],
                    'total_bytes' => (string) $row->total_bytes,
                    'destination_ip_count' => (int) $row->destination_ip_count,
                    'destination_asn_count' => $asnCount,
                ];
            }, self::REPRESENTATIVE_TIMEOUT_MS);
        } catch (QueryTimeoutException) {
            return null;
        }
    }

    /**
     * @return array{
     *     batch_count: int, late_batch_count: int, missing_bucket_estimate: int,
     *     reported_pending_batches: int, maximum_retry_attempt: int, quality: array<string, int>
     * }
     */
    private function batchStatistics(int $from, int $to, ?int $nodeId): array
    {
        $summary = DB::table(Tables::BATCHES)
            ->where('bucket_start_unix', '>=', $from)
            ->where('bucket_start_unix', '<', $to)
            ->when($nodeId !== null, fn (Builder $query) => $query->where('node_id', $nodeId))
            ->selectRaw('COUNT(*) AS batch_count')
            ->selectRaw('COUNT(DISTINCT node_id) AS node_count')
            ->selectRaw('COALESCE(SUM(CAST(received_at_unix AS SIGNED) - CAST(bucket_start_unix AS SIGNED) > 660), 0) AS late_batch_count')
            ->selectRaw('COALESCE(SUM(collapsed_dimensions), 0) AS collapsed_dimensions')
            ->selectRaw('COALESCE(SUM(collapsed_bytes), 0) AS collapsed_bytes')
            ->selectRaw('COALESCE(SUM(unknown_domain_bytes), 0) AS unknown_domain_bytes')
            ->selectRaw('COALESCE(SUM(unknown_destination_ip_bytes), 0) AS unknown_destination_ip_bytes')
            ->first();

        $pending = 0;
        $retry = 0;
        foreach ($this->latestBatches($from, $to, $nodeId) as $row) {
            $pending += (int) ($row->reported_pending_batches ?? 0);
            $retry = max($retry, (int) ($row->reported_retry_attempt ?? 0));
        }

        $minutes = intdiv($to - $from + 59, 60);
        return [
            'batch_count' => (int) $summary->batch_count,
            'late_batch_count' => (int) $summary->late_batch_count,
            'missing_bucket_estimate' => max(0, (int) $summary->node_count * $minutes - (int) $summary->batch_count),
            'reported_pending_batches' => $pending,
            'maximum_retry_attempt' => $retry,
            'quality' => [
                'collapsed_dimensions' => (int) $summary->collapsed_dimensions,
                'collapsed_bytes' => (int) $summary->collapsed_bytes,
                'unknown_domain_bytes' => (int) $summary->unknown_domain_bytes,
                'unknown_destination_ip_bytes' => (int) $summary->unknown_destination_ip_bytes,
            ],
        ];
    }

    /** @return array<int, object> node id => latest batch in range, with batch_count */
    private function latestBatches(int $from, int $to, ?int $nodeId): array
    {
        $latest = DB::table(Tables::BATCHES)
            ->where('bucket_start_unix', '>=', $from)
            ->where('bucket_start_unix', '<', $to)
            ->when($nodeId !== null, fn (Builder $query) => $query->where('node_id', $nodeId))
            ->groupBy('node_id')
            ->selectRaw('node_id, MAX(bucket_start_unix) AS latest_bucket, COUNT(*) AS batch_count');

        $rows = DB::table(Tables::BATCHES . ' as batch')
            ->joinSub($latest, 'latest', function ($join): void {
                $join->on('latest.node_id', '=', 'batch.node_id')
                    ->on('latest.latest_bucket', '=', 'batch.bucket_start_unix');
            })
            ->orderBy('batch.node_id')
            ->get(['batch.*', 'latest.batch_count']);

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row->node_id] = $row;
        }
        return $map;
    }
}
