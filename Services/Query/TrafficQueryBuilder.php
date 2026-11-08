<?php

declare(strict_types=1);

namespace Plugin\XboardStatistic\Services\Query;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Plugin\XboardStatistic\Support\IpAddress;
use Plugin\XboardStatistic\Support\Tables;
use Throwable;

/**
 * Builds filtered, grouped SQL over whichever fact table a TrafficSource selected.
 */
final class TrafficQueryBuilder
{
    public const GROUPS = [
        'time', 'node', 'user', 'domain', 'source_ip', 'destination_ip',
        'network', 'application_protocol', 'inbound_type', 'outbound', 'destination_port',
        'source_country', 'destination_country', 'source_asn', 'destination_asn',
    ];

    public function base(TrafficFilters $filters, TrafficSource $source, ?string $groupBy = null, string $search = ''): Builder
    {
        $time = 'traffic.' . $source->timeColumn;
        $query = DB::table($source->table . ' as traffic')
            ->where($time, '>=', $source->from)
            ->where($time, '<', $source->to);

        if ($filters->nodeId !== null) {
            $query->where('traffic.node_id', $filters->nodeId);
        }
        if ($filters->userId !== null) {
            $query->where('traffic.user_id', $filters->userId);
        }
        if ($filters->domain !== null) {
            $this->applyDomainFilter($query, $source, $filters->domain);
        }

        foreach (['source', 'destination'] as $side) {
            if ($filters->needsGeo($side) || in_array($groupBy, [$side . '_country', $side . '_asn'], true)) {
                $this->requireMinute($source);
                $query->leftJoin(Tables::IP_ADDRESSES . " as {$side}_geo", "{$side}_geo.id", '=', "traffic.{$side}_ip_id");
            }
        }
        if ($filters->hasDetailFilters()) {
            $this->requireMinute($source);
            $this->applyDetailFilters($query, $filters->detail);
        }

        if ($groupBy !== null && $search !== '') {
            $this->applySearch($query, $source, $groupBy, $search);
        }

        return $query;
    }

    /**
     * @return array{0: list<string|Expression>, 1: list<string|Expression>} select columns and group-by columns
     */
    public function groupColumns(string $groupBy, int $timeBucketSeconds = 3600, string $timeColumn = 'bucket_start_unix'): array
    {
        if ($groupBy === 'time') {
            $expression = "(traffic.{$timeColumn} DIV {$timeBucketSeconds}) * {$timeBucketSeconds}";
            return [[DB::raw("{$expression} AS group_time_unix")], [DB::raw($expression)]];
        }

        $simple = [
            'node' => 'node_id',
            'user' => 'user_id',
            'domain' => 'domain_id',
            'outbound' => 'outbound_id',
        ];
        if (isset($simple[$groupBy])) {
            $column = 'traffic.' . $simple[$groupBy];
            return [["{$column} AS group_id"], [$column]];
        }
        if ($groupBy === 'source_ip' || $groupBy === 'destination_ip') {
            return [
                ["traffic.{$groupBy}_id AS group_id", "traffic.{$groupBy}_state AS group_state"],
                ["traffic.{$groupBy}_id", "traffic.{$groupBy}_state"],
            ];
        }
        if (in_array($groupBy, ['network', 'application_protocol', 'inbound_type', 'destination_port'], true)) {
            return [["traffic.{$groupBy} AS group_value"], ["traffic.{$groupBy}"]];
        }
        if (preg_match('/^(source|destination)_(country|asn)$/', $groupBy, $match)) {
            $expression = self::geoExpression($match[1], $match[2]);
            return [[DB::raw("{$expression} AS group_value")], [DB::raw($expression)]];
        }

        throw new InvalidArgumentException('不支持的 group_by');
    }

    public static function geoExpression(string $side, string $field): string
    {
        $state = "traffic.{$side}_ip_state";
        $known = $field === 'country'
            ? "COALESCE({$side}_geo.country_code, 'unknown')"
            : "COALESCE(CAST({$side}_geo.asn AS CHAR), 'unknown')";
        return "(CASE WHEN {$state} = 2 THEN '__other__' WHEN {$state} = 1 THEN {$known} ELSE 'unknown' END)";
    }

