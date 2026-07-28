<?php

declare(strict_types=1);

$candidates = array_filter([
    getenv('XBOARD_PATH') ?: null,
    getcwd(),
    dirname(__DIR__, 3),
]);

foreach ($candidates as $candidate) {
    $autoload = rtrim($candidate, DIRECTORY_SEPARATOR) . '/vendor/autoload.php';
    if (is_file($autoload)) {
        require $autoload;
        return;
    }
}

fwrite(STDERR, "Cannot find Xboard vendor/autoload.php. Set XBOARD_PATH or run PHPUnit from Xboard root.\n");
exit(1);
