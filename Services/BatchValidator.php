<?php

declare(strict_types=1);

namespace Plugin\XboardStatistic\Services;

use DateTimeImmutable;
use Plugin\XboardStatistic\Exceptions\IngestException;
use Plugin\XboardStatistic\Support\IpAddress;
use Plugin\XboardStatistic\Support\UtcTime;
use Throwable;

/**
 * Validates and normalizes schema v2 one-minute report batches.
 */
final class BatchValidator
{
    public const SCHEMA_VERSION = 2;
    public const BUCKET_SECONDS = 60;
    public const QUALITY_FIELDS = [
        'collapsed_dimensions', 'collapsed_bytes',
        'unknown_domain_bytes', 'unknown_destination_ip_bytes',
    ];

    private const MAX_ID = 4294967295;
    private const TOKEN_PATTERN = '/^[a-z0-9][a-z0-9._-]*$/';
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';
    private const DOMAIN_PATTERN = '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/';

    private const ENVELOPE_FIELDS = [
        'schema_version', 'batch_id', 'bucket_start', 'bucket_end',
        'node_version', 'collector_version', 'records', 'quality',
    ];
    private const REQUIRED_ENVELOPE_FIELDS = [
        'schema_version', 'batch_id', 'bucket_start', 'bucket_end', 'records', 'quality',
    ];
    private const RECORD_FIELDS = [
        'user_id', 'source_ip', 'destination_ip', 'exact_domain', 'registrable_domain',
        'network', 'application_protocol', 'inbound_type', 'outbound_tag', 'outbound_type',
        'destination_port', 'upload_bytes', 'download_bytes', 'connection_count', 'active_seconds',
    ];

    /**
     * @return array{
     *     batch_id: string,
     *     bucket_start_unix: int,
     *     node_version: ?string,
     *     collector_version: ?string,
     *     quality: array<string, int>,
     *     records: list<array<string, mixed>>
     * }
     */
    public function validate(array $payload, int $maxRecords, int $maxAgeDays, ?int $nowUnix = null): array
    {
        $this->assertAllowedKeys($payload, self::ENVELOPE_FIELDS, '批次');
        $this->assertRequiredKeys($payload, self::REQUIRED_ENVELOPE_FIELDS, '批次');
        if (($payload['schema_version'] ?? null) !== self::SCHEMA_VERSION) {
            throw new IngestException('unsupported_schema_version', '仅支持 schema_version=2 的分钟批次');
        }

        $batchId = strtolower($this->requiredString($payload, 'batch_id', 36));
        if (!preg_match(self::UUID_PATTERN, $batchId)) {
            throw new IngestException('invalid_batch_id', 'batch_id 必须是有效 UUID');
        }

        $bucketStart = $this->utcMinute($payload['bucket_start'], 'bucket_start');
        $bucketEnd = $this->utcMinute($payload['bucket_end'], 'bucket_end');
        if ($bucketEnd - $bucketStart !== self::BUCKET_SECONDS) {
            throw new IngestException('invalid_bucket_range', '统计批次必须恰好覆盖一分钟');
        }

        $now = $nowUnix ?? time();
        if ($bucketEnd > $now + 300) {
            throw new IngestException('future_bucket', '不能上报未来的时间桶');
        }
        if ($bucketStart < $now - $maxAgeDays * 86400) {
            throw new IngestException('expired_bucket', '批次超过最大补报时间');
        }

        $records = $payload['records'];
        if (!is_array($records) || !array_is_list($records)) {
            throw new IngestException('invalid_records', 'records 必须是 JSON 数组');
        }
        if (count($records) > $maxRecords) {
            throw new IngestException('too_many_records', '批次记录数超过插件限制', 413, [
                'limit' => $maxRecords,
                'actual' => count($records),
            ]);
        }

        $normalizedRecords = [];
        foreach ($records as $index => $record) {
            $normalizedRecords[] = $this->normalizeRecordAt($index, $record);
        }

        return [
            'batch_id' => $batchId,
            'bucket_start_unix' => $bucketStart,
            'node_version' => $this->optionalString($payload, 'node_version', 64),
            'collector_version' => $this->optionalString($payload, 'collector_version', 64),
            'quality' => $this->normalizeQuality($payload['quality']),
            'records' => $normalizedRecords,
        ];
    }

    private function normalizeRecordAt(int $index, mixed $record): array
    {
        if (!is_array($record) || array_is_list($record)) {
            throw $this->recordError($index, 'invalid_record', '记录必须是 JSON 对象');
        }
        try {
            $this->assertAllowedKeys($record, self::RECORD_FIELDS, '记录');
            $this->assertRequiredKeys($record, self::RECORD_FIELDS, '记录');
            return $this->normalizeRecord($record);
        } catch (IngestException $exception) {
            throw new IngestException(
                $exception->errorCode,
                "records.{$index}: {$exception->getMessage()}",
                $exception->httpStatus,
                ['record_index' => $index] + $exception->details,
            );
        } catch (Throwable $exception) {
            throw $this->recordError($index, 'invalid_record', $exception->getMessage());
        }
    }

