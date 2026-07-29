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
    public const DATABASE_FORMAT = 'Y-m-d H:i:s';
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
            $errors = DateTimeImmutable::getLastErrors();
            if (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
                throw new InvalidArgumentException('时间包含无效的日期或时刻');
            }
            return $time->setTimezone(self::timezone());
        } catch (Throwable $exception) {
            if ($exception instanceof InvalidArgumentException) {
                throw $exception;
            }
            throw new InvalidArgumentException('时间不是有效的 ISO 8601 时间', previous: $exception);
        }
    }

    public static function parseStoredUtc(mixed $value): DateTimeImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return self::fromUnix($value->getTimestamp());
        }

        return (new DateTimeImmutable((string) $value, self::timezone()))
            ->setTimezone(self::timezone());
    }

    public static function database(DateTimeInterface $value): string
    {
        return self::fromUnix($value->getTimestamp())->format(self::DATABASE_FORMAT);
    }

    public static function iso(DateTimeInterface $value): string
    {
        return self::fromUnix($value->getTimestamp())->format(self::ISO_FORMAT);
    }

    private static function timezone(): DateTimeZone
    {
        static $timezone;
        return $timezone ??= new DateTimeZone('UTC');
    }
}
