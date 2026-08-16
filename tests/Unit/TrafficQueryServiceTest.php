<?php

declare(strict_types=1);

namespace Plugin\XboardStatistic\Tests\Unit;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Plugin\XboardStatistic\Services\TrafficQueryService;
use Plugin\XboardStatistic\Support\IpAddress;

final class TrafficQueryServiceTest extends TestCase
{
    private Capsule $database;

    protected function setUp(): void
    {
        parent::setUp();

        $container = new Container();
        $this->database = new Capsule($container);
        $this->database->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $container->instance('db', $this->database->getDatabaseManager());
        Facade::setFacadeApplication($container);

        DB::statement('CREATE TABLE xbs_domains (
            id INTEGER PRIMARY KEY,
            exact_domain TEXT NOT NULL,
            registrable_domain TEXT NULL
        )');
        DB::statement('CREATE TABLE xbs_ip_addresses (
            id INTEGER PRIMARY KEY,
            address BLOB NOT NULL,
            country_code TEXT NULL,
            asn INTEGER NULL,
            asn_organization TEXT NULL
        )');
        DB::statement('CREATE TABLE xbs_traffic_hourly (
            id INTEGER PRIMARY KEY,
            bucket_start_unix INTEGER NOT NULL,
            node_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            domain_id INTEGER NULL,
            destination_ip_id INTEGER NULL,
            destination_ip_state INTEGER NOT NULL,
            upload_bytes INTEGER NOT NULL,
            download_bytes INTEGER NOT NULL,
            connection_count INTEGER NOT NULL,
            active_seconds INTEGER NOT NULL
        )');

        DB::table('xbs_domains')->insert([
            'id' => 1,
            'exact_domain' => 'example.com',
            'registrable_domain' => 'example.com',
        ]);
        DB::table('xbs_ip_addresses')->insert([
            [
                'id' => 1,
                'address' => IpAddress::normalize('91.108.56.128')['packed'],
                'country_code' => 'SG',
                'asn' => 62014,
                'asn_organization' => 'Telegram Messenger Inc',
            ],
            [
                'id' => 2,
                'address' => IpAddress::normalize('203.0.113.8')['packed'],
                'country_code' => 'US',
                'asn' => 64500,
                'asn_organization' => 'Example Network',
            ],
        ]);
        DB::table('xbs_traffic_hourly')->insert([
            $this->trafficRow(1, null, 1, 100, 900),
            $this->trafficRow(2, null, 2, 30, 270),
            $this->trafficRow(3, 1, 2, 50, 450),
        ]);
    }

    protected function tearDown(): void
    {
        DB::disconnect();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);

        parent::tearDown();
    }

    public function testUnknownDomainUsesTheLargestDestinationAsItsDisplaySummary(): void
    {
        $result = (new TrafficQueryService())->query($this->request(['group_by' => 'domain']));
        $unknown = array_values(array_filter(
            $result['items'],
            static fn (array $item): bool => $item['value'] === 'unknown',
        ))[0];

        self::assertTrue($unknown['unknown_domain']);
        self::assertSame('91.108.56.128', $unknown['label']);
        self::assertSame('91.108.56.128', $unknown['representative_destination']['ip']);
        self::assertSame(62014, $unknown['representative_destination']['asn']);
        self::assertSame('Telegram Messenger Inc', $unknown['representative_destination']['asn_organization']);
        self::assertSame('1000', $unknown['representative_destination']['total_bytes']);
        self::assertSame(2, $unknown['representative_destination']['destination_ip_count']);
        self::assertSame(2, $unknown['representative_destination']['destination_asn_count']);
    }

    public function testUnknownDomainFilterKeepsTheWholeGroupForDestinationDrillDown(): void
    {
        $result = (new TrafficQueryService())->query($this->request([
            'group_by' => 'destination_ip',
            'domain' => 'unknown',
        ]));

        self::assertSame('1300', $result['grand_total_bytes']);
        self::assertSame(2, $result['total']);
        self::assertSame('91.108.56.128', $result['items'][0]['value']);
        self::assertSame('1000', $result['items'][0]['total_bytes']);
    }

    private function request(array $query): Request
    {
        return Request::create('/', 'GET', $query + [
            'from' => '2023-11-14T00:00:00Z',
            'to' => '2023-11-15T00:00:00Z',
            'node_id' => 1,
            'user_id' => 1,
            'current' => 1,
            'pageSize' => 50,
        ]);
    }

    private function trafficRow(int $id, ?int $domainId, int $destinationIpId, int $upload, int $download): array
    {
        return [
            'id' => $id,
            'bucket_start_unix' => 1_700_000_000 + $id * 60,
            'node_id' => 1,
            'user_id' => 1,
            'domain_id' => $domainId,
            'destination_ip_id' => $destinationIpId,
            'destination_ip_state' => 1,
            'upload_bytes' => $upload,
            'download_bytes' => $download,
            'connection_count' => 1,
            'active_seconds' => 60,
        ];
    }
}
