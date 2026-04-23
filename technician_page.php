<?php
session_start();
$currentPage = basename($_SERVER['PHP_SELF']);
require_once 'config.php';

/* Technicians only */
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'technician') {
  header("Location: ./index.php"); exit();
}

function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$techId = (int)($_SESSION['user_id'] ?? 0);
$name   = $_SESSION['name'] ?? 'Technician';

/* Detect subcategory column */
$SUBCOL_DB = '';
foreach (['subcategory','sub_category'] as $try) {
  $res = $conn->query("SHOW COLUMNS FROM complaints LIKE '".$conn->real_escape_string($try)."'");
  if ($res && $res->num_rows > 0) { $SUBCOL_DB = $try; $res->close(); break; }
  if ($res) $res->close();
}

/* ===== Filters (Block/Gender removed) ===== */
$fStatus = trim($_GET['status'] ?? '');
$fFrom   = trim($_GET['from']   ?? '');
$fTo     = trim($_GET['to']     ?? '');
$fSub    = trim($_GET['subcat'] ?? '');

/* WHERE (scoped to this tech) */
$w      = ["c.is_deleted=0", "c.assigned_to=?"];
$types  = "i";
$params = [$techId];

if ($fStatus!=='') { $w[]="c.status=?";      $types.="s"; $params[]=$fStatus; }
if ($fFrom!=='')   { $w[]="c.created_at>=?"; $types.="s"; $params[]=$fFrom.' 00:00:00'; }
if ($fTo!=='')     { $w[]="c.created_at<=?"; $types.="s"; $params[]=$fTo.' 23:59:59'; }
if ($SUBCOL_DB && $fSub!=='') {
  $w[] = "COALESCE(NULLIF(c.`$SUBCOL_DB`,''),'(Unspecified)')=?";
  $types.="s"; $params[]=$fSub;
}
$WS = implode(" AND ", $w);

