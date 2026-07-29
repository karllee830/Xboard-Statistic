<?php

declare(strict_types=1);

namespace Plugin\XboardStatistic\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Plugin\XboardStatistic\Support\GeoIpRecord;

final class GeoIpRecordTest extends TestCase
{
    public function testItReadsDbIpCountryRecord(): void
    {
        self::assertSame('US', GeoIpRecord::countryCode([
            'country' => ['iso_code' => 'us'],
        ]));
    }

    public function testItReadsDbIpAsnRecord(): void
    {
        self::assertSame([
            'number' => 15169,
            'organization' => 'Google LLC',
        ], GeoIpRecord::asn([
            'autonomous_system_number' => 15169,
            'autonomous_system_organization' => 'Google LLC',
        ]));
    }

    public function testItRejectsMissingOrInvalidGeoData(): void
    {
        self::assertNull(GeoIpRecord::countryCode(null));
        self::assertNull(GeoIpRecord::countryCode(['country' => ['iso_code' => 'USA']]));
        self::assertNull(GeoIpRecord::asn(['autonomous_system_number' => 0]));
    }
}
