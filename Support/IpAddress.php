<?php

declare(strict_types=1);

namespace Plugin\XboardStatistic\Support;

use InvalidArgumentException;

final class IpAddress
{
    public static function normalize(?string $address): ?array
    {
        if ($address === null || $address === '' || $address === 'unknown') {
            return null;
        }
        if ($address === '__other__') {
            return [
                'key' => '__other__',
                'packed' => null,
                'version' => 0,
                'kind' => 'other',
            ];
        }

        $packed = @inet_pton($address);
        if ($packed === false) {
            throw new InvalidArgumentException('IP 地址格式无效');
        }

        if (strlen($packed) === 4) {
            return [
                'key' => inet_ntop($packed),
                'packed' => str_repeat("\0", 10) . "\xff\xff" . $packed,
                'version' => 4,
                'kind' => 'known',
            ];
        }

        return [
            'key' => strtolower((string) inet_ntop($packed)),
            'packed' => $packed,
            'version' => 6,
            'kind' => 'known',
        ];
    }

    public static function display(string $packed): string
    {
        if (strlen($packed) !== 16) {
            throw new InvalidArgumentException('数据库 IP 必须为 16 字节');
        }

        if (substr($packed, 0, 12) === str_repeat("\0", 10) . "\xff\xff") {
            return (string) inet_ntop(substr($packed, 12));
        }

        return strtolower((string) inet_ntop($packed));
    }

    public static function masked(string $address): string
    {
        $packed = @inet_pton($address);
        if ($packed === false) {
            return $address;
        }
        if (strlen($packed) === 4) {
            return implode('.', array_slice(explode('.', $address), 0, 3)) . '.0/24';
        }
        $masked = substr($packed, 0, 6) . str_repeat("\0", 10);
        return strtolower((string) inet_ntop($masked)) . '/48';
    }
}
