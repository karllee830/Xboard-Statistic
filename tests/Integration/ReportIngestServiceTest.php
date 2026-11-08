<?php

declare(strict_types=1);

namespace Plugin\XboardStatistic\Tests\Integration;

use Illuminate\Support\Facades\DB;
use Plugin\XboardStatistic\Exceptions\IngestException;
use Plugin\XboardStatistic\Support\IpAddress;
use Plugin\XboardStatistic\Support\Tables;
use Plugin\XboardStatistic\Support\UtcTime;
use Plugin\XboardStatistic\Tests\DatabaseTestCase;
use Plugin\XboardStatistic\Tests\ReportFactory;

final class ReportIngestServiceTest extends DatabaseTestCase
{
    public function testAcceptedBatchWritesFactsRollupsAndDimensions(): void
    {
        $bucket = ReportFactory::minutesAgo(5);
        $result = ReportFactory::ingest(7, $bucket, [
            ReportFactory::record(),
            // Same dimensions as the first record: collapsed into one fact.
            ReportFactory::record(['upload_bytes' => 1, 'download_bytes' => 2]),
            ReportFactory::record(['user_id' => 2, 'exact_domain' => null, 'registrable_domain' => null, 'destination_ip' => '__other__']),
        ], ['unknown_domain_bytes' => 1000]);

        self::assertSame('accepted', $result['result']);
        self::assertSame(3, $result['record_count']);
        self::assertSame(2, $result['stored_dimensions']);

        $batch = DB::table(Tables::BATCHES)->first();
        self::assertSame($bucket, (int) $batch->bucket_start_unix);
        self::assertSame(1000, (int) $batch->unknown_domain_bytes);
        self::assertSame(2, (int) $batch->stored_dimensions);

        $facts = DB::table(Tables::MINUTE)->orderBy('user_id')->get();
        self::assertCount(2, $facts);
        self::assertSame(101, (int) $facts[0]->upload_bytes);
        self::assertSame(902, (int) $facts[0]->download_bytes);
        self::assertSame(2, (int) $facts[1]->destination_ip_state);
        self::assertNull($facts[1]->domain_id);

        $hour = UtcTime::floorHour($bucket);
        $nodeUser = DB::table(Tables::HOUR_NODE_USER)->orderBy('user_id')->get();
        self::assertSame([[$hour, 7, 1, 101], [$hour, 7, 2, 100]], $nodeUser->map(fn ($row) => [
            (int) $row->hour_unix, (int) $row->node_id, (int) $row->user_id, (int) $row->upload_bytes,
        ])->all());

        $domainId = (int) DB::table(Tables::DOMAINS)->where('exact_domain', 'video.example.com')->value('id');
        $nodeUserDomain = DB::table(Tables::HOUR_NODE_USER_DOMAIN)->orderBy('user_id')->get();
        self::assertSame([$domainId, Tables::UNKNOWN_DOMAIN_ID], $nodeUserDomain->map(fn ($row) => (int) $row->domain_id)->all());

        self::assertSame(2, DB::table(Tables::IP_ADDRESSES)->count());
        self::assertSame($bucket, (int) DB::table(Tables::IP_ADDRESSES)
            ->where('address', IpAddress::normalize('198.51.100.7')['packed'])
            ->value('last_seen_unix'));
        self::assertSame(1, DB::table(Tables::OUTBOUNDS)->count());
    }

    public function testBatchesInTheSameHourAccumulateIntoOneRollupRow(): void
    {
        $hour = UtcTime::floorHour(ReportFactory::minutesAgo(90));
        ReportFactory::ingest(7, $hour, [ReportFactory::record()]);
        ReportFactory::ingest(7, $hour + 60, [ReportFactory::record(['upload_bytes' => 5])]);
        ReportFactory::ingest(8, $hour + 60, [ReportFactory::record()]);

        $row = DB::table(Tables::HOUR_NODE_USER)->where('node_id', 7)->first();
        self::assertSame(105, (int) $row->upload_bytes);
        self::assertSame(1800, (int) $row->download_bytes);
        self::assertSame(2, (int) $row->connection_count);
        self::assertSame(2, DB::table(Tables::HOUR_NODE_USER)->count());
        self::assertSame(2, DB::table(Tables::HOUR_NODE_USER_DOMAIN)->count());
    }

    public function testRetryWithSameBodyIsADuplicateAndRefreshesHealth(): void
    {
        $payload = ReportFactory::payload(ReportFactory::minutesAgo(3), [ReportFactory::record()]);
        $checksum = hash('sha256', json_encode($payload));
        $service = ReportFactory::service();

        $service->ingest(7, $checksum, $payload, 100, 14, 3, 1);
        $duplicate = $service->ingest(7, $checksum, $payload, 100, 14, 0, 2);

        self::assertSame('duplicate', $duplicate['result']);
        self::assertSame(1, DB::table(Tables::MINUTE)->count());
        self::assertSame(900, (int) DB::table(Tables::HOUR_NODE_USER)->value('download_bytes'));
        $batch = DB::table(Tables::BATCHES)->first();
        self::assertSame(0, (int) $batch->reported_pending_batches);
        self::assertSame(2, (int) $batch->reported_retry_attempt);
    }

    public function testSameBatchIdWithDifferentBodyIsAConflict(): void
    {
        $payload = ReportFactory::payload(ReportFactory::minutesAgo(3), [ReportFactory::record()]);
        $service = ReportFactory::service();
        $service->ingest(7, hash('sha256', 'first'), $payload, 100, 14);

        $this->expectExceptionObject(new IngestException('batch_checksum_conflict', '相同 batch_id 的请求体校验和不同', 409));
        $service->ingest(7, hash('sha256', 'second'), $payload, 100, 14);
    }

    public function testBucketReportedTwiceUnderDifferentBatchIdsIsRejected(): void
    {
        $bucket = ReportFactory::minutesAgo(3);
        ReportFactory::ingest(7, $bucket, [ReportFactory::record()]);

        try {
            ReportFactory::ingest(7, $bucket, [ReportFactory::record()]);
            self::fail('Expected bucket_conflict');
        } catch (IngestException $exception) {
            self::assertSame('bucket_conflict', $exception->errorCode);
            self::assertSame(409, $exception->httpStatus);
        }
        self::assertSame(900, (int) DB::table(Tables::HOUR_NODE_USER)->value('download_bytes'));
    }

    public function testLastSeenOnlyMovesForwardAndAtMostHourly(): void
    {
        $older = UtcTime::floorHour(ReportFactory::minutesAgo(300));
        ReportFactory::ingest(7, $older, [ReportFactory::record()]);
        ReportFactory::ingest(7, $older + 600, [ReportFactory::record()]);
        self::assertSame($older, (int) DB::table(Tables::DOMAINS)->value('last_seen_unix'));

        ReportFactory::ingest(7, $older + 7200, [ReportFactory::record()]);
        self::assertSame($older + 7200, (int) DB::table(Tables::DOMAINS)->value('last_seen_unix'));

        ReportFactory::ingest(8, $older - 7200, [ReportFactory::record()]);
        self::assertSame($older + 7200, (int) DB::table(Tables::DOMAINS)->value('last_seen_unix'));
    }
}
