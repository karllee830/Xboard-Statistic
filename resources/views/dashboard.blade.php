<!doctype html>
<html lang="zh-CN" class="auth-pending">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Xboard 流量分析</title>
    <script>
        window.XboardStatisticAuth = (() => {
            const apiBase = @json($apiBase);
            const dashboardUrl = @json($dashboardUrl);
            const loginUrl = @json($loginUrl);
            const tokenKey = 'XBOARD_ACCESS_TOKEN';
            const returnKey = 'XboardStatistic_return_after_login';

            function token() {
                try {
                    const stored = JSON.parse(localStorage.getItem(tokenKey) || '{}');
                    if (!stored.value || (stored.expire && Number(stored.expire) <= Date.now())) {
                        localStorage.removeItem(tokenKey);
                        return '';
                    }
                    return String(stored.value);
                } catch (_) {
                    localStorage.removeItem(tokenKey);
                    return '';
                }
            }

            function reveal() {
                document.documentElement.classList.remove('auth-pending');
            }

            function redirectToLogin() {
                try {
                    localStorage.removeItem(tokenKey);
                    sessionStorage.setItem(returnKey, dashboardUrl);
                } catch (_) {}
                window.location.replace(loginUrl);
                return new Promise(() => {});
            }

            const ready = (async () => {
                const authorization = token();
                if (!authorization) return redirectToLogin();

                try {
                    const response = await fetch(`${apiBase}/auth-check`, {
                        cache: 'no-store',
                        headers: {Authorization: authorization, Accept: 'application/json'},
                    });
                    if (response.status === 401 || response.status === 403) return redirectToLogin();
                } catch (_) {
                    // 网络或服务异常交给页面的常规错误状态展示，不把它误判为未登录。
                }

                reveal();
                return true;
            })();

            return {ready, token, redirectToLogin};
        })();
    </script>
    <style>
        html.auth-pending body { visibility: hidden; }
        :root {
            color-scheme: light dark;
            --page: #f6f7f9;
            --surface: #ffffff;
            --surface-subtle: #f9fafb;
            --text: #172033;
            --text-strong: #101828;
            --muted: #667085;
            --muted-strong: #475467;
            --line: #e4e7ec;
            --line-strong: #d0d5dd;
            --brand: #155eef;
            --brand-hover: #004eeb;
            --brand-soft: #eef4ff;
            --download: #06aed4;
            --warning: #b54708;
            --warning-soft: #fffaeb;
            --danger: #b42318;
            --danger-soft: #fef3f2;
            --success: #027a48;
            --success-soft: #ecfdf3;
            --shadow: 0 1px 2px rgb(16 24 40 / 4%);
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --page: #0b0f16;
                --surface: #121822;
                --surface-subtle: #171e29;
                --text: #e7ecf3;
                --text-strong: #f5f7fa;
                --muted: #98a2b3;
                --muted-strong: #c5ccd6;
                --line: #293140;
                --line-strong: #3a4556;
                --brand: #84adff;
                --brand-hover: #a4c3ff;
                --brand-soft: #172747;
                --download: #36c5e5;
                --warning: #fdb022;
                --warning-soft: #332817;
                --danger: #f97066;
                --danger-soft: #321c1e;
                --success: #32d583;
                --success-soft: #143025;
                --shadow: none;
            }
        }

        * { box-sizing: border-box; }
        html { min-width: 320px; }
        body {
            margin: 0;
            background: var(--page);
            color: var(--text);
            font: 14px/1.5 ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", "PingFang SC", "Microsoft YaHei", sans-serif;
            -webkit-font-smoothing: antialiased;
        }
        button, input, select { font: inherit; }
        button, a, input, select { -webkit-tap-highlight-color: transparent; }
        button { color: inherit; }
        a { color: var(--brand); text-decoration: none; }
        a:hover { text-decoration: underline; }
        h1, h2, h3, p { margin: 0; }
        .hidden { display: none !important; }
        .num { font-variant-numeric: tabular-nums; }

        .app-shell { max-width: 1520px; margin: 0 auto; padding: 24px 28px 44px; }
        .topbar {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 24px;
            margin-bottom: 20px;
        }
        .eyebrow { margin-bottom: 4px; color: var(--muted); font-size: 12px; font-weight: 650; letter-spacing: .08em; text-transform: uppercase; }
        h1 { color: var(--text-strong); font-size: 26px; line-height: 1.25; letter-spacing: -.02em; }
        .subtitle { margin-top: 6px; color: var(--muted); }
        .top-actions { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; justify-content: flex-end; }

        .button, .icon-button {
            min-height: 38px;
            border: 1px solid var(--line-strong);
            border-radius: 8px;
            background: var(--surface);
            padding: 8px 12px;
            cursor: pointer;
            font-weight: 650;
            transition: border-color .16s ease, background .16s ease, color .16s ease;
        }
        .button:hover, .icon-button:hover { border-color: var(--muted); background: var(--surface-subtle); }
        .button:focus-visible, .icon-button:focus-visible, input:focus-visible, select:focus-visible, .row-button:focus-visible, .summary-action:focus-visible {
            outline: 3px solid color-mix(in srgb, var(--brand) 28%, transparent);
            outline-offset: 2px;
        }
        .button.primary { border-color: var(--brand); background: var(--brand); color: #fff; }
        .button.primary:hover { border-color: var(--brand-hover); background: var(--brand-hover); }
        .button.quiet { border-color: transparent; background: transparent; color: var(--muted-strong); }
        .button.small { min-height: 32px; padding: 5px 9px; font-size: 13px; }

        .tabs, .segment {
            display: inline-flex;
            align-items: center;
            gap: 3px;
            border: 1px solid var(--line);
            border-radius: 9px;
            background: var(--surface-subtle);
            padding: 3px;
        }
        .tab, .segment button {
            min-height: 32px;
            border: 0;
            border-radius: 6px;
            background: transparent;
            padding: 6px 11px;
            color: var(--muted);
            cursor: pointer;
            font-weight: 650;
        }
        .tab[aria-selected="true"], .segment button[aria-pressed="true"] {
            background: var(--surface);
            color: var(--text-strong);
            box-shadow: var(--shadow);
        }
        .segment button:disabled { color: var(--muted); cursor: not-allowed; opacity: .48; }

        .notice {
            margin-bottom: 16px;
            border: 1px solid color-mix(in srgb, var(--warning) 32%, var(--line));
            border-radius: 10px;
            background: var(--warning-soft);
            padding: 12px 14px;
            color: var(--warning);
        }

        .control-bar {
            position: sticky;
            top: 0;
            z-index: 12;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 16px;
            border: 1px solid var(--line);
            border-radius: 12px;
            background: color-mix(in srgb, var(--surface) 96%, transparent);
            padding: 10px 12px;
            box-shadow: var(--shadow);
            backdrop-filter: blur(12px);
        }
        .control-left, .control-right, .date-controls { display: flex; align-items: center; gap: 8px; }
        .control-label { color: var(--muted); font-size: 12px; font-weight: 650; }
        .timezone-label { color: var(--muted); font-size: 11px; white-space: nowrap; }
        input, select {
            min-height: 36px;
            border: 1px solid var(--line-strong);
            border-radius: 8px;
            background: var(--surface);
            padding: 7px 9px;
            color: var(--text);
        }
        input::placeholder { color: var(--muted); }
        input[type="datetime-local"] { width: 184px; }
        .preset { min-width: 58px; }
        .preset[aria-pressed="true"] { border-color: color-mix(in srgb, var(--brand) 40%, var(--line)); background: var(--brand-soft); color: var(--brand); }
        .filter-count {
            display: inline-flex;
            min-width: 19px;
            height: 19px;
            align-items: center;
            justify-content: center;
            margin-left: 5px;
            border-radius: 999px;
            background: var(--brand-soft);
            color: var(--brand);
            font-size: 11px;
        }

        .filter-chips { display: flex; gap: 7px; flex-wrap: wrap; margin: -4px 0 16px; }
        .filter-chip {
            min-height: 30px;
            border: 1px solid color-mix(in srgb, var(--brand) 24%, var(--line));
            border-radius: 999px;
            background: var(--brand-soft);
            padding: 4px 9px;
            color: var(--brand);
            cursor: pointer;
            font-size: 12px;
            font-weight: 600;
        }

        .analysis-layout { display: block; }
        .workspace { min-width: 0; }
        .context-row { display: flex; min-height: 36px; align-items: center; justify-content: space-between; gap: 14px; margin-bottom: 10px; }
        .context-path { display: flex; min-width: 0; align-items: center; gap: 10px; }
        .context-label { flex: 0 0 auto; color: var(--muted); font-size: 12px; font-weight: 650; }
        .breadcrumbs { display: flex; min-width: 0; align-items: center; gap: 7px; flex-wrap: wrap; }
        .crumb { border: 0; background: transparent; padding: 3px 0; color: var(--muted); cursor: pointer; }
        .crumb:hover { color: var(--brand); }
        .crumb.current { color: var(--text-strong); font-weight: 700; }
        .crumb-separator { color: var(--line-strong); }
        .query-status { color: var(--muted); font-size: 12px; white-space: nowrap; }
        .query-status.error { color: var(--danger); }

        .summary-band {
            display: grid;
            grid-template-columns: minmax(240px, 1.3fr) repeat(4, minmax(115px, .6fr));
            margin-bottom: 16px;
            border: 1px solid var(--line);
            border-radius: 12px;
            background: var(--surface);
            box-shadow: var(--shadow);
        }
        .summary-primary, .summary-stat { padding: 18px 20px; }
        .summary-stat { border-left: 1px solid var(--line); }
        .summary-action { border-top: 0; border-right: 0; border-bottom: 0; background: transparent; color: inherit; cursor: pointer; text-align: left; transition: background .14s ease; }
        .summary-action:hover { background: var(--surface-subtle); }
        .summary-action[aria-pressed="true"] { background: var(--brand-soft); }
        .summary-action[aria-pressed="true"] .summary-label,
        .summary-action[aria-pressed="true"] .summary-value { color: var(--brand); }
        .summary-label { color: var(--muted); font-size: 12px; font-weight: 650; }
        .summary-value { margin-top: 4px; color: var(--text-strong); font-size: 20px; font-weight: 730; letter-spacing: -.02em; }
        .summary-primary .summary-value { font-size: 30px; }
        .traffic-split { display: flex; gap: 14px; margin-top: 8px; color: var(--muted); font-size: 12px; }
        .upload-text { color: var(--brand); }
        .download-text { color: var(--download); }

        .surface {
            border: 1px solid var(--line);
            border-radius: 12px;
            background: var(--surface);
            box-shadow: var(--shadow);
        }
        .trend-panel { padding: 18px 20px 14px; }
        .surface-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 14px; margin-bottom: 14px; }
        .surface-head h2 { color: var(--text-strong); font-size: 16px; }
        .surface-kicker { margin-top: 3px; color: var(--muted); font-size: 12px; }
        .legend { display: flex; align-items: center; gap: 14px; color: var(--muted); font-size: 12px; }
        .legend-upload { color: var(--brand); }
        .legend-download { color: var(--download); }
        .chart-wrap { position: relative; height: 210px; }
        #trend-canvas { width: 100%; height: 100%; }
        .chart-tooltip { position: absolute; z-index: 2; pointer-events: none; border: 1px solid var(--line); border-radius: 8px; background: var(--surface); padding: 8px 10px; box-shadow: 0 8px 24px rgb(16 24 40 / 12%); color: var(--muted); font-size: 12px; white-space: nowrap; }

        .ranking-panel { overflow: hidden; margin-bottom: 16px; }
        .ranking-head { padding: 18px 20px 12px; }
        .ranking-tools { display: flex; align-items: center; gap: 8px; }
        .ranking-tools input { width: 210px; }
        .ranking-tools select { width: 116px; }
        .ranking-tools .dimension-select { width: 158px; }
        .analysis-mode { flex: 0 0 auto; }
        .table-scroll { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border-top: 1px solid var(--line); padding: 12px 14px; text-align: left; white-space: nowrap; }
        th { background: var(--surface-subtle); color: var(--muted); font-size: 11px; font-weight: 700; letter-spacing: .04em; }
        td.align-right, th.align-right { text-align: right; }
        tbody tr { transition: background .14s ease; }
        tbody tr:hover { background: var(--surface-subtle); }
        .rank-cell { width: 52px; color: var(--muted); }
        .dimension-cell { min-width: 250px; }
        .row-primary { display: flex; min-width: 0; align-items: center; gap: 10px; }
        .row-button { display: block; max-width: 340px; overflow: hidden; border: 0; background: transparent; padding: 0; color: var(--text-strong); cursor: pointer; font-weight: 670; text-align: left; text-overflow: ellipsis; white-space: nowrap; }
        .row-button:hover { color: var(--brand); }
        .row-label { display: block; max-width: 340px; overflow: hidden; color: var(--text-strong); font-weight: 670; text-overflow: ellipsis; }
        .ip-metadata { display: flex; min-width: 0; align-items: center; gap: 6px; }
        .ip-badge { display: inline-flex; min-height: 23px; max-width: 260px; align-items: center; overflow: hidden; border: 1px solid var(--line); border-radius: 999px; background: var(--surface-subtle); padding: 2px 7px; color: var(--muted-strong); font-size: 11px; font-weight: 620; text-overflow: ellipsis; white-space: nowrap; }
        .ip-badge.country { border-color: color-mix(in srgb, var(--brand) 20%, var(--line)); background: var(--brand-soft); color: var(--brand); }
        .ip-badge.unknown { color: var(--muted); font-weight: 550; }
        .row-drill-hint { flex: 0 0 auto; color: var(--muted); font-size: 11px; font-weight: 650; opacity: .78; transition: color .14s ease, opacity .14s ease; }
        tbody tr.drillable { cursor: pointer; }
        tbody tr.drillable:hover .row-button, tbody tr.drillable:hover .row-drill-hint { color: var(--brand); opacity: 1; }
        .share-cell { min-width: 176px; }
        .share-line { display: flex; align-items: center; gap: 9px; }
        .bar { width: 100px; height: 6px; overflow: hidden; border-radius: 999px; background: var(--line); }
        .bar span { display: block; height: 100%; border-radius: inherit; background: var(--brand); }
        .empty-state { padding: 46px 20px !important; color: var(--muted); text-align: center; }
        .pagination { display: flex; align-items: center; justify-content: space-between; gap: 12px; border-top: 1px solid var(--line); padding: 12px 14px; color: var(--muted); font-size: 12px; }
        .ranking-meta-group { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .geoip-credit { color: var(--muted); font-size: 11px; }
        .pagination-actions { display: flex; gap: 6px; }

        .health-summary { display: flex; align-items: center; gap: 10px; color: var(--muted); font-size: 12px; }
        .health-summary strong { color: var(--text-strong); font-weight: 700; }
        .health-state { display: inline-flex; border-radius: 999px; padding: 3px 8px; font-size: 12px; font-weight: 650; }
        .health-state.ok { background: var(--success-soft); color: var(--success); }
        .health-state.warn { background: var(--warning-soft); color: var(--warning); }
        .health-view { padding: 18px 20px 0; }

        .drawer-backdrop { position: fixed; z-index: 40; inset: 0; background: rgb(16 24 40 / 34%); }
        .drawer {
            position: fixed;
            z-index: 41;
            top: 0;
            right: 0;
            width: min(460px, 100vw);
            height: 100vh;
            overflow: auto;
            border-left: 1px solid var(--line);
            background: var(--surface);
            box-shadow: -18px 0 48px rgb(16 24 40 / 16%);
            padding: 24px;
        }
        .drawer-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; }
        .drawer-head h2 { font-size: 20px; }
        .drawer-head p { margin-top: 5px; color: var(--muted); }
        .filter-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-top: 24px; }
        .field label { display: block; margin-bottom: 5px; color: var(--muted); font-size: 12px; font-weight: 650; }
        .field input, .field select { width: 100%; }
        .drawer-actions { position: sticky; bottom: -24px; display: flex; justify-content: flex-end; gap: 8px; margin: 24px -24px -24px; border-top: 1px solid var(--line); background: var(--surface); padding: 14px 24px; }

        body.loading .workspace { opacity: .68; }
        body.loading .workspace, body.loading .button.primary { cursor: progress; }

        @media (max-width: 1100px) {
            .control-bar { position: static; align-items: flex-start; }
            .control-left, .control-right { flex-wrap: wrap; }
            .date-controls { width: 100%; }
            .summary-band { grid-template-columns: repeat(4, 1fr); }
            .summary-primary { grid-column: 1 / -1; border-bottom: 1px solid var(--line); }
            .summary-primary + .summary-stat { border-left: 0; }
        }
        @media (max-width: 820px) {
            .app-shell { padding: 18px 16px 36px; }
            .topbar, .control-bar { flex-direction: column; }
            .top-actions, .control-left, .control-right { width: 100%; justify-content: flex-start; }
            .context-row { align-items: flex-start; flex-direction: column; gap: 5px; }
            .context-path { width: 100%; flex-wrap: wrap; }
            .summary-band { grid-template-columns: 1fr 1fr; }
            .summary-primary { grid-column: 1 / -1; }
            .summary-stat { border-top: 1px solid var(--line); border-left: 0; }
            .summary-stat:nth-child(odd) { border-left: 1px solid var(--line); }
            .ranking-head, .surface-head { flex-direction: column; }
            .ranking-tools { width: 100%; flex-wrap: wrap; }
            .ranking-tools input { flex: 1 1 180px; }
            .ranking-tools select { flex: 0 1 140px; }
        }
        @media (max-width: 560px) {
            .date-controls { display: grid; grid-template-columns: 1fr 1fr; }
            input[type="datetime-local"] { width: 100%; }
            .control-label { grid-column: 1 / -1; }
            .summary-band { grid-template-columns: 1fr; }
            .summary-stat, .summary-stat:nth-child(odd) { border-top: 1px solid var(--line); border-left: 0; }
            .filter-grid { grid-template-columns: 1fr; }
            .chart-wrap { height: 190px; }
            .health-summary { flex-wrap: wrap; }
        }
    </style>
