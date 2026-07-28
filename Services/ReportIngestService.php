<?php

declare(strict_types=1);

namespace Plugin\XboardStatistic\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Plugin\XboardStatistic\Exceptions\IngestException;
use Throwable;

final class ReportIngestService
{
    public function __construct(private readonly BatchValidator $validator)
    {
    }

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

        if ($existing = $this->findBatch($nodeId, $batch['batch_id'])) {
            return $this->duplicateOrConflict(
                $existing,
                $checksum,
                $reportedPendingBatches,
                $reportedRetryAttempt,
            );
        }
        if ($existingBucket = $this->findBucket($nodeId, $batch['bucket_start']->format('Y-m-d H:i:s'))) {
            throw new IngestException('bucket_conflict', '该节点的时间桶已使用其他批次上报', 409, [
                'existing_batch_id' => $existingBucket->batch_id,
            ]);
        }

        try {
            return DB::transaction(function () use ($nodeId, $checksum, $batch, $reportedPendingBatches, $reportedRetryAttempt): array {
                $now = now();
                $batchRowId = DB::table('xbs_report_batches')->insertGetId([
                    'node_id' => $nodeId,
                    'batch_id' => $batch['batch_id'],
                    'schema_version' => $batch['schema_version'],
                    'bucket_start' => $batch['bucket_start']->format('Y-m-d H:i:s'),
                    'bucket_end' => $batch['bucket_end']->format('Y-m-d H:i:s'),
                    'checksum' => $checksum,
                    'record_count' => count($batch['records']),
                    'status' => 'accepted',
                    'node_version' => $batch['node_version'],
                    'collector_version' => $batch['collector_version'],
                    'quality' => json_encode($batch['quality'], JSON_THROW_ON_ERROR),
                    'reported_pending_batches' => $reportedPendingBatches,
                    'reported_retry_attempt' => $reportedRetryAttempt,
                    'received_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $dimensions = $this->resolveDimensions($batch['records'], $now);
                $facts = $this->buildFacts(
                    $batchRowId,
                    $nodeId,
                    $batch['bucket_start']->format('Y-m-d H:i:s'),
                    $batch['records'],
                    $dimensions,
                    $now,
                );

                foreach (array_chunk($facts, 1000) as $chunk) {
                    DB::table('xbs_traffic_hourly')->insert($chunk);
                }

                return [
                    'result' => 'accepted',
                    'batch_id' => $batch['batch_id'],
                    'bucket_start' => $batch['bucket_start']->format('Y-m-d\TH:i:s\Z'),
                    'record_count' => count($batch['records']),
                    'stored_dimensions' => count($facts),
                ];
            }, 3);
        } catch (QueryException $exception) {
            // A concurrent retry may have won the unique constraint race.
            if ($existing = $this->findBatch($nodeId, $batch['batch_id'])) {
                return $this->duplicateOrConflict(
                    $existing,
                    $checksum,
                    $reportedPendingBatches,
                    $reportedRetryAttempt,
                );
            }
            if ($existingBucket = $this->findBucket($nodeId, $batch['bucket_start']->format('Y-m-d H:i:s'))) {
                throw new IngestException('bucket_conflict', '该节点的时间桶已使用其他批次上报', 409, [
                    'existing_batch_id' => $existingBucket->batch_id,
                ]);
            }
            throw $exception;
        }
    }

    private function resolveDimensions(array $records, mixed $now): array
    {
        $domains = [];
        $ips = [];
        $outbounds = [];

        foreach ($records as $record) {
            if ($record['exact_domain'] !== null) {
                $domains[$record['exact_domain']] = $record['registrable_domain'];
            }
            foreach (['source_ip', 'destination_ip'] as $field) {
                if ($record[$field] !== null) {
                    if ($record[$field]['kind'] === 'known') {
                        $ips[bin2hex($record[$field]['packed'])] = $record[$field];
                    }
                }
            }
            if ($record['outbound_tag'] !== 'unknown' || $record['outbound_type'] !== 'unknown') {
                $key = $record['outbound_tag'] . "\0" . $record['outbound_type'];
                $outbounds[$key] = [
                    'tag' => $record['outbound_tag'],
                    'type' => $record['outbound_type'],
                ];
            }
        }

        $domainMap = $this->resolveDomains($domains, $now);
        $ipMap = $this->resolveIps($ips, $now);
        $outboundMap = $this->resolveOutbounds($outbounds, $now);

        return compact('domainMap', 'ipMap', 'outboundMap');
    }

    private function resolveDomains(array $domains, mixed $now): array
    {
        if ($domains === []) {
            return [];
        }
        $rows = [];
        foreach ($domains as $exact => $registrable) {
            $rows[] = [
                'exact_domain' => $exact,
                'registrable_domain' => $registrable,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        foreach (array_chunk($rows, 1000) as $chunk) {
            DB::table('xbs_domains')->insertOrIgnore($chunk);
        }

        $map = [];
        foreach (array_chunk(array_keys($domains), 1000) as $chunk) {
            foreach (DB::table('xbs_domains')->whereIn('exact_domain', $chunk)->get(['id', 'exact_domain']) as $row) {
                $map[$row->exact_domain] = (int) $row->id;
            }
        }
        return $map;
    }

    private function resolveIps(array $ips, mixed $now): array
    {
        if ($ips === []) {
            return [];
        }
        $rows = [];
        foreach ($ips as $ip) {
            $rows[] = [
                'address' => $ip['packed'],
                'ip_version' => $ip['version'],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        foreach (array_chunk($rows, 1000) as $chunk) {
            DB::table('xbs_ip_addresses')->insertOrIgnore($chunk);
        }

        $map = [];
        $packedAddresses = array_map(static fn (array $ip): string => $ip['packed'], array_values($ips));
        foreach (array_chunk($packedAddresses, 500) as $chunk) {
            foreach (DB::table('xbs_ip_addresses')->whereIn('address', $chunk)->get(['id', 'address']) as $row) {
                $map[bin2hex($row->address)] = (int) $row->id;
            }
        }
        return $map;
    }

    private function resolveOutbounds(array $outbounds, mixed $now): array
    {
        if ($outbounds === []) {
            return [];
        }
        $rows = [];
        foreach ($outbounds as $outbound) {
            $rows[] = $outbound + ['created_at' => $now, 'updated_at' => $now];
        }
        foreach (array_chunk($rows, 1000) as $chunk) {
            DB::table('xbs_outbounds')->insertOrIgnore($chunk);
        }

        $tags = array_values(array_unique(array_column($outbounds, 'tag')));
        $map = [];
        foreach (array_chunk($tags, 500) as $chunk) {
            foreach (DB::table('xbs_outbounds')->whereIn('tag', $chunk)->get(['id', 'tag', 'type']) as $row) {
                $map[$row->tag . "\0" . $row->type] = (int) $row->id;
            }
        }
        return $map;
    }

    private function buildFacts(
        int $batchRowId,
        int $nodeId,
        string $bucketStart,
        array $records,
        array $dimensions,
        mixed $now,
    ): array {
        $facts = [];
        foreach ($records as $record) {
            $domainId = $record['exact_domain'] === null
                ? null
                : ($dimensions['domainMap'][$record['exact_domain']] ?? null);
            $sourceIpId = $record['source_ip'] === null
                ? null
                : ($record['source_ip']['kind'] === 'known'
                    ? ($dimensions['ipMap'][bin2hex($record['source_ip']['packed'])] ?? null)
                    : null);
            $destinationIpId = $record['destination_ip'] === null
                ? null
                : ($record['destination_ip']['kind'] === 'known'
                    ? ($dimensions['ipMap'][bin2hex($record['destination_ip']['packed'])] ?? null)
                    : null);
            $sourceIpState = $record['source_ip'] === null ? 0 : ($record['source_ip']['kind'] === 'known' ? 1 : 2);
            $destinationIpState = $record['destination_ip'] === null ? 0 : ($record['destination_ip']['kind'] === 'known' ? 1 : 2);
            $outboundKey = $record['outbound_tag'] . "\0" . $record['outbound_type'];
            $outboundId = $dimensions['outboundMap'][$outboundKey] ?? null;

            if ($record['exact_domain'] !== null && $domainId === null) {
                throw new IngestException('dimension_resolution_failed', '域名维度写入失败', 500);
            }
            if ($sourceIpState === 1 && $sourceIpId === null) {
                throw new IngestException('dimension_resolution_failed', '来源 IP 维度写入失败', 500);
            }
            if ($destinationIpState === 1 && $destinationIpId === null) {
                throw new IngestException('dimension_resolution_failed', '目的 IP 维度写入失败', 500);
            }

            $dimensionValues = [
                $record['user_id'],
                $sourceIpId ?? 0,
                $sourceIpState,
                $destinationIpId ?? 0,
                $destinationIpState,
                $domainId ?? 0,
                $record['network'],
                $record['application_protocol'],
                $record['inbound_type'],
                $outboundId ?? 0,
                $record['destination_port'] ?? 0,
            ];
            $hash = hash('sha256', json_encode($dimensionValues, JSON_THROW_ON_ERROR));

            if (!isset($facts[$hash])) {
                $facts[$hash] = [
                    'batch_id' => $batchRowId,
                    'bucket_start' => $bucketStart,
                    'node_id' => $nodeId,
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
                    'dimension_hash' => $hash,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            foreach (['upload_bytes', 'download_bytes', 'connection_count', 'active_seconds'] as $metric) {
                $facts[$hash][$metric] = $this->checkedAdd($facts[$hash][$metric], $record[$metric], $metric);
            }
        }

        return array_values($facts);
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
        return DB::table('xbs_report_batches')
            ->where('node_id', $nodeId)
            ->where('batch_id', $batchId)
            ->first();
    }

    private function findBucket(int $nodeId, string $bucketStart): ?object
    {
        return DB::table('xbs_report_batches')
            ->where('node_id', $nodeId)
            ->where('bucket_start', $bucketStart)
            ->first();
    }

    private function duplicateOrConflict(
        object $existing,
        string $checksum,
        ?int $reportedPendingBatches,
        ?int $reportedRetryAttempt,
    ): array
    {
        if (!hash_equals((string) $existing->checksum, $checksum)) {
            throw new IngestException('batch_checksum_conflict', '相同 batch_id 的请求体校验和不同', 409);
        }

        $health = [];
        if ($reportedPendingBatches !== null) {
            $health['reported_pending_batches'] = $reportedPendingBatches;
        }
        if ($reportedRetryAttempt !== null) {
            $health['reported_retry_attempt'] = max(
                (int) ($existing->reported_retry_attempt ?? 0),
                $reportedRetryAttempt,
            );
        }
        if ($health !== []) {
            $health['updated_at'] = now();
            DB::table('xbs_report_batches')->where('id', $existing->id)->update($health);
        }

        return [
            'result' => 'duplicate',
            'batch_id' => $existing->batch_id,
            'bucket_start' => (string) $existing->bucket_start,
            'record_count' => (int) $existing->record_count,
        ];
    }
}
