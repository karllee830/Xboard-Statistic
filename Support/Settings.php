<?php

declare(strict_types=1);

namespace Plugin\XboardStatistic\Support;

use Closure;

/**
 * Typed, range-checked access to the plugin configuration declared in config.json.
 */
final class Settings
{
    private function __construct(private readonly Closure $get)
    {
    }

    /** @param callable(string, mixed): mixed $getter */
    public static function fromGetter(callable $getter): self
    {
        return new self(Closure::fromCallable($getter));
    }

    /** Builds settings from an Xboard plugin instance, or defaults when the plugin is not enabled. */
    public static function fromPlugin(?object $plugin): self
    {
        return self::fromGetter(
            static fn (string $key, mixed $default): mixed => $plugin === null
                ? $default
                : ($plugin->getConfig($key, $default) ?? $default),
        );
    }

    public function minuteRetentionDays(): int
    {
        return $this->int('minute_retention_days', 30, 7, 90);
    }

    public function rollupRetentionDays(): int
    {
        return max($this->minuteRetentionDays(), $this->int('rollup_retention_days', 365, 30, 730));
    }

    public function batchRetentionDays(): int
    {
        return $this->int('batch_retention_days', 90, 30, 365);
    }

    /** Late reports must still land inside the minute retention window. */
    public function maxReportAgeDays(): int
    {
        return min($this->minuteRetentionDays(), $this->int('max_report_age_days', 14, 1, 90));
    }

    public function maxRecordsPerBatch(): int
    {
        return $this->int('max_records_per_batch', 200000, 1, 200000);
    }

    public function maskSourceIp(): bool
    {
        return (bool) ($this->get)('mask_source_ip', false);
    }

    public function countryMmdbOverride(): string
    {
        return trim((string) ($this->get)('geoip_country_mmdb', ''));
    }

    public function asnMmdbOverride(): string
    {
        return trim((string) ($this->get)('geoip_asn_mmdb', ''));
    }

    private function int(string $key, int $default, int $min, int $max): int
    {
        $value = ($this->get)($key, $default);
        $value = is_numeric($value) ? (int) $value : $default;
        return max($min, min($max, $value));
    }
}
