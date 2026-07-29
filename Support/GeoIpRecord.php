<?php

declare(strict_types=1);

namespace Plugin\XboardStatistic\Support;

final class GeoIpRecord
{
    public static function countryCode(mixed $record): ?string
    {
        if (!is_array($record)) {
            return null;
        }

        $code = strtoupper(trim((string) ($record['country']['iso_code'] ?? '')));
        return preg_match('/^[A-Z]{2}$/', $code) === 1 ? $code : null;
    }

    /**
     * @return array{number: int, organization: string}|null
     */
    public static function asn(mixed $record): ?array
    {
        if (!is_array($record)) {
            return null;
        }

        $number = filter_var(
            $record['autonomous_system_number'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]],
        );
        if ($number === false) {
            return null;
        }

        return [
            'number' => (int) $number,
            'organization' => mb_substr(
                trim((string) ($record['autonomous_system_organization'] ?? '')),
                0,
                255,
            ),
        ];
    }
}
