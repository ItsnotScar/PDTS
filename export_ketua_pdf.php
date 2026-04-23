<?php
session_start();
require_once 'config.php';

// --- Access: ketua_penyelia only
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'ketua_penyelia') {
  header("Location: index.php"); exit;
}

function val($k){ return trim($_GET[$k] ?? ''); }
function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Incoming filters
$block    = val('block');
$student  = val('student');            // student_id LIKE
$cat      = val('category');
$subcat   = val('subcategory');
$status   = val('status');
$gender   = strtolower(val('gender')); // male/female
$start    = val('start');
$end      = val('end');

$createdSort = strtolower($_GET['created_sort'] ?? 'desc');
$createdSort = $createdSort === 'asc' ? 'ASC' : 'DESC';

// WHERE builder
$where  = ["c.is_deleted=0", "p.role='student'"];
$types  = "";
$params = [];

if ($block !== '')                        { $where[]="p.block=?";           $types.="s"; $params[]=$block; }
if ($student !== '')                      { $where[]="p.student_id LIKE ?"; $types.="s"; $params[]="%{$student}%"; }
if ($cat !== '')                          { $where[]="c.category=?";        $types.="s"; $params[]=$cat; }
if ($subcat !== '')                       { $where[]="c.subcategory=?";     $types.="s"; $params[]=$subcat; }
if ($status !== '')                       { $where[]="c.status=?";          $types.="s"; $params[]=$status; }
if ($gender==='male' || $gender==='female'){ $where[]="p.gender=?";         $types.="s"; $params[]=$gender; }
if ($start !== '')                        { $where[]="c.created_at>=?";     $types.="s"; $params[]=$start.' 00:00:00'; }
if ($end !== '')                          { $where[]="c.created_at<=?";     $types.="s"; $params[]=$end.' 23:59:59'; }

$whereSql = implode(" AND ", $where);

// Main data (no c.priority / no c.attachment)
$sql = "
  SELECT
    c.id,
    c.title,
    c.category,
    c.subcategory,
    c.complaint,
    c.status,
    c.created_at,
    p.name AS student_name,
    p.student_id,
    p.block,
    p.room_number,
    p.gender AS student_gender,
    (
      SELECT GROUP_CONCAT(a.file_path ORDER BY a.id SEPARATOR '; ')
      FROM complaint_attachments a
      WHERE a.complaint_id = c.id
    ) AS attachments
  FROM complaints c
  JOIN profile p ON p.student_id = c.student_id
  WHERE {$whereSql}
  ORDER BY c.created_at {$createdSort}, c.id " . ($createdSort === 'ASC' ? 'ASC' : 'DESC');

$stmt = $conn->prepare($sql);
if ($types) { $stmt->bind_param($types, ...$params); }
$stmt->execute();
$tickets = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Category stats
$sqlCat = "
  SELECT c.category, COUNT(*) AS cnt
  FROM complaints c
  JOIN profile p ON p.student_id = c.student_id
  WHERE {$whereSql}
  GROUP BY c.category
  ORDER BY cnt DESC, c.category ASC";
$catStmt = $conn->prepare($sqlCat);
if ($types) { $catStmt->bind_param($types, ...$params); }
$catStmt->execute();
$catRes = $catStmt->get_result();
$catLabels = []; $catValues = [];
while ($r = $catRes->fetch_assoc()) { $catLabels[] = $r['category']; $catValues[] = (int)$r['cnt']; }
$catStmt->close();

// Sub-Category stats
$sqlSub = "
  SELECT COALESCE(NULLIF(c.subcategory,''),'(Unspecified)') AS subcategory, COUNT(*) AS cnt
  FROM complaints c
  JOIN profile p ON p.student_id = c.student_id
  WHERE {$whereSql}
  GROUP BY subcategory
  ORDER BY cnt DESC, subcategory ASC";
$subStmt = $conn->prepare($sqlSub);
if ($types) { $subStmt->bind_param($types, ...$params); }
$subStmt->execute();
$subRes = $subStmt->get_result();
$subLabels = []; $subValues = [];
while ($r = $subRes->fetch_assoc()) { $subLabels[] = $r['subcategory']; $subValues[] = (int)$r['cnt']; }
$subStmt->close();

// Status stats
$sqlStatus = "
  SELECT c.status, COUNT(*) AS cnt
  FROM complaints c
  JOIN profile p ON p.student_id = c.student_id
  WHERE {$whereSql}
  GROUP BY c.status
  ORDER BY FIELD(c.status,'Pending','In Progress','Completed','Rejected')";
$stStmt = $conn->prepare($sqlStatus);
if ($types) { $stStmt->bind_param($types, ...$params); }
$stStmt->execute();
$stRes = $stStmt->get_result();
$statusLabels = []; $statusValues = [];
while ($r = $stRes->fetch_assoc()) { $statusLabels[] = $r['status']; $statusValues[] = (int)$r['cnt']; }
$stStmt->close();

