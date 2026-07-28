<?php

declare(strict_types=1);

namespace Plugin\XboardStatistic\Services;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Plugin\XboardStatistic\Support\IpAddress;
use Throwable;

final class TrafficQueryService
{
    private const GROUPS = [
        'time', 'node', 'user', 'domain', 'source_ip', 'destination_ip',
        'network', 'application_protocol', 'inbound_type', 'outbound', 'destination_port',
        'source_country', 'destination_country', 'source_asn', 'destination_asn',
    ];

    public function query(Request $request): array
    {
        [$from, $to] = $this->timeRange($request);
        $groupBy = strtolower((string) $request->query('group_by', 'user'));
        if (!in_array($groupBy, self::GROUPS, true)) {
            throw new InvalidArgumentException('不支持的 group_by');
        }
        $page = max(1, (int) $request->query('current', '1'));
        $defaultLimit = (string) $request->query('limit', '50');
        $limit = max(1, min(500, (int) $request->query('pageSize', $defaultLimit)));
        $offset = $request->query->has('current') ? ($page - 1) * $limit : 0;

        $base = $this->baseQuery($request, $from, $to, $groupBy);
        $grandTotal = (string) ((clone $base)->selectRaw('COALESCE(SUM(traffic.upload_bytes + traffic.download_bytes), 0) AS aggregate')->value('aggregate') ?? '0');

        [$selects, $groups] = $this->groupColumns($groupBy);
        $query = clone $base;
        $query->select($selects)
            ->selectRaw('SUM(traffic.upload_bytes) AS upload_bytes')
            ->selectRaw('SUM(traffic.download_bytes) AS download_bytes')
            ->selectRaw('SUM(traffic.upload_bytes + traffic.download_bytes) AS total_bytes')
            ->selectRaw('SUM(traffic.connection_count) AS connection_count')
            ->selectRaw('SUM(traffic.active_seconds) AS active_seconds')
            ->groupBy($groups);

        $countQuery = (clone $base)->selectRaw('1 AS grouped_row')->groupBy($groups);
        $groupCount = (int) DB::query()->fromSub($countQuery, 'grouped_traffic')->count();

        $sort = strtolower((string) $request->query('sort_field', $request->query('sort', 'total')));
        $sortColumn = match ($sort) {
            'upload', 'upload_bytes' => 'upload_bytes',
            'download', 'download_bytes' => 'download_bytes',
            'total', 'total_bytes' => 'total_bytes',
            'connections' => 'connection_count',
            'active_seconds' => 'active_seconds',
            default => 'total_bytes',
        };
        $direction = strtolower((string) $request->query('sort_order', $request->query('direction', 'desc'))) === 'asc' ? 'asc' : 'desc';
        $rows = $query->orderBy($sortColumn, $direction)->offset($offset)->limit($limit)->get();

        return [
            'from' => $from->format('Y-m-d\TH:i:s\Z'),
            'to' => $to->format('Y-m-d\TH:i:s\Z'),
            'group_by' => $groupBy,
            'grand_total_bytes' => $grandTotal,
            'total' => $groupCount,
            'items' => $this->decorateRows($groupBy, $rows->all(), $grandTotal, $offset),
        ];
    }

