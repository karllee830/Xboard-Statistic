<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Plugin\XboardStatistic\Controllers\Admin\TrafficController;
use Plugin\XboardStatistic\Controllers\ReportController;
use Plugin\XboardStatistic\Middleware\DecodeStatisticsPayload;
use Plugin\XboardStatistic\Middleware\PrepareStatisticsAuth;
use Plugin\XboardStatistic\Support\AdminPath;

Route::post('/api/v2/server/statistics/report', [ReportController::class, 'store'])
    ->middleware([PrepareStatisticsAuth::class, 'server.v2', DecodeStatisticsPayload::class]);

Route::prefix(AdminPath::apiPrefix())
    ->middleware(['admin', 'log'])
    ->group(function (): void {
        Route::get('/auth-check', [TrafficController::class, 'authCheck']);
        Route::get('/overview', [TrafficController::class, 'overview']);
        Route::get('/health', [TrafficController::class, 'health']);
        Route::get('/traffic', [TrafficController::class, 'traffic']);
    });
