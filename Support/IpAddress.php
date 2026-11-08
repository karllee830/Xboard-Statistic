<?php

declare(strict_types=1);

namespace Plugin\XboardStatistic\Support;

use InvalidArgumentException;

final class IpAddress
{
    private const IPV4_MAPPED_PREFIX = "\0\0\0\0\0\0\0\0\0\0\xff\xff";

    /**
     * @return array{packed: ?string, version: int, kind: 'known'|'other'}|null
     */
    public static function normalize(?string $address): ?array
    {
        if ($address === null || $address === '' || $address === 'unknown') {
            return null;
        }
        if ($address === '__other__') {
            return ['packed' => null, 'version' => 0, 'kind' => 'other'];
        }

        $packed = @inet_pton($address);
        if ($packed === false) {
            throw new InvalidArgumentException('IP 地址格式无效');
        }
        if (strlen($packed) === 4) {
            $packed = self::IPV4_MAPPED_PREFIX . $packed;
        }

        return [
            'packed' => $packed,
            'version' => str_starts_with($packed, self::IPV4_MAPPED_PREFIX) ? 4 : 6,
            'kind' => 'known',
        ];
    }

    public static function display(string $packed): string
    {
        if (strlen($packed) !== 16) {
            throw new InvalidArgumentException('数据库 IP 必须为 16 字节');
        }
        if (str_starts_with($packed, self::IPV4_MAPPED_PREFIX)) {
            return (string) inet_ntop(substr($packed, 12));
        }

        return strtolower((string) inet_ntop($packed));
    }

    public static function masked(string $address): string
    {
        $normalized = @inet_pton($address);
        if ($normalized === false) {
            return $address;
        }
        if (strlen($normalized) === 4) {
            return inet_ntop(substr($normalized, 0, 3) . "\0") . '/24';
        }

        return strtolower((string) inet_ntop(substr($normalized, 0, 6) . str_repeat("\0", 10))) . '/48';
    }
}