    public function overview(Request $request): array
    {
        [$from, $to] = $this->timeRange($request);
        $base = $this->baseQuery($request, $from, $to, null);
        $totals = (clone $base)->selectRaw('COALESCE(SUM(traffic.upload_bytes), 0) AS upload_bytes')
            ->selectRaw('COALESCE(SUM(traffic.download_bytes), 0) AS download_bytes')
            ->selectRaw('COALESCE(SUM(traffic.connection_count), 0) AS connection_count')
            ->selectRaw('COALESCE(SUM(traffic.active_seconds), 0) AS active_seconds')
            ->selectRaw('COUNT(DISTINCT traffic.node_id) AS node_count')
            ->selectRaw('COUNT(DISTINCT traffic.user_id) AS user_count')
            ->selectRaw('COUNT(DISTINCT traffic.domain_id) AS domain_count')
            ->selectRaw('COUNT(DISTINCT traffic.source_ip_id) AS source_ip_count')
            ->selectRaw('COUNT(DISTINCT traffic.destination_ip_id) AS destination_ip_count')
            ->first();

        $lastBatch = DB::table('xbs_report_batches')->max('received_at');
        $batchRows = DB::table('xbs_report_batches')
            ->where('bucket_start', '>=', $from->format('Y-m-d H:i:s'))
            ->where('bucket_start', '<', $to->format('Y-m-d H:i:s'))
            ->get([
                'node_id', 'bucket_start', 'bucket_end', 'received_at', 'quality',
                'reported_pending_batches', 'reported_retry_attempt',
            ]);
        $batchCount = $batchRows->count();

        $quality = [
            'collapsed_dimensions' => 0,
            'collapsed_bytes' => 0,
            'unknown_domain_bytes' => 0,
            'unknown_destination_ip_bytes' => 0,
        ];
        $lateBatchCount = 0;
        $coveredBucketMinutes = 0;
        $latestByNode = [];
        foreach ($batchRows as $batch) {
            $values = json_decode((string) ($batch->quality ?? '{}'), true);
            if (is_array($values)) {
                foreach (array_keys($quality) as $field) {
                    $quality[$field] += max(0, (int) ($values[$field] ?? 0));
                }
            }
            try {
                $bucketEnd = new DateTimeImmutable((string) $batch->bucket_end, new DateTimeZone('UTC'));
                $bucketStart = new DateTimeImmutable((string) $batch->bucket_start, new DateTimeZone('UTC'));
                $receivedAt = new DateTimeImmutable((string) $batch->received_at, new DateTimeZone('UTC'));
                $coveredBucketMinutes += max(0, intdiv(
                    $bucketEnd->getTimestamp() - $bucketStart->getTimestamp(),
                    60,
                ));
                if ($receivedAt->getTimestamp() - $bucketEnd->getTimestamp() > 600) {
                    $lateBatchCount++;
                }
            } catch (Throwable) {
                // Invalid legacy metadata should not make the overview unavailable.
            }
            $nodeId = (int) $batch->node_id;
            if (!isset($latestByNode[$nodeId])
                || (string) $batch->received_at > (string) $latestByNode[$nodeId]->received_at) {
                $latestByNode[$nodeId] = $batch;
            }
        }
        $reportedPending = 0;
        $maximumRetryAttempt = 0;
        foreach ($latestByNode as $batch) {
            $reportedPending += max(0, (int) ($batch->reported_pending_batches ?? 0));
            $maximumRetryAttempt = max($maximumRetryAttempt, (int) ($batch->reported_retry_attempt ?? 0));
        }

        $upload = (string) ($totals->upload_bytes ?? '0');
        $download = (string) ($totals->download_bytes ?? '0');
        $total = $this->decimalAdd($upload, $download);
        $totalFloat = (float) $total;
        $nodeCount = (int) ($totals->node_count ?? 0);
        $minutes = (int) ceil(($to->getTimestamp() - $from->getTimestamp()) / 60);
        return [
            'from' => $from->format('Y-m-d\TH:i:s\Z'),
            'to' => $to->format('Y-m-d\TH:i:s\Z'),
            'upload_bytes' => $upload,
            'download_bytes' => $download,
            'total_bytes' => $total,
            'connection_count' => (int) ($totals->connection_count ?? 0),
            'active_seconds' => (int) ($totals->active_seconds ?? 0),
            'node_count' => $nodeCount,
            'user_count' => (int) ($totals->user_count ?? 0),
            'domain_count' => (int) ($totals->domain_count ?? 0),
            'source_ip_count' => (int) ($totals->source_ip_count ?? 0),
            'destination_ip_count' => (int) ($totals->destination_ip_count ?? 0),
            'batch_count' => $batchCount,
            'last_received_at' => $lastBatch,
            'late_batch_count' => $lateBatchCount,
            'missing_bucket_estimate' => max(0, $nodeCount * $minutes - $coveredBucketMinutes),
            'reported_pending_batches' => $reportedPending,
            'maximum_retry_attempt' => $maximumRetryAttempt,
            'quality' => $quality + [
                'domain_recognition_rate' => $totalFloat > 0
                    ? round(max(0.0, 1.0 - $quality['unknown_domain_bytes'] / $totalFloat), 6)
                    : null,
                'destination_ip_recognition_rate' => $totalFloat > 0
                    ? round(max(0.0, 1.0 - $quality['unknown_destination_ip_bytes'] / $totalFloat), 6)
                    : null,
                'collapsed_bytes_rate' => $totalFloat > 0
                    ? round($quality['collapsed_bytes'] / $totalFloat, 6)
                    : null,
            ],
        ];
    }