</head>
<body>
<main class="app-shell">
    <header class="topbar">
        <div>
            <div class="eyebrow">Xboard Statistics</div>
            <h1>流量分析</h1>
            <p class="subtitle">从节点或用户出发，沿关联关系逐层找到流量去向</p>
        </div>
        <div class="top-actions">
            <div class="tabs" role="tablist" aria-label="页面视图">
                <button class="tab" id="analysis-tab" type="button" role="tab" aria-selected="true">流量分析</button>
                <button class="tab" id="health-tab" type="button" role="tab" aria-selected="false">上报健康</button>
            </div>
            <a class="button" href="{{ $adminUrl }}">返回 Xboard</a>
        </div>
    </header>

    <section class="control-bar" aria-label="分析控制">
        <div class="control-left">
            <span class="control-label">分析起点</span>
            <div class="segment" aria-label="分析视角">
                <button id="perspective-node" type="button" aria-pressed="true">节点视角</button>
                <button id="perspective-user" type="button" aria-pressed="false">用户视角</button>
            </div>
            <button class="button small preset" type="button" data-hours="24" aria-pressed="true">24 小时</button>
            <button class="button small preset" type="button" data-hours="168" aria-pressed="false">7 天</button>
            <button class="button small preset" type="button" data-hours="720" aria-pressed="false">30 天</button>
        </div>
        <div class="control-right">
            <div class="date-controls">
                <span class="control-label">时间范围</span>
                <input id="from" type="datetime-local" aria-label="开始时间">
                <input id="to" type="datetime-local" aria-label="结束时间">
                <span id="timezone-label" class="timezone-label" aria-label="当前显示时区"></span>
            </div>
            <button class="button" id="apply-time" type="button">应用时间</button>
            <button class="button" id="open-filters" type="button">更多筛选<span id="filter-count" class="filter-count hidden">0</span></button>
        </div>
    </section>

    <div id="filter-chips" class="filter-chips"></div>

    <section id="analysis-view" class="analysis-layout" role="tabpanel" aria-labelledby="analysis-tab">
        <div class="workspace">
            <div class="context-row">
                <div class="context-path">
                    <span class="context-label">当前范围</span>
                    <nav id="breadcrumbs" class="breadcrumbs" aria-label="当前分析范围"></nav>
                    <button class="button quiet small hidden" id="reset-path" type="button">重置路径</button>
                </div>
                <span id="query-status" class="query-status" role="status">准备查询</span>
            </div>

            <section class="summary-band" aria-label="当前范围概览">
                <div class="summary-primary">
                    <div id="summary-context" class="summary-label">全部流量</div>
                    <div id="summary-total" class="summary-value num">—</div>
                    <div class="traffic-split">
                        <span class="upload-text">上传 <strong id="summary-upload" class="num">—</strong></span>
                        <span class="download-text">下载 <strong id="summary-download" class="num">—</strong></span>
                    </div>
                </div>
                <div class="summary-stat">
                    <div class="summary-label">连接数</div>
                    <div id="summary-connections" class="summary-value num">—</div>
                </div>
                <div class="summary-stat">
                    <div id="summary-scope-label" class="summary-label">活跃用户</div>
                    <div id="summary-scope-value" class="summary-value num">—</div>
                </div>
                <button class="summary-stat summary-action" id="summary-source-action" type="button" aria-pressed="false" title="查看来源 IP、国家和 ASN">
                    <div class="summary-label">来源 IP</div>
                    <div id="summary-source-ips" class="summary-value num">—</div>
                </button>
                <div class="summary-stat">
                    <div class="summary-label">域名识别率</div>
                    <div id="summary-quality" class="summary-value num">—</div>
                </div>
            </section>

            <section class="surface ranking-panel">
                <div class="surface-head ranking-head">
                    <div>
                        <h2 id="ranking-title">流量排行</h2>
                        <p id="ranking-subtitle" class="surface-kicker">选择一项继续分析</p>
                    </div>
                    <div class="ranking-tools">
                        <div class="segment analysis-mode" aria-label="分析方向">
                            <button id="analysis-mode-path" type="button" aria-pressed="true">流量路径</button>
                            <button id="analysis-mode-source" type="button" aria-pressed="false">来源</button>
                            <button id="analysis-mode-destination" type="button" aria-pressed="false">去向</button>
                        </div>
                        <select id="source-group" class="dimension-select hidden" aria-label="来源分析维度">
                            <option value="source_ip">来源 IP · 国家 · ASN</option>
                            <option value="source_country">来源国家</option>
                            <option value="source_asn">来源 ASN</option>
                        </select>
                        <select id="destination-group" class="dimension-select hidden" aria-label="去向分析维度">
                            <option value="destination_ip">目的 IP · 国家 · ASN</option>
                            <option value="destination_country">目的国家</option>
                            <option value="destination_asn">目的 ASN</option>
                            <option value="outbound">出站</option>
                            <option value="application_protocol">应用协议</option>
                            <option value="destination_port">目的端口</option>
                        </select>
                        <input id="ranking-search" type="search" placeholder="搜索当前排行" aria-label="搜索当前排行">
                        <select id="ranking-sort" aria-label="排序方式">
                            <option value="total_bytes">总流量</option>
                            <option value="download_bytes">下载</option>
                            <option value="upload_bytes">上传</option>
                            <option value="connections">连接数</option>
                        </select>
                        <button class="button small" id="ranking-search-button" type="button">搜索</button>
                    </div>
                </div>
                <div class="table-scroll">
                    <table>
                        <thead>
                        <tr>
                            <th class="rank-cell">排名</th>
                            <th id="ranking-dimension-heading" class="dimension-cell">项目</th>
                            <th class="align-right">总流量</th>
                            <th class="align-right">上传</th>
                            <th class="align-right">下载</th>
                            <th class="align-right">连接数</th>
                            <th>占比</th>
                        </tr>
                        </thead>
                        <tbody id="ranking-rows"></tbody>
                    </table>
                </div>
                <div class="pagination">
                    <div class="ranking-meta-group">
                        <span id="ranking-meta">0 项</span>
                        <a class="geoip-credit" href="https://db-ip.com" target="_blank" rel="noopener noreferrer">IP Geolocation by DB-IP</a>
                    </div>
                    <div class="pagination-actions">
                        <button class="button small" id="previous-page" type="button">上一页</button>
                        <button class="button small" id="next-page" type="button">下一页</button>
                    </div>
                </div>
            </section>

            <section class="surface trend-panel">
                <div class="surface-head">
                    <div>
                        <h2>流量趋势</h2>
                        <p id="trend-context" class="surface-kicker">当前分析范围的小时变化</p>
                    </div>
                    <div class="legend" aria-label="图例">
                        <span class="legend-upload">上传</span>
                        <span class="legend-download">下载</span>
                    </div>
                </div>
                <div class="chart-wrap">
                    <canvas id="trend-canvas" aria-label="上传与下载流量趋势图"></canvas>
                    <div id="chart-tooltip" class="chart-tooltip hidden"></div>
                </div>
            </section>
        </div>
    </section>

    <section id="health-view" class="surface health-view hidden" role="tabpanel" aria-labelledby="health-tab">
        <div class="surface-head">
            <div>
                <h2>节点上报健康</h2>
                <p class="surface-kicker">按当前时间范围检查延迟、待报批次和重试状态</p>
            </div>
            <div id="health-summary" class="health-summary"></div>
        </div>
        <div class="table-scroll">
            <table>
                <thead><tr><th>状态</th><th>节点</th><th>最新分钟桶</th><th>接收时间</th><th class="align-right">延迟</th><th class="align-right">待报</th><th class="align-right">重试</th><th class="align-right">范围内批次</th><th></th></tr></thead>
                <tbody id="health-rows"></tbody>
            </table>
        </div>
    </section>
