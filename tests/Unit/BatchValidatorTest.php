<?php

declare(strict_types=1);

namespace Plugin\XboardStatistic\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Plugin\XboardStatistic\Exceptions\IngestException;
use Plugin\XboardStatistic\Services\BatchValidator;

final class BatchValidatorTest extends TestCase
{
    private const NOW = 1793000000;

    public function testItNormalizesAOneMinuteBatch(): void
    {
        $validated = $this->validate($this->payload());

        self::assertSame('019c0000-0000-7000-8000-000000000001', $validated['batch_id']);
        self::assertSame($this->bucketStart(), $validated['bucket_start_unix']);
        self::assertSame('video.example.com', $validated['records'][0]['exact_domain']);
        self::assertSame('example.com', $validated['records'][0]['registrable_domain']);
        self::assertSame('tls', $validated['records'][0]['application_protocol']);
        self::assertSame(4, $validated['records'][0]['source_ip']['version']);
        self::assertSame(6, $validated['records'][0]['destination_ip']['version']);
        self::assertSame([
            'collapsed_dimensions' => 0,
            'collapsed_bytes' => 0,
            'unknown_domain_bytes' => 5,
            'unknown_destination_ip_bytes' => 0,
        ], $validated['quality']);
    }

    public function testSchemaVersionOneIsRejected(): void
    {
        $payload = $this->payload();
        $payload['schema_version'] = 1;

        $this->assertIngestError('unsupported_schema_version', $payload);
    }

    public function testHourLongBucketIsRejected(): void
    {
        $payload = $this->payload();
        $payload['bucket_end'] = gmdate('Y-m-d\TH:i:s\Z', $this->bucketStart() + 3600);

        $this->assertIngestError('invalid_bucket_range', $payload);
    }

    public function testBucketMustStartOnAWholeMinute(): void
    {
        $payload = $this->payload();
        $payload['bucket_start'] = gmdate('Y-m-d\TH:i:30\Z', $this->bucketStart());

        $this->assertIngestError('invalid_bucket_start', $payload);
    }

    public function testExpiredAndFutureBucketsAreRejected(): void
    {
        $expired = $this->payload(self::NOW - 15 * 86400);
        $this->assertIngestError('expired_bucket', $expired);

        $future = $this->payload(self::NOW + 600);
        $this->assertIngestError('future_bucket', $future);
    }

    public function testItRejectsInvalidRegistrableDomain(): void
    {
        $payload = $this->payload();
        $payload['records'][0]['registrable_domain'] = 'other.test';

        $this->assertIngestError('invalid_registrable_domain', $payload);
    }

    public function testUnknownAndCollapsedDomainsDropRegistrableDomain(): void
    {
        $payload = $this->payload();
        $payload['records'][0]['exact_domain'] = '__other__';
        $payload['records'][1] = $payload['records'][0];
        $payload['records'][1]['exact_domain'] = 'unknown';

        $validated = $this->validate($payload);

        self::assertSame('__other__', $validated['records'][0]['exact_domain']);
        self::assertNull($validated['records'][0]['registrable_domain']);
        self::assertNull($validated['records'][1]['exact_domain']);
        self::assertNull($validated['records'][1]['registrable_domain']);
    }

    public function testItRejectsTooManyRecords(): void
    {
        $payload = $this->payload();
        $payload['records'][] = $payload['records'][0];

        try {
            (new BatchValidator())->validate($payload, 1, 14, self::NOW);
            self::fail('Expected too_many_records');
        } catch (IngestException $exception) {
            self::assertSame('too_many_records', $exception->errorCode);
            self::assertSame(413, $exception->httpStatus);
        }
    }

    public function testCollapsedDimensionsRemainDistinctFromUnknown(): void
    {
        $payload = $this->payload();
        $payload['records'][0]['source_ip'] = '__other__';
        $payload['records'][0]['destination_port'] = 0;
        $payload['records'][0]['outbound_tag'] = '__other__';

        $validated = $this->validate($payload);

        self::assertSame('other', $validated['records'][0]['source_ip']['kind']);
        self::assertSame(0, $validated['records'][0]['destination_port']);
        self::assertSame('__other__', $validated['records'][0]['outbound_tag']);
    }

    public function testItRejectsUnversionedFieldsIncludingUserAgent(): void
    {
        $payload = $this->payload();
        $payload['records'][0]['user_agent'] = 'not-collected';

        $this->assertIngestError('unknown_field', $payload);
    }

    public function testRecordErrorsCarryTheRecordIndex(): void
    {
        $payload = $this->payload();
        $payload['records'][] = $payload['records'][0];
        $payload['records'][1]['network'] = 'icmp';

        try {
            $this->validate($payload);
            self::fail('Expected invalid_network');
        } catch (IngestException $exception) {
            self::assertSame('invalid_network', $exception->errorCode);
            self::assertSame(1, $exception->details['record_index']);
            self::assertStringStartsWith('records.1:', $exception->getMessage());
        }
    }

    public function testUserIdMustFitTheStorageColumn(): void
    {
        $payload = $this->payload();
        $payload['records'][0]['user_id'] = 4294967296;

        $this->assertIngestError('invalid_user_id', $payload);
    }

    private function validate(array $payload): array
    {
        return (new BatchValidator())->validate($payload, 100, 14, self::NOW);
    }

    private function assertIngestError(string $code, array $payload): void
    {
        try {
            $this->validate($payload);
            self::fail("Expected {$code}");
        } catch (IngestException $exception) {
            self::assertSame($code, $exception->errorCode);
        }
    }

    private function bucketStart(?int $at = null): int
    {
        return intdiv(($at ?? self::NOW - 120), 60) * 60;
    }

    private function payload(?int $at = null): array
    {
        $start = $this->bucketStart($at);

        return [
            'schema_version' => 2,
            'batch_id' => '019c0000-0000-7000-8000-000000000001',
            'bucket_start' => gmdate('Y-m-d\TH:i:s\Z', $start),
            'bucket_end' => gmdate('Y-m-d\TH:i:s\Z', $start + 60),
            'quality' => ['unknown_domain_bytes' => 5],
            'records' => [[
                'user_id' => 42,
                'source_ip' => '198.51.100.7',
                'destination_ip' => '2001:db8::8',
                'exact_domain' => 'VIDEO.Example.COM.',
                'registrable_domain' => 'example.com',
                'network' => 'tcp',
                'application_protocol' => 'TLS',
                'inbound_type' => 'VLESS',
                'outbound_tag' => 'direct',
                'outbound_type' => 'direct',
                'destination_port' => 443,
                'upload_bytes' => 100,
                'download_bytes' => 900,
                'connection_count' => 1,
                'active_seconds' => 20,
            ]],
        ];
    }
}
