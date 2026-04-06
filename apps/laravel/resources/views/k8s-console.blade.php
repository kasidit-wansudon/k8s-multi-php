<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>K8s Auto-Scale Console</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Segoe UI',monospace;background:#0a0e1a;color:#e2e8f0;padding:20px;min-height:100vh}
.wrap{max-width:1100px;margin:0 auto}
.header{display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;flex-wrap:wrap;gap:10px}
.title{font-size:22px;font-weight:700;color:#f1f5f9}
.title span{color:#38bdf8}
.status-bar{display:flex;gap:16px;align-items:center;font-size:12px;color:#64748b}
.dot{width:8px;height:8px;border-radius:50%;display:inline-block;margin-right:5px;animation:pulse 1.5s infinite}
.dot.live{background:#34d399}.dot.err{background:#fb7185;animation:none}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.4}}

/* HPA Cards */
.hpa-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:14px;margin-bottom:24px}
.hpa-card{background:#111827;border:1px solid #1e3a5f;border-radius:12px;padding:18px;position:relative;overflow:hidden}
.hpa-card.scaling{border-color:#fbbf24;box-shadow:0 0 16px rgba(251,191,36,.15)}
.hpa-name{font-size:13px;font-weight:700;color:#38bdf8;margin-bottom:12px;display:flex;justify-content:space-between;align-items:center}
.hpa-badge{font-size:10px;font-weight:600;padding:2px 8px;border-radius:10px;background:#1e3a5f;color:#7dd3fc}
.hpa-badge.scaling{background:#451a03;color:#fbbf24}
.replicas-vis{display:flex;gap:6px;margin:12px 0;flex-wrap:wrap}
.pod-icon{width:28px;height:28px;border-radius:6px;display:flex;align-items:center;justify-content:center;font-size:14px;transition:all .3s}
.pod-icon.active{background:#0f3460;border:1px solid #38bdf8}
.pod-icon.pending{background:#451a03;border:1px solid #fbbf24;animation:pulse 1s infinite}
.pod-icon.empty{background:#1e293b;border:1px dashed #334155;opacity:.4}
.hpa-stats{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-top:12px}
.stat{background:#0f172a;border-radius:8px;padding:8px;text-align:center}
.stat-lbl{font-size:9px;color:#475569;text-transform:uppercase;letter-spacing:.5px;margin-bottom:3px}
.stat-val{font-size:18px;font-weight:700;color:#f1f5f9}
.stat-val.green{color:#34d399}.stat-val.yellow{color:#fbbf24}.stat-val.red{color:#fb7185}
.cpu-bar-wrap{margin-top:12px}
.cpu-bar-bg{background:#1e293b;border-radius:4px;height:6px;overflow:hidden}
.cpu-bar{height:6px;border-radius:4px;transition:width .5s ease;background:linear-gradient(90deg,#38bdf8,#818cf8)}
.cpu-bar.warn{background:linear-gradient(90deg,#fbbf24,#f97316)}
.cpu-bar.hot{background:linear-gradient(90deg,#fb7185,#e11d48)}
.cpu-label{display:flex;justify-content:space-between;font-size:10px;color:#64748b;margin-bottom:4px}

/* Pods Table */
h2{font-size:12px;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:1px;margin-bottom:10px}
.table-wrap{background:#111827;border:1px solid #1e293b;border-radius:12px;overflow:hidden;margin-bottom:20px}
table{width:100%;border-collapse:collapse;font-size:12px}
th{background:#0d1117;color:#475569;text-transform:uppercase;font-size:10px;letter-spacing:1px;padding:8px 12px;text-align:left;border-bottom:1px solid #1e293b}
td{padding:7px 12px;border-bottom:1px solid #0f172a;color:#cbd5e1;font-family:monospace}
tr:last-child td{border-bottom:none}
tr.new-pod td{animation:highlight 2s ease-out}
@keyframes highlight{0%{background:#1e3a5f}100%{background:transparent}}
.status-badge{display:inline-flex;align-items:center;gap:4px;font-size:10px;font-weight:600;padding:2px 8px;border-radius:10px}
.status-badge.running{background:#052e16;color:#34d399;border:1px solid #166534}
.status-badge.pending{background:#451a03;color:#fbbf24;border:1px solid #92400e}
.status-badge.other{background:#1e293b;color:#94a3b8;border:1px solid #334155}
.app-tag{font-size:10px;padding:2px 7px;border-radius:8px;font-weight:600}
.app-tag.php84{background:#1e1b4b;color:#818cf8;border:1px solid #312e81}
.app-tag.php74{background:#1c1018;color:#fb7185;border:1px solid #7f1d1d}
.app-tag.other{background:#0f2027;color:#64748b;border:1px solid #1e293b}
.cpu-mini{display:flex;align-items:center;gap:5px}
.cpu-mini-bar{width:50px;height:4px;background:#1e293b;border-radius:2px;overflow:hidden}
.cpu-mini-fill{height:4px;border-radius:2px;background:#38bdf8}

/* Load Test Panel */
.load-panel{background:#111827;border:1px solid #1e293b;border-radius:12px;padding:16px;margin-bottom:20px}
.load-panel h3{font-size:12px;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:1px;margin-bottom:12px}
.btn-row{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
.btn{border:none;border-radius:8px;padding:8px 18px;font-size:12px;font-weight:600;cursor:pointer;transition:all .2s}
.btn-red{background:#e11d48;color:#fff}.btn-red:hover{background:#be123c}
.btn-green{background:#059669;color:#fff}.btn-green:hover{background:#047857}
.btn-gray{background:#1e293b;color:#94a3b8;border:1px solid #334155}.btn-gray:hover{background:#334155}
.load-status{font-size:11px;color:#64748b;margin-top:8px;font-family:monospace}
.load-status .active{color:#fbbf24}

/* Refresh indicator */
.refresh-ring{display:flex;align-items:center;gap:6px;font-size:11px;color:#475569}
.ring{width:14px;height:14px;border:2px solid #1e293b;border-top-color:#38bdf8;border-radius:50%;animation:spin 1s linear infinite;display:none}
.ring.spinning{display:block}
@keyframes spin{to{transform:rotate(360deg)}}
</style>
</head>
<body>
<div class="wrap">

<div class="header">
    <div>
        <div class="title">K8s <span>Auto-Scale</span> Console</div>
        <div style="font-size:11px;color:#475569;margin-top:3px">Namespace: <span style="color:#38bdf8">oway</span> &nbsp;|&nbsp; Auto-refresh ทุก 2 วินาที</div>
    </div>
    <div class="status-bar">
        <div class="refresh-ring"><div class="ring" id="ring"></div></div>
        <div id="ts" style="font-size:12px;color:#38bdf8;font-family:monospace">--:--:--</div>
        <div><span class="dot live" id="live-dot"></span><span id="live-txt">Live</span></div>
        <a href="/" style="font-size:11px;color:#475569;text-decoration:none">← Benchmark</a>
    </div>
</div>

<!-- HPA Cards -->
<h2>Horizontal Pod Autoscalers</h2>
<div class="hpa-grid" id="hpa-grid">
    <div class="hpa-card"><div style="color:#475569;font-size:12px;padding:20px 0;text-align:center">กำลังโหลด...</div></div>
</div>

<!-- Load Test -->
<div class="load-panel">
    <h3>Load Test — กด Start เพื่อดู Auto-Scale ทำงาน</h3>
    <div class="btn-row">
        <button class="btn btn-red" onclick="startLoad('php84', 20)">🔥 Laravel Load (20 req)</button>
        <button class="btn btn-red" onclick="startLoad('php74', 20)">🔥 Laminas Load (20 req)</button>
        <button class="btn btn-green" onclick="startLoad('both', 30)">⚡ Both (30 req each)</button>
        <button class="btn btn-gray" onclick="stopLoad()">■ Stop</button>
    </div>
    <div class="load-status" id="load-status">ยังไม่ได้รัน — กด Start แล้วดู HPA scale pods ขึ้น</div>
</div>

<!-- Pods Table -->
<h2>Pods — <span id="pod-count" style="color:#38bdf8">0</span> pods</h2>
<div class="table-wrap">
    <table>
        <thead><tr>
            <th>Pod Name</th><th>App</th><th>Status</th><th>Node</th>
            <th>CPU</th><th>Memory</th><th>Age</th>
        </tr></thead>
        <tbody id="pods-body">
            <tr><td colspan="7" style="text-align:center;color:#475569;padding:20px">กำลังโหลด...</td></tr>
        </tbody>
    </table>
</div>

</div>

<script>
let prevPodNames = new Set();
let loadInterval = null;
let loadCount = 0;
let loadTarget = 0;

async function fetchData() {
    document.getElementById('ring').classList.add('spinning');
    try {
        const r = await fetch('/k8s/api');
        const d = await r.json();
        renderHpas(d.hpas || []);
        renderPods(d.pods || []);
        document.getElementById('ts').textContent = d.ts || '--';
        document.getElementById('live-dot').className = 'dot live';
        document.getElementById('live-txt').textContent = 'Live · ' + (d.hostname || '');
    } catch(e) {
        document.getElementById('live-dot').className = 'dot err';
        document.getElementById('live-txt').textContent = 'Error';
    }
    document.getElementById('ring').classList.remove('spinning');
}

function renderHpas(hpas) {
    if (!hpas.length) {
        document.getElementById('hpa-grid').innerHTML = '<div class="hpa-card"><div style="color:#fb7185;font-size:12px;padding:20px 0;text-align:center">ไม่พบ HPA — รัน deploy.sh ก่อน</div></div>';
        return;
    }
    document.getElementById('hpa-grid').innerHTML = hpas.map(h => {
        const isScaling = h.desired > h.current || h.desired < h.current;
        const cpuPct = typeof h.cpu_current === 'number' ? h.cpu_current : 0;
        const cpuTarget = h.cpu_target || 50;
        const barW = Math.min(100, cpuPct);
        const barClass = cpuPct >= cpuTarget ? (cpuPct >= cpuTarget * 1.5 ? 'hot' : 'warn') : '';
        const labelColor = cpuPct >= cpuTarget ? (cpuPct >= cpuTarget * 1.5 ? 'red' : 'yellow') : 'green';

        // pod icons: current=active, desired-current=pending, max-desired=empty
        let icons = '';
        for (let i = 0; i < h.max; i++) {
            if (i < h.current) icons += '<div class="pod-icon active">🟦</div>';
            else if (i < h.desired) icons += '<div class="pod-icon pending">🟡</div>';
            else icons += '<div class="pod-icon empty">□</div>';
        }

        return `<div class="hpa-card ${isScaling ? 'scaling' : ''}">
            <div class="hpa-name">
                <span>${h.name}</span>
                <span class="hpa-badge ${isScaling ? 'scaling' : ''}">${isScaling ? '⚡ Scaling' : '✓ Stable'}</span>
            </div>
            <div class="replicas-vis">${icons}</div>
            <div class="hpa-stats">
                <div class="stat"><div class="stat-lbl">Current</div><div class="stat-val green">${h.current}</div></div>
                <div class="stat"><div class="stat-lbl">Desired</div><div class="stat-val ${h.desired > h.current ? 'yellow' : 'green'}">${h.desired}</div></div>
                <div class="stat"><div class="stat-lbl">Max</div><div class="stat-val">${h.max}</div></div>
            </div>
            <div class="cpu-bar-wrap">
                <div class="cpu-label">
                    <span>CPU Usage</span>
                    <span class="${labelColor === 'red' ? 'stat-val red' : labelColor === 'yellow' ? 'stat-val yellow' : ''}" style="font-size:10px">
                        ${cpuPct !== 0 ? cpuPct + '%' : 'รอ metrics...'} / target ${cpuTarget}%
                    </span>
                </div>
                <div class="cpu-bar-bg"><div class="cpu-bar ${barClass}" style="width:${barW}%"></div></div>
            </div>
        </div>`;
    }).join('');
}

function renderPods(pods) {
    const currentNames = new Set(pods.map(p => p.name));
    document.getElementById('pod-count').textContent = pods.length;
    document.getElementById('pods-body').innerHTML = pods.map(p => {
        const isNew = !prevPodNames.has(p.name) && prevPodNames.size > 0;
        const appClass = p.app === 'php84' ? 'php84' : p.app === 'php74' ? 'php74' : 'other';
        const statusClass = p.phase === 'Running' ? 'running' : p.phase === 'Pending' ? 'pending' : 'other';
        const cpuBarW = Math.min(100, p.cpuM / 5); // 500m = 100%
        const shortName = p.name.length > 38 ? p.name.substring(0, 38) + '…' : p.name;
        return `<tr class="${isNew ? 'new-pod' : ''}">
            <td title="${p.name}">${shortName}</td>
            <td><span class="app-tag ${appClass}">${p.app}</span></td>
            <td><span class="status-badge ${statusClass}">${p.phase === 'Running' && p.ready ? '● Running' : p.phase}</span></td>
            <td style="color:#64748b">${p.node}</td>
            <td><div class="cpu-mini">
                <span style="width:32px;text-align:right;color:${p.cpuM>300?'#fb7185':p.cpuM>150?'#fbbf24':'#34d399'}">${p.cpuM}m</span>
                <div class="cpu-mini-bar"><div class="cpu-mini-fill" style="width:${cpuBarW}%"></div></div>
            </div></td>
            <td style="color:#94a3b8">${p.memMi}Mi</td>
            <td style="color:#475569">${p.age}</td>
        </tr>`;
    }).join('') || '<tr><td colspan="7" style="text-align:center;color:#475569;padding:20px">ไม่มี pods</td></tr>';
    prevPodNames = currentNames;
}

// ── Load test: ยิง concurrent requests เพื่อ trigger CPU spike ──
function startLoad(target, concurrency) {
    stopLoad();
    loadCount = 0;
    loadTarget = concurrency;
    const urls = [];
    if (target === 'php84' || target === 'both') urls.push('https://laravel.localhost:666/?loops=800000');
    if (target === 'php74' || target === 'both') urls.push('https://zend2.localhost:666/?loops=800000');

    document.getElementById('load-status').innerHTML = '<span class="active">⚡ กำลังยิง requests... (loops=800000 ต่อ request) — รอดู HPA scale up</span>';

    function fire() {
        urls.forEach(url => {
            for (let i = 0; i < concurrency; i++) {
                fetch(url, {mode: 'no-cors'}).then(() => {
                    loadCount++;
                    document.getElementById('load-status').innerHTML =
                        `<span class="active">⚡ ยิงไปแล้ว ${loadCount} requests — ดู CPU bar ขึ้น → HPA จะ scale pods</span>`;
                }).catch(() => { loadCount++; });
            }
        });
    }

    fire();
    loadInterval = setInterval(fire, 3000);
}

function stopLoad() {
    if (loadInterval) { clearInterval(loadInterval); loadInterval = null; }
    document.getElementById('load-status').textContent = 'หยุดแล้ว — รอดู pods scale down (30 วินาที)';
}

// Auto-refresh ทุก 2 วินาที
fetchData();
setInterval(fetchData, 2000);
</script>
</body>
</html>
