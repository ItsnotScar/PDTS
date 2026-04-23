
<?php
session_start();
require_once 'config.php';

// --- Access guard: penyelia only
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'penyelia') { header("Location: index.php"); exit; }

$penyeliaName   = $_SESSION['name']    ?? 'Unknown';
$penyeliaBlock  = $_SESSION['block']   ?? null;
$penyeliaGender = $_SESSION['gender']  ?? null;

if (!$penyeliaBlock) {
  $_SESSION['error_message'] = "Your block is not set. Please contact admin.";
  header("Location: index.php"); exit;
}
if (!$penyeliaGender || !in_array($penyeliaGender, ['male','female'], true)) {
  $_SESSION['error_message'] = "Your gender is not set. Please contact admin.";
  header("Location: index.php"); exit;
}

function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Small helper to fetch all attachments for a complaint
function get_complaint_attachments(mysqli $conn, int $complaintId): array {
  $rows = [];
  $st = $conn->prepare("SELECT id, file_path, file_size, mime_type FROM complaint_attachments WHERE complaint_id = ? ORDER BY id ASC");
  $st->bind_param("i", $complaintId);
  $st->execute();
  $res = $st->get_result();
  while ($r = $res->fetch_assoc()) {
    $rows[] = [
      'id'   => (int)$r['id'],
      'path' => (string)$r['file_path'],
      'size' => (int)$r['file_size'],
      'mime' => (string)($r['mime_type'] ?? '')
    ];
  }
  $st->close();
  return $rows;
}

/* ---------------------- Categories/Subcategories (Malay) ---------------------- */
$SUBCATS = [
  "KEJURUTERAAN AWAM" => [
    "Bumbung","Siling","Lantai","Dinding","Tangga","Pintu/Jejenang Pintu",
    "Tingkap/Jejenang Tingkap/Window Handle","Pagar","Gutter",
    "RWDP (Rain Water Down Pipe","Saluran Paip","Pili Paip","Sinki",
    "Bidet","Tandas","Sistem Bekalan Air","Kebocoran",
    "Katil Pelajar","Almari Pelajar","Perabot (Kerusi/Meja/Kabinet)",
    "Tombol Pintu","Pokok/Landskap"
  ],
  "KEJURUTERAAN ELEKTRIK" => [
    "Kipas","Lampu","Pendawaian/Wiring","Plug Socket","Suis",
    "Bekalan Elektrik Terputus/Power Trip","Perangkap Kilat/Lightning Arrestor",
    "Lampu Jalan/Lampu Foyer","MSB/SSB/DB"
  ],
  "KEJURUTERAAN MEKANIKAL" => [
    "Alat Pemadam Api","Fire Alarm Panel","Heat Detector",
    "Alarm Bell","Break Glass Fire Alarm","Hose Reel"
  ]
];
$CATEGORIES = array_keys($SUBCATS);

/* ---------------------- Filters (GET) ---------------------- */
$studentIdFilter = trim($_GET['student_id'] ?? '');
$categoryFilter  = trim($_GET['category']   ?? '');
$subcatFilter    = trim($_GET['subcategory']?? '');
$statusFilter    = trim($_GET['status']     ?? '');
$startDate       = trim($_GET['start_date'] ?? '');
$endDate         = trim($_GET['end_date']   ?? '');
$createdSortIn   = strtolower($_GET['created_sort'] ?? 'desc'); // 'asc' | 'desc'
$createdSort     = $createdSortIn === 'asc' ? 'ASC' : 'DESC';

/* ---------------------- Pagination (GET) ---------------------- */
$perPage = 20;
$page    = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset  = ($page - 1) * $perPage;

/* Build WHERE parts shared by queries */
$where  = ["c.is_deleted = 0", "p.block = ?", "p.gender = ?", "p.role = 'student'"];
$types  = "ss";
$params = [$penyeliaBlock, $penyeliaGender];

if ($studentIdFilter !== '') { $where[]="p.student_id LIKE ?"; $types.="s"; $params[]="%{$studentIdFilter}%"; }
if ($categoryFilter !== '')  { $where[]="c.category = ?";       $types.="s"; $params[]=$categoryFilter; }
if ($subcatFilter !== '')    { $where[]="c.subcategory = ?";    $types.="s"; $params[]=$subcatFilter; }
if ($statusFilter !== '')    { $where[]="c.status = ?";         $types.="s"; $params[]=$statusFilter; }
if ($startDate !== '')       { $where[]="c.created_at >= ?";    $types.="s"; $params[]=$startDate." 00:00:00"; }
if ($endDate !== '')         { $where[]="c.created_at <= ?";    $types.="s"; $params[]=$endDate." 23:59:59"; }

$whereSql = implode(" AND ", $where);

/* ---------------------- Count for pagination ---------------------- */
$sqlCount = "
  SELECT COUNT(*) AS cnt
  FROM complaints c
  JOIN profile p ON p.student_id = c.student_id
  WHERE {$whereSql}";
$cStmt = $conn->prepare($sqlCount);
$cStmt->bind_param($types, ...$params);
$cStmt->execute();
$totalComplaints = (int)($cStmt->get_result()->fetch_assoc()['cnt'] ?? 0);
$cStmt->close();

