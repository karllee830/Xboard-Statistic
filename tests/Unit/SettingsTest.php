<?php

declare(strict_types=1);

namespace Plugin\XboardStatistic\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Plugin\XboardStatistic\Support\Settings;

final class SettingsTest extends TestCase
{
    public function testDefaultsApplyWithoutAPlugin(): void
    {
        $settings = Settings::fromPlugin(null);

        self::assertSame(30, $settings->minuteRetentionDays());
        self::assertSame(365, $settings->rollupRetentionDays());
        self::assertSame(90, $settings->batchRetentionDays());
        self::assertSame(14, $settings->maxReportAgeDays());
        self::assertSame(200000, $settings->maxRecordsPerBatch());
        self::assertFalse($settings->maskSourceIp());
    }

    public function testValuesAreClampedToTheirRanges(): void
    {
        $settings = $this->settings([
            'minute_retention_days' => 500,
            'rollup_retention_days' => 1,
            'max_report_age_days' => 0,
            'max_records_per_batch' => '999999',
        ]);

        self::assertSame(90, $settings->minuteRetentionDays());
        self::assertSame(90, $settings->rollupRetentionDays(), 'rollups never expire before minute facts');
        self::assertSame(1, $settings->maxReportAgeDays());
        self::assertSame(200000, $settings->maxRecordsPerBatch());
    }

    public function testLateReportsCannotOutliveMinuteRetention(): void
    {
        $settings = $this->settings(['minute_retention_days' => 7, 'max_report_age_days' => 14]);

        self::assertSame(7, $settings->maxReportAgeDays());
    }

    public function testNonNumericValuesFallBackToDefaults(): void
    {
        self::assertSame(30, $this->settings(['minute_retention_days' => 'abc'])->minuteRetentionDays());
    }

    private function settings(array $values): Settings
    {
        return Settings::fromGetter(static fn (string $key, mixed $default): mixed => $values[$key] ?? $default);
    }
}