// Helper to humanize blank values for the header pills
function pill($label, $value, $fallback='All'){
  $v = ($value === '' || $value === null) ? $fallback : e($value);
  return "<span class=\"pill\">{$label}: {$v}</span>";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Tickets – Ketua Penyelia Export</title>
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

    .chart-wrap { width:100%; max-width:700px; height:340px; margin:8px 0 22px; }
    .charts-grid { display:flex; gap:24px; flex-wrap:wrap; align-items:stretch; }

    table { width:100%; border-collapse: collapse; }
    th, td { border:1px solid #ddd; padding:8px; text-align:left; font-size: 12px; }
    thead th { background:#f3f4f6; text-transform: uppercase; letter-spacing: .03em; }

    .badge { padding:4px 8px; border-radius: 999px; font-weight:700; font-size:11px; display:inline-block; }
    .status-pending{ background:#fde68a; }
    .status-in-progress{ background:#bae6fd; }
    .status-completed{ background:#bbf7d0; }
    .status-rejected{ background:#fecaca; }

    .pill { display:inline-block; padding:2px 8px; border-radius: 999px; background:#eef2ff; font-size:12px; margin-right:6px; }
  </style>
</head>
<body>

  <div class="actions no-print">
    <a class="btn" href="ketua_penyelia_page.php">&larr; Back</a>
    <a class="btn" href="#" onclick="window.print();return false;">Print</a>
  </div>

  <h1>Tickets – Ketua Penyelia Export</h1>
  <div class="muted">Generated: <?= date('Y-m-d H:i:s') ?></div>

  <div style="margin:8px 0 16px;">
    <?= pill('Block', $block) ?>
    <?= pill('Student ID', $student) ?>
    <?= pill('Category', $cat) ?>
    <?= pill('Sub-Category', $subcat) ?>
    <?= pill('Status', $status) ?>
    <?= pill('Gender', $gender ?: '', 'All') ?>
    <span class="pill">From: <?= $start ? e($start) : '—' ?></span>
    <span class="pill">To: <?= $end ? e($end) : '—' ?></span>
    <span class="pill">Order: <?= $createdSort === 'ASC' ? 'Oldest first' : 'Newest first' ?></span>
  </div>

  <!-- Charts -->
  <div class="charts-grid">
    <div style="flex:1 1 340px; min-width:300px;">
      <h2>Ticket Categories</h2>
      <div class="chart-wrap"><canvas id="catChart" height="340"></canvas></div>
    </div>
    <div style="flex:1 1 340px; min-width:300px;">
      <h2>Ticket Sub-Categories</h2>
      <div class="chart-wrap"><canvas id="subChart" height="340"></canvas></div>
    </div>
    <div style="flex:1 1 340px; min-width:300px;">
      <h2>Ticket Status</h2>
      <div class="chart-wrap"><canvas id="statusChart" height="340"></canvas></div>
    </div>
  </div>

  <!-- Table -->
  <table>
    <thead>
      <tr>
        <th>#</th>
        <th>Student</th>
        <th>Student ID</th>
        <th>Gender</th>
        <th>Block</th>
        <th>Room</th>
        <th>Title</th>
        <th>Category</th>
        <th>Sub-Category</th>
        <th>Status</th>
        <th>Created</th>
        <th>Attachments</th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$tickets): ?>
        <tr><td colspan="12" style="text-align:center;">No tickets match your filters.</td></tr>
      <?php else: $i=1; foreach ($tickets as $t): ?>
        <tr>
          <td><?= $i++ ?></td>
          <td><?= e($t['student_name']) ?></td>
          <td><?= e($t['student_id']) ?></td>
          <td><?= e(ucfirst($t['student_gender'] ?? '')) ?></td>
          <td><?= e($t['block']) ?></td>
          <td><?= e($t['room_number']) ?></td>
          <td><?= e($t['title']) ?></td>
          <td><?= e($t['category']) ?></td>
          <td><?= e($t['subcategory'] ?? '') ?></td>
          <td><span class="badge status-<?= e(strtolower(str_replace(' ','-',$t['status']))) ?>"><?= e($t['status']) ?></span></td>
          <td><?= e($t['created_at'] ?? '') ?></td>
          <td><?= e($t['attachments'] ?? '') ?></td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>

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

    if (subLabels.length) {
      const sctx = document.getElementById('subChart').getContext('2d');
      new Chart(sctx, {
        type: 'bar',
        data: { labels: subLabels, datasets: [{ label: 'Tickets', data: subValues, borderWidth: 1 }] },
        options: {
          indexAxis: 'y',
          responsive: true,
          maintainAspectRatio: false,
          scales: { x: { beginAtZero: true, ticks: { precision: 0 } } },
          plugins: { legend: { display: false } }
        }
      });
    }

    if (statusLabels.length) {
      const sctx = document.getElementById('statusChart').getContext('2d');
      new Chart(sctx, {
        type: 'doughnut',
        data: { labels: statusLabels, datasets: [{ label: 'Tickets', data: statusValues }] },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } }, cutout: '60%' }
      });
    }
  })();
  </script>
</body>
</html>