    public function health(Request $request): array
    {
        [$from, $to] = $this->timeRange($request);
        $rows = DB::table('xbs_report_batches')
            ->where('bucket_start', '>=', $from->format('Y-m-d H:i:s'))
            ->where('bucket_start', '<', $to->format('Y-m-d H:i:s'))
            ->orderBy('node_id')
            ->orderByDesc('received_at')
            ->get([
                'node_id', 'bucket_start', 'bucket_end', 'received_at', 'quality',
                'reported_pending_batches', 'reported_retry_attempt',
            ]);

        $latest = [];
        $batchCounts = [];
        foreach ($rows as $row) {
            $nodeId = (int) $row->node_id;
            $batchCounts[$nodeId] = ($batchCounts[$nodeId] ?? 0) + 1;
            $latest[$nodeId] ??= $row;
        }
        $labels = $this->simpleMap('v2_server', array_keys($latest), 'name');
        $items = [];
        foreach ($latest as $nodeId => $row) {
            $delay = null;
            try {
                $delay = max(0, (new DateTimeImmutable((string) $row->received_at))->getTimestamp()
                    - (new DateTimeImmutable((string) $row->bucket_end))->getTimestamp());
            } catch (Throwable) {
                // Preserve the row and expose an unknown delay.
            }
            $quality = json_decode((string) ($row->quality ?? '{}'), true);
            $items[] = [
                'node_id' => $nodeId,
                'node_name' => $labels[$nodeId]['value'] ?? ('已删除 #' . $nodeId),
                'last_bucket_start' => (string) $row->bucket_start,
                'last_received_at' => (string) $row->received_at,
                'report_delay_seconds' => $delay,
                'reported_pending_batches' => isset($row->reported_pending_batches)
                    ? (int) $row->reported_pending_batches
                    : null,
                'reported_retry_attempt' => isset($row->reported_retry_attempt)
                    ? (int) $row->reported_retry_attempt
                    : null,
                'batch_count' => $batchCounts[$nodeId],
                'quality' => is_array($quality) ? $quality : [],
            ];
        }
        usort($items, static fn (array $left, array $right): int => ($right['reported_pending_batches'] ?? -1)
            <=> ($left['reported_pending_batches'] ?? -1));

        return [
            'from' => $from->format('Y-m-d\TH:i:s\Z'),
            'to' => $to->format('Y-m-d\TH:i:s\Z'),
            'items' => $items,
        ];
    }