</main>

<div id="drawer-backdrop" class="drawer-backdrop hidden"></div>
<aside id="filter-drawer" class="drawer hidden" role="dialog" aria-modal="true" aria-labelledby="filter-title">
    <div class="drawer-head">
        <div>
            <h2 id="filter-title">更多筛选</h2>
            <p>这些条件会叠加到当前分析路径，不会改变下钻顺序。</p>
        </div>
        <button class="icon-button" id="close-filters" type="button" aria-label="关闭筛选">关闭</button>
    </div>
    <div class="filter-grid">
        <div class="field"><label for="filter-source_ip">来源 IP</label><input id="filter-source_ip" placeholder="IPv4 / IPv6"></div>
        <div class="field"><label for="filter-destination_ip">目的 IP</label><input id="filter-destination_ip" placeholder="IPv4 / IPv6"></div>
        <div class="field"><label for="filter-network">网络</label><select id="filter-network"><option value="">全部</option><option value="tcp">TCP</option><option value="udp">UDP</option></select></div>
        <div class="field"><label for="filter-application_protocol">应用协议</label><input id="filter-application_protocol" placeholder="tls / http / quic"></div>
        <div class="field"><label for="filter-inbound_type">入站类型</label><input id="filter-inbound_type" placeholder="vless / vmess"></div>
        <div class="field"><label for="filter-outbound">出站标签</label><input id="filter-outbound" placeholder="direct / warp"></div>
        <div class="field"><label for="filter-destination_port">目的端口</label><input id="filter-destination_port" inputmode="numeric" placeholder="443"></div>
        <div class="field"><label for="filter-source_country">来源国家</label><input id="filter-source_country" placeholder="CN / US"></div>
        <div class="field"><label for="filter-destination_country">目的国家</label><input id="filter-destination_country" placeholder="CN / US"></div>
        <div class="field"><label for="filter-source_asn">来源 ASN</label><input id="filter-source_asn" inputmode="numeric" placeholder="4134"></div>
        <div class="field"><label for="filter-destination_asn">目的 ASN</label><input id="filter-destination_asn" inputmode="numeric" placeholder="13335"></div>
    </div>
    <div class="drawer-actions">
        <button class="button" id="clear-filters" type="button">清空条件</button>
        <button class="button primary" id="apply-filters" type="button">应用筛选</button>
    </div>
