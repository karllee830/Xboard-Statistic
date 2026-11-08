<?php

declare(strict_types=1);

namespace Plugin\XboardStatistic\Middleware;

use Closure;
use Illuminate\Http\Request;
use Plugin\XboardStatistic\Support\AdminPath;
use Symfony\Component\HttpFoundation\Response;

final class InjectAdminDashboardNavigation
{
    private const SCRIPT_MARKER = 'data-xboard-statistic-navigation';

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $securePath = AdminPath::securePath();

        if (trim($request->path(), '/') !== $securePath || !$this->isHtml($response)) {
            return $response;
        }

        $content = $response->getContent();
        if (!is_string($content)
            || !str_contains(strtolower($content), '</body>')
            || str_contains($content, self::SCRIPT_MARKER)) {
            return $response;
        }

        $dashboardUrl = url(AdminPath::dashboardPath());
        $script = $this->navigationScript($dashboardUrl);
        $position = strripos($content, '</body>');
        if ($position === false) {
            return $response;
        }

        $response->setContent(substr($content, 0, $position) . $script . substr($content, $position));
        $response->headers->remove('Content-Length');

        return $response;
    }

    private function isHtml(Response $response): bool
    {
        return str_contains(strtolower((string) $response->headers->get('Content-Type')), 'text/html');
    }

    private function navigationScript(string $dashboardUrl): string
    {
        $url = json_encode(
            $dashboardUrl,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES,
        );

        return <<<HTML
<script data-xboard-statistic-navigation>
(() => {
    const dashboardUrl = {$url};
    const returnKey = 'XboardStatistic_return_after_login';
    const tokenKey = 'XBOARD_ACCESS_TOKEN';

    function hasValidToken() {
        try {
            const stored = JSON.parse(localStorage.getItem(tokenKey) || '{}');
            if (!stored.value || (stored.expire && Number(stored.expire) <= Date.now())) return false;
            return true;
        } catch (_) {
            return false;
        }
    }

    function pendingReturn() {
        try { return sessionStorage.getItem(returnKey) === dashboardUrl; } catch (_) { return false; }
    }

    function resumeAfterLogin() {
        if (!pendingReturn() || !hasValidToken()) return false;
        sessionStorage.removeItem(returnKey);
        window.location.assign(dashboardUrl);
        return true;
    }

    // Poll only while a login started from the dashboard is waiting to return there.
    if (pendingReturn() && !resumeAfterLogin()) {
        const resumeTimer = window.setInterval(() => {
            if (!pendingReturn() || resumeAfterLogin()) window.clearInterval(resumeTimer);
        }, 300);
        window.addEventListener('pagehide', () => window.clearInterval(resumeTimer), {once: true});
    }

    document.addEventListener('click', event => {
        const link = event.target instanceof Element ? event.target.closest('a') : null;
        if (!link) return;

        const rawHref = link.getAttribute('href') || '';
        let decodedHref = rawHref;
        try { decodedHref = decodeURIComponent(rawHref); } catch (_) {}

        let resolved;
        try { resolved = new URL(link.href, window.location.href); } catch (_) { return; }
        const hashPath = resolved.hash.replace(/^#/, '').split('?')[0].replace(/\/+$/, '');
        const pathname = resolved.pathname.replace(/\/+$/, '');
        const isStatisticMenu = decodedHref.includes('/plugins/xboard_statistic/')
            && decodedHref.includes('xboard-statistic');
        const isResolvedTarget = hashPath.endsWith('/xboard-statistic')
            || pathname.endsWith('/xboard-statistic');

        if (!isStatisticMenu && !isResolvedTarget) return;

        event.preventDefault();
        event.stopImmediatePropagation();
        window.location.assign(dashboardUrl);
    }, true);
})();
</script>
HTML;
    }
}