    private function baseQuery(Request $request, DateTimeImmutable $from, DateTimeImmutable $to, ?string $groupBy): Builder
    {
        $query = DB::table('xbs_traffic_hourly as traffic')
            ->where('traffic.bucket_start', '>=', $from->format('Y-m-d H:i:s'))
            ->where('traffic.bucket_start', '<', $to->format('Y-m-d H:i:s'));

        if (in_array($groupBy, ['source_country', 'source_asn'], true)
            || $request->query->has('source_country') || $request->query->has('source_asn')) {
            $query->leftJoin('xbs_ip_addresses as source_geo', 'source_geo.id', '=', 'traffic.source_ip_id');
        }
        if (in_array($groupBy, ['destination_country', 'destination_asn'], true)
            || $request->query->has('destination_country') || $request->query->has('destination_asn')) {
            $query->leftJoin('xbs_ip_addresses as destination_geo', 'destination_geo.id', '=', 'traffic.destination_ip_id');
        }

        foreach (['node_id', 'user_id'] as $field) {
            if ($request->query->has($field)) {
                $value = filter_var($request->query($field), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($value === false) {
                    throw new InvalidArgumentException("{$field} 必须是正整数");
                }
                $query->where('traffic.' . $field, $value);
            }
        }

        if ($request->query->has('domain')) {
            $domain = strtolower(rtrim(trim((string) $request->query('domain')), '.'));
            $query->join('xbs_domains as filter_domain', 'filter_domain.id', '=', 'traffic.domain_id')
                ->where(function (Builder $nested) use ($domain): void {
                    $nested->where('filter_domain.exact_domain', $domain)
                        ->orWhere('filter_domain.registrable_domain', $domain);
                });
        }
        $this->applyIpFilter($query, $request, 'source_ip', 'source_ip_id', 'source_ip_state');
        $this->applyIpFilter($query, $request, 'destination_ip', 'destination_ip_id', 'destination_ip_state');

        foreach ([
            'network' => 'network',
            'application_protocol' => 'application_protocol',
            'inbound_type' => 'inbound_type',
        ] as $parameter => $column) {
            if ($request->query->has($parameter)) {
                $value = strtolower(trim((string) $request->query($parameter)));
                if (!preg_match('/^(?:[a-z0-9][a-z0-9._-]*|__other__)$/', $value)) {
                    throw new InvalidArgumentException("{$parameter} 包含非法字符");
                }
                $query->where('traffic.' . $column, $value);
            }
        }

        if ($request->query->has('outbound')) {
            $value = strtolower(trim((string) $request->query('outbound')));
            $query->join('xbs_outbounds as filter_outbound', 'filter_outbound.id', '=', 'traffic.outbound_id')
                ->where('filter_outbound.tag', $value);
        }
        if ($request->query->has('destination_port')) {
            $port = filter_var($request->query('destination_port'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 65535]]);
            if ($port === false) {
                throw new InvalidArgumentException('destination_port 必须在 0-65535 之间');
            }
            $query->where('traffic.destination_port', $port);
        }
        foreach (['source_country', 'destination_country'] as $parameter) {
            if (!$request->query->has($parameter)) {
                continue;
            }
            $value = strtoupper(trim((string) $request->query($parameter)));
            if (!preg_match('/^(?:[A-Z]{2}|UNKNOWN|__OTHER__)$/', $value)) {
                throw new InvalidArgumentException("{$parameter} 必须是两位国家代码、unknown 或 __other__");
            }
            $side = str_starts_with($parameter, 'source_') ? 'source' : 'destination';
            $query->whereRaw($this->geoExpression($side, 'country') . ' = ?', [strtolower($value) === 'unknown' ? 'unknown' : ($value === '__OTHER__' ? '__other__' : $value)]);
        }
        foreach (['source_asn', 'destination_asn'] as $parameter) {
            if (!$request->query->has($parameter)) {
                continue;
            }
            $value = strtolower(trim((string) $request->query($parameter)));
            if ($value !== 'unknown' && $value !== '__other__' && (!ctype_digit($value) || (int) $value <= 0)) {
                throw new InvalidArgumentException("{$parameter} 必须是正整数、unknown 或 __other__");
            }
            $side = str_starts_with($parameter, 'source_') ? 'source' : 'destination';
            $query->whereRaw($this->geoExpression($side, 'asn') . ' = ?', [$value]);
        }
        $this->applySearch($query, $request, $groupBy);
        return $query;
    }

