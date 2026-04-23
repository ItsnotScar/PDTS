<?php
session_start();
require_once 'config.php';

/* Allow admin OR boss_ups */
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin','boss_ups'], true)) {
  header("Location: ./index.php"); exit();
}

/* Helpers */
function val($k){ return trim($_GET[$k] ?? ''); }
function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* Fixed categories & subcategory detection (matches admin_page.php) */
$FIXED_CATS = ['KEJURUTERAAN AWAM','KEJURUTERAAN ELEKTRIK','KEJURUTERAAN MEKANIKAL'];
$SUBCOL_DB = ''; $hasSubCategory=false;
foreach (['sub_category','subcategory'] as $try) {
  $res=$conn->query("SHOW COLUMNS FROM complaints LIKE '".$conn->real_escape_string($try)."'");
  if ($res && $res->num_rows > 0){ $SUBCOL_DB=$try; $hasSubCategory=true; }
  if ($res) $res->close();
}

/* Filters (mirror dashboard) */
$dbBlock     = val('db_block');
$dbGender    = strtolower(val('db_gender'));
$dbStatus    = val('db_status');
$dbCategory  = val('db_category');
$dbSubCat    = val('db_subcategory');
$dbFrom      = val('db_from');
$dbTo        = val('db_to');

/* WHERE + params */
$w=["c.is_deleted=0"]; $types=''; $params=[];
if ($dbBlock!==''){ $w[]="p.block=?"; $types.='s'; $params[]=$dbBlock; }
if ($dbGender==='male' || $dbGender==='female'){ $w[]="p.gender=?"; $types.='s'; $params[]=$dbGender; }
if ($dbStatus!==''){ $w[]="c.status=?"; $types.='s'; $params[]=$dbStatus; }
if ($dbCategory!==''){ $w[]="c.category=?"; $types.='s'; $params[]=$dbCategory; }
if ($hasSubCategory && $dbSubCat!==''){ $w[]="c.`$SUBCOL_DB`=?"; $types.='s'; $params[]=$dbSubCat; }
if ($dbFrom!==''){ $w[]="c.created_at>=?"; $types.='s'; $params[]=$dbFrom.' 00:00:00'; }
if ($dbTo  !==''){ $w[]="c.created_at<=?"; $types.='s'; $params[]=$dbTo.' 23:59:59'; }
$WS = implode(' AND ', $w);

