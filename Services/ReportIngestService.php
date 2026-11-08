<?php

declare(strict_types=1);

namespace Plugin\XboardStatistic\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Plugin\XboardStatistic\Exceptions\IngestException;
use Plugin\XboardStatistic\Support\Tables;
use Plugin\XboardStatistic\Support\UtcTime;

final class ReportIngestService
{
    public const METRICS = ['upload_bytes', 'download_bytes', 'connection_count', 'active_seconds'];
    private const INSERT_CHUNK = 1000;
    private const UPSERT_CHUNK = 500;

    public function __construct(
        private readonly BatchValidator $validator,
        private readonly DimensionStore $dimensions,
    ) {
    }

    /**
     * @return array{result: 'accepted'|'duplicate', batch_id: string, bucket_start: string, record_count: int, stored_dimensions?: int}
     */
    public function ingest(
        int $nodeId,
        string $checksum,
        array $payload,
        int $maxRecords,
        int $maxAgeDays,
        ?int $reportedPendingBatches = null,
        ?int $reportedRetryAttempt = null,
    ): array {
        $batch = $this->validator->validate($payload, $maxRecords, $maxAgeDays);
        $health = ['pending' => $reportedPendingBatches, 'retry' => $reportedRetryAttempt];

        if ($existing = $this->findBatch($nodeId, $batch['batch_id'])) {
            return $this->duplicateOrConflict($existing, $checksum, $health);
        }
        $this->assertBucketFree($nodeId, $batch['bucket_start_unix']);

        $dimensionIds = $this->dimensions->resolve($batch['records'], $batch['bucket_start_unix']);
        $facts = $this->buildFacts($nodeId, $batch, $dimensionIds);

        try {
            DB::transaction(function () use ($nodeId, $checksum, $batch, $facts, $health): void {
                $now = time();
                DB::table(Tables::BATCHES)->insert([
                    'bucket_start_unix' => $batch['bucket_start_unix'],
                    'node_id' => $nodeId,
                    'batch_id' => $batch['batch_id'],
                    'checksum' => $checksum,
                    'record_count' => count($batch['records']),
                    'stored_dimensions' => count($facts),
                    'node_version' => $batch['node_version'],
                    'collector_version' => $batch['collector_version'],
                    'reported_pending_batches' => $health['pending'],
                    'reported_retry_attempt' => $health['retry'],
                    'received_at_unix' => $now,
                    'updated_at_unix' => $now,
                ] + $batch['quality']);

                foreach (array_chunk($facts, self::INSERT_CHUNK) as $chunk) {
                    DB::table(Tables::MINUTE)->insert($chunk);
                }

                $hour = UtcTime::floorHour($batch['bucket_start_unix']);
                $this->increment(Tables::HOUR_NODE_USER, ['hour_unix', 'node_id', 'user_id'], $this->rollup(
                    $facts,
                    static fn (array $fact): array => [$hour, $nodeId, $fact['user_id']],
                ));
                $this->increment(Tables::HOUR_NODE_USER_DOMAIN, ['hour_unix', 'node_id', 'user_id', 'domain_id'], $this->rollup(
                    $facts,
                    static fn (array $fact): array => [$hour, $nodeId, $fact['user_id'], $fact['domain_id'] ?? Tables::UNKNOWN_DOMAIN_ID],
                ));
            }, 3);
        } catch (QueryException $exception) {
            // A concurrent retry may have won the race for the batch or bucket key.
            if ($existing = $this->findBatch($nodeId, $batch['batch_id'])) {
                return $this->duplicateOrConflict($existing, $checksum, $health);
            }
            $this->assertBucketFree($nodeId, $batch['bucket_start_unix']);
            throw $exception;
        }

        return [
            'result' => 'accepted',
            'batch_id' => $batch['batch_id'],
            'bucket_start' => UtcTime::iso($batch['bucket_start_unix']),
            'record_count' => count($batch['records']),
            'stored_dimensions' => count($facts),
        ];
    }

    /**
     * Collapses records that share every dimension into one fact row.
     *
     * @return list<array<string, mixed>>
     */
    private function buildFacts(int $nodeId, array $batch, array $dimensionIds): array
    {
        $facts = [];
        foreach ($batch['records'] as $record) {
            $domainId = null;
            if ($record['exact_domain'] !== null) {
                $domainId = $dimensionIds['domains'][$record['exact_domain']]
                    ?? throw new IngestException('dimension_resolution_failed', '域名维度写入失败', 500);
            }
            [$sourceIpId, $sourceIpState] = $this->ipReference($record['source_ip'], $dimensionIds['ips'], '来源 IP');
            [$destinationIpId, $destinationIpState] = $this->ipReference($record['destination_ip'], $dimensionIds['ips'], '目的 IP');
            $outboundId = $dimensionIds['outbounds'][DimensionStore::outboundKey($record['outbound_tag'], $record['outbound_type'])] ?? null;

            $dimensions = [
                $record['user_id'],
                $sourceIpId, $sourceIpState,
                $destinationIpId, $destinationIpState,
                $domainId,
                $record['network'],
                $record['application_protocol'],
                $record['inbound_type'],
                $outboundId,
                $record['destination_port'],
            ];
            $hash = substr(hash('sha256', json_encode($dimensions, JSON_THROW_ON_ERROR), true), 0, 16);

            $facts[$hash] ??= [
                'bucket_start_unix' => $batch['bucket_start_unix'],
                'node_id' => $nodeId,
                'dimension_hash' => $hash,
                'user_id' => $record['user_id'],
                'source_ip_id' => $sourceIpId,
                'source_ip_state' => $sourceIpState,
                'destination_ip_id' => $destinationIpId,
                'destination_ip_state' => $destinationIpState,
                'domain_id' => $domainId,
                'network' => $record['network'],
                'application_protocol' => $record['application_protocol'],
                'inbound_type' => $record['inbound_type'],
                'outbound_id' => $outboundId,
                'destination_port' => $record['destination_port'],
                'upload_bytes' => 0,
                'download_bytes' => 0,
                'connection_count' => 0,
                'active_seconds' => 0,
            ];
            foreach (self::METRICS as $metric) {
                $facts[$hash][$metric] = $this->checkedAdd($facts[$hash][$metric], $record[$metric], $metric);
            }
        }

        return array_values($facts);
    }

