<?php

declare(strict_types=1);

namespace Plugin\XboardStatistic\Controllers\Admin;

use App\Http\Controllers\PluginController;
use Illuminate\Contracts\View\View;
use Plugin\XboardStatistic\Support\AdminPath;

final class DashboardController extends PluginController
{
    public function index(): View
    {
        $adminUrl = url('/' . AdminPath::securePath());

        return view('XboardStatistic::dashboard', [
            'apiBase' => url(AdminPath::apiPrefix()),
            'adminUrl' => $adminUrl,
            'dashboardUrl' => url(AdminPath::dashboardPath()),
            'loginUrl' => $adminUrl . '#/sign-in',
        ]);
    }
}
