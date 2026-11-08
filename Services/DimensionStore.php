<?php

declare(strict_types=1);

namespace Plugin\XboardStatistic\Services;

use Illuminate\Support\Facades\DB;
use Plugin\XboardStatistic\Support\Tables;

/**
 * Maps domain, IP and outbound values to their dimension ids, creating rows as needed.
 *
 * Runs outside the fact transaction: every statement autocommits, so each SELECT
 * sees rows that concurrent reports have just created. Rows left behind by a
 * failed report are harmless and age out through last_seen_unix.
 */
final class DimensionStore
{
    /** last_seen_unix is refreshed at most once per hour per row to avoid a write every minute. */
    public const LAST_SEEN_GRANULARITY = 3600;
    private const CHUNK = 1000;

    /**
     * @param list<array<string, mixed>> $records normalized records
     * @return array{domains: array<string, int>, ips: array<string, int>, outbounds: array<string, int>}
     */
    public function resolve(array $records, int $seenUnix): array
    {
        $domains = [];
        $ips = [];
        $outbounds = [];
        foreach ($records as $record) {
            if ($record['exact_domain'] !== null) {
                $domains[$record['exact_domain']] = $record['registrable_domain'];
            }
            foreach (['source_ip', 'destination_ip'] as $field) {
                if (($record[$field]['kind'] ?? null) === 'known') {
                    $ips[$record[$field]['packed']] = true;
                }
            }
            if ($record['outbound_tag'] !== 'unknown' || $record['outbound_type'] !== 'unknown') {
                $outbounds[self::outboundKey($record['outbound_tag'], $record['outbound_type'])] = [
                    'tag' => $record['outbound_tag'],
                    'type' => $record['outbound_type'],
                ];
            }
        }

        return [
            'domains' => $this->resolveDomains($domains, $seenUnix),
            'ips' => $this->resolveIps(array_keys($ips), $seenUnix),
            'outbounds' => $this->resolveOutbounds($outbounds, $seenUnix),
        ];
    }

    public static function outboundKey(string $tag, string $type): string
    {
        return $tag . "\0" . $type;
    }

    /**
     * @param array<string, ?string> $domains exact domain => registrable domain
     * @return array<string, int>
     */
    private function resolveDomains(array $domains, int $seenUnix): array
    {
        ksort($domains, SORT_STRING);
        $lookup = fn (array $keys): array => $this->select(Tables::DOMAINS, 'exact_domain', $keys);
        $found = $lookup(array_keys($domains));

        $missing = array_diff_key($domains, $found);
        if ($missing !== []) {
            $rows = [];
            foreach ($missing as $exact => $registrable) {
                $rows[] = [
                    'exact_domain' => (string) $exact,
                    'registrable_domain' => $registrable,
                    'last_seen_unix' => $seenUnix,
                ];
            }
            $this->insertIgnore(Tables::DOMAINS, $rows);
            $found += $lookup(array_map('strval', array_keys($missing)));
        }

        return $this->touchAndMap(Tables::DOMAINS, $found, $seenUnix);
    }

    /**
     * @param list<string> $addresses packed 16-byte addresses
     * @return array<string, int> packed address => id
     */
    private function resolveIps(array $addresses, int $seenUnix): array
    {
        sort($addresses, SORT_STRING);
        $lookup = fn (array $keys): array => $this->select(Tables::IP_ADDRESSES, 'address', $keys);
        $found = $lookup($addresses);

        $missing = array_values(array_diff($addresses, array_map('strval', array_keys($found))));
        if ($missing !== []) {
            $this->insertIgnore(Tables::IP_ADDRESSES, array_map(
                static fn (string $address): array => ['address' => $address, 'last_seen_unix' => $seenUnix],
                $missing,
            ));
            $found += $lookup($missing);
        }

        return $this->touchAndMap(Tables::IP_ADDRESSES, $found, $seenUnix);
    }

    /**
     * @param array<string, array{tag: string, type: string}> $outbounds
     * @return array<string, int>
     */
    private function resolveOutbounds(array $outbounds, int $seenUnix): array
    {
        if ($outbounds === []) {
            return [];
        }
        ksort($outbounds, SORT_STRING);
        $lookup = function (array $wanted): array {
            $found = [];
            $tags = array_values(array_unique(array_column($wanted, 'tag')));
            foreach (array_chunk($tags, self::CHUNK) as $chunk) {
                $rows = DB::table(Tables::OUTBOUNDS)->whereIn('tag', $chunk)->get(['id', 'tag', 'type', 'last_seen_unix']);
                foreach ($rows as $row) {
                    $key = self::outboundKey((string) $row->tag, (string) $row->type);
                    if (isset($wanted[$key])) {
                        $found[$key] = $row;
                    }
                }
            }
            return $found;
        };
        $found = $lookup($outbounds);

        $missing = array_diff_key($outbounds, $found);
        if ($missing !== []) {
            $this->insertIgnore(Tables::OUTBOUNDS, array_map(
                static fn (array $outbound): array => $outbound + ['last_seen_unix' => $seenUnix],
                array_values($missing),
            ));
            $found += $lookup($missing);
        }

        return $this->touchAndMap(Tables::OUTBOUNDS, $found, $seenUnix);
    }

    /**
     * @param list<string> $keys
     * @return array<string, object> key => row with id and last_seen_unix
     */
    private function select(string $table, string $column, array $keys): array
    {
        $found = [];
        foreach (array_chunk($keys, self::CHUNK) as $chunk) {
            foreach (DB::table($table)->whereIn($column, $chunk)->get(['id', $column, 'last_seen_unix']) as $row) {
                $found[(string) $row->{$column}] = $row;
            }
        }
        return $found;
    }

    /** @param list<array<string, mixed>> $rows */
    private function insertIgnore(string $table, array $rows): void
    {
        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            DB::table($table)->insertOrIgnore($chunk);
        }
    }

    /**
     * @param array<string, object> $found
     * @return array<string, int>
     */
    private function touchAndMap(string $table, array $found, int $seenUnix): array
    {
        $map = [];
        $stale = [];
        foreach ($found as $key => $row) {
            $map[$key] = (int) $row->id;
            if ((int) $row->last_seen_unix < $seenUnix - self::LAST_SEEN_GRANULARITY) {
                $stale[] = (int) $row->id;
            }
        }
        sort($stale);
        foreach (array_chunk($stale, self::CHUNK) as $chunk) {
            DB::table($table)
                ->whereIn('id', $chunk)
                ->update(['last_seen_unix' => DB::raw('GREATEST(last_seen_unix, ' . $seenUnix . ')')]);
        }
        return $map;
    }
}