/* Sub-category options for this technician */
$subcats = [];
if ($SUBCOL_DB) {
  $stmt = $conn->prepare("
    SELECT DISTINCT COALESCE(NULLIF(c.`$SUBCOL_DB`,''),'(Unspecified)') AS s
    FROM complaints c
    WHERE c.is_deleted=0 AND c.assigned_to=?");
  $stmt->bind_param("i", $techId);
  $stmt->execute();
  $rs = $stmt->get_result();
  while($r = $rs->fetch_assoc()){ $subcats[] = $r['s']; }
  $stmt->close();
}

/* ===== KPI ===== */
$stmt=$conn->prepare("
  SELECT
    COUNT(*) AS total_cnt,
    SUM(CASE WHEN c.status='Pending'     THEN 1 ELSE 0 END) AS pend_cnt,
    SUM(CASE WHEN c.status='In Progress' THEN 1 ELSE 0 END) AS prog_cnt,
    SUM(CASE WHEN c.status='Completed'   THEN 1 ELSE 0 END) AS comp_cnt,
    SUM(CASE WHEN c.status='Rejected'    THEN 1 ELSE 0 END) AS rej_cnt
  FROM complaints c
  JOIN profile p ON p.student_id=c.student_id
  WHERE $WS
");
$stmt->bind_param($types, ...$params);
$stmt->execute();
$k = $stmt->get_result()->fetch_assoc() ?: [];
$stmt->close();

$totalAssigned = (int)($k['total_cnt'] ?? 0);
$pending       = (int)($k['pend_cnt']  ?? 0);
$inProgress    = (int)($k['prog_cnt']  ?? 0);
$completed     = (int)($k['comp_cnt']  ?? 0);

/* ===== Charts ===== */
$statusLabels = ['Pending','In Progress','Completed'];
$statusCounts = [$pending,$inProgress,$completed];

$subLabels = []; $subCounts = [];
if ($SUBCOL_DB) {
  $stmt = $conn->prepare("
    SELECT COALESCE(NULLIF(c.`$SUBCOL_DB`, ''),'(Unspecified)') AS subcat,
           COUNT(*) AS cnt
    FROM complaints c
    JOIN profile p ON p.student_id=c.student_id
    WHERE $WS
    GROUP BY subcat
    ORDER BY cnt DESC, subcat ASC
  ");
  $stmt->bind_param($types, ...$params);
  $stmt->execute();
  $rs = $stmt->get_result();
  while($row=$rs->fetch_assoc()){ $subLabels[]=$row['subcat']; $subCounts[]=(int)$row['cnt']; }
  $stmt->close();
}

/* Preserve filters for side-menu links */
$qs = http_build_query([
  'status'=>$fStatus, 'from'=>$fFrom, 'to'=>$fTo, 'subcat'=>$fSub
]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Technician Dashboard</title>

  <link rel="icon" type="image/png" href="assets/favicon.png" sizes="32x32">
  <link rel="apple-touch-icon" href="assets/favicon.png">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
  <link rel="stylesheet" href="admin.css">

  <style>
    :root{
      --card:#fff; --ring:#e5e7eb;
      --shadow:0 10px 26px rgba(0,0,0,.12);
      --soft:0 8px 22px rgba(0,0,0,.10);

      --yellow:#f59e0b; --blue:#2563eb; --green:#22c55e;

      --bg-total:#F1F5F9;
      --bg-pending:#FEF3C7;
      --bg-progress:#DBEAFE;
      --bg-completed:#DCFCE7;
    }
    html,body{ overflow-x:hidden; } /* no side scroll */
    body{ margin:0; }
    body.technician::before{
      content:""; position:fixed; inset:0;
      background:url('assets/dormitory.jpg') center/cover no-repeat;
      filter:blur(8px) brightness(.9); z-index:-2;
    }
    body.technician::after{
      content:""; position:fixed; inset:0; background:rgba(0,0,0,.40); z-index:-1;
    }

    /* Slide-out menu */
    .slide-panel{
      position:fixed; top:0; right:-320px; width:320px; max-width:90vw; height:100vh;
      background:var(--card); border-left:1px solid var(--ring);
      box-shadow:-8px 0 24px rgba(0,0,0,.15);
      transition:right .25s ease; z-index:1001; padding:14px; display:flex; flex-direction:column;
    }
    .slide-panel[aria-hidden="false"]{ right:0; }
    .slide-overlay{ display:none; position:fixed; inset:0; background:rgba(0,0,0,.35); z-index:1000; }
    .slide-header{ display:flex; align-items:center; justify-content:space-between; margin-bottom:10px; }
    .slide-link{ display:block; padding:10px 12px; text-decoration:none; color:#111; border-radius:10px; }
    .slide-link.active,.slide-link:hover{ background:#eef2ff; }
    .slide-divider{ height:1px; background:#e5e7eb; margin:8px 0; }
    .logout-btn-wide{ width:100%; padding:10px 12px; border:0; background:#ef4444; color:#fff; border-radius:10px; cursor:pointer; }

    /* Page width */
    .wrap{ width:min(1180px, 94vw); margin:26px auto 60px; }

    /* Header pill (menu never overlaps on phones) */
    .hero{
      position:relative;
      background:var(--card); border:1px solid var(--ring); border-radius:28px;
      box-shadow:var(--shadow); padding:22px 24px; padding-left:72px;
      display:flex; justify-content:center; align-items:center;
    }
    .hero h1{ margin:0; font-size:28px; font-weight:800; color:#1f2937; text-align:center; }
    .hero p{ margin:6px 0 0; color:#6b7280; text-align:center; }
    .menu-btn{
      position:absolute; left:14px; top:50%; transform:translateY(-50%);
      width:48px; height:48px; border-radius:14px; border:0; cursor:pointer;
      background:#3b82f6; color:#fff; font-size:22px; display:grid; place-items:center;
      box-shadow:0 8px 18px rgba(59,130,246,.32);
    }
    @media (min-width:640px){ .hero{ padding-left:24px; } }

    /* Filters – stay inside frame */
    .filters{
      background:var(--card); border:1px solid var(--ring); border-radius:20px;
      box-shadow:var(--soft); padding:16px; margin:16px 0;
      display:flex; flex-wrap:wrap; gap:12px; align-items:center; overflow:hidden;
    }
    .filters > *{ flex:1 1 240px; min-width:220px; max-width:100%; }
    .filters select, .filters input[type="date"]{
      width:100%; max-width:100%;
      padding:12px 14px; border:1px solid #d1d5db; border-radius:12px; background:#fafafa; font-size:14px;
    }
    .filters .btn{ padding:12px 16px; border:0; border-radius:12px; cursor:pointer; font-weight:700; }
    .filters .primary{ background:#2563eb; color:#fff; }
    .filters .ghost{ background:#eef2ff; color:#111; text-decoration:none; text-align:center; display:inline-block; }
    @media (max-width:640px){
      .filters{ gap:10px; }
      .filters .btn{ flex:1 1 160px; }
    }

    /* KPI row (COMPACT MOBILE) */
    .kpis{
      display:grid; grid-template-columns:repeat(4, minmax(0,1fr)); gap:14px;
    }
    .kpi{
      border:2px solid var(--ring);
      border-radius:18px; box-shadow:var(--soft);
      padding:14px 16px;
      display:flex; align-items:center; gap:12px; justify-content:center;
      background: var(--card);
      min-width:0; /* allow shrink */
    }
    .kpi .icon{ width:40px; height:40px; border-radius:12px; display:grid; place-items:center; font-weight:900; color:#0f172a; }
    .kpi .v{ font-size:22px; font-weight:900; text-align:center; color:#0f172a; line-height:1; }
    .kpi .l{ font-size:13px; color:#475569; text-align:center; white-space:nowrap; }

    /* color variants */
    .kpi--total{ background: var(--bg-total); }
    .kpi--total .icon{ background:#eef2f7; }
    .kpi--pending{ border-color: var(--yellow); box-shadow: 0 8px 22px rgba(245,158,11,.15); background: var(--bg-pending); }
    .kpi--pending .icon{ background:#fde68a; }
    .kpi--progress{ border-color: var(--blue); box-shadow: 0 8px 22px rgba(37,99,235,.15); background: var(--bg-progress); }
    .kpi--progress .icon{ background:#c7d2fe; }
    .kpi--completed{ border-color: var(--green); box-shadow: 0 8px 22px rgba(34,197,94,.15); background: var(--bg-completed); }
    .kpi--completed .icon{ background:#bbf7d0; }

    /* ultra-compact tweaks for phones so 4 tiles fit one row */
    @media (max-width:640px){
      .kpis{ grid-template-columns:repeat(4, 1fr); gap:6px; }
      .kpi{ padding:10px 8px; border-radius:14px; gap:8px; }
      .kpi .icon{ width:28px; height:28px; border-radius:8px; font-size:14px; }
      .kpi .v{ font-size:16px; }
      .kpi .l{ font-size:11px; }
    }

    /* Charts */
    .charts{ display:grid; grid-template-columns:1fr 1fr; gap:18px; margin-top:18px; }
    .chart-card{ background:var(--card); border:1px solid var(--ring); border-radius:18px; box-shadow:var(--soft); padding:16px; height:380px; }
    .chart-card h3{ margin:0 0 10px; text-align:center; color:#111827; }
    .chart-card canvas{ width:100%; height:310px !important; }

    @media (max-width: 980px){ .kpis{ gap:10px; } .charts{ grid-template-columns:1fr; } }
  </style>
</head>
<body class="dashboard technician">

<!-- Slide menu -->
<div class="slide-overlay" id="slideOverlay"></div>
<aside class="slide-panel" id="slidePanel" aria-hidden="true">
  <div class="slide-header">
    <strong>Quick Menu</strong>
    <button id="panelClose" style="border:0;background:#0000;font-size:22px;cursor:pointer" aria-label="Close">&times;</button>
  </div>
  <div class="slide-body">
    <a class="slide-link <?= $currentPage=='technician_page.php'?'active':'' ?>" href="technician_page.php<?= $qs?('?'.$qs):''; ?>">📊 Dashboard</a>
    <a class="slide-link <?= $currentPage=='technician_mytickets.php'?'active':'' ?>" href="technician_mytickets.php">🎫 My Tickets</a>
    <a class="slide-link <?= $currentPage=='technician_reports.php'?'active':'' ?>" href="technician_reports.php">📑 Reports</a>
    <a class="slide-link <?= $currentPage=='technician_profile.php'?'active':'' ?>" href="technician_profile.php">👤 Profile</a>
    <div class="slide-divider"></div>
    <form action="logout.php" method="post"><button type="submit" class="logout-btn-wide">⏻ Logout</button></form>
  </div>
</aside>

<div class="wrap">

  <!-- Header -->
  <section class="hero">
    <button id="panelToggleBtn" class="menu-btn" aria-label="Open menu">≡</button>
    <div>
      <h1>Welcome, <?= strtoupper(e($name)) ?></h1>
      <p>Your assigned tickets overview</p>
    </div>
  </section>

  <!-- Filters -->
  <form method="get" class="filters">
    <select name="status" aria-label="Status">
      <option value="">Status (All)</option>
      <?php foreach(['Pending','In Progress','Completed'] as $s): ?>
        <option value="<?= e($s) ?>" <?= $fStatus===$s?'selected':'' ?>><?= e($s) ?></option>
      <?php endforeach; ?>
    </select>

    <select name="subcat" aria-label="Sub-Category">
      <option value="">Sub-Category (All)</option>
      <?php foreach($subcats as $sc): ?>
        <option value="<?= e($sc) ?>" <?= $fSub===$sc?'selected':'' ?>><?= e($sc) ?></option>
      <?php endforeach; ?>
    </select>

    <input type="date" name="from" value="<?= e($fFrom) ?>" aria-label="From date">
    <input type="date" name="to"   value="<?= e($fTo)   ?>" aria-label="To date">

    <button class="btn primary" type="submit">Apply</button>
    <a class="btn ghost" href="technician_page.php">Reset</a>
  </form>

  <!-- KPI (now compact on phones) -->
  <section class="kpis">
    <div class="kpi kpi--total">
      <div class="icon">Σ</div>
      <div><div class="v"><?= $totalAssigned ?></div><div class="l">Total Tickets</div></div>
    </div>
    <div class="kpi kpi--pending">
      <div class="icon">⏳</div>
      <div><div class="v"><?= $pending ?></div><div class="l">Pending</div></div>
    </div>
    <div class="kpi kpi--progress">
      <div class="icon">🛠️</div>
      <div><div class="v"><?= $inProgress ?></div><div class="l">In Progress</div></div>
    </div>
    <div class="kpi kpi--completed">
      <div class="icon">✅</div>
      <div><div class="v"><?= $completed ?></div><div class="l">Completed</div></div>
    </div>
  </section>

  <!-- Charts -->
  <section class="charts">
    <div class="chart-card">
      <h3>Tickets by Sub-Category</h3>
      <canvas id="subChart"></canvas>
    </div>
    <div class="chart-card">
      <h3>Tickets by Status</h3>
      <canvas id="statusChart"></canvas>
    </div>
  </section>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
  const subLabels    = <?= json_encode($subLabels, JSON_UNESCAPED_UNICODE) ?>;
  const subCounts    = <?= json_encode($subCounts, JSON_NUMERIC_CHECK) ?>;
  const statusLabels = <?= json_encode($statusLabels, JSON_UNESCAPED_UNICODE) ?>;
  const statusCounts = <?= json_encode($statusCounts, JSON_NUMERIC_CHECK) ?>;

  const palette = ['#7c3aed','#dc2626','#2563eb','#059669','#f59e0b','#db2777','#0ea5e9','#10b981','#f97316','#9333ea','#ef4444','#22d3ee'];

  // Sub-Category chart
  const sctx = document.getElementById('subChart');
  if (sctx) {
    new Chart(sctx, {
      type: 'bar',
      data: {
        labels: subLabels,
        datasets: [{
          label: 'Tickets',
          data: subCounts,
          backgroundColor: subLabels.map((_,i)=>palette[i % palette.length]),
          borderColor:   subLabels.map((_,i)=>palette[i % palette.length]),
          borderWidth: 2, borderRadius: 8, barThickness: 'flex'
        }]
      },
      options: {
        indexAxis: 'y', responsive: true, maintainAspectRatio: false,
        scales: { x: { beginAtZero:true, ticks:{ precision:0 } } },
        plugins: { legend:{ display:false } }, animation:{ duration:300 }
      }
    });
  }

  // Status chart
  const stx = document.getElementById('statusChart');
  if (stx) {
    new Chart(stx, {
      type: 'bar',
      data: {
        labels: statusLabels,
        datasets: [{
          data: statusCounts,
          backgroundColor: ['#f59e0b','#2563eb','#22c55e'],
          borderColor: ['#f59e0b','#2563eb','#22c55e'],
          borderWidth: 2, borderRadius: 6
        }]
      },
      options: {
        responsive:true, maintainAspectRatio:false,
        plugins:{ legend:{ display:false } },
        scales:{ y:{ beginAtZero:true, ticks:{ precision:0 } } }
      }
    });
  }

  // Slide Menu
  function openPanel(){ document.getElementById("slidePanel").setAttribute("aria-hidden","false"); document.getElementById("slideOverlay").style.display="block"; }
  function closePanel(){ document.getElementById("slidePanel").setAttribute("aria-hidden","true");  document.getElementById("slideOverlay").style.display="none"; }
  document.getElementById("panelToggleBtn").addEventListener("click", openPanel);
  document.getElementById("panelClose").addEventListener("click", closePanel);
  document.getElementById("slideOverlay").addEventListener("click", closePanel);
</script>

</body>
</html>
