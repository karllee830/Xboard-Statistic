<?php

declare(strict_types=1);

namespace Plugin\XboardStatistic\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;

final class MySqlRequirement
{
    /** Row aliases in INSERT ... ON DUPLICATE KEY UPDATE first shipped in 8.0.19. */
    public const MINIMUM_VERSION = '8.0.19';

    public static function assert(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver !== 'mysql') {
            throw new RuntimeException("Xboard Statistics 仅支持 MySQL " . self::MINIMUM_VERSION . "+，当前数据库驱动为 {$driver}");
        }

        $version = (string) DB::selectOne('SELECT VERSION() AS version')->version;
        if (stripos($version, 'mariadb') !== false) {
            throw new RuntimeException("Xboard Statistics 不支持 MariaDB（{$version}），请使用 MySQL " . self::MINIMUM_VERSION . '+');
        }
        if (!preg_match('/^(\d+\.\d+\.\d+)/', $version, $match)
            || version_compare($match[1], self::MINIMUM_VERSION, '<')) {
            throw new RuntimeException("Xboard Statistics 需要 MySQL " . self::MINIMUM_VERSION . "+，当前版本为 {$version}");
        }
    }
}