</aside>

<script>
const API_BASE = @json($apiBase);
const AUTH = window.XboardStatisticAuth;
const $ = id => document.getElementById(id);
const PAGE_SIZE = 30;
const USER_TIME_ZONE = Intl.DateTimeFormat().resolvedOptions().timeZone || '本地时区';
const REGION_NAMES = typeof Intl.DisplayNames === 'function' ? new Intl.DisplayNames(['zh-CN'], {type: 'region'}) : null;
const ADVANCED_FILTERS = ['source_ip','destination_ip','network','application_protocol','inbound_type','outbound','destination_port','source_country','destination_country','source_asn','destination_asn'];
const SOURCE_GROUPS = ['source_ip','source_country','source_asn'];
const DESTINATION_GROUPS = ['destination_ip','destination_country','destination_asn','outbound','application_protocol','destination_port'];
const DIMENSION_LABELS = {node:'节点',user:'用户',domain:'域名',source_ip:'来源 IP',destination_ip:'目的 IP',network:'网络',application_protocol:'应用协议',inbound_type:'入站类型',outbound:'出站',destination_port:'目的端口',source_country:'来源国家',destination_country:'目的国家',source_asn:'来源 ASN',destination_asn:'目的 ASN',time:'时间'};
const FILTER_LABELS = {source_ip:'来源 IP',destination_ip:'目的 IP',network:'网络',application_protocol:'应用协议',inbound_type:'入站类型',outbound:'出站',destination_port:'目的端口',source_country:'来源国家',destination_country:'目的国家',source_asn:'来源 ASN',destination_asn:'目的 ASN'};

const state = {
    perspective: 'node',
    activeTab: 'analysis',
    selected: {node: null, user: null, domain: null},
    analysisMode: 'path',
    sourceGroup: 'source_ip',
    destinationGroup: 'destination_ip',
    page: 1,
    search: '',
    sort: 'total_bytes',
    requestId: 0,
    ranking: null,
    trend: [],
    health: [],
};

function token() {
    return AUTH.token();
}

function localDate(value) {
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '';
    return new Date(date.getTime() - date.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
}

function iso(value, label) {
    if (!value) throw new Error(`请选择${label}`);
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) throw new Error(`${label}无效`);
    return date.toISOString();
}

function displayDateTime(value) {
    if (!value) return '—';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return String(value);
    return date.toLocaleString('zh-CN', {
        year: 'numeric', month: '2-digit', day: '2-digit',
        hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false,
    });
}

function bytes(value) {
    let number = Number(value || 0);
    const units = ['B','KiB','MiB','GiB','TiB','PiB'];
    let index = 0;
    while (number >= 1024 && index < units.length - 1) { number /= 1024; index++; }
    const digits = index === 0 ? 0 : number >= 100 ? 0 : number >= 10 ? 1 : 2;
    return `${number.toFixed(digits)} ${units[index]}`;
}

function compactBytes(value) {
    let number = Number(value || 0);
    const units = ['B','K','M','G','T','P'];
    let index = 0;
    while (number >= 1024 && index < units.length - 1) { number /= 1024; index++; }
    return `${number.toFixed(number >= 10 || index === 0 ? 0 : 1)}${units[index]}`;
}

function duration(value) {
    const seconds = Number(value || 0);
    if (seconds >= 3600) return `${(seconds / 3600).toFixed(1)} 小时`;
    if (seconds >= 60) return `${(seconds / 60).toFixed(1)} 分钟`;
    return `${seconds} 秒`;
}

function countryFlag(code) {
    if (!/^[A-Z]{2}$/.test(code || '')) return '';
    return [...code].map(character => String.fromCodePoint(127397 + character.charCodeAt(0))).join('');
}

function countryLabel(code) {
    if (!/^[A-Z]{2}$/.test(code || '')) return '国家未知';
    let name = code;
    try { name = REGION_NAMES?.of(code) || code; } catch (_) {}
    return `${countryFlag(code)} ${name} (${code})`;
}

