<?php

declare(strict_types=1);

namespace Plugin\XboardStatistic\Controllers\Admin;

use App\Http\Controllers\PluginController;
use Illuminate\Contracts\View\View;

final class DashboardController extends PluginController
{
    public function index(): View
    {
        $securePath = admin_setting(
            'secure_path',
            admin_setting('frontend_admin_path', hash('crc32b', config('app.key'))),
        );

        return view('XboardStatistic::dashboard', [
            'apiBase' => url('/api/v2/' . $securePath . '/plugin/xboard-statistic'),
            'adminUrl' => url('/' . $securePath),
        ]);
    }
}