    private function applyDomainFilter(Builder $query, TrafficSource $source, string $domain): void
    {
        if ($domain === 'unknown') {
            $source->isMinute()
                ? $query->whereNull('traffic.domain_id')
                : $query->where('traffic.domain_id', Tables::UNKNOWN_DOMAIN_ID);
            return;
        }
        $query->whereIn('traffic.domain_id', DB::table(Tables::DOMAINS)
            ->select('id')
            ->where('exact_domain', $domain)
            ->orWhere('registrable_domain', $domain));
    }

    /** @param array<string, mixed> $detail */
    private function applyDetailFilters(Builder $query, array $detail): void
    {
        foreach (['source_ip', 'destination_ip'] as $field) {
            if (isset($detail[$field])) {
                $this->applyIpCondition($query, $field, $detail[$field]);
            }
        }
        foreach (['network', 'application_protocol', 'inbound_type', 'destination_port'] as $field) {
            if (isset($detail[$field])) {
                $query->where('traffic.' . $field, $detail[$field]);
            }
        }
        if (isset($detail['outbound'])) {
            $query->whereIn('traffic.outbound_id', DB::table(Tables::OUTBOUNDS)->select('id')->where('tag', $detail['outbound']));
        }
        foreach (['source', 'destination'] as $side) {
            foreach (['country', 'asn'] as $field) {
                $key = "{$side}_{$field}";
                if (isset($detail[$key])) {
                    $query->whereRaw(self::geoExpression($side, $field) . ' = ?', [$detail[$key]]);
                }
            }
        }
    }

    /** @param array{state: int}|array{packed: string} $condition */
    private function applyIpCondition(Builder $query, string $field, array $condition): void
    {
        if (isset($condition['state'])) {
            $query->where("traffic.{$field}_state", $condition['state']);
            return;
        }
        $query->where("traffic.{$field}_state", 1)
            ->whereIn("traffic.{$field}_id", DB::table(Tables::IP_ADDRESSES)->select('id')->where('address', $condition['packed']));
    }

    private function applySearch(Builder $query, TrafficSource $source, string $groupBy, string $search): void
    {
        if (strlen($search) > 120) {
            throw new InvalidArgumentException('search 不能超过 120 字节');
        }
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], strtolower($search)) . '%';

        switch ($groupBy) {
            case 'node':
                $query->whereIn('traffic.node_id', DB::table('v2_server')->select('id')->where('name', 'like', $like));
                return;
            case 'user':
                $query->whereIn('traffic.user_id', DB::table('v2_user')->select('id')->where('email', 'like', $like));
                return;
            case 'domain':
                $query->whereIn('traffic.domain_id', DB::table(Tables::DOMAINS)->select('id')->where('exact_domain', 'like', $like));
                return;
            case 'outbound':
                $query->whereIn('traffic.outbound_id', DB::table(Tables::OUTBOUNDS)->select('id')->where('tag', 'like', $like));
                return;
            case 'source_ip':
            case 'destination_ip':
                $this->applyIpSearch($query, $groupBy, $search);
                return;
            case 'network':
            case 'application_protocol':
            case 'inbound_type':
                $query->where('traffic.' . $groupBy, 'like', $like);
                return;
            case 'destination_port':
                ctype_digit($search) && (int) $search <= 65535
                    ? $query->where('traffic.destination_port', (int) $search)
                    : $query->whereRaw('1 = 0');
                return;
            case 'source_country':
            case 'destination_country':
            case 'source_asn':
            case 'destination_asn':
                [$side, $field] = explode('_', $groupBy, 2);
                $query->whereRaw('LOWER(' . self::geoExpression($side, $field) . ') LIKE ?', [$like]);
                return;
            default:
                // Searching the time axis has no meaning; the trend ignores the search box.
                return;
        }
    }

    private function applyIpSearch(Builder $query, string $field, string $search): void
    {
        $value = strtolower($search);
        if ($value === 'unknown' || $value === '__other__') {
            $query->where("traffic.{$field}_state", $value === 'unknown' ? 0 : 2);
            return;
        }
        try {
            $normalized = IpAddress::normalize($value);
        } catch (Throwable) {
            $normalized = null;
        }
        if ($normalized === null || $normalized['packed'] === null) {
            $query->whereRaw('1 = 0');
            return;
        }
        $this->applyIpCondition($query, $field, ['packed' => $normalized['packed']]);
    }

    private function requireMinute(TrafficSource $source): void
    {
        if (!$source->isMinute()) {
            throw new InvalidArgumentException('该查询需要分钟明细表');
        }
    }
}
