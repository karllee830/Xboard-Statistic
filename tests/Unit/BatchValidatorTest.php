<?php

declare(strict_types=1);

namespace Plugin\XboardStatistic\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Plugin\XboardStatistic\Exceptions\IngestException;
use Plugin\XboardStatistic\Services\BatchValidator;

final class BatchValidatorTest extends TestCase
{
    public function testItAcceptsUuidV7AndNormalizesDimensions(): void
    {
        $validated = (new BatchValidator())->validate($this->payload(), 100, 14);

        self::assertSame('019c0000-0000-7000-8000-000000000001', $validated['batch_id']);
        self::assertSame('video.example.com', $validated['records'][0]['exact_domain']);
        self::assertSame('example.com', $validated['records'][0]['registrable_domain']);
        self::assertSame(4, $validated['records'][0]['source_ip']['version']);
        self::assertSame(6, $validated['records'][0]['destination_ip']['version']);
    }

    public function testItRejectsInvalidRegistrableDomain(): void
    {
        $payload = $this->payload();
        $payload['records'][0]['registrable_domain'] = 'other.test';

        $this->expectException(IngestException::class);
        $this->expectExceptionMessage('registrable_domain');
        (new BatchValidator())->validate($payload, 100, 14);
    }

    public function testItRejectsTooManyRecords(): void
    {
        $payload = $this->payload();
        $payload['records'][] = $payload['records'][0];

        try {
            (new BatchValidator())->validate($payload, 1, 14);
            self::fail('Expected too_many_records');
        } catch (IngestException $exception) {
            self::assertSame('too_many_records', $exception->errorCode);
            self::assertSame(413, $exception->httpStatus);
        }
    }

    public function testEmptyQualityObjectIsAccepted(): void
    {
        $payload = $this->payload();
        $payload['quality'] = [];

        $validated = (new BatchValidator())->validate($payload, 100, 14);
        self::assertSame([], $validated['quality']);
    }

    public function testCollapsedDimensionsRemainDistinctFromUnknown(): void
    {
        $payload = $this->payload();
        $payload['records'][0]['source_ip'] = '__other__';
        $payload['records'][0]['destination_port'] = 0;
        $payload['records'][0]['outbound_tag'] = '__other__';

        $validated = (new BatchValidator())->validate($payload, 100, 14);

        self::assertSame('other', $validated['records'][0]['source_ip']['kind']);
        self::assertSame(0, $validated['records'][0]['destination_port']);
        self::assertSame('__other__', $validated['records'][0]['outbound_tag']);
    }

    public function testItRejectsUnversionedFieldsIncludingUserAgent(): void
    {
        $payload = $this->payload();
        $payload['records'][0]['user_agent'] = 'not-collected';

        try {
            (new BatchValidator())->validate($payload, 100, 14);
            self::fail('Expected unknown_field');
        } catch (IngestException $exception) {
            self::assertSame('unknown_field', $exception->errorCode);
            self::assertStringContainsString('user_agent', $exception->getMessage());
        }
    }

    private function payload(): array
    {
        $start = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify('-1 hour')
            ->setTime((int) gmdate('H', time() - 3600), 0, 0);

        return [
            'schema_version' => 1,
            'batch_id' => '019c0000-0000-7000-8000-000000000001',
            'bucket_start' => $start->format('Y-m-d\TH:i:s\Z'),
            'bucket_end' => $start->modify('+1 hour')->format('Y-m-d\TH:i:s\Z'),
            'quality' => ['unknown_domain_bytes' => 0],
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