    private function normalizeRecord(array $record): array
    {
        $exactDomain = $this->normalizeDomain($this->nullableDimension($record, 'exact_domain', 253));
        $registrableDomain = $this->normalizeDomain($this->nullableDimension($record, 'registrable_domain', 253));
        if ($exactDomain === null || str_starts_with($exactDomain, '__')) {
            $registrableDomain = null;
        } elseif ($registrableDomain === null) {
            throw new IngestException('missing_registrable_domain', '已识别域名必须带 registrable_domain');
        } elseif ($exactDomain !== $registrableDomain && !str_ends_with($exactDomain, '.' . $registrableDomain)) {
            throw new IngestException('invalid_registrable_domain', 'registrable_domain 不是 exact_domain 的有效后缀');
        }

        $network = strtolower($this->requiredString($record, 'network', 3));
        if ($network !== 'tcp' && $network !== 'udp') {
            throw new IngestException('invalid_network', 'network 只能是 tcp 或 udp');
        }

        $destinationPort = $record['destination_port'];
        if ($destinationPort !== null && (!is_int($destinationPort) || $destinationPort < 0 || $destinationPort > 65535)) {
            throw new IngestException('invalid_destination_port', 'destination_port 必须在 0-65535 之间，0 表示 __other__');
        }

        $metrics = [];
        foreach (['upload_bytes', 'download_bytes', 'connection_count', 'active_seconds'] as $metric) {
            $metrics[$metric] = $this->requiredInt($record, $metric, 0, PHP_INT_MAX);
        }
        if (array_sum($metrics) === 0) {
            throw new IngestException('empty_record', '记录的所有指标不能同时为零');
        }

        return [
            'user_id' => $this->requiredInt($record, 'user_id', 1, self::MAX_ID),
            'source_ip' => IpAddress::normalize($this->nullableDimension($record, 'source_ip', 45)),
            'destination_ip' => IpAddress::normalize($this->nullableDimension($record, 'destination_ip', 45)),
            'exact_domain' => $exactDomain,
            'registrable_domain' => $registrableDomain,
            'network' => $network,
            'application_protocol' => $this->token($record, 'application_protocol', 32),
            'inbound_type' => $this->token($record, 'inbound_type', 64),
            'outbound_tag' => $this->token($record, 'outbound_tag', 64),
            'outbound_type' => $this->token($record, 'outbound_type', 64),
            'destination_port' => $destinationPort,
        ] + $metrics;
    }

    /** @return array<string, int> */
    private function normalizeQuality(mixed $quality): array
    {
        if (!is_array($quality) || ($quality !== [] && array_is_list($quality))) {
            throw new IngestException('invalid_quality', 'quality 必须是 JSON 对象');
        }
        $this->assertAllowedKeys($quality, self::QUALITY_FIELDS, 'quality');

        $normalized = [];
        foreach (self::QUALITY_FIELDS as $field) {
            $normalized[$field] = array_key_exists($field, $quality)
                ? $this->requiredInt($quality, $field, 0, PHP_INT_MAX)
                : 0;
        }
        return $normalized;
    }

    private function utcMinute(mixed $value, string $field): int
    {
        if (!is_string($value) || $value === '') {
            throw new IngestException('missing_' . $field, "{$field} 是必填字段");
        }
        try {
            $date = new DateTimeImmutable($value);
        } catch (Throwable) {
            throw new IngestException('invalid_' . $field, "{$field} 不是有效时间");
        }
        if ($date->getOffset() !== 0 || $date->format('s.u') !== '00.000000') {
            throw new IngestException('invalid_' . $field, "{$field} 必须是 UTC 整分钟");
        }
        return UtcTime::fromUnix($date->getTimestamp())->getTimestamp();
    }

    private function normalizeDomain(?string $domain): ?string
    {
        if ($domain === null) {
            return null;
        }
        $domain = strtolower(rtrim($domain, '.'));
        if ($domain === '__other__') {
            return $domain;
        }
        if ($domain === '' || strlen($domain) > 253 || !preg_match(self::DOMAIN_PATTERN, $domain)) {
            throw new IngestException('invalid_domain', '域名必须是规范化 ASCII/Punycode 主机名');
        }
        return $domain;
    }

    private function token(array $data, string $field, int $maxLength): string
    {
        $value = strtolower($this->optionalString($data, $field, $maxLength) ?? 'unknown');
        if ($value !== '__other__' && !preg_match(self::TOKEN_PATTERN, $value)) {
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

    private function recordError(int $index, string $code, string $message): IngestException
    {
        return new IngestException($code, "records.{$index}: {$message}", 422, ['record_index' => $index]);
    }

    private function assertAllowedKeys(array $data, array $allowed, string $scope): void
    {
        $unknown = array_values(array_diff(array_keys($data), $allowed));
        if ($unknown !== []) {
            throw new IngestException('unknown_field', $scope . '包含未支持字段: ' . implode(', ', $unknown), 422, [
                'fields' => $unknown,
            ]);
        }
    }

    private function assertRequiredKeys(array $data, array $required, string $scope): void
    {
        $missing = array_values(array_diff($required, array_keys($data)));
        if ($missing !== []) {
            throw new IngestException('missing_field', $scope . '缺少必填字段: ' . implode(', ', $missing), 422, [
                'fields' => $missing,
            ]);
        }
    }
}
