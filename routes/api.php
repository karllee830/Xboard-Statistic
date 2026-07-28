<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Plugin\XboardStatistic\Controllers\ReportController;
use Plugin\XboardStatistic\Controllers\Admin\TrafficController;
use Plugin\XboardStatistic\Middleware\DecodeStatisticsPayload;

Route::post('/api/v2/server/statistics/report', [ReportController::class, 'store'])
    ->middleware([DecodeStatisticsPayload::class, 'server.v2']);

$adminPath = admin_setting(
    'secure_path',
    admin_setting('frontend_admin_path', hash('crc32b', config('app.key'))),
);

Route::prefix('/api/v2/' . $adminPath . '/plugin/xboard-statistic')
    ->middleware(['admin', 'log'])
    ->group(function (): void {
        Route::get('/auth-check', [TrafficController::class, 'authCheck']);
        Route::get('/overview', [TrafficController::class, 'overview']);
        Route::get('/health', [TrafficController::class, 'health']);
        Route::get('/traffic', [TrafficController::class, 'traffic']);
        Route::get('/ranking/{group}', [TrafficController::class, 'ranking']);
    });
