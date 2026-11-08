<?php

declare(strict_types=1);

namespace Plugin\XboardStatistic\Tests;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Plugin\XboardStatistic\Support\Tables;

/**
 * Runs against a disposable MySQL 8 database configured through XBS_TEST_DB_* variables.
 * The plugin schema is rebuilt once per test class and truncated before each test.
 */
abstract class DatabaseTestCase extends TestCase
{
    private const PLUGIN_TABLES = [
        Tables::HOUR_NODE_USER_DOMAIN,
        Tables::HOUR_NODE_USER,
        Tables::MINUTE,
        Tables::OUTBOUNDS,
        Tables::IP_ADDRESSES,
        Tables::DOMAINS,
        Tables::BATCHES,
    ];

    private static ?Capsule $capsule = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::connect();

        foreach ([...self::PLUGIN_TABLES, 'v2_server', 'v2_user'] as $table) {
            DB::statement("DROP TABLE IF EXISTS {$table}");
        }
        self::migration()->up();
        DB::statement('CREATE TABLE v2_server (id INT UNSIGNED PRIMARY KEY, name VARCHAR(255) NOT NULL)');
        DB::statement('CREATE TABLE v2_user (id INT UNSIGNED PRIMARY KEY, email VARCHAR(255) NOT NULL)');
    }

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([...self::PLUGIN_TABLES, 'v2_server', 'v2_user'] as $table) {
            DB::statement("TRUNCATE TABLE {$table}");
        }
    }

    protected static function migration(): object
    {
        return require dirname(__DIR__) . '/database/migrations/2026_11_08_000001_create_xboard_statistic_schema.php';
    }

    private static function connect(): void
    {
        if (self::$capsule !== null) {
            return;
        }

        $container = new Container();
        $capsule = new Capsule($container);
        $capsule->addConnection([
            'driver' => 'mysql',
            'host' => getenv('XBS_TEST_DB_HOST') ?: '127.0.0.1',
            'port' => (int) (getenv('XBS_TEST_DB_PORT') ?: 3306),
            'database' => getenv('XBS_TEST_DB_DATABASE') ?: 'xbs_test',
            'username' => getenv('XBS_TEST_DB_USERNAME') ?: 'root',
            'password' => getenv('XBS_TEST_DB_PASSWORD') ?: '',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
        ]);
        $capsule->setAsGlobal();
        $container->instance('db', $capsule->getDatabaseManager());
        Facade::setFacadeApplication($container);
        self::$capsule = $capsule;
    }
}
