<?php

declare(strict_types=1);

namespace Plugin\XboardStatistic\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Plugin\XboardStatistic\Support\UtcTime;

final class UtcTimeTest extends TestCase
{
    public function testItNormalizesOffsetApiTimeToUtc(): void
    {
        $time = UtcTime::parseApi('2026-07-29T00:54:30+08:00');

        self::assertSame(1785257670, $time->getTimestamp());
        self::assertSame('2026-07-28T16:54:30Z', UtcTime::iso($time));
    }

    public function testUnixTimestampRoundTripsWithoutServerTimezone(): void
    {
        self::assertSame('2026-07-28T16:54:30Z', UtcTime::iso(1785257670));
        self::assertSame(1785257670, UtcTime::fromUnix(1785257670)->getTimestamp());
        self::assertNull(UtcTime::isoOrNull(null));
    }

    public function testHourAndDayBoundaries(): void
    {
        self::assertSame(1785254400, UtcTime::floorHour(1785257670));
        self::assertSame(1785258000, UtcTime::ceilHour(1785257670));
        self::assertSame(1785254400, UtcTime::ceilHour(1785254400));
        self::assertSame(1785196800, UtcTime::floorDay(1785257670));
    }

    public function testApiTimeMustContainAnExplicitTimezone(): void
    {
        $this->expectException(InvalidArgumentException::class);
        UtcTime::parseApi('2026-07-29T00:54:30');
    }

    public function testApiTimeRejectsAnInvalidCalendarDate(): void
    {
        $this->expectException(InvalidArgumentException::class);
        UtcTime::parseApi('2026-02-30T00:00:00Z');
    }
}
