<?php

declare(strict_types=1);

namespace Plugin\XboardStatistic\Tests;

use Plugin\XboardStatistic\Services\BatchValidator;
use Plugin\XboardStatistic\Services\DimensionStore;
use Plugin\XboardStatistic\Services\ReportIngestService;

final class ReportFactory
{
    private static int $sequence = 0;

    public static function service(): ReportIngestService
    {
        return new ReportIngestService(new BatchValidator(), new DimensionStore());
    }

    /** A whole minute that lies the given number of minutes before now. */
    public static function minutesAgo(int $minutes): int
    {
        return intdiv(time(), 60) * 60 - $minutes * 60;
    }

    /** @param list<array<string, mixed>> $records */
    public static function payload(int $bucketStart, array $records, ?string $batchId = null, array $quality = []): array
    {
        return [
            'schema_version' => 2,
            'batch_id' => $batchId ?? self::batchId(),
            'bucket_start' => gmdate('Y-m-d\TH:i:s\Z', $bucketStart),
            'bucket_end' => gmdate('Y-m-d\TH:i:s\Z', $bucketStart + 60),
            'quality' => $quality,
            'records' => $records,
        ];
    }

    public static function record(array $overrides = []): array
    {
        return $overrides + [
            'user_id' => 1,
            'source_ip' => '198.51.100.7',
            'destination_ip' => '203.0.113.10',
            'exact_domain' => 'video.example.com',
            'registrable_domain' => 'example.com',
            'network' => 'tcp',
            'application_protocol' => 'tls',
            'inbound_type' => 'vless',
            'outbound_tag' => 'direct',
            'outbound_type' => 'direct',
            'destination_port' => 443,
            'upload_bytes' => 100,
            'download_bytes' => 900,
            'connection_count' => 1,
            'active_seconds' => 30,
        ];
    }

    /** @param list<array<string, mixed>> $records */
    public static function ingest(int $nodeId, int $bucketStart, array $records, array $quality = []): array
    {
        $payload = self::payload($bucketStart, $records, null, $quality);
        $checksum = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        return self::service()->ingest($nodeId, $checksum, $payload, 200000, 14);
    }

    public static function batchId(): string
    {
        self::$sequence++;
        return sprintf('019c0000-0000-7000-8000-%012x', self::$sequence + random_int(0, 0xffffff) * 1000);
    }
}
