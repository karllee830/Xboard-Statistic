<?php

declare(strict_types=1);

namespace Plugin\XboardStatistic\Tests\Integration;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Plugin\XboardStatistic\Services\Query\TrafficQueryBuilder;
use Plugin\XboardStatistic\Services\Query\TrafficQueryService;
use Plugin\XboardStatistic\Services\Query\TrafficRowDecorator;
use Plugin\XboardStatistic\Support\IpAddress;
use Plugin\XboardStatistic\Support\Tables;
use Plugin\XboardStatistic\Tests\DatabaseTestCase;
use Plugin\XboardStatistic\Tests\ReportFactory;

final class TrafficQueryServiceTest extends DatabaseTestCase
{
    private int $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = time();

        DB::table('v2_server')->insert([['id' => 1, 'name' => 'Tokyo'], ['id' => 2, 'name' => 'Hong Kong']]);
        DB::table('v2_user')->insert([['id' => 1, 'email' => 'alice@example.com'], ['id' => 2, 'email' => 'bob@example.com']]);

        $recent = ReportFactory::minutesAgo(30);
        ReportFactory::ingest(1, $recent, [
            ReportFactory::record(),
            ReportFactory::record([
                'user_id' => 2,
                'exact_domain' => null,
                'registrable_domain' => null,
                'upload_bytes' => 50,
                'download_bytes' => 450,
            ]),
        ], ['unknown_domain_bytes' => 500]);
        ReportFactory::ingest(2, $recent, [
            ReportFactory::record(['exact_domain' => 'api.example.org', 'registrable_domain' => 'example.org', 'network' => 'udp']),
        ]);
        ReportFactory::ingest(1, ReportFactory::minutesAgo(3 * 1440), [
            ReportFactory::record(['upload_bytes' => 1000, 'download_bytes' => 9000]),
        ]);
    }

    public function testLongRangeRankingReadsHourlyRollups(): void
    {
        $result = $this->query(['group_by' => 'user'], 7);

        self::assertSame('hourly', $result['source']);
        self::assertSame('12500', $result['grand_total_bytes']);
        self::assertSame(2, $result['total']);
        self::assertSame([[1, 'alice@example.com', '12000'], [2, 'bob@example.com', '500']], $this->summary($result));
        self::assertSame(0, (int) substr($result['from'], 14, 2), 'rollup ranges start on a whole hour');
    }

    public function testShortRangeRankingReadsMinuteFacts(): void
    {
        $result = $this->query(['group_by' => 'user'], 1);

        self::assertSame('minute', $result['source']);
        self::assertSame([[1, 'alice@example.com', '2000'], [2, 'bob@example.com', '500']], $this->summary($result));
    }

    public function testDetailFilterFallsBackToMinuteFactsWithTheSameTotals(): void
    {
        $result = $this->query(['group_by' => 'user', 'network' => 'tcp'], 7);

        self::assertSame('minute', $result['source']);
        self::assertSame([[1, 'alice@example.com', '11000'], [2, 'bob@example.com', '500']], $this->summary($result));
    }

    public function testPaginationKeepsWindowTotals(): void
    {
        $second = $this->query(['group_by' => 'user', 'pageSize' => 1, 'current' => 2], 7);
        self::assertSame([[2, 'bob@example.com', '500']], $this->summary($second));
        self::assertSame(2, $second['items'][0]['rank']);
        self::assertSame(2, $second['total']);
        self::assertSame('12500', $second['grand_total_bytes']);

        $pastEnd = $this->query(['group_by' => 'user', 'pageSize' => 1, 'current' => 3], 7);
        self::assertSame([], $pastEnd['items']);
        self::assertSame(2, $pastEnd['total']);
        self::assertSame('12500', $pastEnd['grand_total_bytes']);
    }

    public function testSortByConnectionCountAscending(): void
    {
        $result = $this->query(['group_by' => 'node', 'sort_field' => 'connection_count', 'sort_order' => 'asc'], 7);

        self::assertSame([2, 1], array_column($result['items'], 'value'));
    }

    public function testTrendIsChronologicalAndSwitchesToDailyPoints(): void
    {
        $hourly = $this->query(['group_by' => 'time'], 7);
        self::assertSame(3600, $hourly['bucket_seconds']);
        $times = array_column($hourly['items'], 'value');
        $sorted = $times;
        sort($sorted);
        self::assertSame($sorted, $times);
        self::assertSame(12500, array_sum(array_map('intval', array_column($hourly['items'], 'total_bytes'))));

        $daily = $this->query(['group_by' => 'time'], 40);
        self::assertSame(86400, $daily['bucket_seconds']);
        self::assertSame('12500', $daily['grand_total_bytes']);
    }

    public function testDomainRankingIncludesUnknownDomainWithRepresentativeDestination(): void
    {
        $result = $this->query(['group_by' => 'domain', 'node_id' => 1], 7);

        self::assertSame('hourly', $result['source']);
        self::assertSame(['video.example.com', 'unknown'], array_column($result['items'], 'value'));
        $unknown = $result['items'][1];
        self::assertTrue($unknown['unknown_domain']);
        self::assertSame('203.0.113.10', $unknown['label']);
        self::assertSame(1, $unknown['representative_destination']['destination_ip_count']);
    }

    public function testUnknownDomainFilterMatchesOnBothSources(): void
    {
        foreach ([1, 7] as $days) {
            $result = $this->query(['group_by' => 'user', 'domain' => 'unknown'], $days);
            self::assertSame([[2, 'bob@example.com', '500']], $this->summary($result));
        }
    }

    public function testRegistrableDomainFilterOnRollups(): void
    {
        $result = $this->query(['group_by' => 'node', 'domain' => 'example.com'], 7);

        self::assertSame('hourly', $result['source']);
        self::assertSame('11000', $result['grand_total_bytes']);
    }

    public function testSearchMatchesNodeNames(): void
    {
        $result = $this->query(['group_by' => 'node', 'search' => 'tok'], 7);

        self::assertSame([1], array_column($result['items'], 'value'));
    }

    public function testDestinationCountryGroupingUsesEnrichedGeoData(): void
    {
        DB::table(Tables::IP_ADDRESSES)
            ->where('address', IpAddress::normalize('203.0.113.10')['packed'])
            ->update(['country_code' => 'JP', 'asn' => 2516, 'asn_organization' => 'KDDI']);

        $countries = $this->query(['group_by' => 'destination_country'], 1);
        self::assertSame(['JP'], array_column($countries['items'], 'value'));

        $asns = $this->query(['group_by' => 'destination_asn'], 1);
        self::assertSame('2516', $asns['items'][0]['value']);
        self::assertSame('KDDI', $asns['items'][0]['asn_organization']);

        $filtered = $this->query(['group_by' => 'user', 'destination_country' => 'jp'], 7);
        self::assertSame('12500', $filtered['grand_total_bytes']);
    }

    public function testSourceIpRankingCarriesGeoMetadata(): void
    {
        $result = $this->query(['group_by' => 'source_ip'], 1);

        self::assertSame('198.51.100.7', $result['items'][0]['value']);
        self::assertArrayHasKey('country_code', $result['items'][0]);
    }

    public function testDetailQueriesAreLimitedToNinetyDays(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->query(['group_by' => 'destination_port'], 100);
    }

    public function testOverviewOnRollupsSkipsDistinctIpCounts(): void
    {
        $overview = $this->service()->overview($this->request([], 7));

        self::assertSame('12500', $overview['total_bytes']);
        self::assertSame(2, $overview['node_count']);
        self::assertSame(2, $overview['user_count']);
        self::assertSame(2, $overview['domain_count']);
        self::assertNull($overview['source_ip_count']);
        self::assertSame(3, $overview['batch_count']);
        self::assertSame(500, $overview['quality']['unknown_domain_bytes']);
        self::assertEqualsWithDelta(0.96, $overview['quality']['domain_recognition_rate'], 0.000001);
    }

    public function testOverviewOnMinuteFactsCountsDistinctIps(): void
    {
        $overview = $this->service()->overview($this->request([], 1));

        self::assertSame('2500', $overview['total_bytes']);
        self::assertSame(1, $overview['source_ip_count']);
        self::assertSame(1, $overview['destination_ip_count']);
        self::assertSame(2, $overview['domain_count']);
    }

    public function testHealthReportsTheLatestBatchPerNode(): void
    {
        $health = $this->service()->health($this->request([], 7));

        self::assertCount(2, $health['items']);
        $byNode = array_column($health['items'], null, 'node_id');
        self::assertSame('Tokyo', $byNode[1]['node_name']);
        self::assertSame(2, $byNode[1]['batch_count']);
        self::assertSame(gmdate('Y-m-d\TH:i:s\Z', ReportFactory::minutesAgo(30)), $byNode[1]['last_bucket_start']);
        self::assertSame(500, $byNode[1]['quality']['unknown_domain_bytes']);
    }

    private function query(array $parameters, int $days): array
    {
        return $this->service()->query($this->request($parameters, $days));
    }

    private function request(array $parameters, int $days): Request
    {
        return Request::create('/traffic', 'GET', $parameters + [
            'from' => gmdate('Y-m-d\TH:i:s\Z', $this->now - $days * 86400),
            'to' => gmdate('Y-m-d\TH:i:s\Z', $this->now),
        ]);
    }

    private function service(): TrafficQueryService
    {
        return new TrafficQueryService(new TrafficQueryBuilder(), new TrafficRowDecorator());
    }

    /** @return list<array{0: mixed, 1: string, 2: string}> */
    private function summary(array $result): array
    {
        return array_map(
            static fn (array $item): array => [$item['value'], $item['label'], $item['total_bytes']],
            $result['items'],
        );
    }
}