    private function applySearch(Builder $query, Request $request, ?string $groupBy): void
    {
        $search = trim((string) $request->query('search', ''));
        if ($search === '' || $groupBy === null) {
            return;
        }
        if (strlen($search) > 120) {
            throw new InvalidArgumentException('search 不能超过 120 字节');
        }
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], strtolower($search)) . '%';
        match ($groupBy) {
            'node' => $query->join('v2_server as search_node', 'search_node.id', '=', 'traffic.node_id')
                ->whereRaw('LOWER(search_node.name) LIKE ?', [$like]),
            'user' => $query->join('v2_user as search_user', 'search_user.id', '=', 'traffic.user_id')
                ->whereRaw('LOWER(search_user.email) LIKE ?', [$like]),
            'domain' => $query->join('xbs_domains as search_domain', 'search_domain.id', '=', 'traffic.domain_id')
                ->whereRaw('LOWER(search_domain.exact_domain) LIKE ?', [$like]),
            'source_ip' => $this->applyIpSearch($query, $search, 'source_ip_id', 'source_ip_state'),
            'destination_ip' => $this->applyIpSearch($query, $search, 'destination_ip_id', 'destination_ip_state'),
            'network', 'application_protocol', 'inbound_type' => $query->whereRaw(
                'LOWER(traffic.' . $groupBy . ') LIKE ?',
                [$like],
            ),
            'outbound' => $query->join('xbs_outbounds as search_outbound', 'search_outbound.id', '=', 'traffic.outbound_id')
                ->whereRaw('LOWER(search_outbound.tag) LIKE ?', [$like]),
            'destination_port' => ctype_digit($search)
                ? $query->where('traffic.destination_port', min(65535, (int) $search))
                : $query->whereRaw('1 = 0'),
            'source_country' => $query->whereRaw('LOWER(' . $this->geoExpression('source', 'country') . ') LIKE ?', [$like]),
            'destination_country' => $query->whereRaw('LOWER(' . $this->geoExpression('destination', 'country') . ') LIKE ?', [$like]),
            'source_asn' => $query->whereRaw('LOWER(' . $this->geoExpression('source', 'asn') . ') LIKE ?', [$like]),
            'destination_asn' => $query->whereRaw('LOWER(' . $this->geoExpression('destination', 'asn') . ') LIKE ?', [$like]),
            'time' => $query->where('traffic.bucket_start', 'like', $like),
            default => null,
        };
    }

    private function applyIpSearch(Builder $query, string $search, string $idColumn, string $stateColumn): void
    {
        $value = strtolower($search);
        if ($value === 'unknown') {
            $query->where('traffic.' . $stateColumn, 0);
            return;
        }
        if ($value === '__other__') {
            $query->where('traffic.' . $stateColumn, 2);
            return;
        }
        try {
            $normalized = IpAddress::normalize($value);
        } catch (Throwable) {
            $query->whereRaw('1 = 0');
            return;
        }
        $query->where('traffic.' . $stateColumn, 1)
            ->whereIn('traffic.' . $idColumn, DB::table('xbs_ip_addresses')->select('id')->where('address', $normalized['packed']));
    }

    private function applyIpFilter(Builder $query, Request $request, string $parameter, string $idColumn, string $stateColumn): void
    {
        if (!$request->query->has($parameter)) {
            return;
        }
        $value = strtolower(trim((string) $request->query($parameter)));
        if ($value === 'unknown') {
            $query->where('traffic.' . $stateColumn, 0);
            return;
        }
        if ($value === '__other__') {
            $query->where('traffic.' . $stateColumn, 2);
            return;
        }
        try {
            $normalized = IpAddress::normalize($value);
        } catch (Throwable) {
            throw new InvalidArgumentException("{$parameter} 不是有效 IP 地址");
        }
        $query->where('traffic.' . $stateColumn, 1)
            ->whereIn('traffic.' . $idColumn, DB::table('xbs_ip_addresses')->select('id')->where('address', $normalized['packed']));
    }

    private function groupColumns(string $groupBy): array
    {
        return match ($groupBy) {
            'time' => [
                [DB::raw($this->timeGroupExpression() . ' AS group_time')],
                [DB::raw($this->timeGroupExpression())],
            ],
            'node' => [['traffic.node_id AS group_id'], ['traffic.node_id']],
            'user' => [['traffic.user_id AS group_id'], ['traffic.user_id']],
            'domain' => [['traffic.domain_id AS group_id'], ['traffic.domain_id']],
            'source_ip' => [
                ['traffic.source_ip_id AS group_id', 'traffic.source_ip_state AS group_state'],
                ['traffic.source_ip_id', 'traffic.source_ip_state'],
            ],
            'destination_ip' => [
                ['traffic.destination_ip_id AS group_id', 'traffic.destination_ip_state AS group_state'],
                ['traffic.destination_ip_id', 'traffic.destination_ip_state'],
            ],
            'network' => [['traffic.network AS group_value'], ['traffic.network']],
            'application_protocol' => [['traffic.application_protocol AS group_value'], ['traffic.application_protocol']],
            'inbound_type' => [['traffic.inbound_type AS group_value'], ['traffic.inbound_type']],
            'outbound' => [['traffic.outbound_id AS group_id'], ['traffic.outbound_id']],
            'destination_port' => [['traffic.destination_port AS group_value'], ['traffic.destination_port']],
            'source_country' => [[DB::raw($this->geoExpression('source', 'country') . ' AS group_value')], [DB::raw($this->geoExpression('source', 'country'))]],
            'destination_country' => [[DB::raw($this->geoExpression('destination', 'country') . ' AS group_value')], [DB::raw($this->geoExpression('destination', 'country'))]],
            'source_asn' => [[DB::raw($this->geoExpression('source', 'asn') . ' AS group_value')], [DB::raw($this->geoExpression('source', 'asn'))]],
            'destination_asn' => [[DB::raw($this->geoExpression('destination', 'asn') . ' AS group_value')], [DB::raw($this->geoExpression('destination', 'asn'))]],
            default => throw new InvalidArgumentException('不支持的 group_by'),
        };
    }

    private function timeGroupExpression(): string
    {
        return match (DB::connection()->getDriverName()) {
            'pgsql' => "DATE_TRUNC('hour', traffic.bucket_start)",
            'sqlite' => "STRFTIME('%Y-%m-%d %H:00:00', traffic.bucket_start)",
            'sqlsrv' => 'DATEADD(hour, DATEDIFF(hour, 0, traffic.bucket_start), 0)',
            default => "DATE_FORMAT(traffic.bucket_start, '%Y-%m-%d %H:00:00')",
        };
    }

    private function geoExpression(string $side, string $field): string
    {
        $state = 'traffic.' . $side . '_ip_state';
        $alias = $side . '_geo';
        $known = $field === 'country'
            ? "COALESCE({$alias}.country_code, 'unknown')"
            : "COALESCE(CAST({$alias}.asn AS CHAR), 'unknown')";
        return "CASE WHEN {$state} = 2 THEN '__other__' WHEN {$state} = 1 THEN {$known} ELSE 'unknown' END";
    }

    private function decorateRows(string $groupBy, array $rows, string $grandTotal, int $offset): array
    {
        $ids = array_values(array_unique(array_filter(array_map(
            static fn (object $row): ?int => isset($row->group_id) ? (int) $row->group_id : null,
            $rows,
        ))));
        $labels = match ($groupBy) {
            'node' => $this->simpleMap('v2_server', $ids, 'name'),
            'user' => $this->simpleMap('v2_user', $ids, 'email'),
            'domain' => $this->domainMap($ids),
            'source_ip', 'destination_ip' => $this->ipMap($ids),
            'outbound' => $this->outboundMap($ids),
            default => [],
        };

        $rank = $offset;
        return array_map(function (object $row) use ($groupBy, $grandTotal, $labels, &$rank): array {
            $rank++;
            $value = match ($groupBy) {
                'time' => (string) $row->group_time,
                'node', 'user' => (int) $row->group_id,
                'domain', 'outbound' => $row->group_id === null ? 'unknown' : ($labels[(int) $row->group_id]['value'] ?? 'unknown'),
                'source_ip', 'destination_ip' => $this->ipGroupValue($row, $labels),
                default => $row->group_value ?? 'unknown',
            };
            $label = match ($groupBy) {
                'node', 'user' => $labels[(int) $row->group_id]['value'] ?? ('已删除 #' . (int) $row->group_id),
                default => (string) $value,
            };
            $total = (string) $row->total_bytes;
            return [
                'id' => $groupBy . ':' . $rank,
                'rank' => $rank,
                'value' => $value,
                'label' => $label,
                'upload_bytes' => (string) $row->upload_bytes,
                'download_bytes' => (string) $row->download_bytes,
                'total_bytes' => $total,
                'connection_count' => (int) $row->connection_count,
                'active_seconds' => (int) $row->active_seconds,
                'percentage' => (float) $grandTotal > 0 ? round((float) $total / (float) $grandTotal * 100, 4) : 0.0,
            ];
        }, $rows);
    }

    private function simpleMap(string $table, array $ids, string $valueColumn): array
    {
        if ($ids === []) {
            return [];
        }
        return DB::table($table)->whereIn('id', $ids)->get(['id', $valueColumn])->mapWithKeys(
            static fn (object $row): array => [(int) $row->id => ['value' => (string) $row->{$valueColumn}]],
        )->all();
    }

    private function domainMap(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        return DB::table('xbs_domains')->whereIn('id', $ids)->get(['id', 'exact_domain'])->mapWithKeys(
            static fn (object $row): array => [(int) $row->id => ['value' => (string) $row->exact_domain]],
        )->all();
    }

    private function ipMap(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        return DB::table('xbs_ip_addresses')->whereIn('id', $ids)->get(['id', 'address'])->mapWithKeys(
            static fn (object $row): array => [(int) $row->id => ['value' => IpAddress::display($row->address)]],
        )->all();
    }

    private function outboundMap(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        return DB::table('xbs_outbounds')->whereIn('id', $ids)->get(['id', 'tag', 'type'])->mapWithKeys(
            static fn (object $row): array => [(int) $row->id => ['value' => (string) $row->tag, 'type' => (string) $row->type]],
        )->all();
    }

    private function ipGroupValue(object $row, array $labels): string
    {
        return match ((int) $row->group_state) {
            1 => $labels[(int) $row->group_id]['value'] ?? 'unknown',
            2 => '__other__',
            default => 'unknown',
        };
    }

    private function timeRange(Request $request): array
    {
        $utc = new DateTimeZone('UTC');
        $now = new DateTimeImmutable('now', $utc);
        try {
            $from = $request->query->has('from') ? new DateTimeImmutable((string) $request->query('from')) : $now->modify('-24 hours');
            $to = $request->query->has('to') ? new DateTimeImmutable((string) $request->query('to')) : $now;
        } catch (Throwable) {
            throw new InvalidArgumentException('from/to 不是有效时间');
        }
        $from = $from->setTimezone($utc);
        $to = $to->setTimezone($utc);
        if ($to <= $from) {
            throw new InvalidArgumentException('to 必须晚于 from');
        }
        if ($to->getTimestamp() - $from->getTimestamp() > 90 * 86400) {
            throw new InvalidArgumentException('查询时间范围不能超过 90 天');
        }
        return [$from, $to];
    }

    private function decimalAdd(string $left, string $right): string
    {
        if (function_exists('bcadd')) {
            return bcadd($left, $right, 0);
        }
        $left = strrev($left);
        $right = strrev($right);
        $length = max(strlen($left), strlen($right));
        $carry = 0;
        $result = '';
        for ($index = 0; $index < $length; $index++) {
            $sum = (int) ($left[$index] ?? '0') + (int) ($right[$index] ?? '0') + $carry;
            $result .= (string) ($sum % 10);
            $carry = intdiv($sum, 10);
        }
        if ($carry > 0) {
            $result .= (string) $carry;
        }
        return strrev($result);
    }
}
