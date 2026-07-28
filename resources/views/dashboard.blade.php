<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Xboard 多维流量统计</title>
    <style>
        :root { color-scheme: light dark; --bg:#f4f6fa; --card:#fff; --text:#172033; --muted:#667085; --line:#e4e7ec; --brand:#155eef; --soft:#eef4ff; --danger:#b42318; }
        @media (prefers-color-scheme: dark) { :root { --bg:#0d1117; --card:#161b22; --text:#e6edf3; --muted:#9da7b3; --line:#30363d; --brand:#6ea8fe; --soft:#17233c; --danger:#ff7b72; } }
        * { box-sizing:border-box } body { margin:0; background:var(--bg); color:var(--text); font:14px/1.5 ui-sans-serif,system-ui,-apple-system,"PingFang SC",sans-serif }
        .wrap { max-width:1500px; margin:auto; padding:28px } header { display:flex; align-items:flex-start; justify-content:space-between; gap:20px; margin-bottom:20px }
        h1 { margin:0 0 5px; font-size:25px } p { margin:0; color:var(--muted) } a { color:var(--brand) }
        .panel,.card { background:var(--card); border:1px solid var(--line); border-radius:12px; box-shadow:0 1px 2px #1018280a }
        .panel { padding:16px; margin-bottom:16px } .grid { display:grid; grid-template-columns:repeat(6,minmax(150px,1fr)); gap:12px }
        label { display:block; color:var(--muted); font-size:12px; margin-bottom:5px } input,select,button { width:100%; min-height:38px; border:1px solid var(--line); border-radius:8px; padding:8px 10px; background:var(--card); color:var(--text) }
        button { width:auto; cursor:pointer; font-weight:600 } button.primary { background:var(--brand); border-color:var(--brand); color:white } .actions { display:flex; gap:8px; align-items:end }
        .chips { display:flex; gap:8px; flex-wrap:wrap; margin-top:13px } .chip { background:var(--soft); border:1px solid color-mix(in srgb,var(--brand) 25%,transparent); color:var(--brand); border-radius:999px; padding:5px 10px; cursor:pointer }
        .cards { display:grid; grid-template-columns:repeat(6,1fr); gap:12px; margin-bottom:16px } .card { padding:15px } .card small { color:var(--muted) } .card strong { display:block; margin-top:4px; font-size:20px; font-variant-numeric:tabular-nums }
        .table-head { display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:12px } .table-head h2 { margin:0; font-size:17px }
        table { border-collapse:collapse; width:100% } th,td { border-bottom:1px solid var(--line); padding:10px 9px; text-align:left; white-space:nowrap } th { color:var(--muted); font-size:12px } td.num { text-align:right; font-variant-numeric:tabular-nums }
        .bar { width:120px; height:7px; border-radius:8px; background:var(--line); overflow:hidden } .bar i { display:block; height:100%; background:var(--brand) }
        .notice { padding:10px 12px; border-radius:8px; color:var(--muted); background:var(--soft) } .error { color:var(--danger) } .hidden { display:none }
        @media (max-width:1100px) { .grid { grid-template-columns:repeat(3,1fr) } .cards { grid-template-columns:repeat(3,1fr) } }
        @media (max-width:680px) { .wrap { padding:15px } header { flex-direction:column } .grid,.cards { grid-template-columns:1fr 1fr } .panel.table { overflow:auto } }
    </style>
</head>
<body>
<main class="wrap">
    <header>
        <div><h1>多维流量统计</h1><p>小时级数据 · 点击排行项加入筛选，再切换视角继续下钻</p></div>
        <div><a href="{{ $adminUrl }}">返回 Xboard 管理端</a></div>
    </header>
    <div id="auth-warning" class="panel notice hidden">没有找到 Xboard 管理端登录凭证，请先 <a href="{{ $adminUrl }}">登录管理端</a>，再打开本页。</div>
    <section class="panel">
        <div class="grid">
            <div><label>开始时间</label><input id="from" type="datetime-local"></div>
            <div><label>结束时间</label><input id="to" type="datetime-local"></div>
            <div><label>排行视角</label><select id="group"></select></div>
            <div><label>节点 ID</label><input id="node_id" inputmode="numeric" placeholder="全部"></div>
            <div><label>用户 ID</label><input id="user_id" inputmode="numeric" placeholder="全部"></div>
            <div><label>域名</label><input id="domain" placeholder="example.com"></div>
            <div><label>来源 IP</label><input id="source_ip" placeholder="IPv4 / IPv6"></div>
            <div><label>目的 IP</label><input id="destination_ip" placeholder="IPv4 / IPv6"></div>
            <div><label>网络</label><select id="network"><option value="">全部</option><option>tcp</option><option>udp</option></select></div>
            <div><label>应用协议</label><input id="application_protocol" placeholder="tls / http / quic"></div>
            <div><label>入站类型</label><input id="inbound_type" placeholder="vless / vmess"></div>
            <div><label>出站标签</label><input id="outbound" placeholder="direct / warp"></div>
            <div><label>目的端口</label><input id="destination_port" inputmode="numeric" placeholder="443"></div>
            <div><label>来源国家</label><input id="source_country" placeholder="CN / US"></div>
            <div><label>目的国家</label><input id="destination_country" placeholder="CN / US"></div>
            <div><label>来源 ASN</label><input id="source_asn" inputmode="numeric" placeholder="4134"></div>
            <div><label>目的 ASN</label><input id="destination_asn" inputmode="numeric" placeholder="13335"></div>
            <div class="actions"><button class="primary" id="apply">查询</button><button id="clear">清空</button></div>
        </div>
        <div id="chips" class="chips"></div>
    </section>
    <section id="cards" class="cards"></section>
    <section class="panel table">
        <div class="table-head"><h2 id="ranking-title">流量排行</h2><span id="status" class="notice">准备查询</span></div>
        <table><thead><tr><th>#</th><th>项目</th><th>总流量</th><th>上传</th><th>下载</th><th>连接数</th><th>活跃时长</th><th>占比</th><th></th></tr></thead><tbody id="rows"></tbody></table>
    </section>
    <section class="panel table">
        <div class="table-head"><h2>节点上报健康</h2><span class="notice">按待报队列排序</span></div>
        <table><thead><tr><th>节点</th><th>最新小时桶</th><th>接收时间</th><th>延迟</th><th>待报批次</th><th>重试次数</th><th>范围内批次</th></tr></thead><tbody id="health-rows"></tbody></table>
    </section>
</main>
<script>
const API_BASE = @json($apiBase);
const filters = ['node_id','user_id','domain','source_ip','destination_ip','network','application_protocol','inbound_type','outbound','destination_port','source_country','destination_country','source_asn','destination_asn'];
const groups = {node:'节点',user:'用户',domain:'域名',source_ip:'来源 IP',destination_ip:'目的 IP',network:'TCP/UDP',application_protocol:'应用协议',inbound_type:'入站类型',outbound:'出站',destination_port:'目的端口',source_country:'来源国家',destination_country:'目的国家',source_asn:'来源 ASN',destination_asn:'目的 ASN',time:'小时趋势'};
const groupFilter = {node:'node_id',user:'user_id',domain:'domain',source_ip:'source_ip',destination_ip:'destination_ip',network:'network',application_protocol:'application_protocol',inbound_type:'inbound_type',outbound:'outbound',destination_port:'destination_port',source_country:'source_country',destination_country:'destination_country',source_asn:'source_asn',destination_asn:'destination_asn'};
const $ = id => document.getElementById(id);
Object.entries(groups).forEach(([value,label]) => $('group').add(new Option(label,value)));

function token() { try { return JSON.parse(localStorage.getItem('XBOARD_ACCESS_TOKEN') || '{}').value || ''; } catch { return ''; } }
function localDate(value) { const d=new Date(value); d.setMinutes(d.getMinutes()-d.getTimezoneOffset()); return d.toISOString().slice(0,16); }
function iso(value) { return value ? new Date(value).toISOString() : ''; }
function bytes(value) { let n=Number(value||0), units=['B','KiB','MiB','GiB','TiB','PiB'],i=0; while(n>=1024&&i<units.length-1){n/=1024;i++} return `${n.toFixed(i?2:0)} ${units[i]}`; }
function duration(value) { const n=Number(value||0); return n>=3600?`${(n/3600).toFixed(1)} h`:n>=60?`${(n/60).toFixed(1)} min`:`${n} s`; }
function params(includeGroup=true) { const p=new URLSearchParams(); p.set('from',iso($('from').value)); p.set('to',iso($('to').value)); if(includeGroup)p.set('group_by',$('group').value); filters.forEach(k=>{const v=$(k).value.trim();if(v)p.set(k,v)}); p.set('limit','100'); return p; }
function syncUrl(){ const p=params(); history.pushState(null,'',location.pathname+'?'+p.toString()); renderChips(); }
function renderChips(){ const box=$('chips');box.innerHTML='';filters.forEach(k=>{const v=$(k).value.trim();if(!v)return;const chip=document.createElement('button');chip.className='chip';chip.textContent=`${k}: ${v} ×`;chip.onclick=()=>{$(k).value='';load()};box.appendChild(chip)}); }
async function api(path,p){ const auth=token();$('auth-warning').classList.toggle('hidden',!!auth);if(!auth)throw new Error('请先登录 Xboard 管理端');const response=await fetch(`${API_BASE}${path}?${p}`,{headers:{Authorization:auth,Accept:'application/json'}});const body=await response.json();if(!response.ok)throw new Error(body.message||`HTTP ${response.status}`);return body.data??body; }
function card(label,value){return `<div class="card"><small>${label}</small><strong>${value}</strong></div>`}
async function load(){ syncUrl();$('status').textContent='查询中…';$('status').classList.remove('error');try{const p=params();const [overview,ranking,health]=await Promise.all([api('/overview',params(false)),api('/traffic',p),api('/health',params(false))]);$('cards').innerHTML=[card('总流量',bytes(overview.total_bytes)),card('上传',bytes(overview.upload_bytes)),card('下载',bytes(overview.download_bytes)),card('连接数',Number(overview.connection_count).toLocaleString()),card('活跃用户',overview.user_count),card('活跃节点',overview.node_count),card('域名识别率',overview.quality.domain_recognition_rate==null?'-':(overview.quality.domain_recognition_rate*100).toFixed(1)+'%'),card('目的 IP 识别率',overview.quality.destination_ip_recognition_rate==null?'-':(overview.quality.destination_ip_recognition_rate*100).toFixed(1)+'%'),card('__other__ 占比',overview.quality.collapsed_bytes_rate==null?'-':(overview.quality.collapsed_bytes_rate*100).toFixed(2)+'%'),card('节点待报批次',overview.reported_pending_batches),card('最大重试次数',overview.maximum_retry_attempt),card('迟报批次',overview.late_batch_count),card('批次数',overview.batch_count),card('缺桶估算',overview.missing_bucket_estimate)].join('');
        $('ranking-title').textContent=groups[ranking.group_by]+'流量排行';const body=$('rows');body.innerHTML='';ranking.items.forEach(item=>{const tr=document.createElement('tr');tr.innerHTML=`<td>${item.rank}</td><td>${escapeHtml(item.label)}</td><td class="num">${bytes(item.total_bytes)}</td><td class="num">${bytes(item.upload_bytes)}</td><td class="num">${bytes(item.download_bytes)}</td><td class="num">${Number(item.connection_count).toLocaleString()}</td><td class="num">${duration(item.active_seconds)}</td><td><div class="bar"><i style="width:${Math.min(100,item.percentage)}%"></i></div> ${item.percentage.toFixed(2)}%</td><td></td>`;const key=groupFilter[ranking.group_by];if(key&&item.value!=='unknown'&&item.value!=='__other__'){const b=document.createElement('button');b.textContent='加入筛选';b.onclick=()=>{$(key).value=String(item.value);load()};tr.lastElementChild.appendChild(b)}body.appendChild(tr)});$('status').textContent=`${ranking.total} 项 · 范围总计 ${bytes(ranking.grand_total_bytes)}`;
        const healthBody=$('health-rows');healthBody.innerHTML='';health.items.forEach(item=>{const tr=document.createElement('tr');tr.innerHTML=`<td>${escapeHtml(item.node_name)} (#${item.node_id})</td><td>${escapeHtml(item.last_bucket_start)}</td><td>${escapeHtml(item.last_received_at)}</td><td class="num">${item.report_delay_seconds==null?'-':duration(item.report_delay_seconds)}</td><td class="num">${item.reported_pending_batches??'-'}</td><td class="num">${item.reported_retry_attempt??'-'}</td><td class="num">${item.batch_count}</td>`;healthBody.appendChild(tr)});if(!health.items.length)healthBody.innerHTML='<tr><td colspan="7">当前范围没有节点上报记录</td></tr>';
    }catch(e){$('status').textContent=e.message;$('status').classList.add('error');}}
function escapeHtml(value){const d=document.createElement('div');d.textContent=String(value);return d.innerHTML}
function init(){const q=new URLSearchParams(location.search),now=new Date(),before=new Date(now-86400000);$('from').value=q.has('from')?localDate(q.get('from')):localDate(before);$('to').value=q.has('to')?localDate(q.get('to')):localDate(now);$('group').value=q.get('group_by')||'user';filters.forEach(k=>{$(k).value=q.get(k)||''});$('apply').onclick=load;$('clear').onclick=()=>{filters.forEach(k=>$(k).value='');load()};window.onpopstate=()=>location.reload();renderChips();load()}
init();
</script>
</body>
</html>