function pathDimensions() { return state.perspective === 'node' ? ['node','user','domain'] : ['user','node','domain']; }

function nextPathDimension() {
    return pathDimensions().find(dimension => !state.selected[dimension]) || null;
}

function currentGroup() {
    if (state.analysisMode === 'source') return state.sourceGroup;
    if (state.analysisMode === 'destination') return state.destinationGroup;
    return nextPathDimension() || state.destinationGroup;
}

function advancedValue(key) { return $(`filter-${key}`).value.trim(); }

function currentFilters() {
    const values = {};
    if (state.selected.node) values.node_id = state.selected.node.value;
    if (state.selected.user) values.user_id = state.selected.user.value;
    if (state.selected.domain) values.domain = state.selected.domain.value;
    ADVANCED_FILTERS.forEach(key => {
        const value = advancedValue(key);
        if (value) values[key] = value;
    });
    return values;
}

function buildParams(options = {}) {
    const params = new URLSearchParams();
    const from = iso($('from').value, '开始时间');
    const to = iso($('to').value, '结束时间');
    if (new Date(to) <= new Date(from)) throw new Error('结束时间必须晚于开始时间');
    params.set('from', from);
    params.set('to', to);
    Object.entries(currentFilters()).forEach(([key, value]) => params.set(key, value));
    if (options.group) params.set('group_by', options.group);
    if (options.search) params.set('search', options.search);
    if (options.sort) params.set('sort_field', options.sort);
    params.set('current', String(options.page || 1));
    params.set('pageSize', String(options.limit || PAGE_SIZE));
    return params;
}

async function api(path, params) {
    const auth = token();
    if (!auth) return AUTH.redirectToLogin();
    const response = await fetch(`${API_BASE}${path}?${params}`, {headers: {Authorization: auth, Accept: 'application/json'}});
    if (response.status === 401 || response.status === 403) return AUTH.redirectToLogin();
    let body;
    try { body = await response.json(); }
    catch { throw new Error(`服务器返回了无法解析的响应（HTTP ${response.status}）`); }
    if (!response.ok) throw new Error(body.message || `请求失败（HTTP ${response.status}）`);
    return body.data ?? body;
}

function syncUrl() {
    const params = buildParams();
    params.set('view', state.perspective);
    if (state.selected.node?.label) params.set('node_label', state.selected.node.label);
    if (state.selected.user?.label) params.set('user_label', state.selected.user.label);
    if (state.selected.domain?.label) params.set('domain_label', state.selected.domain.label);
    if (state.analysisMode !== 'path') params.set('analysis_mode', state.analysisMode);
    if (state.sourceGroup !== 'source_ip') params.set('source_group', state.sourceGroup);
    if (state.destinationGroup !== 'destination_ip') params.set('destination_group', state.destinationGroup);
    if (state.analysisMode === 'source') params.set('leaf_group', state.sourceGroup);
    if (state.analysisMode === 'destination' && state.destinationGroup !== 'destination_ip') {
        params.set('leaf_group', state.destinationGroup);
    }
    history.replaceState(null, '', `${location.pathname}?${params}`);
}

function selectedContext() {
    const dimensions = pathDimensions();
    const labels = dimensions.filter(d => state.selected[d]).map(d => state.selected[d].label);
    return labels.length ? labels.join(' · ') : (state.perspective === 'node' ? '全部节点' : '全部用户');
}

function clearDownstream(dimension, keepDimension = true) {
    const dimensions = pathDimensions();
    const index = dimensions.indexOf(dimension);
    dimensions.slice(index + (keepDimension ? 1 : 0)).forEach(key => { state.selected[key] = null; });
    state.analysisMode = 'path';
    state.page = 1;
    state.search = '';
    $('ranking-search').value = '';
}

function resetPath() {
    state.selected = {node: null, user: null, domain: null};
    state.analysisMode = 'path';
    state.page = 1;
    state.search = '';
    $('ranking-search').value = '';
}

function setPerspective(perspective) {
    if (state.perspective === perspective) return;
    state.perspective = perspective;
    resetPath();
    renderStaticState();
    load();
}

function resetRankingQuery() {
    state.page = 1;
    state.search = '';
    $('ranking-search').value = '';
}

function setAnalysisMode(mode) {
    if (mode === 'path' && !nextPathDimension()) return;
    if (!['path','source','destination'].includes(mode) || state.analysisMode === mode) return;
    state.analysisMode = mode;
    resetRankingQuery();
    renderRankingHeading();
    load();
}

function setAnalysisGroup(side, group) {
    const allowed = side === 'source' ? SOURCE_GROUPS : DESTINATION_GROUPS;
    if (!allowed.includes(group)) return;
    const key = side === 'source' ? 'sourceGroup' : 'destinationGroup';
    if (state[key] === group && state.analysisMode === side) return;
    state[key] = group;
    state.analysisMode = side;
    resetRankingQuery();
    renderRankingHeading();
    load();
}

function renderStaticState() {
    $('perspective-node').setAttribute('aria-pressed', String(state.perspective === 'node'));
    $('perspective-user').setAttribute('aria-pressed', String(state.perspective === 'user'));
    renderBreadcrumbs();
    renderFilterChips();
    renderRankingHeading();
}

function renderBreadcrumbs() {
    const container = $('breadcrumbs');
    container.innerHTML = '';
    const root = document.createElement('button');
    root.type = 'button';
    root.className = 'crumb';
    root.textContent = state.perspective === 'node' ? '全部节点' : '全部用户';
    root.onclick = () => { resetPath(); renderStaticState(); load(); };
    container.appendChild(root);
    pathDimensions().forEach(dimension => {
        const selection = state.selected[dimension];
        if (!selection) return;
        const separator = document.createElement('span');
        separator.className = 'crumb-separator';
        separator.textContent = '/';
        const crumb = document.createElement('button');
        crumb.type = 'button';
        crumb.className = 'crumb';
        crumb.textContent = selection.label;
        crumb.title = selection.label;
        crumb.onclick = () => { clearDownstream(dimension, true); renderStaticState(); load(); };
        container.append(separator, crumb);
    });
    const crumbs = container.querySelectorAll('.crumb');
    if (crumbs.length) crumbs[crumbs.length - 1].classList.add('current');
    $('reset-path').classList.toggle('hidden', !Object.values(state.selected).some(Boolean));
}

function renderFilterChips() {
    const container = $('filter-chips');
    container.innerHTML = '';
    let count = 0;
    ADVANCED_FILTERS.forEach(key => {
        const value = advancedValue(key);
        if (!value) return;
        count++;
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'filter-chip';
        button.textContent = `${FILTER_LABELS[key]}：${value}  移除`;
        button.onclick = () => { $(`filter-${key}`).value = ''; state.page = 1; renderFilterChips(); load(); };
        container.appendChild(button);
    });
    $('filter-count').textContent = String(count);
    $('filter-count').classList.toggle('hidden', count === 0);
}

