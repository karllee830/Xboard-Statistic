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
        self::assertSame('2026-07-28 16:54:30', UtcTime::database($time));
    }

    public function testUnixTimestampRoundTripsWithoutServerTimezone(): void
    {
        $timestamp = 1785257670;

        self::assertSame($timestamp, UtcTime::fromUnix($timestamp)->getTimestamp());
        self::assertSame('2026-07-28T16:54:30Z', UtcTime::iso(UtcTime::fromUnix($timestamp)));
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
