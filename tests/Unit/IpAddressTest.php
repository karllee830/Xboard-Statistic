<?php

declare(strict_types=1);

namespace Plugin\XboardStatistic\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Plugin\XboardStatistic\Support\IpAddress;

final class IpAddressTest extends TestCase
{
    #[DataProvider('addresses')]
    public function testItRoundTripsIpv4AndIpv6(string $input, string $expected, int $version): void
    {
        $normalized = IpAddress::normalize($input);

        self::assertNotNull($normalized);
        self::assertSame(16, strlen($normalized['packed']));
        self::assertSame($version, $normalized['version']);
        self::assertSame($expected, IpAddress::display($normalized['packed']));
    }

    public static function addresses(): iterable
    {
        yield 'IPv4' => ['198.51.100.7', '198.51.100.7', 4];
        yield 'IPv4-mapped IPv6' => ['::ffff:198.51.100.7', '198.51.100.7', 4];
        yield 'IPv6 canonicalized' => ['2001:0DB8:0:0::8', '2001:db8::8', 6];
    }

    public function testIpv4AndMappedFormsShareOneStoredValue(): void
    {
        self::assertSame(
            IpAddress::normalize('198.51.100.7')['packed'],
            IpAddress::normalize('::FFFF:198.51.100.7')['packed'],
        );
    }

    public function testUnknownAddressBecomesNull(): void
    {
        self::assertNull(IpAddress::normalize(null));
        self::assertNull(IpAddress::normalize('unknown'));
    }

    public function testOtherAddressUsesExplicitSentinel(): void
    {
        $normalized = IpAddress::normalize('__other__');

        self::assertSame('other', $normalized['kind']);
        self::assertNull($normalized['packed']);
    }

    public function testInvalidAddressIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        IpAddress::normalize('999.1.1.1');
    }

    public function testItMasksIpv4AndIpv6ToNetworkPrefixes(): void
    {
        self::assertSame('203.0.113.0/24', IpAddress::masked('203.0.113.42'));
        self::assertSame('2001:db8:abcd::/48', IpAddress::masked('2001:db8:abcd:1234::1'));
    }
}