function renderRankingHeading() {
    const group = currentGroup();
    const node = state.selected.node?.label;
    const user = state.selected.user?.label;
    const domain = state.selected.domain?.label;
    let title;
    let subtitle;
    if (group === 'node') {
        title = user ? `${user} 的节点流量` : '节点流量排行';
        subtitle = user ? '选择一个节点，查看这个用户在该节点访问的域名' : '选择一个节点，查看该节点内的用户流量';
    }
    else if (group === 'user') {
        title = node ? `${node} 内的用户流量` : '用户流量排行';
        subtitle = node ? '选择一个用户，查看该用户在这个节点访问的域名' : '选择一个用户，查看该用户的流量主要经过哪些节点';
    }
    else if (group === 'domain') {
        title = node && user ? `${user} 在 ${node} 的域名流量` : user ? `${user} 的域名流量` : '域名流量排行';
        subtitle = '选择一个域名继续下钻；未识别域名以流量最大的目的 IP 和 ASN 摘要显示';
    }
    else if (group === 'source_ip') {
        title = `${selectedContext()} 的来源 IP`;
        subtitle = '每个来源 IP 直接标注国家、ASN 和网络运营组织';
    }
    else if (group === 'source_country') {
        title = `${selectedContext()} 的来源国家分布`;
        subtitle = '按来源 IP 所属国家汇总当前范围内的流量';
    }
    else if (group === 'source_asn') {
        title = `${selectedContext()} 的来源 ASN 分布`;
        subtitle = '按来源网络汇总流量，并显示对应的 AS 组织';
    }
    else {
        title = `${domain || selectedContext()} 的${DIMENSION_LABELS[group]} 分布`;
        subtitle = '可在来源画像和访问去向之间切换分析维度';
    }
    $('ranking-title').textContent = title;
    $('ranking-subtitle').textContent = subtitle;
    $('ranking-dimension-heading').textContent = group === 'source_ip'
        ? '来源 IP / 国家 / ASN'
        : group === 'destination_ip'
            ? '目的 IP / 国家 / ASN'
            : group === 'domain'
                ? '域名 / 主目的 IP / ASN'
                : DIMENSION_LABELS[group] || '项目';
    const pathGroup = nextPathDimension();
    $('analysis-mode-path').disabled = !pathGroup;
    $('analysis-mode-path').title = pathGroup ? `继续查看${DIMENSION_LABELS[pathGroup]}` : '节点、用户和域名路径已定位';
    ['path','source','destination'].forEach(mode => {
        $(`analysis-mode-${mode}`).setAttribute('aria-pressed', String(state.analysisMode === mode));
    });
    $('source-group').classList.toggle('hidden', state.analysisMode !== 'source');
    $('destination-group').classList.toggle('hidden', state.analysisMode !== 'destination');
    $('source-group').value = state.sourceGroup;
    $('destination-group').value = state.destinationGroup;
    $('summary-source-action').setAttribute('aria-pressed', String(state.analysisMode === 'source' && state.sourceGroup === 'source_ip'));
    $('ranking-search').placeholder = `搜索${DIMENSION_LABELS[group] || '当前排行'}`;
}

function renderSummary(overview) {
    $('summary-context').textContent = selectedContext();
    $('summary-total').textContent = bytes(overview.total_bytes);
    $('summary-upload').textContent = bytes(overview.upload_bytes);
    $('summary-download').textContent = bytes(overview.download_bytes);
    $('summary-connections').textContent = Number(overview.connection_count || 0).toLocaleString();
    const scopeIsNode = state.perspective === 'node' && !state.selected.node;
    $('summary-scope-label').textContent = scopeIsNode ? '活跃节点' : '活跃用户';
    $('summary-scope-value').textContent = Number(scopeIsNode ? overview.node_count : overview.user_count).toLocaleString();
    $('summary-source-ips').textContent = Number(overview.source_ip_count || 0).toLocaleString();
    const quality = overview.quality?.domain_recognition_rate;
    $('summary-quality').textContent = quality == null ? '—' : `${(quality * 100).toFixed(1)}%`;
    $('trend-context').textContent = `${selectedContext()} · 当前时间范围的小时变化`;
}

function renderRanking(ranking) {
    state.ranking = ranking;
    renderRankingHeading();
    const body = $('ranking-rows');
    body.innerHTML = '';
    const drillDimension = nextPathDimension();
    if (!ranking.items.length) {
        const row = document.createElement('tr');
        const cell = document.createElement('td');
        cell.colSpan = 7;
        cell.className = 'empty-state';
        cell.textContent = state.search ? '没有找到匹配项，请换一个关键词' : '当前分析范围内没有流量数据';
        row.appendChild(cell);
        body.appendChild(row);
    }
    ranking.items.forEach(item => {
        const row = document.createElement('tr');
        const rank = document.createElement('td');
        rank.className = 'rank-cell num';
        rank.textContent = String(item.rank);
        const name = document.createElement('td');
        name.className = 'dimension-cell';
        const specialValue = ['unknown','__other__'].includes(String(item.value));
        const drillable = drillDimension === ranking.group_by
            && (!specialValue || (ranking.group_by === 'domain' && item.unknown_domain === true));
        const primary = document.createElement('div');
        primary.className = 'row-primary';
        const label = document.createElement(drillable ? 'button' : 'span');
        label.className = drillable ? 'row-button' : 'row-label';
        const aggregateCountry = ['source_country', 'destination_country'].includes(ranking.group_by);
        const aggregateAsn = ['source_asn', 'destination_asn'].includes(ranking.group_by);
        const displayLabel = aggregateCountry && !['unknown', '__other__'].includes(String(item.value))
            ? countryLabel(String(item.value))
            : aggregateAsn && /^\d+$/.test(String(item.value))
                ? `AS${item.value}`
                : item.label;
        label.textContent = displayLabel;
        label.title = displayLabel;
        if (drillable) {
            label.type = 'button';
            label.setAttribute('aria-label', `查看${item.label}的下一层流量`);
            label.onclick = event => { event.stopPropagation(); selectRankingItem(ranking.group_by, item); };
        }
        primary.appendChild(label);
        const representativeDestination = ranking.group_by === 'domain'
            ? item.representative_destination
            : null;
        const ipMetadata = ['source_ip', 'destination_ip'].includes(ranking.group_by) && !specialValue
            ? item
            : representativeDestination;
        if (ipMetadata) {
            const metadata = document.createElement('div');
            metadata.className = 'ip-metadata';
            const country = document.createElement('span');
            country.className = `ip-badge country${ipMetadata.country_code ? '' : ' unknown'}`;
            country.textContent = countryLabel(ipMetadata.country_code);
            const asn = document.createElement('span');
            asn.className = `ip-badge${ipMetadata.asn ? '' : ' unknown'}`;
            asn.textContent = ipMetadata.asn
                ? `AS${ipMetadata.asn}${ipMetadata.asn_organization ? ` · ${ipMetadata.asn_organization}` : ''}`
                : 'ASN 未知';
            asn.title = asn.textContent;
            metadata.append(country, asn);
            primary.appendChild(metadata);
        }
        if (aggregateAsn && item.asn_organization) {
            const organization = document.createElement('span');
            organization.className = 'ip-badge';
            organization.textContent = item.asn_organization;
            organization.title = item.asn_organization;
            primary.appendChild(organization);
        }
        if (drillable) {
            const dimensions = pathDimensions();
            const nextDimension = dimensions[dimensions.indexOf(ranking.group_by) + 1] || state.destinationGroup;
            const hint = document.createElement('span');
            hint.className = 'row-drill-hint';
            hint.textContent = `查看${DIMENSION_LABELS[nextDimension] || '详情'} →`;
            primary.appendChild(hint);
            row.classList.add('drillable');
            row.onclick = () => selectRankingItem(ranking.group_by, item);
        }
        name.appendChild(primary);
        const total = numericCell(bytes(item.total_bytes));
        const upload = numericCell(bytes(item.upload_bytes));
        const download = numericCell(bytes(item.download_bytes));
        const connections = numericCell(Number(item.connection_count || 0).toLocaleString());
        const share = document.createElement('td');
        share.className = 'share-cell';
        const shareLine = document.createElement('div');
        shareLine.className = 'share-line';
        const bar = document.createElement('div');
        bar.className = 'bar';
        const fill = document.createElement('span');
        fill.style.width = `${Math.min(100, Number(item.percentage || 0))}%`;
        bar.appendChild(fill);
        const percent = document.createElement('span');
        percent.className = 'num';
        percent.textContent = `${Number(item.percentage || 0).toFixed(2)}%`;
        shareLine.append(bar, percent);
        share.appendChild(shareLine);
        row.append(rank, name, total, upload, download, connections, share);
        body.appendChild(row);
    });
    const start = ranking.total ? (state.page - 1) * PAGE_SIZE + 1 : 0;
    const end = Math.min(state.page * PAGE_SIZE, ranking.total);
    $('ranking-meta').textContent = `${start}–${end} / ${ranking.total} 项 · 范围总计 ${bytes(ranking.grand_total_bytes)}`;
    $('previous-page').disabled = state.page <= 1;
    $('next-page').disabled = state.page * PAGE_SIZE >= ranking.total;
}

