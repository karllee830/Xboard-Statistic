<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Plugin\XboardStatistic\Controllers\Admin\DashboardController;

$adminPath = admin_setting(
    'secure_path',
    admin_setting('frontend_admin_path', hash('crc32b', config('app.key'))),
);

Route::get('/' . $adminPath . '/xboard-statistic', [DashboardController::class, 'index'])
    ->name('xboard-statistic.dashboard');