$totalPages = max(1, (int)ceil($totalComplaints / $perPage));
if ($page > $totalPages) { $page = $totalPages; $offset = ($page - 1) * $perPage; }

/* ---------------------- Complaints (table) ---------------------- */
/* NOTE: priority removed */
$sqlComplaints = "
  SELECT 
    c.id, c.title, c.category, c.subcategory, c.complaint, c.status, c.created_at, c.updated_at,
    c.proof_note, c.proof_attachment,
    c.remark_pending, c.remark_in_progress, c.remark_completed, c.remark_rejected,
    p.name AS student_name, p.student_id, p.block, p.room_number, p.gender AS student_gender, p.phone AS student_phone,
    t.name AS tech_name

  FROM complaints c
  JOIN profile p ON p.student_id = c.student_id
  LEFT JOIN profile t ON t.id = c.assigned_to
  WHERE {$whereSql}
  ORDER BY c.created_at {$createdSort}, c.id " . ($createdSort === 'ASC' ? 'ASC' : 'DESC') . "
  LIMIT ? OFFSET ?";


$tStmt = $conn->prepare($sqlComplaints);
$typesComplaints  = $types . "ii";
$paramsComplaints = array_merge($params, [$perPage, $offset]);
$tStmt->bind_param($typesComplaints, ...$paramsComplaints);
$tStmt->execute();
$complaints = $tStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$tStmt->close();

/* ---------------------- Category stats (filtered) ---------------------- */
$sqlCat = "
  SELECT c.category, COUNT(*) AS cnt
  FROM complaints c
  JOIN profile p ON p.student_id = c.student_id
  WHERE {$whereSql}
  GROUP BY c.category
  ORDER BY cnt DESC, c.category ASC";
$catStmt = $conn->prepare($sqlCat);
$catStmt->bind_param($types, ...$params);
$catStmt->execute();
$catRes = $catStmt->get_result();
$catLabels = []; $catValues = [];
while ($r = $catRes->fetch_assoc()) { $catLabels[] = $r['category']; $catValues[] = (int)$r['cnt']; }
$catStmt->close();

/* ---------------------- Sub-Category stats (filtered) ---------------------- */
$sqlSubcat = "
  SELECT COALESCE(NULLIF(c.subcategory,''),'(Unspecified)') AS subcategory, COUNT(*) AS cnt
  FROM complaints c
  JOIN profile p ON p.student_id = c.student_id
  WHERE {$whereSql}
  GROUP BY subcategory
  ORDER BY cnt DESC, subcategory ASC";
$subStmt = $conn->prepare($sqlSubcat);
$subStmt->bind_param($types, ...$params);
$subStmt->execute();
$subRes = $subStmt->get_result();
$subcatLabels = []; $subcatValues = [];
while ($r = $subRes->fetch_assoc()) { $subcatLabels[] = $r['subcategory']; $subcatValues[] = (int)$r['cnt']; }
$subStmt->close();

/* ---------------------- Status stats (filtered) ---------------------- */
$sqlStatus = "
  SELECT c.status, COUNT(*) AS cnt
  FROM complaints c
  JOIN profile p ON p.student_id = c.student_id
  WHERE {$whereSql}
  GROUP BY c.status
  ORDER BY FIELD(c.status,'Pending','In Progress','Completed','Rejected')";
$stStmt = $conn->prepare($sqlStatus);
$stStmt->bind_param($types, ...$params);
$stStmt->execute();
$stRes = $stStmt->get_result();
$statusLabels = []; $statusValues = [];
while ($r = $stRes->fetch_assoc()) {
  $statusLabels[] = $r['status'];
  $statusValues[] = (int)$r['cnt'];
}
$stStmt->close();

/* Helper to preserve current filters in links (base query string WITHOUT 'page') */
$qs = http_build_query([
  'student_id'   => $studentIdFilter,
  'category'     => $categoryFilter,
  'subcategory'  => $subcatFilter,
  'status'       => $statusFilter,
  'start_date'   => $startDate,
  'end_date'     => $endDate,
  'created_sort' => $createdSortIn,
]);

