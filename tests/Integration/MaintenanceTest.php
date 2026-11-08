<?php

declare(strict_types=1);

namespace Plugin\XboardStatistic\Tests\Integration;

use Illuminate\Support\Facades\DB;
use Plugin\XboardStatistic\Exceptions\QueryTimeoutException;
use Plugin\XboardStatistic\Services\IpEnrichmentService;
use Plugin\XboardStatistic\Services\Query\QueryGuard;
use Plugin\XboardStatistic\Services\StatisticsMaintenance;
use Plugin\XboardStatistic\Support\IpAddress;
use Plugin\XboardStatistic\Support\MinutePartitions;
use Plugin\XboardStatistic\Support\Settings;
use Plugin\XboardStatistic\Support\Tables;
use Plugin\XboardStatistic\Support\UtcTime;
use Plugin\XboardStatistic\Tests\DatabaseTestCase;
use Plugin\XboardStatistic\Tests\ReportFactory;

final class MaintenanceTest extends DatabaseTestCase
{
    public function testMigrationPreparesDailyPartitionsAhead(): void
    {
        $bounds = MinutePartitions::bounds();
        $today = UtcTime::floorDay(time());

        self::assertArrayHasKey('p' . gmdate('Ymd', $today), $bounds);
        self::assertArrayHasKey('p' . gmdate('Ymd', $today + MinutePartitions::FUTURE_DAYS * 86400), $bounds);
        self::assertNull($bounds['pmax']);
        self::assertSame([], MinutePartitions::ensureFuture(time()), 'ensureFuture is idempotent');
    }

    public function testEnsureFutureExtendsAndDropBeforeRemovesWholeDays(): void
    {
        $later = time() + 3 * 86400;
        $created = MinutePartitions::ensureFuture($later);
        self::assertCount(3, $created);

        $today = UtcTime::floorDay(time());
        $dropped = MinutePartitions::dropBefore($today + 2 * 86400);
        self::assertSame(['p' . gmdate('Ymd', $today), 'p' . gmdate('Ymd', $today + 86400)], $dropped);
        self::assertArrayNotHasKey('p' . gmdate('Ymd', $today), MinutePartitions::bounds());

        // Restore the default layout for the other tests in this class.
        DB::statement('DROP TABLE ' . Tables::MINUTE);
        foreach ([Tables::HOUR_NODE_USER_DOMAIN, Tables::HOUR_NODE_USER, Tables::OUTBOUNDS, Tables::IP_ADDRESSES, Tables::DOMAINS, Tables::BATCHES] as $table) {
            DB::statement("DROP TABLE {$table}");
        }
        self::migration()->up();
    }

    public function testMaintenancePurgesExpiredRowsAndKeepsRecentOnes(): void
    {
        $recent = ReportFactory::minutesAgo(10);
        ReportFactory::ingest(1, $recent, [ReportFactory::record()]);

        $longAgo = UtcTime::floorDay(time()) - 400 * 86400;
        DB::table(Tables::HOUR_NODE_USER)->insert([
            'hour_unix' => $longAgo, 'node_id' => 1, 'user_id' => 1,
            'upload_bytes' => 1, 'download_bytes' => 1, 'connection_count' => 1, 'active_seconds' => 1,
        ]);
        DB::table(Tables::BATCHES)->insert([
            'bucket_start_unix' => $longAgo, 'node_id' => 1, 'batch_id' => ReportFactory::batchId(),
            'checksum' => str_repeat('a', 64), 'record_count' => 1, 'stored_dimensions' => 1,
            'received_at_unix' => $longAgo, 'updated_at_unix' => $longAgo,
        ]);
        DB::table(Tables::IP_ADDRESSES)->insert(['address' => IpAddress::normalize('192.0.2.1')['packed'], 'last_seen_unix' => $longAgo]);
        DB::table(Tables::DOMAINS)->insert(['exact_domain' => 'stale.example', 'last_seen_unix' => $longAgo]);

        $settings = Settings::fromPlugin(null);
        $preview = (new StatisticsMaintenance())->run($settings, time(), true);
        self::assertSame(1, $preview['deleted'][Tables::HOUR_NODE_USER]);
        self::assertSame(2, DB::table(Tables::HOUR_NODE_USER)->count(), 'dry run changes nothing');

        $report = (new StatisticsMaintenance())->run($settings, time());
        self::assertSame(1, $report['deleted'][Tables::HOUR_NODE_USER]);
        self::assertSame(1, $report['deleted'][Tables::BATCHES]);
        self::assertSame(1, $report['deleted'][Tables::IP_ADDRESSES]);
        self::assertSame(1, $report['deleted'][Tables::DOMAINS]);
        self::assertSame(1, DB::table(Tables::HOUR_NODE_USER)->count());
        self::assertSame(1, DB::table(Tables::MINUTE)->count());
        self::assertSame(2, DB::table(Tables::IP_ADDRESSES)->count());
    }

    public function testEnrichmentUpdatesRowsOncePerDatabaseVersion(): void
    {
        ReportFactory::ingest(1, ReportFactory::minutesAgo(10), [ReportFactory::record()]);
        $lookups = 0;
        $country = function (string $ip) use (&$lookups): array {
            $lookups++;
            return ['country' => ['iso_code' => $ip === '203.0.113.10' ? 'jp' : 'us']];
        };
        $asn = static fn (string $ip): array => ['autonomous_system_number' => 64500, 'autonomous_system_organization' => 'Example Net'];
        $service = new IpEnrichmentService();

        self::assertSame(2, $service->enrich($country, 'dbip-2026-11', $asn, 'dbip-2026-11', 100, 60));
        self::assertSame(0, $service->enrich($country, 'dbip-2026-11', $asn, 'dbip-2026-11', 100, 60));
        self::assertSame(2, $lookups);

        $row = DB::table(Tables::IP_ADDRESSES)->where('address', IpAddress::normalize('203.0.113.10')['packed'])->first();
        self::assertSame('JP', $row->country_code);
        self::assertSame(64500, (int) $row->asn);
        self::assertSame('Example Net', $row->asn_organization);

        self::assertSame(2, $service->enrich($country, 'dbip-2026-12', null, null, 100, 60));
        $row = DB::table(Tables::IP_ADDRESSES)->where('address', IpAddress::normalize('203.0.113.10')['packed'])->first();
        self::assertSame(64500, (int) $row->asn, 'an unavailable database leaves its columns untouched');
    }

    public function testQueryGuardTurnsTheExecutionLimitIntoATimeout(): void
    {
        try {
            QueryGuard::run(static fn () => DB::select(
                'SELECT COUNT(*) AS total FROM information_schema.COLUMNS a, information_schema.COLUMNS b, information_schema.COLUMNS c',
            ), 50);
            self::fail('Expected a timeout');
        } catch (QueryTimeoutException) {
            self::assertSame(0, (int) DB::selectOne('SELECT @@SESSION.max_execution_time AS value')->value);
        }
    }
}