function numericCell(text) {
    const cell = document.createElement('td');
    cell.className = 'align-right num';
    cell.textContent = text;
    return cell;
}

function selectRankingItem(dimension, item) {
    state.selected[dimension] = {value: String(item.value), label: String(item.label)};
    clearDownstream(dimension, true);
    if (!nextPathDimension()) state.analysisMode = 'destination';
    renderStaticState();
    load();
}

function nodeHealthState(item) {
    const pending = Number(item.reported_pending_batches || 0);
    const retries = Number(item.reported_retry_attempt || 0);
    const delay = item.report_delay_seconds == null ? null : Number(item.report_delay_seconds);
    return delay != null && delay <= 600 && pending === 0 && retries === 0 ? 'ok' : 'warn';
}

function renderHealth(items) {
    state.health = items;
    const normal = items.filter(item => nodeHealthState(item) === 'ok').length;
    const attention = items.length - normal;
    $('health-summary').innerHTML = `<span><strong>${normal}</strong> 正常</span><span><strong>${attention}</strong> 需关注</span><span><strong>${items.length}</strong> 个上报节点</span>`;
    const body = $('health-rows');
    body.innerHTML = '';
    items.forEach(item => {
        const status = nodeHealthState(item);
        const row = document.createElement('tr');
        const stateCell = document.createElement('td');
        const badge = document.createElement('span');
        badge.className = `health-state ${status}`;
        badge.textContent = status === 'ok' ? '正常' : '需关注';
        stateCell.appendChild(badge);
        const node = document.createElement('td');
        const nodeButton = document.createElement('button');
        nodeButton.type = 'button';
        nodeButton.className = 'row-button';
        nodeButton.textContent = `${item.node_name} (#${item.node_id})`;
        nodeButton.onclick = () => inspectHealthNode(item);
        node.appendChild(nodeButton);
        row.append(
            stateCell,
            node,
            textCell(displayDateTime(item.last_bucket_start)),
            textCell(displayDateTime(item.last_received_at)),
            numericCell(item.report_delay_seconds == null ? '—' : duration(item.report_delay_seconds)),
            numericCell(item.reported_pending_batches ?? '—'),
            numericCell(item.reported_retry_attempt ?? '—'),
            numericCell(Number(item.batch_count || 0).toLocaleString())
        );
        const action = document.createElement('td');
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'button small';
        button.textContent = '分析流量';
        button.onclick = () => inspectHealthNode(item);
        action.appendChild(button);
        row.appendChild(action);
        body.appendChild(row);
    });
    if (!items.length) {
        const row = document.createElement('tr');
        const cell = document.createElement('td');
        cell.colSpan = 9;
        cell.className = 'empty-state';
        cell.textContent = '当前时间范围没有节点上报记录';
        row.appendChild(cell);
        body.appendChild(row);
    }
}

function textCell(text) {
    const cell = document.createElement('td');
    cell.textContent = String(text);
    return cell;
}

function inspectHealthNode(item) {
    state.perspective = 'node';
    resetPath();
    state.selected.node = {value: String(item.node_id), label: String(item.node_name)};
    setActiveTab('analysis');
    renderStaticState();
    load();
}

let trendPoints = [];
let hoverIndex = null;

function renderTrend(items) {
    trendPoints = [...items].sort((left, right) => new Date(left.value) - new Date(right.value));
    hoverIndex = null;
    drawTrend();
}

function drawTrend() {
    const canvas = $('trend-canvas');
    const rect = canvas.getBoundingClientRect();
    if (!rect.width || !rect.height) return;
    const ratio = window.devicePixelRatio || 1;
    canvas.width = Math.round(rect.width * ratio);
    canvas.height = Math.round(rect.height * ratio);
    const context = canvas.getContext('2d');
    context.scale(ratio, ratio);
    const width = rect.width;
    const height = rect.height;
    const padding = {top: 10, right: 16, bottom: 28, left: 48};
    const plotWidth = width - padding.left - padding.right;
    const plotHeight = height - padding.top - padding.bottom;
    const styles = getComputedStyle(document.documentElement);
    const line = styles.getPropertyValue('--line').trim();
    const muted = styles.getPropertyValue('--muted').trim();
    const brand = styles.getPropertyValue('--brand').trim();
    const download = styles.getPropertyValue('--download').trim();
    context.clearRect(0, 0, width, height);
    context.font = '11px ui-sans-serif, system-ui, sans-serif';
    context.fillStyle = muted;
    context.strokeStyle = line;
    context.lineWidth = 1;
    if (!trendPoints.length) {
        context.textAlign = 'center';
        context.fillText('当前范围没有趋势数据', width / 2, height / 2);
        return;
    }
    const max = Math.max(1, ...trendPoints.flatMap(item => [Number(item.upload_bytes || 0), Number(item.download_bytes || 0)]));
    for (let step = 0; step <= 4; step++) {
        const y = padding.top + plotHeight * step / 4;
        context.beginPath();
        context.moveTo(padding.left, y);
        context.lineTo(width - padding.right, y);
        context.stroke();
        context.textAlign = 'right';
        context.fillText(compactBytes(max * (1 - step / 4)), padding.left - 8, y + 4);
    }
    const xFor = index => padding.left + (trendPoints.length === 1 ? plotWidth / 2 : plotWidth * index / (trendPoints.length - 1));
    const yFor = value => padding.top + plotHeight - Number(value || 0) / max * plotHeight;
    const drawSeries = (key, color) => {
        context.beginPath();
        trendPoints.forEach((item, index) => {
            const x = xFor(index);
            const y = yFor(item[key]);
            if (index === 0) context.moveTo(x, y); else context.lineTo(x, y);
        });
        context.strokeStyle = color;
        context.lineWidth = 2;
        context.lineJoin = 'round';
        context.lineCap = 'round';
        context.stroke();
    };
    drawSeries('upload_bytes', brand);
    drawSeries('download_bytes', download);
    const labelCount = Math.min(6, trendPoints.length);
    for (let index = 0; index < labelCount; index++) {
        const pointIndex = Math.round(index * (trendPoints.length - 1) / Math.max(1, labelCount - 1));
        const date = new Date(trendPoints[pointIndex].value);
        context.fillStyle = muted;
        context.textAlign = index === 0 ? 'left' : index === labelCount - 1 ? 'right' : 'center';
        context.fillText(formatChartTime(date), xFor(pointIndex), height - 7);
    }
    if (hoverIndex != null && trendPoints[hoverIndex]) {
        const x = xFor(hoverIndex);
        context.strokeStyle = line;
        context.lineWidth = 1;
        context.beginPath();
        context.moveTo(x, padding.top);
        context.lineTo(x, padding.top + plotHeight);
        context.stroke();
        [['upload_bytes', brand], ['download_bytes', download]].forEach(([key, color]) => {
            context.fillStyle = color;
            context.beginPath();
            context.arc(x, yFor(trendPoints[hoverIndex][key]), 4, 0, Math.PI * 2);
            context.fill();
        });
    }
}

function formatChartTime(date) {
    return date.toLocaleString('zh-CN', {
        month: 'numeric', day: 'numeric', hour: '2-digit', minute: '2-digit', hour12: false,
    });
}

function handleChartPointer(event) {
    if (!trendPoints.length) return;
    const canvas = $('trend-canvas');
    const rect = canvas.getBoundingClientRect();
    const plotStart = 48;
    const plotWidth = rect.width - 64;
    const ratio = Math.max(0, Math.min(1, (event.clientX - rect.left - plotStart) / plotWidth));
    hoverIndex = Math.round(ratio * (trendPoints.length - 1));
    const item = trendPoints[hoverIndex];
    const tooltip = $('chart-tooltip');
    tooltip.innerHTML = `${formatChartTime(new Date(item.value))}<br><span class="upload-text">上传 ${bytes(item.upload_bytes)}</span><br><span class="download-text">下载 ${bytes(item.download_bytes)}</span>`;
    tooltip.classList.remove('hidden');
    const x = plotStart + (trendPoints.length === 1 ? plotWidth / 2 : plotWidth * hoverIndex / (trendPoints.length - 1));
    tooltip.style.left = `${Math.min(rect.width - tooltip.offsetWidth - 6, Math.max(6, x + 10))}px`;
    tooltip.style.top = '8px';
    drawTrend();
}

