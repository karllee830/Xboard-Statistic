<?php

declare(strict_types=1);

namespace Plugin\XboardStatistic\Services\Query;

use Illuminate\Support\Facades\DB;
use Plugin\XboardStatistic\Support\IpAddress;
use Plugin\XboardStatistic\Support\Tables;
use Plugin\XboardStatistic\Support\UtcTime;

/**
 * Turns grouped SQL rows into ranking items with human-readable labels.
 */
final class TrafficRowDecorator
{
    /**
     * @param list<object> $rows
     * @param array<string, mixed>|null $unknownDomainDestination
     * @return list<array<string, mixed>>
     */
    public function decorate(string $groupBy, array $rows, string $grandTotal, int $offset, ?array $unknownDomainDestination = null): array
    {
        $ids = array_values(array_unique(array_filter(array_map(
            static fn (object $row): int => (int) ($row->group_id ?? 0),
            $rows,
        ))));
        $labels = match ($groupBy) {
            'node' => $this->nameMap('v2_server', $ids, 'name'),
            'user' => $this->nameMap('v2_user', $ids, 'email'),
            'domain' => $this->nameMap(Tables::DOMAINS, $ids, 'exact_domain'),
            'outbound' => $this->nameMap(Tables::OUTBOUNDS, $ids, 'tag'),
            'source_ip', 'destination_ip' => $this->ipMap($ids),
            default => [],
        };
        $asnOrganizations = in_array($groupBy, ['source_asn', 'destination_asn'], true)
            ? $this->asnOrganizations($rows)
            : [];
        $grand = (float) $grandTotal;

        $items = [];
        foreach ($rows as $index => $row) {
            $rank = $offset + $index + 1;
            $groupId = (int) ($row->group_id ?? 0);
            $value = match ($groupBy) {
                'time' => UtcTime::iso((int) $row->group_time_unix),
                'node', 'user' => $groupId,
                'domain', 'outbound' => $groupId === 0 ? 'unknown' : ($labels[$groupId]['value'] ?? 'unknown'),
                'source_ip', 'destination_ip' => match ((int) $row->group_state) {
                    1 => $labels[$groupId]['value'] ?? 'unknown',
                    2 => '__other__',
                    default => 'unknown',
                },
                default => $row->group_value === null ? 'unknown' : (string) $row->group_value,
            };
            $label = in_array($groupBy, ['node', 'user'], true)
                ? ($labels[$groupId]['value'] ?? '已删除 #' . $groupId)
                : (string) $value;

            $total = (string) $row->total_bytes;
            $item = [
                'id' => $groupBy . ':' . $rank,
                'rank' => $rank,
                'value' => $value,
                'label' => $label,
                'upload_bytes' => (string) $row->upload_bytes,
                'download_bytes' => (string) $row->download_bytes,
                'total_bytes' => $total,
                'connection_count' => (int) $row->connection_count,
                'active_seconds' => (int) $row->active_seconds,
                'percentage' => $grand > 0 ? round((float) $total / $grand * 100, 4) : 0.0,
            ];
            if (($groupBy === 'source_ip' || $groupBy === 'destination_ip') && (int) $row->group_state === 1) {
                $item['country_code'] = $labels[$groupId]['country_code'] ?? null;
                $item['asn'] = $labels[$groupId]['asn'] ?? null;
                $item['asn_organization'] = $labels[$groupId]['asn_organization'] ?? null;
            }
            if (($groupBy === 'source_asn' || $groupBy === 'destination_asn') && ctype_digit((string) $value)) {
                $item['asn_organization'] = $asnOrganizations[(int) $value] ?? null;
            }
            if ($groupBy === 'domain' && $value === 'unknown') {
                $item['unknown_domain'] = true;
                $item['label'] = $unknownDomainDestination['ip'] ?? '未识别域名流量';
                $item['representative_destination'] = $unknownDomainDestination;
            }
            $items[] = $item;
        }
        return $items;
    }

    /**
     * @param list<int> $ids
     * @return array<int, array{value: string, country_code: ?string, asn: ?int, asn_organization: ?string}>
     */
    public function ipMap(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $map = [];
        foreach (DB::table(Tables::IP_ADDRESSES)->whereIn('id', $ids)->get(['id', 'address', 'country_code', 'asn', 'asn_organization']) as $row) {
            $map[(int) $row->id] = [
                'value' => IpAddress::display((string) $row->address),
                'country_code' => $row->country_code === null ? null : (string) $row->country_code,
                'asn' => $row->asn === null ? null : (int) $row->asn,
                'asn_organization' => $row->asn_organization === null || $row->asn_organization === ''
                    ? null
                    : (string) $row->asn_organization,
            ];
        }
        return $map;
    }

    /**
     * @param list<int> $ids
     * @return array<int, array{value: string}>
     */
    private function nameMap(string $table, array $ids, string $column): array
    {
        if ($ids === []) {
            return [];
        }
        $map = [];
        foreach (DB::table($table)->whereIn('id', $ids)->get(['id', $column]) as $row) {
            $map[(int) $row->id] = ['value' => (string) $row->{$column}];
        }
        return $map;
    }

    /**
     * @param list<object> $rows
     * @return array<int, string>
     */
    private function asnOrganizations(array $rows): array
    {
        $asns = [];
        foreach ($rows as $row) {
            if (ctype_digit((string) ($row->group_value ?? ''))) {
                $asns[(int) $row->group_value] = true;
            }
        }
        if ($asns === []) {
            return [];
        }

        $organizations = [];
        $rows = DB::table(Tables::IP_ADDRESSES)
            ->whereIn('asn', array_keys($asns))
            ->where('asn_organization', '<>', '')
            ->groupBy('asn')
            ->selectRaw('asn, MIN(asn_organization) AS asn_organization')
            ->get();
        foreach ($rows as $row) {
            $organizations[(int) $row->asn] = (string) $row->asn_organization;
        }
        return $organizations;
    }
}
