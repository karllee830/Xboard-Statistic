<?php

declare(strict_types=1);

namespace Plugin\XboardStatistic\Services;

use DateTimeImmutable;
use Plugin\XboardStatistic\Exceptions\IngestException;
use Plugin\XboardStatistic\Support\IpAddress;
use Plugin\XboardStatistic\Support\UtcTime;
use Throwable;

final class BatchValidator
{
    private const TOKEN_PATTERN = '/^[a-z0-9][a-z0-9._-]*$/';

    private const ENVELOPE_FIELDS = [
        'schema_version', 'batch_id', 'bucket_start', 'bucket_end',
        'node_version', 'collector_version', 'records', 'quality',
    ];

    private const RECORD_FIELDS = [
        'user_id', 'source_ip', 'destination_ip', 'exact_domain', 'registrable_domain',
        'network', 'application_protocol', 'inbound_type', 'outbound_tag', 'outbound_type',
        'destination_port', 'upload_bytes', 'download_bytes', 'connection_count', 'active_seconds',
    ];

    private const QUALITY_FIELDS = [
        'collapsed_dimensions', 'collapsed_bytes',
        'unknown_domain_bytes', 'unknown_destination_ip_bytes',
    ];

    public function validate(array $payload, int $maxRecords, int $maxAgeDays): array
    {
        $this->assertAllowedKeys($payload, self::ENVELOPE_FIELDS, '批次');
        $this->assertRequiredKeys(
            $payload,
            ['schema_version', 'batch_id', 'bucket_start', 'bucket_end', 'records', 'quality'],
            '批次',
        );
        $schemaVersion = $this->requiredInt($payload, 'schema_version', 1, PHP_INT_MAX);
        if (!in_array($schemaVersion, [1, 2], true)) {
            throw new IngestException('unsupported_schema_version', '仅支持 schema_version=1 或 2', 422);
        }

        $batchId = strtolower($this->requiredString($payload, 'batch_id', 36));
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $batchId)) {
            throw new IngestException('invalid_batch_id', 'batch_id 必须是有效 UUID', 422);
        }

        $bucketSeconds = $schemaVersion === 1 ? 3600 : 60;
        $bucketStart = $this->utcBoundary($payload['bucket_start'] ?? null, 'bucket_start', $bucketSeconds);
        $bucketEnd = $this->utcBoundary($payload['bucket_end'] ?? null, 'bucket_end', $bucketSeconds);
        if ($bucketEnd->getTimestamp() - $bucketStart->getTimestamp() !== $bucketSeconds) {
            $label = $schemaVersion === 1 ? '一个小时' : '一分钟';
            throw new IngestException('invalid_bucket_range', "统计批次必须恰好覆盖{$label}", 422);
        }

        $now = UtcTime::now();
        if ($bucketEnd > $now->modify('+5 minutes')) {
            throw new IngestException('future_bucket', '不能上报未来的时间桶', 422);
        }
        if ($bucketStart < $now->modify(sprintf('-%d days', $maxAgeDays))) {
            throw new IngestException('expired_bucket', '批次超过最大补报时间', 422);
        }

        $records = $payload['records'] ?? null;
        if (!is_array($records) || !array_is_list($records)) {
            throw new IngestException('invalid_records', 'records 必须是 JSON 数组', 422);
        }
        if (count($records) > $maxRecords) {
            throw new IngestException('too_many_records', '批次记录数超过插件限制', 413, [
                'limit' => $maxRecords,
                'actual' => count($records),
            ]);
        }

        $normalizedRecords = [];
        foreach ($records as $index => $record) {
            if (!is_array($record) || array_is_list($record)) {
                throw $this->recordError($index, '记录必须是 JSON 对象');
            }
            try {
                $this->assertAllowedKeys($record, self::RECORD_FIELDS, '记录');
                $this->assertRequiredKeys($record, self::RECORD_FIELDS, '记录');
                $normalizedRecords[] = $this->normalizeRecord($record);
            } catch (IngestException $exception) {
                throw new IngestException(
                    $exception->errorCode,
                    "records.{$index}: {$exception->getMessage()}",
                    $exception->httpStatus,
                    ['record_index' => $index] + $exception->details,
                );
            } catch (Throwable $exception) {
                throw $this->recordError($index, $exception->getMessage());
            }
        }

        $quality = $payload['quality'] ?? [];
        if (!is_array($quality) || ($quality !== [] && array_is_list($quality))) {
            throw new IngestException('invalid_quality', 'quality 必须是 JSON 对象', 422);
        }
        $this->assertAllowedKeys($quality, self::QUALITY_FIELDS, 'quality');
        $normalizedQuality = [];
        foreach (self::QUALITY_FIELDS as $field) {
            if (array_key_exists($field, $quality)) {
                $normalizedQuality[$field] = $this->requiredInt($quality, $field, 0, PHP_INT_MAX);
            }
        }

        return [
            'schema_version' => $schemaVersion,
            'batch_id' => $batchId,
            'bucket_start' => $bucketStart,
            'bucket_end' => $bucketEnd,
            'node_version' => $this->optionalString($payload, 'node_version', 64),
            'collector_version' => $this->optionalString($payload, 'collector_version', 64),
            'quality' => $normalizedQuality,
            'records' => $normalizedRecords,
        ];
    }

    private function normalizeRecord(array $record): array
    {
        $userId = $this->requiredInt($record, 'user_id', 1, PHP_INT_MAX);
        $sourceIp = IpAddress::normalize($this->nullableDimension($record, 'source_ip', 45));
        $destinationIp = IpAddress::normalize($this->nullableDimension($record, 'destination_ip', 45));

        $exactDomain = $this->normalizeDomain($this->nullableDimension($record, 'exact_domain', 253));
        $registrableDomain = $this->normalizeDomain($this->nullableDimension($record, 'registrable_domain', 253));
        if ($exactDomain !== null && !str_starts_with($exactDomain, '__')) {
            if ($registrableDomain === null) {
                throw new IngestException('missing_registrable_domain', '已识别域名必须带 registrable_domain');
            }
            if ($exactDomain !== $registrableDomain && !str_ends_with($exactDomain, '.' . $registrableDomain)) {
                throw new IngestException('invalid_registrable_domain', 'registrable_domain 不是 exact_domain 的有效后缀');
            }
        } elseif ($exactDomain === null) {
            $registrableDomain = null;
        } else {
            $registrableDomain = null;
        }

        $network = strtolower($this->requiredString($record, 'network', 3));
        if (!in_array($network, ['tcp', 'udp'], true)) {
            throw new IngestException('invalid_network', 'network 只能是 tcp 或 udp');
        }

        $applicationProtocol = $this->token($record, 'application_protocol', 32, 'unknown');
        $inboundType = $this->token($record, 'inbound_type', 64, 'unknown');
        $outboundTag = $this->token($record, 'outbound_tag', 64, 'unknown');
        $outboundType = $this->token($record, 'outbound_type', 64, 'unknown');

        $destinationPort = $record['destination_port'] ?? null;
        if ($destinationPort !== null) {
            if (!is_int($destinationPort) || $destinationPort < 0 || $destinationPort > 65535) {
                throw new IngestException('invalid_destination_port', 'destination_port 必须在 0-65535 之间，0 表示 __other__');
            }
        }

        $upload = $this->requiredInt($record, 'upload_bytes', 0, PHP_INT_MAX);
        $download = $this->requiredInt($record, 'download_bytes', 0, PHP_INT_MAX);
        $connections = $this->requiredInt($record, 'connection_count', 0, PHP_INT_MAX);
        $activeSeconds = $this->requiredInt($record, 'active_seconds', 0, PHP_INT_MAX);
        if ($upload === 0 && $download === 0 && $connections === 0 && $activeSeconds === 0) {
            throw new IngestException('empty_record', '记录的所有指标不能同时为零');
        }

        return [
            'user_id' => $userId,
            'source_ip' => $sourceIp,
            'destination_ip' => $destinationIp,
            'exact_domain' => $exactDomain,
            'registrable_domain' => $registrableDomain,
            'network' => $network,
            'application_protocol' => $applicationProtocol,
            'inbound_type' => $inboundType,
            'outbound_tag' => $outboundTag,
            'outbound_type' => $outboundType,
            'destination_port' => $destinationPort,
            'upload_bytes' => $upload,
            'download_bytes' => $download,
            'connection_count' => $connections,
            'active_seconds' => $activeSeconds,
        ];
    }

    private function utcBoundary(mixed $value, string $field, int $bucketSeconds): DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            throw new IngestException('missing_' . $field, "{$field} 是必填字段", 422);
        }
        try {
            $date = new DateTimeImmutable($value);
        } catch (Throwable) {
            throw new IngestException('invalid_' . $field, "{$field} 不是有效时间", 422);
        }
        $boundary = $bucketSeconds === 3600 ? 'i:s.u' : 's.u';
        $expected = $bucketSeconds === 3600 ? '00:00.000000' : '00.000000';
        if ($date->getOffset() !== 0 || $date->format($boundary) !== $expected) {
            $label = $bucketSeconds === 3600 ? 'UTC 整点' : 'UTC 整分钟';
            throw new IngestException('invalid_' . $field, "{$field} 必须是{$label}", 422);
        }
        return UtcTime::fromUnix($date->getTimestamp());
    }

    private function normalizeDomain(?string $domain): ?string
    {
        if ($domain === null || $domain === 'unknown') {
            return null;
        }
        $domain = strtolower(rtrim($domain, '.'));
        if (in_array($domain, ['__other__'], true)) {
            return $domain;
        }
        if (strlen($domain) > 253 || !preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $domain)) {
            throw new IngestException('invalid_domain', '域名必须是规范化 ASCII/Punycode 主机名');
        }
        return $domain;
    }

    private function token(array $data, string $field, int $maxLength, string $default): string
    {
        $value = strtolower($this->optionalString($data, $field, $maxLength) ?? $default);
        if ($value === '__other__') {
            return $value;
        }
        if (!preg_match(self::TOKEN_PATTERN, $value)) {
            throw new IngestException('invalid_' . $field, "{$field} 包含非法字符");
        }
        return $value;
    }

    private function nullableDimension(array $data, string $field, int $maxLength): ?string
    {
        $value = $this->optionalString($data, $field, $maxLength);
        return $value === 'unknown' ? null : $value;
    }

    private function requiredString(array $data, string $field, int $maxLength): string
    {
        $value = $this->optionalString($data, $field, $maxLength);
        if ($value === null || $value === '') {
            throw new IngestException('missing_' . $field, "{$field} 是必填字段");
        }
        return $value;
    }

    private function optionalString(array $data, string $field, int $maxLength): ?string
    {
        $value = $data[$field] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || strlen($value) > $maxLength || str_contains($value, "\0")) {
            throw new IngestException('invalid_' . $field, "{$field} 必须是不超过 {$maxLength} 字节的字符串");
        }
        return trim($value);
    }

    private function requiredInt(array $data, string $field, int $min, int $max): int
    {
        $value = $data[$field] ?? null;
        if (!is_int($value) || $value < $min || $value > $max) {
            throw new IngestException('invalid_' . $field, "{$field} 必须是 {$min}-{$max} 之间的整数");
        }
        return $value;
    }

    private function recordError(int $index, string $message): IngestException
    {
        return new IngestException('invalid_record', "records.{$index}: {$message}", 422, [
            'record_index' => $index,
        ]);
    }

    private function assertAllowedKeys(array $data, array $allowed, string $scope): void
    {
        $unknown = array_values(array_diff(array_keys($data), $allowed));
        if ($unknown !== []) {
            throw new IngestException(
                'unknown_field',
                $scope . '包含未支持字段: ' . implode(', ', $unknown),
                422,
                ['fields' => $unknown],
            );
        }
    }

    private function assertRequiredKeys(array $data, array $required, string $scope): void
    {
        $missing = array_values(array_diff($required, array_keys($data)));
        if ($missing !== []) {
            throw new IngestException(
                'missing_field',
                $scope . '缺少必填字段: ' . implode(', ', $missing),
                422,
                ['fields' => $missing],
            );
        }
    }
}
