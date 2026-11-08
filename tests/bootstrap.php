<?php

declare(strict_types=1);

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "Run `composer install` before running the tests.\n");
    exit(1);
}
require $autoload;

if (!class_exists(\PHPUnit\Framework\TestCase::class)) {
    fwrite(STDERR, "PHPUnit is missing: install the development dependencies with `composer install`.\n");
    exit(1);
}