function hideChartTooltip() {
    hoverIndex = null;
    $('chart-tooltip').classList.add('hidden');
    drawTrend();
}

function setLoading(loading, message = '') {
    document.body.classList.toggle('loading', loading);
    $('query-status').textContent = message || (loading ? '正在更新分析…' : '已更新');
    $('query-status').classList.remove('error');
}

async function load() {
    const requestId = ++state.requestId;
    setLoading(true);
    try {
        renderStaticState();
        syncUrl();
        const group = currentGroup();
        const [overview, ranking, trend, health] = await Promise.all([
            api('/overview', buildParams()),
            api('/traffic', buildParams({group, search: state.search, sort: state.sort, page: state.page})),
            api('/traffic', buildParams({group: 'time', limit: 500})),
            api('/health', buildParams({limit: 500})),
        ]);
        if (requestId !== state.requestId) return;
        renderSummary(overview);
        renderRanking(ranking);
        renderTrend(trend.items || []);
        renderHealth(health.items || []);
        const lastReceived = overview.last_received_at
            ? new Date(overview.last_received_at).toLocaleString('zh-CN', {month:'numeric', day:'numeric', hour:'2-digit', minute:'2-digit', hour12:false})
            : null;
        setLoading(false, lastReceived ? `数据更新于 ${lastReceived}` : '已更新');
    } catch (error) {
        if (requestId !== state.requestId) return;
        setLoading(false, error.message);
        $('query-status').classList.add('error');
        renderRanking({group_by: currentGroup(), items: [], total: 0, grand_total_bytes: 0});
        renderTrend([]);
    }
}

function setActiveTab(tab) {
    state.activeTab = tab;
    $('analysis-tab').setAttribute('aria-selected', String(tab === 'analysis'));
    $('health-tab').setAttribute('aria-selected', String(tab === 'health'));
    $('analysis-view').classList.toggle('hidden', tab !== 'analysis');
    $('health-view').classList.toggle('hidden', tab !== 'health');
    $('perspective-node').disabled = tab !== 'analysis';
    $('perspective-user').disabled = tab !== 'analysis';
    if (tab === 'analysis') requestAnimationFrame(drawTrend);
}

function openFilters() {
    $('filter-drawer').classList.remove('hidden');
    $('drawer-backdrop').classList.remove('hidden');
    $('filter-source_ip').focus();
}

function closeFilters() {
    $('filter-drawer').classList.add('hidden');
    $('drawer-backdrop').classList.add('hidden');
    $('open-filters').focus();
}

function applyPreset(hours) {
    const to = new Date();
    const from = new Date(to.getTime() - hours * 3600000);
    $('from').value = localDate(from);
    $('to').value = localDate(to);
    document.querySelectorAll('.preset').forEach(button => button.setAttribute('aria-pressed', String(Number(button.dataset.hours) === hours)));
    state.page = 1;
    load();
}

function initFromUrl() {
    const query = new URLSearchParams(location.search);
    const now = new Date();
    const before = new Date(now.getTime() - 86400000);
    $('from').value = (query.has('from') ? localDate(query.get('from')) : '') || localDate(before);
    $('to').value = (query.has('to') ? localDate(query.get('to')) : '') || localDate(now);
    $('timezone-label').textContent = USER_TIME_ZONE;
    $('timezone-label').title = `筛选和页面时间按 ${USER_TIME_ZONE} 显示；请求以 UTC 发送`;
    state.perspective = query.get('view') === 'user' ? 'user' : 'node';
    if (query.get('node_id')) state.selected.node = {value: query.get('node_id'), label: query.get('node_label') || `节点 #${query.get('node_id')}`};
    if (query.get('user_id')) state.selected.user = {value: query.get('user_id'), label: query.get('user_label') || `用户 #${query.get('user_id')}`};
    if (query.get('domain')) state.selected.domain = {value: query.get('domain'), label: query.get('domain_label') || query.get('domain')};
    const requestedGroup = query.get('leaf_group');
    const requestedMode = query.get('analysis_mode');
    const requestedSourceGroup = query.get('source_group');
    const requestedDestinationGroup = query.get('destination_group');
    if (SOURCE_GROUPS.includes(requestedSourceGroup)) state.sourceGroup = requestedSourceGroup;
    else if (SOURCE_GROUPS.includes(requestedGroup)) state.sourceGroup = requestedGroup;
    if (DESTINATION_GROUPS.includes(requestedDestinationGroup)) state.destinationGroup = requestedDestinationGroup;
    else if (DESTINATION_GROUPS.includes(requestedGroup)) state.destinationGroup = requestedGroup;
    if (requestedMode === 'source' || (!requestedMode && SOURCE_GROUPS.includes(requestedGroup))) {
        state.analysisMode = 'source';
    } else if (requestedMode === 'destination' || (!requestedMode && DESTINATION_GROUPS.includes(requestedGroup))) {
        state.analysisMode = 'destination';
    } else if (!nextPathDimension()) {
        state.analysisMode = 'destination';
    }
    ADVANCED_FILTERS.forEach(key => { $(`filter-${key}`).value = query.get(key) || ''; });
}

function bindEvents() {
    $('perspective-node').onclick = () => setPerspective('node');
    $('perspective-user').onclick = () => setPerspective('user');
    $('analysis-tab').onclick = () => setActiveTab('analysis');
    $('health-tab').onclick = () => setActiveTab('health');
    $('reset-path').onclick = () => { resetPath(); renderStaticState(); load(); };
    $('apply-time').onclick = () => { state.page = 1; document.querySelectorAll('.preset').forEach(button => button.setAttribute('aria-pressed', 'false')); load(); };
    document.querySelectorAll('.preset').forEach(button => { button.onclick = () => applyPreset(Number(button.dataset.hours)); });
    $('open-filters').onclick = openFilters;
    $('close-filters').onclick = closeFilters;
    $('drawer-backdrop').onclick = closeFilters;
    $('apply-filters').onclick = () => { state.page = 1; closeFilters(); renderFilterChips(); load(); };
    $('clear-filters').onclick = () => { ADVANCED_FILTERS.forEach(key => { $(`filter-${key}`).value = ''; }); renderFilterChips(); };
    $('ranking-search-button').onclick = () => { state.search = $('ranking-search').value.trim(); state.page = 1; load(); };
    $('ranking-search').onkeydown = event => { if (event.key === 'Enter') $('ranking-search-button').click(); };
    $('ranking-sort').onchange = () => { state.sort = $('ranking-sort').value; state.page = 1; load(); };
    $('analysis-mode-path').onclick = () => setAnalysisMode('path');
    $('analysis-mode-source').onclick = () => setAnalysisMode('source');
    $('analysis-mode-destination').onclick = () => setAnalysisMode('destination');
    $('source-group').onchange = () => setAnalysisGroup('source', $('source-group').value);
    $('destination-group').onchange = () => setAnalysisGroup('destination', $('destination-group').value);
    $('summary-source-action').onclick = () => setAnalysisGroup('source', 'source_ip');
    $('previous-page').onclick = () => { if (state.page > 1) { state.page--; load(); } };
    $('next-page').onclick = () => { if (state.ranking && state.page * PAGE_SIZE < state.ranking.total) { state.page++; load(); } };
    $('trend-canvas').addEventListener('pointermove', handleChartPointer);
    $('trend-canvas').addEventListener('pointerleave', hideChartTooltip);
    window.addEventListener('resize', drawTrend);
    if (window.ResizeObserver) new ResizeObserver(drawTrend).observe($('trend-canvas'));
    document.addEventListener('keydown', event => { if (event.key === 'Escape' && !$('filter-drawer').classList.contains('hidden')) closeFilters(); });
}

async function init() {
    await AUTH.ready;
    initFromUrl();
    bindEvents();
    renderStaticState();
    setActiveTab('analysis');
    load();
}

init();
</script>
</body>
</html>
