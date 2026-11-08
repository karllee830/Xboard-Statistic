<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Plugin\XboardStatistic\Controllers\Admin\DashboardController;
use Plugin\XboardStatistic\Support\AdminPath;

Route::get(AdminPath::dashboardPath(), [DashboardController::class, 'index'])
    ->name('xboard-statistic.dashboard');