/* KPI */
$stmt=$conn->prepare("
  SELECT
    COUNT(*)                                                          AS total_cnt,
    SUM(CASE WHEN c.status='Pending'     THEN 1 ELSE 0 END)           AS pend_cnt,
    SUM(CASE WHEN c.status='In Progress' THEN 1 ELSE 0 END)           AS prog_cnt,
    SUM(CASE WHEN c.status='Completed'   THEN 1 ELSE 0 END)           AS comp_cnt,
    SUM(CASE WHEN c.status='Rejected'    THEN 1 ELSE 0 END)           AS rej_cnt
  FROM complaints c
  JOIN profile p ON p.student_id=c.student_id
  WHERE $WS
");
if($types) $stmt->bind_param($types, ...$params);
$stmt->execute(); $k=$stmt->get_result()->fetch_assoc() ?: [];
$stmt->close();

/* Categories with fixed order (zeros included) */
$catCountsMap = array_fill_keys($FIXED_CATS, 0);
$stmt=$conn->prepare("
  SELECT c.category, COUNT(*) cnt
  FROM complaints c JOIN profile p ON p.student_id=c.student_id
  WHERE $WS
  GROUP BY c.category
");
if($types) $stmt->bind_param($types, ...$params);
$stmt->execute();
$rs=$stmt->get_result();
while($r=$rs->fetch_assoc()){
  $cat = (string)$r['category'];
  if (isset($catCountsMap[$cat])) $catCountsMap[$cat] = (int)$r['cnt'];
}
$stmt->close();
$catRows=[];
foreach ($FIXED_CATS as $fc) { $catRows[] = ['category'=>$fc, 'cnt'=>$catCountsMap[$fc]]; }

/* Blocks male vs female (include all blocks) */
$allBlocks = [];
$blkListRes = $conn->query("SELECT DISTINCT block FROM profile WHERE block IS NOT NULL AND block<>'' ORDER BY block");
while($b = $blkListRes->fetch_assoc()){ $allBlocks[] = $b['block']; }
$blkMap = [];
foreach ($allBlocks as $b) { $blkMap[$b] = ['male_cnt'=>0,'female_cnt'=>0]; }

$stmt=$conn->prepare("
  SELECT p.block,
         SUM(CASE WHEN p.gender='male' THEN 1 ELSE 0 END) male_cnt,
         SUM(CASE WHEN p.gender='female' THEN 1 ELSE 0 END) female_cnt
  FROM complaints c JOIN profile p ON p.student_id=c.student_id
  WHERE $WS
  GROUP BY p.block
");
if($types) $stmt->bind_param($types, ...$params);
$stmt->execute(); $rs=$stmt->get_result();
while($r=$rs->fetch_assoc()){
  $b = (string)$r['block'];
  if (!isset($blkMap[$b])) $blkMap[$b] = ['male_cnt'=>0,'female_cnt'=>0];
  $blkMap[$b]['male_cnt']   = (int)$r['male_cnt'];
  $blkMap[$b]['female_cnt'] = (int)$r['female_cnt'];
}
$stmt->close();
$blkRows=[];
foreach ($allBlocks as $b) {
  $blkRows[] = ['block'=>$b, 'male_cnt'=>$blkMap[$b]['male_cnt'], 'female_cnt'=>$blkMap[$b]['female_cnt']];
}

/* Sub-categories per category */
$subRows=[];
if ($hasSubCategory){
  $stmt=$conn->prepare("
    SELECT c.category,
           COALESCE(NULLIF(c.`$SUBCOL_DB`, ''), '(Unspecified)') AS subcat,
           COUNT(*) cnt
    FROM complaints c
    JOIN profile p ON p.student_id=c.student_id
    WHERE $WS
    GROUP BY c.category, subcat
    ORDER BY c.category, cnt DESC, subcat
  ");
  if($types) $stmt->bind_param($types, ...$params);
  $stmt->execute(); $rs=$stmt->get_result();
  while($r=$rs->fetch_assoc()){ $subRows[]=$r; }
  $stmt->close();
}

/* Prep chart arrays */
$catLabels=[]; $catValues=[];
foreach($catRows as $r){ $catLabels[]=$r['category']; $catValues[]=(int)$r['cnt']; }
$subLabels=[]; $subValues=[];
if ($hasSubCategory){
  foreach($subRows as $r){ $subLabels[]=$r['category'].': '.$r['subcat']; $subValues[]=(int)$r['cnt']; }
}
$statusLabels=['Pending','In Progress','Completed','Rejected'];
$statusValues=[(int)($k['pend_cnt']??0),(int)($k['prog_cnt']??0),(int)($k['comp_cnt']??0),(int)($k['rej_cnt']??0)];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Dashboard Export (Printable)</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <style>
    @media print { .no-print { display:none !important; } body { -webkit-print-color-adjust: exact; print-color-adjust: exact; } }
    body { font-family: Arial, sans-serif; margin: 24px; color:#111; }
    h1 { margin:0 0 6px; font-size: 20px; }
    h2 { margin:18px 0 8px; font-size: 16px; }
    .muted { color:#555; margin-bottom: 16px; }
    .actions { margin: 12px 0 20px; }
    .btn { display:inline-block; padding:8px 12px; background:#2563eb; color:#fff; text-decoration:none; border-radius:6px; }
    .pill { display:inline-block; padding:2px 8px; border-radius:999px; background:#eef2ff; font-size:12px; margin:0 6px 6px 0; }
    .charts-grid { display:flex; gap:24px; flex-wrap:wrap; align-items:stretch; }
    .chart-wrap { width:100%; max-width:700px; height:320px; margin:8px 0 22px; }
    table { width:100%; border-collapse: collapse; margin-top: 8px; }
    th, td { border:1px solid #ddd; padding:8px; text-align:left; font-size: 12px; }
    thead th { background:#f3f4f6; text-transform: uppercase; letter-spacing: .03em; }
  </style>
</head>
<body>

  <div class="actions no-print">
    <a class="btn" href="admin_page.php?section=dashboard">&larr; Back</a>
    <a class="btn" href="#" onclick="window.print();return false;">Print</a>
  </div>

  <h1>Dashboard Export</h1>
  <div class="muted">Generated: <?= date('Y-m-d H:i:s') ?></div>

  <div style="margin:8px 0 12px;">
    <span class="pill">Block: <?= $dbBlock ? e($dbBlock) : 'All' ?></span>
    <span class="pill">Gender: <?= $dbGender ? e($dbGender) : 'All' ?></span>
    <span class="pill">Status: <?= $dbStatus ? e($dbStatus) : 'All' ?></span>
    <span class="pill">Category: <?= $dbCategory ? e($dbCategory) : 'All' ?></span>
    <span class="pill">Sub-Category: <?= $hasSubCategory ? ($dbSubCat ? e($dbSubCat) : 'All') : '—' ?></span>
    <span class="pill">From: <?= $dbFrom ? e($dbFrom) : '—' ?></span>
    <span class="pill">To: <?= $dbTo ? e($dbTo) : '—' ?></span>
  </div>

  <h2>KPI</h2>
  <table>
    <thead><tr><th>Total</th><th>Pending</th><th>In Progress</th><th>Completed</th><th>Rejected</th></tr></thead>
    <tbody>
      <tr>
        <td><?= (int)($k['total_cnt'] ?? 0) ?></td>
        <td><?= (int)($k['pend_cnt']  ?? 0) ?></td>
        <td><?= (int)($k['prog_cnt']  ?? 0) ?></td>
        <td><?= (int)($k['comp_cnt']  ?? 0) ?></td>
        <td><?= (int)($k['rej_cnt']   ?? 0) ?></td>
      </tr>
    </tbody>
  </table>

  <div class="charts-grid">
    <div style="flex:1 1 340px; min-width:300px;">
      <h2>Tickets by Category</h2>
      <div class="chart-wrap"><canvas id="catChart" height="320"></canvas></div>
    </div>

    <?php if ($hasSubCategory): ?>
    <div style="flex:1 1 340px; min-width:300px;">
      <h2>Sub-Categories</h2>
      <div class="chart-wrap"><canvas id="subChart" height="320"></canvas></div>
    </div>
    <?php endif; ?>

    <div style="flex:1 1 340px; min-width:300px;">
      <h2>Status Breakdown</h2>
      <div class="chart-wrap"><canvas id="statusChart" height="320"></canvas></div>
    </div>
  </div>

  <h2>Tickets by Block — Male vs Female</h2>
  <table>
    <thead><tr><th>Block</th><th>Male</th><th>Female</th></tr></thead>
    <tbody>
      <?php if (!$blkRows): ?>
        <tr><td colspan="3" style="text-align:center;">No data</td></tr>
      <?php else: foreach($blkRows as $r): ?>
        <tr>
          <td><?= e($r['block'] ?: 'N/A') ?></td>
          <td><?= (int)$r['male_cnt'] ?></td>
          <td><?= (int)$r['female_cnt'] ?></td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>

  <h2>Tickets by Category</h2>
  <table>
    <thead><tr><th>Category</th><th>Count</th></tr></thead>
    <tbody>
      <?php foreach($catRows as $r): ?>
        <tr><td><?= e($r['category']) ?></td><td><?= (int)$r['cnt'] ?></td></tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <?php if ($hasSubCategory): ?>
  <h2>Sub-Categories (grouped by Category)</h2>
  <table>
    <thead><tr><th>Category</th><th>Sub Category</th><th>Count</th></tr></thead>
    <tbody>
      <?php if (!$subRows): ?>
        <tr><td colspan="3" style="text-align:center;">No data</td></tr>
      <?php else: foreach($subRows as $r): ?>
        <tr><td><?= e($r['category']) ?></td><td><?= e($r['subcat']) ?></td><td><?= (int)$r['cnt'] ?></td></tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
  <?php endif; ?>

  <script>
  (function(){
    const catLabels    = <?= json_encode($catLabels, JSON_UNESCAPED_UNICODE) ?>;
    const catValues    = <?= json_encode($catValues, JSON_NUMERIC_CHECK) ?>;
    const subLabels    = <?= json_encode($subLabels, JSON_UNESCAPED_UNICODE) ?>;
    const subValues    = <?= json_encode($subValues, JSON_NUMERIC_CHECK) ?>;
    const statusLabels = <?= json_encode($statusLabels, JSON_UNESCAPED_UNICODE) ?>;
    const statusValues = <?= json_encode($statusValues, JSON_NUMERIC_CHECK) ?>;

    if (catLabels.length) {
      const cctx = document.getElementById('catChart').getContext('2d');
      new Chart(cctx, {
        type: 'bar',
        data: { labels: catLabels, datasets: [{ label: 'Tickets', data: catValues, borderWidth: 1 }] },
        options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }, plugins: { legend: { display: false } } }
      });
    }

    if (subLabels.length && document.getElementById('subChart')) {
      const sctx = document.getElementById('subChart').getContext('2d');
      new Chart(sctx, {
        type: 'bar',
        data: { labels: subLabels, datasets: [{ label: 'Tickets', data: subValues, borderWidth: 1 }] },
        options: { indexAxis:'y', responsive:true, maintainAspectRatio:false, scales:{ x:{ beginAtZero:true, ticks:{ precision:0 } } }, plugins:{ legend:{ display:false } } }
      });
    }

    const sctx = document.getElementById('statusChart').getContext('2d');
    new Chart(sctx, {
      type: 'bar',
      data: { labels: <?= json_encode($statusLabels) ?>, datasets: [{ label: 'Tickets', data: <?= json_encode($statusValues) ?>, borderWidth: 1 }] },
      options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }, plugins: { legend: { display: false } } }
    });
  })();
  </script>
</body>
</html>
