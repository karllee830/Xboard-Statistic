<?php

declare(strict_types=1);

namespace Plugin\XboardStatistic\Services;

use Illuminate\Support\Facades\DB;
use Plugin\XboardStatistic\Support\GeoIpRecord;
use Plugin\XboardStatistic\Support\IpAddress;
use Plugin\XboardStatistic\Support\Tables;
use Throwable;

/**
 * Fills country and ASN columns from local MMDB lookups, re-checking rows when a database version changes.
 */
final class IpEnrichmentService
{
    public const BATCH_SIZE = 1000;

    /**
     * @param (callable(string): mixed)|null $countryLookup returns the raw MMDB record for an IP
     * @param (callable(string): mixed)|null $asnLookup returns the raw MMDB record for an IP
     * @return int number of rows checked
     */
    public function enrich(
        ?callable $countryLookup,
        ?string $countryVersion,
        ?callable $asnLookup,
        ?string $asnVersion,
        int $limit,
        int $timeBudgetSeconds,
    ): int {
        $geoVersion = self::geoVersion($countryLookup === null ? null : $countryVersion, $asnLookup === null ? null : $asnVersion);
        $deadline = microtime(true) + $timeBudgetSeconds;
        $processed = 0;
        $lastId = 0;

        while ($processed < $limit && microtime(true) < $deadline) {
            $rows = DB::table(Tables::IP_ADDRESSES)
                ->where('id', '>', $lastId)
                ->where(fn ($query) => $query->whereNull('geo_version')->orWhere('geo_version', '<>', $geoVersion))
                ->orderBy('id')
                ->limit(min(self::BATCH_SIZE, $limit - $processed))
                ->get(['id', 'address']);
            if ($rows->isEmpty()) {
                break;
            }

            $changes = [];
            foreach ($rows as $row) {
                $ip = IpAddress::display((string) $row->address);
                $change = ['geo_version' => $geoVersion];
                if ($countryLookup !== null) {
                    $change['country_code'] = GeoIpRecord::countryCode($this->lookup($countryLookup, $ip));
                }
                if ($asnLookup !== null) {
                    $asn = GeoIpRecord::asn($this->lookup($asnLookup, $ip));
                    $change['asn'] = $asn['number'] ?? null;
                    $change['asn_organization'] = $asn['organization'] ?? null;
                }
                $changes[(int) $row->id] = $change;
                $lastId = (int) $row->id;
            }
            $this->apply($changes);
            $processed += count($changes);
        }

        return $processed;
    }

    public static function geoVersion(?string $countryVersion, ?string $asnVersion): string
    {
        return substr(($countryVersion ?? '-') . '|' . ($asnVersion ?? '-'), 0, 80);
    }

    private function lookup(callable $lookup, string $ip): mixed
    {
        try {
            return $lookup($ip);
        } catch (Throwable) {
            // Private, reserved and absent ranges deliberately remain unknown.
            return null;
        }
    }

    /**
     * Writes one UPDATE per batch using CASE expressions keyed by id.
     *
     * @param array<int, array<string, mixed>> $changes
     */
    private function apply(array $changes): void
    {
        $ids = array_keys($changes);
        $columns = array_keys(reset($changes));
        $sets = [];
        $bindings = [];
        foreach ($columns as $column) {
            $cases = [];
            foreach ($changes as $id => $change) {
                $cases[] = 'WHEN ? THEN ?';
                array_push($bindings, $id, $change[$column]);
            }
            $sets[] = "{$column} = CASE id " . implode(' ', $cases) . " ELSE {$column} END";
        }
        array_push($bindings, ...$ids);

        DB::update(
            sprintf(
                'UPDATE %s SET %s WHERE id IN (%s)',
                Tables::IP_ADDRESSES,
                implode(', ', $sets),
                implode(', ', array_fill(0, count($ids), '?')),
            ),
            $bindings,
        );
    }
}
