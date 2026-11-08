<?php

declare(strict_types=1);

namespace Plugin\XboardStatistic\Support;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use Throwable;

final class UtcTime
{
    public const ISO_FORMAT = 'Y-m-d\TH:i:s\Z';

    public static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', self::timezone());
    }

    public static function fromUnix(int $timestamp): DateTimeImmutable
    {
        return (new DateTimeImmutable('@' . $timestamp))->setTimezone(self::timezone());
    }

    public static function parseApi(string $value): DateTimeImmutable
    {
        if (!preg_match(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2}(?:\.\d{1,6})?)?(?:Z|[+-]\d{2}:\d{2})$/i',
            $value,
        )) {
            throw new InvalidArgumentException('时间必须是包含时区的 ISO 8601 格式');
        }

        try {
            $time = new DateTimeImmutable($value);
        } catch (Throwable $exception) {
            throw new InvalidArgumentException('时间不是有效的 ISO 8601 时间', previous: $exception);
        }
        $errors = DateTimeImmutable::getLastErrors();
        if (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
            throw new InvalidArgumentException('时间包含无效的日期或时刻');
        }

        return $time->setTimezone(self::timezone());
    }

    public static function iso(DateTimeInterface|int $value): string
    {
        $timestamp = is_int($value) ? $value : $value->getTimestamp();
        return self::fromUnix($timestamp)->format(self::ISO_FORMAT);
    }

    public static function isoOrNull(mixed $timestamp): ?string
    {
        return $timestamp === null ? null : self::iso((int) $timestamp);
    }

    public static function floorHour(int $timestamp): int
    {
        return intdiv($timestamp, 3600) * 3600;
    }

    public static function ceilHour(int $timestamp): int
    {
        return intdiv($timestamp + 3599, 3600) * 3600;
    }

    public static function floorDay(int $timestamp): int
    {
        return intdiv($timestamp, 86400) * 86400;
    }

    private static function timezone(): DateTimeZone
    {
        static $timezone;
        return $timezone ??= new DateTimeZone('UTC');
    }
}
