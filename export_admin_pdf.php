<?php
session_start();
require_once 'config.php';

/* Allow admin OR boss_ups */
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin','boss_ups'], true)) {
  http_response_code(403); exit('Forbidden');
}
function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* ---- detect subcategory column ---- */
$SUBCOL_DB = ''; $hasSubCategory=false;
foreach (['sub_category','subcategory'] as $try) {
  $res=$conn->query("SHOW COLUMNS FROM complaints LIKE '".$conn->real_escape_string($try)."'");
  if ($res && $res->num_rows > 0){ $SUBCOL_DB=$try; $hasSubCategory=true; }
  if ($res) $res->close();
}

/* ---- filters ---- */
$status     = $_GET['status']     ?? '';
$category   = $_GET['category']   ?? '';
$block      = $_GET['block']      ?? '';
$gender     = strtolower(trim($_GET['gender'] ?? ''));
$technician = $_GET['technician'] ?? '';
$room       = trim($_GET['room'] ?? '');
$dateFrom   = $_GET['from']       ?? '';
$dateTo     = $_GET['to']         ?? '';

/* Build base conditions (WITHOUT status) so we can reuse for Pending table */
$baseConds = ["c.is_deleted=0"];
if ($category)   $baseConds[] = "c.category = '".$conn->real_escape_string($category)."'";
if ($block)      $baseConds[] = "p.block = '".$conn->real_escape_string($block)."'";
if ($gender && in_array($gender, ['male','female'], true))
                 $baseConds[] = "p.gender = '".$conn->real_escape_string($gender)."'";
if ($technician) $baseConds[] = "c.assigned_to = ".intval($technician);
if ($room!=='')  $baseConds[] = "p.room_number LIKE '%".$conn->real_escape_string($room)."%'";
if ($dateFrom && $dateTo) $baseConds[] = "DATE(c.created_at) BETWEEN '".$conn->real_escape_string($dateFrom)."' AND '".$conn->real_escape_string($dateTo)."'";
$whereBase = implode(" AND ", $baseConds);

/* Full conditions (WITH status) for main list + charts */
$conds = $baseConds;
if ($status) $conds[] = "c.status = '".$conn->real_escape_string($status)."'";
$whereSql = implode(" AND ", $conds);

/* ---- tickets (include subcategory + phone) ---- */
$selectSub = $hasSubCategory ? ", c.`$SUBCOL_DB` AS subcategory" : ", '' AS subcategory";
$sql = "
  SELECT
    c.id, c.title, c.category $selectSub, c.status, c.created_at,
    p.name AS student_name, p.student_id, p.block, p.room_number, p.gender AS student_gender, p.phone AS student_phone,
    t.name AS tech_name
  FROM complaints c
  JOIN profile p ON p.student_id = c.student_id
  LEFT JOIN profile t ON c.assigned_to = t.id
  WHERE $whereSql
  ORDER BY c.created_at DESC, c.id DESC
";
$tickets = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);