    /**
     * @param array{packed: ?string, kind: string}|null $ip
     * @param array<string, int> $ipIds
     * @return array{0: ?int, 1: int} dimension id and state (0 unknown, 1 known, 2 collapsed)
     */
    private function ipReference(?array $ip, array $ipIds, string $label): array
    {
        if ($ip === null) {
            return [null, 0];
        }
        if ($ip['kind'] !== 'known') {
            return [null, 2];
        }
        $id = $ipIds[$ip['packed']] ?? throw new IngestException('dimension_resolution_failed', "{$label} 维度写入失败", 500);
        return [$id, 1];
    }

    /**
     * @param list<array<string, mixed>> $facts
     * @param callable(array<string, mixed>): list<int> $key
     * @return array<string, array{key: list<int>, metrics: array<string, int>}>
     */
    private function rollup(array $facts, callable $key): array
    {
        $rows = [];
        foreach ($facts as $fact) {
            $values = $key($fact);
            $rowKey = implode(':', $values);
            $rows[$rowKey] ??= ['key' => $values, 'metrics' => array_fill_keys(self::METRICS, 0)];
            foreach (self::METRICS as $metric) {
                $rows[$rowKey]['metrics'][$metric] = $this->checkedAdd($rows[$rowKey]['metrics'][$metric], $fact[$metric], $metric);
            }
        }
        // A stable key order keeps concurrent upserts from deadlocking on each other.
        ksort($rows, SORT_NATURAL);
        return $rows;
    }

    /**
     * Adds metrics onto existing rollup rows, inserting the rows that do not exist yet.
     *
     * @param list<string> $keyColumns
     * @param array<string, array{key: list<int>, metrics: array<string, int>}> $rows
     */
    private function increment(string $table, array $keyColumns, array $rows): void
    {
        $columns = [...$keyColumns, ...self::METRICS];
        $tuple = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
        $updates = implode(', ', array_map(
            static fn (string $metric): string => "{$metric} = {$table}.{$metric} + new.{$metric}",
            self::METRICS,
        ));

        foreach (array_chunk($rows, self::UPSERT_CHUNK) as $chunk) {
            $bindings = [];
            foreach ($chunk as $row) {
                array_push($bindings, ...$row['key'], ...array_values($row['metrics']));
            }
            DB::statement(
                sprintf(
                    'INSERT INTO %s (%s) VALUES %s AS new ON DUPLICATE KEY UPDATE %s',
                    $table,
                    implode(', ', $columns),
                    implode(', ', array_fill(0, count($chunk), $tuple)),
                    $updates,
                ),
                $bindings,
            );
        }
    }

    private function checkedAdd(int $left, int $right, string $metric): int
    {
        if ($right > PHP_INT_MAX - $left) {
            throw new IngestException('metric_overflow', "{$metric} 聚合后溢出", 422);
        }
        return $left + $right;
    }

    private function findBatch(int $nodeId, string $batchId): ?object
    {
        return DB::table(Tables::BATCHES)
            ->where('node_id', $nodeId)
            ->where('batch_id', $batchId)
            ->first();
    }

    private function assertBucketFree(int $nodeId, int $bucketStartUnix): void
    {
        $existing = DB::table(Tables::BATCHES)
            ->where('bucket_start_unix', $bucketStartUnix)
            ->where('node_id', $nodeId)
            ->first(['batch_id']);
        if ($existing !== null) {
            throw new IngestException('bucket_conflict', '该节点的时间桶已使用其他批次上报', 409, [
                'existing_batch_id' => $existing->batch_id,
            ]);
        }
    }

    /** @param array{pending: ?int, retry: ?int} $health */
    private function duplicateOrConflict(object $existing, string $checksum, array $health): array
    {
        if (!hash_equals((string) $existing->checksum, $checksum)) {
            throw new IngestException('batch_checksum_conflict', '相同 batch_id 的请求体校验和不同', 409);
        }

        $updates = [];
        if ($health['pending'] !== null) {
            $updates['reported_pending_batches'] = $health['pending'];
        }
        if ($health['retry'] !== null) {
            $updates['reported_retry_attempt'] = max((int) ($existing->reported_retry_attempt ?? 0), $health['retry']);
        }
        if ($updates !== []) {
            DB::table(Tables::BATCHES)
                ->where('bucket_start_unix', $existing->bucket_start_unix)
                ->where('node_id', $existing->node_id)
                ->update($updates + ['updated_at_unix' => time()]);
        }

        return [
            'result' => 'duplicate',
            'batch_id' => (string) $existing->batch_id,
            'bucket_start' => UtcTime::iso((int) $existing->bucket_start_unix),
            'record_count' => (int) $existing->record_count,
        ];
    }
}