/* Active filter chips */
$activeFilters = 0; $chipLabels = [];
if ($studentIdFilter !== '') { $activeFilters++; $chipLabels[] = "ID: ".e($studentIdFilter); }
if ($categoryFilter  !== '') { $activeFilters++; $chipLabels[] = "Cat: ".e($categoryFilter); }
if ($subcatFilter    !== '') { $activeFilters++; $chipLabels[] = "Sub: ".e($subcatFilter); }
if ($statusFilter    !== '') { $activeFilters++; $chipLabels[] = "Status: ".e($statusFilter); }
if ($startDate       !== '') { $activeFilters++; $chipLabels[] = "From: ".e($startDate); }
if ($endDate         !== '') { $activeFilters++; $chipLabels[] = "To: ".e($endDate); }
if ($createdSort     === 'ASC') { $activeFilters++; $chipLabels[] = "Oldest first"; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Penyelia Dashboard</title>

  <link rel="icon" type="image/png" sizes="32x32" href="assets/favicon.png">
  <link rel="icon" type="image/png" sizes="16x16" href="assets/favicon.png">
  <link rel="apple-touch-icon" href="assets/logo.png">

  <link rel="stylesheet" href="student.css">
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

  <style>
    :root{
      --fs-12: 12px; --fs-13: 13px; --fs-14: 14px; --fs-16: 16px;
      --sp-4:4px; --sp-6:6px; --sp-8:8px; --sp-10:10px; --sp-12:12px;
      --r-8:8px; --r-10:10px; --r-12:12px;
    }
    html, body { height: 100%; }
    body {
      margin:0; min-height:100vh; background:#eaf0ff; color:#1f2937; font-size:var(--fs-13);
      font-family: system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;
      -webkit-font-smoothing:antialiased; -moz-osx-font-smoothing:grayscale;
    }
    body::before { content:""; position:fixed; inset:0; background:url('assets/dormitory.jpg') center/cover no-repeat;
      filter: blur(8px) brightness(0.92) saturate(90%); transform: scale(1.06); z-index:-2; }
    body::after { content:""; position:fixed; inset:0; background:rgba(0,0,0,.32); z-index:-1; }

    .container { max-width: 1120px; margin: 14px auto; padding: 0 10px; }

    .header {
      display:flex; justify-content:space-between; align-items:center; gap:var(--sp-8);
      background: rgba(116,148,236,.92); color:#fff; padding:8px 10px; border-radius:0 0 var(--r-10) var(--r-10);
      box-shadow:0 2px 8px rgba(0,0,0,.15); flex-wrap:wrap;
    }
    .header h1 { margin:0; font-size:var(--fs-16); font-weight:800; }
    .muted { font-size:var(--fs-12); opacity:.95; }
    .header .btn { padding:8px 10px; border-radius:var(--r-10); font-size:var(--fs-12); }

    .card {
      background:#fff; border:1px solid rgba(2,6,23,.06); border-radius:var(--r-12);
      padding:10px; margin-top:10px; box-shadow:0 4px 14px rgba(0,0,0,.08);
    }
    .card-header{ display:flex; align-items:center; justify-content:space-between; margin-bottom:6px; }
    .card h3{ margin:0; font-size:var(--fs-14); font-weight:800; color:#0f172a; }

    .btn{ border:0; background:#6b82e6; color:#fff; padding:8px 10px; border-radius:var(--r-10); font-weight:800; font-size:var(--fs-12); text-decoration:none; cursor:pointer; }
    .btn:hover{ background:#5a71d6; }
    .btn.ghost{ background:#fff; color:#0f172a; border:1px solid #cbd5e1; }

    /* ===== Filters ===== */
    .filters-card{ padding:8px; }
    .filters-mobile-header{ display:grid; gap:6px; }
    .filter-toggle{ background:#0f172a; color:#fff; width:100%; display:flex; justify-content:center; align-items:center; gap:6px; border-radius:var(--r-12); padding:10px; font-size:var(--fs-13); font-weight:800; }
    .chipbar{ display:flex; gap:6px; flex-wrap:wrap; }
    .chip{ font-size:var(--fs-12); padding:4px 8px; background:#f1f5f9; border:1px solid #e5e7eb; border-radius:999px; font-weight:800; color:#334155; }

    .filters-panel{ display:none; margin-top:6px; }
    .filters-panel.open{ display:block; }

    .nice-filter{
      display:grid; gap:8px; grid-template-columns: 1fr;
      background:#fff; border:1px solid #e5e7eb; border-radius:var(--r-12); padding:8px;
    }
    @media (min-width:400px){ .nice-filter{ grid-template-columns: 1fr 1fr; } }
    @media (min-width:680px){ .filters-mobile-header{ display:none; } .filters-panel{ display:block !important; } .nice-filter{ grid-template-columns: repeat(7, minmax(110px,1fr)); padding:8px; } }

    .nice-filter .field{ display:flex; flex-direction:column; gap:3px; min-width:0; }
    .nice-filter .field label{ font-size:var(--fs-12); color:#475569; font-weight:900; }
    .nice-filter input, .nice-filter select{
      border:1px solid #cbd5e1; border-radius:var(--r-10); padding:7px 9px; font-size:var(--fs-13); background:#fff; min-height:34px; width:100%;
    }

    .filter-actions-row{
      grid-column:1/-1; display:flex; gap:6px; padding-top:2px;
      position:sticky; bottom:-8px; background:linear-gradient(#fff 65%, rgba(255,255,255,.7));
    }
    .filter-actions-row .btn{ flex:1; padding:9px 10px; }

    /* Charts */
    .row{ display:flex; gap:10px; flex-wrap:wrap; }
    .chart-wrap{ width:100%; max-width:520px; height:240px; }
    @media (max-width:1120px){ .chart-wrap{ max-width:100%; } }

    /* Badges */
    .badge{ display:inline-block; padding:4px 8px; border-radius:999px; font-size:var(--fs-12); font-weight:800; background:#e2e8f0; color:#0f172a; }
    .status-pending{ background:#fff7ed; color:#92400e; border:1px solid #fed7aa; }
    .status-in-progress{ background:#eff6ff; color:#1e40af; border:1px solid #bfdbfe; }
    .status-completed{ background:#ecfdf5; color:#065f46; border:1px solid #a7f3d0; }
    .status-rejected{ background:#fef2f2; color:#991b1b; border:1px solid #fecaca; }

    /* Table */
    .table-wrap{ overflow:auto; -webkit-overflow-scrolling:touch; }
    table{ width:100%; border-collapse:collapse; font-size:var(--fs-13); }
    thead th{ position:sticky; top:0; font-size:var(--fs-12); text-transform:uppercase; letter-spacing:.04em; background:#f3f4f6; }
    th,td{ border:1px solid #e5e7eb; padding:8px; text-align:center; }
    tbody tr:nth-child(even){ background:#fcfcfd; }
    tbody tr:hover{ background:#f5f7fb; }
    td:nth-child(5){ max-width:250px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }

    /* Pagination */
    .pagination{ display:flex; gap:6px; flex-wrap:wrap; justify-content:center; margin:10px 0 0; }
    .pagination a,.pagination span{ padding:6px 8px; border:1px solid #cbd5e1; border-radius:var(--r-10); text-decoration:none; color:#0f172a; background:#fff; font-weight:800; }
    .pagination .active{ background:#2563eb; color:#fff; border-color:#2563eb; }
    .pagination .disabled{ opacity:.55; pointer-events:none; }

    /* Modal */
    .modal{ display:none; position:fixed; inset:0; background:rgba(0,0,0,.55); z-index:999; align-items:center; justify-content:center; }
    .modal-content{ max-width:720px; width:92%; background:#fff; border-radius:var(--r-12); padding:14px; position:relative; box-shadow:0 20px 40px rgba(0,0,0,.3); }
    .close{ position:absolute; top:8px; right:12px; font-size:20px; cursor:pointer; }
    .modal-details{ display:grid; grid-template-columns:1fr 1fr; gap:10px 18px; font-size:var(--fs-13); }
    .modal-details p{ margin:4px 0; }
    .modal-date{ color:#555; font-size:var(--fs-12); margin-bottom:8px; }
    .attachment a{ color:#2563eb; text-decoration:none; }
    .attachment a:hover{ text-decoration:underline; }
    .att-list{ list-style:disc; margin:6px 0 0 18px; padding:0; }
    .ticket-done{ margin-top:10px; padding:8px; background:#f9fafb; border:1px solid #e5e7eb; border-radius:var(--r-8); }

    @media (max-width:700px){
      table{ min-width:980px; }
      .modal-details{ grid-template-columns:1fr; }
    }


.inner-card {
  background:#f9fafb;
  border:1px solid #e5e7eb;
  border-radius:10px;
  padding:12px 14px;
  margin-top:14px;
  box-shadow:0 2px 6px rgba(0,0,0,.04);
}
.inner-card h4 {
  font-weight:700;
  font-size:15px;
  margin:0 0 6px;
}

    .sheet {
  background:#fff;
  border-radius:12px;
  padding:20px;
  width:90%;
  max-width:720px;
  position:relative;
  box-shadow:0 20px 40px rgba(0,0,0,0.25);
  color:#0f172a;
  font-size:14px;
}

.modal {
  display:none;
  position:fixed;
  inset:0;
  background:rgba(0,0,0,0.6);
  z-index:999;
  align-items:center;
  justify-content:center;
  backdrop-filter:blur(3px);
}

    .sheet {
  background:#fff;
  border-radius:12px;
  padding:20px;
  width:90%;
  max-width:720px;
  position:relative;
  box-shadow:0 20px 40px rgba(0,0,0,0.25);
  color:#0f172a;
  font-size:14px;

  /* 🟢 FIX START */
  max-height: 90vh;            /* limit height to viewport */
  overflow-y: auto;            /* enable scroll inside */
  scrollbar-width: thin;       /* nice thin scrollbar for Firefox */
  scrollbar-color: #cbd5e1 #f1f5f9;
}

/* 🟢 Optional (scrollbar style for Chrome/Edge) */
.sheet::-webkit-scrollbar {
  width: 8px;
}
.sheet::-webkit-scrollbar-track {
  background: #f1f5f9;
  border-radius: 10px;
}
.sheet::-webkit-scrollbar-thumb {
  background-color: #cbd5e1;
  border-radius: 10px;
}
.sheet::-webkit-scrollbar-thumb:hover {
  background-color: #94a3b8;
}

.modal {
  align-items: flex-start;
  padding: 40px 0;
  overflow-y: auto;
}

.ticket-top {
  display:flex;
  justify-content:space-between;
  align-items:center;
  gap:10px;
  margin-bottom:12px;
}

.ticket-top h2 {
  font-weight:800;
  font-size:18px;
  margin:0;
}

.created-text {
  color:#64748b;
  font-size:13px;
  margin-top:2px;
}


  </style>
</head>
<body>
  <div class="container">

    <!-- HEADER -->
    <div class="header">
      <div>
        <h1>Penyelia Dashboard</h1>
        <div class="muted">
          Welcome, <strong><?= e($penyeliaName) ?></strong> — Block <strong><?= e($penyeliaBlock) ?></strong> (<?= e(ucfirst($penyeliaGender)) ?>)
        </div>
      </div>
      <div style="display:flex; gap:6px; flex-wrap:wrap;">
        <a class="btn" href="export_penyelia_csv.php?<?= $qs ?>" target="_blank" rel="noopener">Export CSV</a>
        <a class="btn" href="export_penyelia_pdf.php?<?= $qs ?>" target="_blank" rel="noopener">Export PDF</a>
        <a class="btn" href="logout.php">Logout</a>
      </div>
    </div>

    <!-- FILTERS -->
    <div class="card filters-card">
      <div class="filters-mobile-header">
        <button type="button" class="btn filter-toggle" id="filterToggleBtn">
          🔎 Filters<?= $activeFilters ? " ({$activeFilters})" : "" ?>
        </button>

        <?php if (!empty($chipLabels)): ?>
          <div class="chipbar">
            <?php foreach ($chipLabels as $chip): ?>
              <span class="chip"><?= $chip ?></span>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <div class="filters-panel" id="filtersPanel">
        <div class="card-header"><h3>Filter</h3></div>

        <form method="get" class="nice-filter" onsubmit="document.getElementById('filtersPanel').classList.add('open');">
          <div class="field">
            <label for="student_id">Student ID</label>
            <input type="text" id="student_id" name="student_id" value="<?= e($studentIdFilter) ?>" placeholder="05DDT23F0001" autocomplete="off" inputmode="text">
          </div>

          <div class="field">
            <label for="category">Category</label>
            <select id="category" name="category">
              <option value="">All</option>
              <?php foreach ($CATEGORIES as $c): ?>
                <option value="<?= e($c) ?>" <?= $categoryFilter===$c?'selected':'' ?>><?= e($c) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="field">
            <label for="subcategory">Sub-Category</label>
            <select id="subcategory" name="subcategory" <?= $categoryFilter ? '' : 'disabled' ?>>
              <option value="">All</option>
              <?php if ($categoryFilter && isset($SUBCATS[$categoryFilter])): ?>
                <?php foreach ($SUBCATS[$categoryFilter] as $s): ?>
                  <option value="<?= e($s) ?>" <?= $subcatFilter===$s?'selected':'' ?>><?= e($s) ?></option>
                <?php endforeach; ?>
              <?php endif; ?>
            </select>
          </div>

          <div class="field">
            <label for="status">Status</label>
            <select id="status" name="status">
              <?php
                $sts = ['' => 'All', 'Pending'=>'Pending', 'In Progress'=>'In Progress', 'Completed'=>'Completed', 'Rejected'=>'Rejected'];
                foreach ($sts as $val => $label) {
                  $sel = ($statusFilter === $val) ? 'selected' : '';
                  echo "<option value=\"".e($val)."\" $sel>".e($label)."</option>";
                }
              ?>
            </select>
          </div>

          <div class="field">
            <label for="start_date">From</label>
            <input type="date" id="start_date" name="start_date" value="<?= e($startDate) ?>">
          </div>

          <div class="field">
            <label for="end_date">To</label>
            <input type="date" id="end_date" name="end_date" value="<?= e($endDate) ?>">
          </div>

          <div class="field">
            <label for="created_sort">Created</label>
            <select id="created_sort" name="created_sort">
              <option value="desc" <?= $createdSort==='DESC'?'selected':''; ?>>Newest first</option>
              <option value="asc"  <?= $createdSort==='ASC' ?'selected':''; ?>>Oldest first</option>
            </select>
          </div>

          <div class="filter-actions-row">
            <button class="btn" type="submit">Apply</button>
            <a class="btn ghost" href="penyelia_page.php" onclick="document.getElementById('filtersPanel').classList.add('open');">Reset</a>
          </div>
        </form>
      </div>
    </div>

    <!-- STATS -->
    <div class="row">
      <div class="card" style="flex:1 1 340px;">
        <div class="card-header"><h3>Complaints by Category</h3></div>
        <?php if (!empty($catLabels)): ?>
          <div class="chart-wrap"><canvas id="catChart" height="240"></canvas></div>
        <?php else: ?>
          <p style="margin:6px 0;">No complaints to chart.</p>
        <?php endif; ?>
      </div>

      <div class="card" style="flex:1 1 340px;">
        <div class="card-header"><h3>Complaints by Sub-Category<?= $categoryFilter ? ' — '.e($categoryFilter) : '' ?></h3></div>
        <?php if (!empty($subcatLabels)): ?>
          <div class="chart-wrap"><canvas id="subcatChart" height="240"></canvas></div>
        <?php else: ?>
          <p style="margin:6px 0;">No sub-category data.</p>
        <?php endif; ?>
      </div>

      <div class="card" style="flex:1 1 340px;">
        <div class="card-header"><h3>Complaint Status</h3></div>
        <?php if (!empty($statusLabels)): ?>
          <div class="chart-wrap"><canvas id="statusChart" height="240"></canvas></div>
        <?php else: ?>
          <p style="margin:6px 0;">No complaints to chart.</p>
        <?php endif; ?>
      </div>
    </div>

    <!-- COMPLAINTS -->
    <div class="card">
      <div class="card-header"><h3>Complaints</h3></div>

      <?php if (count($complaints) > 0): ?>
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>#</th>
                <th>Student</th>
                <th>Student ID</th>
                <th>Room</th>
                <th>Title</th>
                <th>Category</th>
                <th>Sub-Category</th>
                <th>Status</th>
                <th>Created</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
            <?php
              $i=1 + $offset;
              foreach ($complaints as $t):
                $atts = get_complaint_attachments($conn, (int)$t['id']);
                $attsJson = e(json_encode($atts));
            ?>
              <tr>
                <td><?= $i++ ?></td>
                <td><?= e($t['student_name']) ?></td>
                <td><?= e($t['student_id']) ?></td>
                <td><?= e($t['room_number']) ?></td>
                <td><?= e($t['title']) ?></td>
                <td><?= e($t['category']) ?></td>
                <td><?= e($t['subcategory'] ?: '—') ?></td>
                <td><span class="badge status-<?= strtolower(str_replace(' ','-',$t['status'])) ?>"><?= e($t['status']) ?></span></td>
                <td><?= e($t['created_at'] ?? '') ?></td>
                <td>
               <button
  class="btn"
  data-id="<?= (int)$t['id'] ?>"
  data-title="<?= e($t['title']) ?>"
  data-student="<?= e($t['student_name']) ?>"
  data-sid="<?= e($t['student_id']) ?>"
  data-block="<?= e($t['block']) ?>"
  data-room="<?= e($t['room_number']) ?>"
  data-category="<?= e($t['category']) ?>"
  data-subcat="<?= e($t['subcategory'] ?: '') ?>"
  data-status="<?= e($t['status']) ?>"
  data-created="<?= e($t['created_at'] ?? '') ?>"
  data-desc="<?= e($t['complaint']) ?>"
  data-remark="<?= e($t['proof_note'] ?? '') ?>"
  data-proof="<?= e($t['proof_attachment'] ?? '') ?>"
  data-gender="<?= e($t['student_gender']) ?>"
  data-phone="<?= e($t['student_phone'] ?? '') ?>"
  data-techname="<?= e($t['tech_name'] ?? '') ?>"
  data-completed="<?= e($t['updated_at'] ?? '') ?>"
  data-attachments='<?= $attsJson ?>'
  data-rpending="<?= e($t['remark_pending'] ?? '') ?>"
  data-rprogress="<?= e($t['remark_in_progress'] ?? '') ?>"
  data-rcompleted="<?= e($t['remark_completed'] ?? '') ?>"
  data-rrejected="<?= e($t['remark_rejected'] ?? '') ?>"



  onclick="openDetails(this)"
>Details</button>


                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <!-- Pagination -->
        <div class="pagination">
          <?php $base = 'penyelia_page.php' . ($qs ? ('?' . $qs . '&') : '?'); ?>
          <a class="<?= $page<=1?'disabled':'' ?>" href="<?= $page<=1 ? '#' : ($base.'page='.($page-1)) ?>">Prev</a>

          <?php
            $window = 3;
            $start = max(1, $page - $window);
            $end   = min($totalPages, $page + $window);

            if ($start > 1) {
              echo '<a href="'.$base.'page=1">1</a>';
              if ($start > 2) echo '<span>…</span>';
            }
            for ($p = $start; $p <= $end; $p++) {
              $cls = $p == $page ? 'active' : '';
              echo '<a class="'.$cls.'" href="'.$base.'page='.$p.'">'.$p.'</a>';
            }
            if ($end < $totalPages) {
              if ($end < $totalPages - 1) echo '<span>…</span>';
              echo '<a href="'.$base.'page='.$totalPages.'">'.$totalPages.'</a>';
            }
          ?>

          <a class="<?= $page>=$totalPages?'disabled':'' ?>" href="<?= $page>=$totalPages ? '#' : ($base.'page='.($page+1)) ?>">Next</a>
        </div>

      <?php else: ?>
        <p style="margin:6px 0;">No complaints match your filters.</p>
      <?php endif; ?>
    </div>
  </div>

<!-- DETAILS MODAL (Admin-style layout for Penyelia) -->
<div id="detailsModal" class="modal" role="dialog" aria-modal="true">
  <div class="sheet">

  <!-- Modal Header -->
<div class="ticket-top">
  <div>
    <h2 id="dTitle">test1</h2>
    <div id="dCreated" class="created-text">2025-10-14 10:54:46</div>
  </div>
  <span id="dStatus" class="badge status-pending">Pending</span>
</div>

    <!-- Info Grid -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;font-size:14px;margin-bottom:10px;">
      <p><strong>Category:</strong> <span id="dCategory">—</span></p>
      <p><strong>Sub Category:</strong> <span id="dSubcat">—</span></p>
        <p><strong>Block/Room:</strong> <span id="dBlock">—</span> / <span id="dRoom">—</span></p>
      <p><strong>Gender:</strong> <span id="dGen">—</span></p>

      <p><strong>Student:</strong> <span id="dStudent">—</span> (<span id="dStudentId">—</span>)</p>
      <p><strong>Phone:</strong> <a id="dPhone" href="#" style="color:#2563eb;text-decoration:none;">—</a></p>

    
      <p><strong>Description:</strong> <span id="dDesc">—</span></p>
    </div>

    <!-- Student Attachment -->
    <div class="inner-card">
      <h4>Student Attachment</h4>
      <div id="studentAttachList">—</div>
    </div>

    <!-- Technician Details -->
    <div class="inner-card">
      <h4>Technician Details</h4>
      <p><strong>Technician:</strong> <span id="techName">—</span></p>
      <p><strong>Technician Remark:</strong> <span id="dRemark">—</span></p>
      <p><strong>Completed At:</strong> <span id="dCompleted">—</span></p>
      <div style="margin-top:6px;">
        <strong>Technician Proof:</strong>
        <div id="techProofList" style="margin-top:4px;">—</div>
      </div>
    </div>

    <!-- Admin Remarks -->
    <div class="inner-card">
      <h4>Remarks by Status</h4>
      <div style="display:grid;grid-template-columns:150px 1fr;gap:4px;font-size:14px;">
        <div><strong>Pending:</strong></div><div id="rPending">—</div>
        <div><strong>In Progress:</strong></div><div id="rProgress">—</div>
        <div><strong>Completed:</strong></div><div id="rCompleted">—</div>
        <div><strong>Rejected:</strong></div><div id="rRejected">—</div>
      </div>
    </div>

    <!-- Close -->
    <div style="text-align:right;margin-top:12px;">
      <button class="btn" onclick="closeDetails()">Close</button>
    </div>

  </div>
</div>




  <!-- Charts + Filters toggle + Modal logic -->
  <script>
  // Subcategory enable + options (based on selected category)
  (function(){
    const map = <?= json_encode($SUBCATS, JSON_UNESCAPED_UNICODE) ?>;
    const cat = document.getElementById('category');
    const sub = document.getElementById('subcategory');
    if (!cat || !sub) return;
    function refresh(){
      const v = cat.value;
      sub.innerHTML = '<option value="">All</option>';
      if (!v || !map[v]) { sub.disabled = true; return; }
      map[v].forEach(s=>{
        const opt=document.createElement('option');
        opt.value=s; opt.textContent=s;
        <?php if ($subcatFilter): ?>
        if (s === <?= json_encode($subcatFilter) ?>) opt.selected = true;
        <?php endif; ?>
        sub.appendChild(opt);
      });
      sub.disabled = false;
    }
    cat.addEventListener('change', refresh);
  })();

  (function(){
    // Category chart
    const catLabels = <?= json_encode($catLabels, JSON_UNESCAPED_UNICODE) ?>;
    const catValues = <?= json_encode($catValues, JSON_NUMERIC_CHECK) ?>;
    if (catLabels.length) {
      const cctx = document.getElementById('catChart')?.getContext('2d');
      if (cctx) new Chart(cctx, {
        type: 'bar',
        data: { labels: catLabels, datasets: [{ label: 'Complaints', data: catValues, borderWidth: 1 }] },
        options: {
          responsive: true, maintainAspectRatio: false,
          scales: { y: { beginAtZero: true, ticks: { precision: 0 } } },
          plugins: { legend: { display: false }, tooltip: { mode: 'index', intersect: false } }
        }
      });
    }

    // Sub-Category chart (horizontal)
    const subLabels = <?= json_encode($subcatLabels, JSON_UNESCAPED_UNICODE) ?>;
    const subValues = <?= json_encode($subcatValues, JSON_NUMERIC_CHECK) ?>;
    if (subLabels.length) {
      const sctx2 = document.getElementById('subcatChart')?.getContext('2d');
      if (sctx2) new Chart(sctx2, {
        type: 'bar',
        data: { labels: subLabels, datasets: [{ label: 'Complaints', data: subValues }] },
        options: {
          indexAxis: 'y',
          responsive: true, maintainAspectRatio: false,
          scales: { x: { beginAtZero: true, ticks: { precision: 0 } } },
          plugins: { legend: { display: false }, tooltip: { mode: 'index', intersect: false } }
        }
      });
    }

    // Status chart (doughnut)
    const statusLabels = <?= json_encode($statusLabels, JSON_UNESCAPED_UNICODE) ?>;
    const statusValues = <?= json_encode($statusValues, JSON_NUMERIC_CHECK) ?>;
    if (statusLabels.length) {
      const sctx = document.getElementById('statusChart')?.getContext('2d');
      if (sctx) new Chart(sctx, {
        type: 'doughnut',
        data: { labels: statusLabels, datasets: [{ label: 'Complaints', data: statusValues }] },
        options: {
          responsive: true, maintainAspectRatio: false,
          plugins: { legend: { position: 'bottom' }, tooltip: { mode: 'index', intersect: false } },
          cutout: '60%'
        }
      });
    }
  })();

  // Filters panel toggle
  (function(){
    const btn = document.getElementById('filterToggleBtn');
    const panel = document.getElementById('filtersPanel');
    if (!btn || !panel) return;

    if (window.matchMedia('(min-width: 680px)').matches) {
      panel.classList.add('open');
    }

    btn.addEventListener('click', function(e){
      e.preventDefault();
      panel.classList.toggle('open');
      if (panel.classList.contains('open')) {
        const sid = document.getElementById('student_id');
        if (sid) setTimeout(() => sid.focus(), 50);
      }
    });

    panel.addEventListener('click', function(e){ e.stopPropagation(); });
  })();

  // Details modal helpers
function openDetails(btn) {
  // Basic info
  document.getElementById('dTitle').textContent     = btn.dataset.title || 'Complaint';
  document.getElementById('dCategory').textContent  = btn.dataset.category || '—';
  document.getElementById('dSubcat').textContent    = btn.dataset.subcat || '—';
  document.getElementById('dStudent').textContent   = btn.dataset.student || '—';
  document.getElementById('dStudentId').textContent = btn.dataset.sid || '—';
  document.getElementById('dBlock').textContent     = btn.dataset.block || '—';
  document.getElementById('dRoom').textContent      = btn.dataset.room || '—';
  document.getElementById('dGen').textContent       = btn.dataset.gender || '—';
  document.getElementById('dCreated').textContent   = btn.dataset.created || '—';
  document.getElementById('dDesc').textContent      = btn.dataset.desc || '—';
  document.getElementById('techName').textContent   = btn.dataset.techname || '—';
  document.getElementById('dCompleted').textContent = btn.dataset.completed || '—';
  document.getElementById('dRemark').textContent    = btn.dataset.remark || '—';

  // Phone clickable
  const phone = btn.dataset.phone || '';
  const phoneEl = document.getElementById('dPhone');
  if (phone) {
    phoneEl.textContent = phone;
    phoneEl.href = 'tel:' + phone;
  } else {
    phoneEl.textContent = '—';
    phoneEl.removeAttribute('href');
  }

  // Status badge
  const staEl = document.getElementById('dStatus');
  const staVal = btn.dataset.status || '—';
  staEl.textContent = staVal;
  staEl.className = 'badge status-' + staVal.toLowerCase().replace(/\s+/g,'-');

  /* ==============================
     🟢 Separate Attachments Logic
     ============================== */
  const studentAttachList = document.getElementById('studentAttachList');
  const techProofList = document.getElementById('techProofList');
  studentAttachList.innerHTML = '';
  techProofList.innerHTML = '';

  let allFiles = [];
  try { allFiles = JSON.parse(btn.dataset.attachments || '[]'); } catch(e) {}

  if (!allFiles || allFiles.length === 0) {
    studentAttachList.textContent = '—';
    techProofList.textContent = '—';
  } else {
    const studentFiles = allFiles.filter(o => !o.path.includes('/proofs/'));
    const techFiles = allFiles.filter(o => o.path.includes('/proofs/'));

    // Student attachments
    if (studentFiles.length === 0) studentAttachList.textContent = '—';
    else studentFiles.forEach(o => {
      let fileName = (o.path || '').split('/').pop();
      let sizeMB = o.size ? ' (' + (o.size / 1024 / 1024).toFixed(2) + ' MB)' : '';
      let href = o.path.startsWith('uploads/') ? o.path : 'uploads/' + o.path;
      studentAttachList.insertAdjacentHTML('beforeend', `
        <div style="margin-bottom:6px;">
          <a href="${href}" target="_blank" style="color:#2563eb;text-decoration:none;">${fileName}</a>${sizeMB}<br>
          ${o.mime.startsWith('image/') ? `<img src="${href}" alt="Attachment" style="max-width:180px;border-radius:8px;margin-top:4px;">` : ''}
        </div>
      `);
    });

    // Technician proofs
    if (techFiles.length === 0) techProofList.textContent = '—';
    else techFiles.forEach(o => {
      let fileName = (o.path || '').split('/').pop();
      let href = o.path.startsWith('uploads/') ? o.path : 'uploads/' + o.path;
      techProofList.insertAdjacentHTML('beforeend', `
        <div style="margin-bottom:6px;">
          <a href="${href}" target="_blank" style="color:#2563eb;text-decoration:none;">${fileName}</a><br>
          ${o.mime.startsWith('image/') ? `<img src="${href}" alt="Proof" style="max-width:180px;border-radius:8px;margin-top:4px;">` : ''}
        </div>
      `);
    });
  }

  /* ==============================
     🟡 Admin Remarks
     ============================== */
  document.getElementById('rPending').textContent   = btn.dataset.rpending   || '—';
  document.getElementById('rProgress').textContent  = btn.dataset.rprogress  || '—';
  document.getElementById('rCompleted').textContent = btn.dataset.rcompleted || '—';
  document.getElementById('rRejected').textContent  = btn.dataset.rrejected  || '—';

  // Show modal
  document.getElementById('detailsModal').style.display = 'flex';
}



function closeDetails() {
  document.getElementById('detailsModal').style.display = 'none';
}

  </script>
</body>
</html>
