<?php

declare(strict_types=1);

namespace Plugin\XboardStatistic\Support;

final class PluginInfo
{
    public const CODE = 'xboard_statistic';

    public static function version(): string
    {
        static $version;
        if ($version === null) {
            $config = json_decode((string) @file_get_contents(dirname(__DIR__) . '/config.json'), true);
            $version = is_array($config) && is_string($config['version'] ?? null) ? $config['version'] : 'unknown';
        }
        return $version;
    }
}