/* ---- category bar chart data + table data ---- */
$cats = $conn->query("
  SELECT c.category, COUNT(*) AS cnt
  FROM complaints c
  JOIN profile p ON p.student_id = c.student_id
  WHERE $whereSql
  GROUP BY c.category
  ORDER BY cnt DESC, c.category ASC
");
$catLabels=[]; $catValues=[]; $catRows=[];
while($r=$cats->fetch_assoc()){
  $catLabels[]=$r['category'];
  $catValues[]=(int)$r['cnt'];
  $catRows[]=['category'=>$r['category'],'cnt'=>(int)$r['cnt']];
}

/* ---- sub-category charts (grouped per main category) + table rows ---- */
$subByCat = [
  'KEJURUTERAAN ELEKTRIK'  => ['labels'=>[], 'values'=>[]],
  'KEJURUTERAAN AWAM'      => ['labels'=>[], 'values'=>[]],
  'KEJURUTERAAN MEKANIKAL' => ['labels'=>[], 'values'=>[]],
];
$subTableRows = [];

if ($hasSubCategory) {
  $q = "
    SELECT c.category,
           COALESCE(NULLIF(c.`$SUBCOL_DB`,''), '(Unspecified)') AS subcat,
           COUNT(*) AS cnt
    FROM complaints c
    JOIN profile p ON p.student_id = c.student_id
    WHERE $whereSql
    GROUP BY c.category, subcat
    ORDER BY c.category, cnt DESC, subcat ASC
  ";
  $rs = $conn->query($q);
  while($r = $rs->fetch_assoc()){
    $cat = (string)$r['category'];
    $cnt = (int)$r['cnt'];
    if (isset($subByCat[$cat])) {
      $subByCat[$cat]['labels'][] = $r['subcat'];
      $subByCat[$cat]['values'][] = $cnt;
    }
    $subTableRows[] = ['category'=>$cat, 'subcat'=>$r['subcat'], 'cnt'=>$cnt];
  }
}

/* ---- Pending status table ---- */
$pendingWhere = $whereBase . " AND c.status='Pending'";
$pendingSql = "
  SELECT
    c.id, c.title, c.category $selectSub, c.status, c.created_at,
    p.name AS student_name, p.student_id, p.block, p.room_number, p.gender AS student_gender, p.phone AS student_phone,
    t.name AS tech_name
  FROM complaints c
  JOIN profile p ON p.student_id = c.student_id
  LEFT JOIN profile t ON c.assigned_to = t.id
  WHERE $pendingWhere
  ORDER BY c.created_at DESC, c.id DESC
";
$pendingTickets = $conn->query($pendingSql)->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Admin Tickets Export</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <style>
    @media print { .no-print { display:none !important; } body { -webkit-print-color-adjust:exact; print-color-adjust:exact; } }
    body{ font-family:Arial, sans-serif; margin:24px; color:#111; }
    .btn{ display:inline-block; padding:8px 12px; background:#2563eb; color:#fff; text-decoration:none; border-radius:6px; }
    h1{ margin:0 0 6px; font-size:20px; } .muted{ color:#555; margin-bottom:14px; }
    .grid{ display:grid; gap:18px; grid-template-columns:1fr; } @media(min-width:1100px){ .grid{ grid-template-columns:1fr 1fr; } }
    .card{ border:1px solid #e5e7eb; border-radius:10px; padding:12px; }
    .pill{ display:inline-block; padding:2px 8px; border-radius:999px; background:#eef2ff; font-size:12px; margin:0 6px 6px 0; }
    table{ width:100%; border-collapse:collapse; margin-top:12px; }
    th,td{ border:1px solid #ddd; padding:8px; font-size:12px; text-align:left; }
    thead th{ background:#f3f4f6; text-transform:uppercase; letter-spacing:.03em; }
    .chart-wrap{ width:100%; height:320px; }
    .chart-wrap.tall{ height:380px; }
    .charts-3col{ display:grid; gap:18px; grid-template-columns:1fr; }
    @media(min-width:1100px){ .charts-3col{ grid-template-columns:1fr 1fr 1fr; } }
    .h2{ margin:18px 0 6px; font-size:16px; }
    .subtle{ color:#444; font-weight:700; }
  </style>
</head>
<body>

<div class="no-print" style="margin-bottom:10px;">
  <a class="btn" href="admin_page.php?section=tickets">&larr; Back</a>
  <a class="btn" href="#" onclick="window.print();return false;">Print</a>
</div>

<h1>Tickets (Admin Export)</h1>
<div class="muted">Generated: <?= date('Y-m-d H:i:s') ?></div>

<div class="grid">
  <div class="card">
    <strong>Filters:</strong>
    <div>
      <span class="pill">Status: <?= e($status ?: 'All') ?></span>
      <span class="pill">Category: <?= e($category ?: 'All') ?></span>
      <span class="pill">Block: <?= e($block ?: 'All') ?></span>
      <span class="pill">Gender: <?= e($gender ?: 'All') ?></span>
      <span class="pill">Technician: <?= e($technician ?: 'All') ?></span>
      <span class="pill">Room: <?= $room !== '' ? e($room) : 'All' ?></span>
      <span class="pill">Date: <?= e($dateFrom ?: '—') ?> → <?= e($dateTo ?: '—') ?></span>
    </div>
  </div>
  <div class="card">
    <strong>Tickets by Category (Bar)</strong>
    <div class="chart-wrap"><canvas id="catChart"></canvas></div>
  </div>
</div>

<?php if ($hasSubCategory): ?>
  <div class="card" style="margin-top:18px;">
    <strong>Sub-Categories (per Category)</strong>
    <div class="charts-3col" style="margin-top:10px;">
      <div>
        <div class="pill" style="background:#dbeafe;">Kejuruteraan Elektrik</div>
        <div class="chart-wrap tall"><canvas id="scElec"></canvas></div>
      </div>
      <div>
        <div class="pill" style="background:#dcfce7;">Kejuruteraan Awam</div>
        <div class="chart-wrap tall"><canvas id="scAwam"></canvas></div>
      </div>
      <div>
        <div class="pill" style="background:#fee2e2;">Kejuruteraan Mekanikal</div>
        <div class="chart-wrap tall"><canvas id="scMek"></canvas></div>
      </div>
    </div>
  </div>
<?php endif; ?>

<h2 class="h2">Tickets by Category <span class="subtle">(table)</span></h2>
<table>
  <thead><tr><th>#</th><th>Category</th><th>Count</th></tr></thead>
  <tbody>
    <?php if (!$catRows): ?>
      <tr><td colspan="3" style="text-align:center;">No category data.</td></tr>
    <?php else: $i=1; foreach ($catRows as $row): ?>
      <tr><td><?= $i++ ?></td><td><?= e($row['category']) ?></td><td><?= (int)$row['cnt'] ?></td></tr>
    <?php endforeach; endif; ?>
  </tbody>
</table>

<?php if ($hasSubCategory): ?>
  <h2 class="h2">Sub-Categories (per Category) <span class="subtle">(table)</span></h2>
  <table>
    <thead><tr><th>#</th><th>Category</th><th>Sub-Category</th><th>Count</th></tr></thead>
    <tbody>
      <?php if (!$subTableRows): ?>
        <tr><td colspan="4" style="text-align:center;">No sub-category data.</td></tr>
      <?php else: $i=1; foreach ($subTableRows as $r): ?>
        <tr><td><?= $i++ ?></td><td><?= e($r['category']) ?></td><td><?= e($r['subcat']) ?></td><td><?= (int)$r['cnt'] ?></td></tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
<?php endif; ?>

<h2 class="h2">Pending Status <span class="subtle">(table)</span></h2>
<table>
  <thead>
    <tr>
      <th>#</th><th>Student</th><th>Student ID</th><th>Gender</th><th>Phone</th>
      <th>Block</th><th>Room</th><th>Title</th><th>Category</th><th>Sub-Category</th>
      <th>Status</th><th>Created</th><th>Technician</th>
    </tr>
  </thead>
  <tbody>
    <?php if (!$pendingTickets): ?>
      <tr><td colspan="13" style="text-align:center;">No pending tickets for the selected filters.</td></tr>
    <?php else: $i=1; foreach ($pendingTickets as $t): ?>
      <tr>
        <td><?= $i++ ?></td>
        <td><?= e($t['student_name']) ?></td>
        <td><?= e($t['student_id']) ?></td>
        <td><?= e(ucfirst($t['student_gender'] ?? '')) ?></td>
        <td><?= e($t['student_phone'] ?? '') ?></td>
        <td><?= e($t['block']) ?></td>
        <td><?= e($t['room_number']) ?></td>
        <td><?= e($t['title']) ?></td>
        <td><?= e($t['category']) ?></td>
        <td><?= e($t['subcategory'] ?? '') ?></td>
        <td><?= e($t['status']) ?></td>
        <td><?= e($t['created_at']) ?></td>
        <td><?= e($t['tech_name'] ?? '') ?></td>
      </tr>
    <?php endforeach; endif; ?>
  </tbody>
</table>

<h2 class="h2">All Tickets <span class="subtle">(matching filters)</span></h2>
<table>
  <thead>
    <tr>
      <th>#</th><th>Student</th><th>Student ID</th><th>Gender</th><th>Phone</th>
      <th>Block</th><th>Room</th><th>Title</th><th>Category</th><th>Sub-Category</th>
      <th>Status</th><th>Created</th><th>Technician</th>
    </tr>
  </thead>
  <tbody>
    <?php if (!$tickets): ?>
      <tr><td colspan="13" style="text-align:center;">No tickets match your filters.</td></tr>
    <?php else: $i=1; foreach ($tickets as $t): ?>
      <tr>
        <td><?= $i++ ?></td>
        <td><?= e($t['student_name']) ?></td>
        <td><?= e($t['student_id']) ?></td>
        <td><?= e(ucfirst($t['student_gender'] ?? '')) ?></td>
        <td><?= e($t['student_phone'] ?? '') ?></td>
        <td><?= e($t['block']) ?></td>
        <td><?= e($t['room_number']) ?></td>
        <td><?= e($t['title']) ?></td>
        <td><?= e($t['category']) ?></td>
        <td><?= e($t['subcategory'] ?? '') ?></td>
        <td><?= e($t['status']) ?></td>
        <td><?= e($t['created_at']) ?></td>
        <td><?= e($t['tech_name'] ?? '') ?></td>
      </tr>
    <?php endforeach; endif; ?>
  </tbody>
</table>

<script>
(function(){
  const labels = <?= json_encode($catLabels, JSON_UNESCAPED_UNICODE) ?>;
  const values = <?= json_encode($catValues, JSON_NUMERIC_CHECK) ?>;
  if (!labels.length) return;
  new Chart(document.getElementById('catChart').getContext('2d'), {
    type: 'bar',
    data: { labels, datasets: [{ label: 'Tickets', data: values, borderWidth: 1 }] },
    options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }, plugins: { legend: { display: false } } }
  });
})();

<?php if ($hasSubCategory): ?>
const scData = <?= json_encode($subByCat, JSON_UNESCAPED_UNICODE|JSON_NUMERIC_CHECK) ?>;
function renderHorizBar(canvasId, labels, values, title){
  const el = document.getElementById(canvasId);
  if (!el || !labels || !labels.length) return;
  new Chart(el.getContext('2d'), {
    type: 'bar',
    data: { labels, datasets: [{ label: '', data: values, borderWidth: 1 }] },
    options: {
      indexAxis: 'y',
      responsive: true, maintainAspectRatio: false,
      plugins: { legend: { display:false }, title:{ display:true, text:title } },
      scales: { x: { beginAtZero: true, ticks: { precision: 0 } } }
    }
  });
}
renderHorizBar('scElec', scData['KEJURUTERAAN ELEKTRIK']?.labels || [], scData['KEJURUTERAAN ELEKTRIK']?.values || [], 'KEJURUTERAAN ELEKTRIK');
renderHorizBar('scAwam', scData['KEJURUTERAAN AWAM']?.labels || [], scData['KEJURUTERAAN AWAM']?.values || [], 'KEJURUTERAAN AWAM');
renderHorizBar('scMek',  scData['KEJURUTERAAN MEKANIKAL']?.labels || [], scData['KEJURUTERAAN MEKANIKAL']?.values || [], 'KEJURUTERAAN MEKANIKAL');
<?php endif; ?>
</script>
</body>
</html>
