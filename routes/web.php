<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Plugin\XboardStatistic\Controllers\Admin\DashboardController;

Route::get('/xboard-statistic', [DashboardController::class, 'index'])
    ->name('xboard-statistic.dashboard');
