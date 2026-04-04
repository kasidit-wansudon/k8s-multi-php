<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>K8s SQL Benchmark — Laravel</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Segoe UI',sans-serif;background:#0f172a;color:#e2e8f0;padding:24px}
.wrap{max-width:1000px;margin:0 auto}
.header{text-align:center;margin-bottom:28px}
.badge{display:inline-block;background:#f43f5e;color:#fff;font-size:11px;font-weight:700;letter-spacing:1px;padding:4px 14px;border-radius:20px;text-transform:uppercase;margin-bottom:10px}
h1{font-size:26px;font-weight:700;color:#f1f5f9}
h1 span{color:#fb7185}
.metrics{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:12px;margin-bottom:24px}
.card{background:#1e293b;border:1px solid #334155;border-radius:10px;padding:16px}
.card.hi{border-color:#f43f5e;background:#1c1018;grid-column:1/-1}
.lbl{font-size:10px;color:#64748b;text-transform:uppercase;letter-spacing:1px;margin-bottom:4px}
.val{font-size:22px;font-weight:700;color:#f1f5f9}
.val.g{color:#34d399}.val.b{color:#60a5fa}.val.r{color:#fb7185}.val.y{color:#fbbf24}
.val small{font-size:12px;color:#94a3b8;font-weight:400}
.sub{font-size:12px;color:#64748b;margin-top:4px}
h2{font-size:14px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:1px;margin:20px 0 10px}
.qblock{background:#1e293b;border:1px solid #334155;border-radius:10px;padding:16px;margin-bottom:14px}
.qhead{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px}
.qname{font-size:13px;font-weight:600;color:#f1f5f9}
.qtag{font-size:11px;background:#0f172a;border:1px solid #475569;border-radius:6px;padding:2px 10px;color:#94a3b8}
.qtag.fast{border-color:#34d399;color:#34d399}
.qtag.med{border-color:#fbbf24;color:#fbbf24}
.qtag.slow{border-color:#fb7185;color:#fb7185}
table{width:100%;border-collapse:collapse;font-size:12px}
th{background:#0f172a;color:#64748b;text-transform:uppercase;font-size:10px;letter-spacing:1px;padding:6px 10px;text-align:left;border-bottom:1px solid #334155}
td{padding:5px 10px;border-bottom:1px solid #1e293b;color:#cbd5e1}
tr:last-child td{border-bottom:none}
tr:hover td{background:#1e293b}
.form{background:#1e293b;border:1px solid #334155;border-radius:10px;padding:16px;display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-top:16px}
.form label{font-size:13px;color:#94a3b8}
.form input{background:#0f172a;border:1px solid #475569;border-radius:6px;color:#f1f5f9;padding:6px 12px;font-size:13px;width:140px}
.form button{background:#f43f5e;color:#fff;border:none;border-radius:6px;padding:6px 18px;font-size:13px;font-weight:600;cursor:pointer}
.form button:hover{background:#e11d48}
</style>
</head>
<body>
<div class="wrap">
<div class="header">
    <div class="badge">Laravel</div>
    <h1>K8s <span>SQL Benchmark</span></h1>
</div>

<div class="metrics">
    <div class="card hi">
        <div class="lbl">Pod Hostname — K8s Load Balancing</div>
        <div class="val r">{{ $hostname }}</div>
        <div class="sub">รีเฟรชหลายครั้ง → hostname เปลี่ยน = K8s กระจาย pods &nbsp;|&nbsp; PHP {{ $phpVersion }} &nbsp;|&nbsp; {{ $framework }}</div>
    </div>
    <div class="card">
        <div class="lbl">Total Page Time</div>
        <div class="val g">{{ $totalTime }}<small> ms</small></div>
    </div>
    <div class="card">
        <div class="lbl">CPU Benchmark</div>
        <div class="val b">{{ $cpuTime }}<small> ms</small></div>
    </div>
    <div class="card">
        <div class="lbl">Total DB Time</div>
        <div class="val y">{{ $totalDbMs }}<small> ms</small></div>
    </div>
    <div class="card">
        <div class="lbl">Loops</div>
        <div class="val">{{ number_format($loops) }}</div>
    </div>
    <div class="card">
        <div class="lbl">Peak Memory</div>
        <div class="val">{{ $memoryPeak }}<small> MB</small></div>
    </div>
    <div class="card">
        <div class="lbl">DB Init</div>
        <div class="val">{{ $dbInitMs }}<small> ms</small></div>
    </div>
</div>

<h2>SQL Query Results — 6 tables (bench_categories, products, customers, orders, order_items, reviews)</h2>

@foreach($queries as $q)
@php
  $cls = $q['ms'] < 20 ? 'fast' : ($q['ms'] < 100 ? 'med' : 'slow');
@endphp
<div class="qblock">
    <div class="qhead">
        <div class="qname">{{ $q['label'] }}</div>
        <div class="qtag {{ $cls }}">{{ $q['ms'] }} ms &nbsp;|&nbsp; {{ $q['rows'] }} rows @if(!empty($q['cached'])) &nbsp;|&nbsp; ⚡ cached @endif</div>
    </div>
    @if(count($q['data']) > 0)
    <table>
        <thead><tr>@foreach(array_keys((array)$q['data'][0]) as $col)<th>{{ $col }}</th>@endforeach</tr></thead>
        <tbody>
        @foreach($q['data'] as $row)
        <tr>@foreach((array)$row as $v)<td>{{ is_numeric($v) ? number_format((float)$v, 2, '.', ',') : $v }}</td>@endforeach</tr>
        @endforeach
        </tbody>
    </table>
    @endif
</div>
@endforeach

<form class="form" method="GET">
    <label>CPU Loops:</label>
    <input type="number" name="loops" value="{{ (int)$loops }}"  step="1000">
    <button type="submit">Run Again</button>
</form>
</div>
</body>
</html>
