<?php

declare(strict_types=1);

namespace Plugin\XboardStatistic\Support;

final class AdminPath
{
    /** Resolves the Xboard admin entry path the same way the host application does. */
    public static function securePath(): string
    {
        return trim((string) admin_setting(
            'secure_path',
            admin_setting('frontend_admin_path', hash('crc32b', (string) config('app.key'))),
        ), '/');
    }

    public static function apiPrefix(): string
    {
        return '/api/v2/' . self::securePath() . '/plugin/xboard-statistic';
    }

    public static function dashboardPath(): string
    {
        return '/' . self::securePath() . '/xboard-statistic';
    }
}
