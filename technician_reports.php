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

/* =========================
   Helpers: safe existence checks
   ========================= */
function table_exists(mysqli $conn, string $table): bool {
  $t = $conn->real_escape_string($table);
  $res = $conn->query("SHOW TABLES LIKE '$t'");
  $ok = $res && $res->num_rows > 0;
  if ($res) $res->close();
  return $ok;
}
function table_has_col(mysqli $conn, string $table, string $col): bool {
  if (!table_exists($conn, $table)) return false;
  $t = $conn->real_escape_string($table);
  $c = $conn->real_escape_string($col);
  $res = $conn->query("SHOW COLUMNS FROM `$t` LIKE '$c'");
  $ok = $res && $res->num_rows > 0;
  if ($res) $res->close();
  return $ok;
}

/* detect phone source (all safe now) */
$PROFILE_HAS_PHONE = table_has_col($conn,'profile','phone'); // optional column
$VS_HAS_PHONE      = table_has_col($conn,'valid_students','phone_digits'); // may not exist

/* =========================
   Filters
   ========================= */
$statusFilter = trim($_GET['status'] ?? '');             // '' | Completed | Rejected
$subcatFilter = trim($_GET['subcategory'] ?? '');
$startDate    = trim($_GET['start'] ?? '');
$endDate      = trim($_GET['end']   ?? '');

/* Subcategory list (from this tech’s closed/rejected tickets) */
$subcats = [];
$sc = $conn->prepare("
  SELECT DISTINCT COALESCE(NULLIF(subcategory,''),'(Unspecified)') AS s
  FROM complaints
  WHERE assigned_to=? AND is_deleted=0 AND status IN ('Completed','Rejected')
  ORDER BY s
");
$sc->bind_param("i", $techId);
$sc->execute();
$sr = $sc->get_result();
while($row=$sr->fetch_assoc()){ $subcats[] = $row['s']; }
$sc->close();

/* =========================
   Base filtered query
   ========================= */
$where = ["c.assigned_to=$techId", "c.is_deleted=0", "c.status IN ('Completed','Rejected')"];
if ($statusFilter !== '') $where[] = "c.status='" . $conn->real_escape_string($statusFilter) . "'";
if ($subcatFilter !== '') $where[] = "COALESCE(NULLIF(c.subcategory,''),'(Unspecified)')='" . $conn->real_escape_string($subcatFilter) . "'";
if ($startDate !== '')    $where[] = "DATE(c.created_at)>='" . $conn->real_escape_string($startDate) . "'";
if ($endDate   !== '')    $where[] = "DATE(c.created_at)<='" . $conn->real_escape_string($endDate) . "'";
$WS = implode(' AND ', $where);

/* dynamic phone select expr */
$phoneSelect = "NULL AS student_phone";
$leftJoinVS  = ""; // add only if we need valid_students
if ($PROFILE_HAS_PHONE) {
  $phoneSelect = "p.phone AS student_phone";
} elseif ($VS_HAS_PHONE) {
  $phoneSelect = "vs.phone_digits AS student_phone";
  $leftJoinVS  = "LEFT JOIN valid_students vs ON vs.student_id=c.student_id";
}

/* query */
/* query */
$query = "
  SELECT 
    c.*,
    p.name AS student_name, p.block, p.room_number, p.gender,
    t.name AS technician_name,
    $phoneSelect,
    COALESCE(NULLIF(c.subcategory,''),'(Unspecified)') AS subcat_clean
  FROM complaints c
  JOIN profile p ON p.student_id = c.student_id
  LEFT JOIN profile t ON t.id = c.assigned_to
  $leftJoinVS
  WHERE $WS
  ORDER BY c.created_at DESC
";



/* =========================
   KPI counts
   ========================= */
$completedCount = $conn->query("
  SELECT COUNT(*) AS c FROM complaints 
  WHERE assigned_to=$techId AND is_deleted=0 AND status='Completed'
")->fetch_assoc()['c'] ?? 0;

$totalCount = $conn->query("
  SELECT COUNT(*) AS c FROM complaints 
  WHERE assigned_to=$techId AND is_deleted=0 AND status IN ('Completed','Rejected')
")->fetch_assoc()['c'] ?? 0;

/* =========================
   Export CSV
   ========================= */
if (isset($_GET['export']) && $_GET['export']==='csv') {
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename=technician_reports.csv');
  $out = fopen('php://output', 'w');
  fputcsv($out, ['#','Title','Sub-Category','Status','Student','Phone','Block','Room','Completed At','Created At']);
  $res = $conn->query($query);
  $i=1;
  while($r=$res->fetch_assoc()){
    $completedAt = ($r['status']==='Completed' && !empty($r['updated_at'])) ? $r['updated_at'] : '';
    fputcsv($out, [
      $i++,
      $r['title'],
      $r['subcat_clean'],
      $r['status'],
      $r['student_name'],
      $r['student_phone'] ?? '',
      $r['block'],
      $r['room_number'],
      $completedAt,
      $r['created_at'],
    ]);
  }
  fclose($out);
  exit;
}

/* =========================
   Pagination
   ========================= */
$perPage = 10;
$page = isset($_GET['page']) ? max(1,(int)$_GET['page']) : 1;
$offset = ($page-1)*$perPage;

$totalResult  = $conn->query($query);
$totalTickets = $totalResult->num_rows;
$totalPages   = (int)ceil(max(1,$totalTickets) / $perPage);

$queryPaged = $query . " LIMIT $perPage OFFSET $offset";
$tickets    = $conn->query($queryPaged);

/* =========================
   Attachments helper
   ========================= */
function get_attachments_any(mysqli $conn, int $cid): array {
  $files=[];
  $st=$conn->prepare("SELECT file_path, file_size, mime_type FROM complaint_attachments WHERE complaint_id=? ORDER BY id ASC");
  $st->bind_param("i",$cid);
  $st->execute();
  $r=$st->get_result();
  while($row=$r->fetch_assoc()){
    $files[] = [
      'path' => (string)$row['file_path'],
      'size' => (int)($row['file_size'] ?? 0),
      'mime' => (string)($row['mime_type'] ?? '')
    ];
  }
  $st->close();
  return $files;
}

/* Choose admin remark based on status (fallbacks included) */
function pick_admin_remark(array $row): string {
  $status = $row['status'] ?? '';
  $map = [
    'Pending'     => 'remark_pending',
    'In Progress' => 'remark_in_progress',
    'Completed'   => 'remark_completed',
    'Rejected'    => 'remark_rejected',
  ];
  if (isset($map[$status]) && !empty($row[$map[$status]])) return (string)$row[$map[$status]];
  if (!empty($row['admin_remark'])) return (string)$row['admin_remark'];
  if (!empty($row['notes']))        return (string)$row['notes'];
  return '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Technician Reports</title>
<link rel="icon" type="image/png" href="assets/favicon.png" sizes="32x32"> <link rel="apple-touch-icon" href="assets/favicon.png">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
<link rel="stylesheet" href="admin.css">
<style>
:root{
  --card:#fff; --ring:#e5e7eb;
  --shadow:0 10px 26px rgba(0,0,0,.12);
  --soft:0 8px 22px rgba(0,0,0,.10);
  --blue:#2563eb; --green:#22c55e;
}
body{ margin:0;}
body.technician::before{
  content:""; position:fixed; inset:0;
  background:url('assets/dormitory.jpg') center/cover no-repeat;
  filter:blur(8px) brightness(.9); z-index:-2;
}
body.technician::after{
  content:""; position:fixed; inset:0; background:rgba(0,0,0,.40); z-index:-1;
}

/* slide menu */
.slide-panel{
  position:fixed; top:0; right:-320px; width:320px; max-width:90vw; height:100vh;
  background:#fff; border-left:1px solid var(--ring);
  box-shadow:-8px 0 24px rgba(0,0,0,.15); transition:right .25s ease;
  z-index:1001; padding:14px; display:flex; flex-direction:column;
}
.slide-panel[aria-hidden="false"]{ right:0; }
.slide-overlay{ display:none; position:fixed; inset:0; background:rgba(0,0,0,.35); z-index:1000; }
.slide-header{ display:flex; align-items:center; justify-content:space-between; margin-bottom:10px; }
.slide-link{ display:block; padding:10px 12px; text-decoration:none; color:#111; border-radius:10px; }
.slide-link.active,.slide-link:hover{ background:#eef2ff; }
.slide-divider{ height:1px; background:#e5e7eb; margin:8px 0; }
.logout-btn-wide{ width:100%; padding:10px 12px; border:0; background:#ef4444; color:#fff; border-radius:10px; cursor:pointer; }

/* centered wrap */
.wrap{ width:min(1180px, 94vw); margin:26px auto 60px; }

/* header pill */
.hero{
  background:#fff; border:1px solid var(--ring); border-radius:28px; box-shadow:var(--shadow);
  padding:22px 24px; position:relative; display:flex; justify-content:center; align-items:center;
}
.hero h1{ margin:0; font-size:28px; font-weight:800; color:#1f2937; text-align:center; }
.hero p{ margin:6px 0 0; color:#6b7280; text-align:center; }
.menu-btn{
  position:absolute; left:18px; top:50%; transform:translateY(-50%);
  width:48px; height:48px; border-radius:14px; border:0; cursor:pointer;
  background:#3b82f6; color:#fff; font-size:22px; display:grid; place-items:center;
  box-shadow:0 8px 18px rgba(59,130,246,.32);
}
.menu-btn:hover{ background:#2563eb; }

/* KPIs */
.kpis{ display:grid; grid-template-columns:repeat(2,minmax(200px,1fr)); gap:14px; margin-top:16px;}
.kpi{
  background:#fff; border:2px solid var(--ring); border-radius:18px; box-shadow:var(--soft);
  padding:14px 16px; display:flex; align-items:center; gap:12px; justify-content:center;
}
.kpi .icon{ width:40px; height:40px; border-radius:12px; display:grid; place-items:center; font-weight:900; }
.kpi .v{ font-size:22px; font-weight:900; text-align:center; }
.kpi .l{ font-size:13px; color:#475569; text-align:center; }
.kpi--total .icon{ background:#eef2f7; }
.kpi--completed{ border-color: var(--green); box-shadow:0 8px 22px rgba(34,197,94,.15); }
.kpi--completed .icon{ background:#bbf7d0; }
.kpi--completed .v{ color: var(--green); }

/* filters */
.filters{
  background:#fff; border:1px solid var(--ring); border-radius:20px; box-shadow:var(--soft);
  padding:16px; margin:16px 0; display:flex; flex-wrap:wrap; gap:12px; justify-content:center; align-items:center;
}
.filters select,.filters input[type="date"]{
  padding:10px 12px; border:1px solid #d1d5db; border-radius:12px; background:#fafafa; font-size:14px;
}
.btn{ padding:10px 16px; border:0; border-radius:12px; cursor:pointer; font-weight:700; }
.primary{ background:#2563eb; color:#fff; }
.ghost{ background:#eef2ff; color:#111; text-decoration:none; }

/* actions */
.actions{ display:flex; gap:10px; justify-content:flex-end; margin:8px 0; }

/* table */
.table-shell table{ width:100%; border-collapse:collapse; background:#fff; border:1px solid var(--ring); border-radius:14px; overflow:hidden; box-shadow:var(--soft);}
.table-shell th, .table-shell td{ padding:12px 14px; border-bottom:1px solid #e5e7eb; font-size:14px; text-align:left; }
.table-shell th{ background:#0f172a; color:#fff; font-weight:600; }
.table-shell tbody tr:nth-child(even){ background:#f8fafc; }

/* detail modal */
.modal{ display:none; position:fixed; inset:0; z-index:1050; background:rgba(0,0,0,.45); align-items:center; justify-content:center; padding:14px; }
.modal-card{
  background:#fff; border-radius:18px; width:min(840px, 96vw); max-height:92vh; overflow:auto;
  padding:18px; border:1px solid var(--ring); box-shadow:0 18px 48px rgba(0,0,0,.22); position:relative;
}
.modal-close{
  position:absolute; right:16px; top:16px;
  width:42px; height:42px; border:0; border-radius:12px; background:#2563eb; color:#fff;
  font-size:20px; font-weight:800; cursor:pointer; display:grid; place-items:center;
}
.m-title{ margin:0 40px 4px 0; font-size:22px; font-weight:800; color:#0f172a; }
.m-subtle{ color:#6b7280; font-size:13px; margin-bottom:12px; }
.meta-grid{ display:grid; grid-template-columns:1fr 1fr; gap:8px 24px; font-size:14px; }
.meta-item{ display:grid; grid-template-columns:140px 1fr; gap:8px; }
.meta-label{ color:#475569; font-weight:600; }
@media (max-width:720px){
  .kpis{ grid-template-columns:1fr; }
  .meta-grid{ grid-template-columns:1fr; }
  .meta-item{ grid-template-columns:120px 1fr; }
}
.kbadge{ display:inline-block; padding:6px 10px; border-radius:999px; font-size:12px; font-weight:800; border:1px solid #a7f3d0; background:#ecfdf5; color:#065f46; }

/* attachments */
.files-grid{ display:flex; flex-wrap:wrap; gap:8px; margin-top:6px; }
.thumb{ width:100px; height:76px; border:1px solid #e5e7eb; border-radius:8px; object-fit:cover; background:#f3f4f6; }
.filechip{ display:inline-block; padding:6px 10px; border:1px solid #cbd5e1; border-radius:999px; background:#f8fafc; font-size:12px; }
.mono{ font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; }
</style>
</head>

<body class="dashboard technician">
<!-- slide menu -->
<div class="slide-overlay" id="slideOverlay"></div>
<aside class="slide-panel" id="slidePanel" aria-hidden="true">
  <div class="slide-header">
    <strong>Quick Menu</strong>
    <button id="panelClose" style="border:0;background:#0000;font-size:22px;cursor:pointer" aria-label="Close">&times;</button>
  </div>
  <div class="slide-body">
    <a class="slide-link <?= $currentPage=='technician_page.php'?'active':'' ?>" href="technician_page.php">📊 Dashboard</a>
    <a class="slide-link <?= $currentPage=='technician_mytickets.php'?'active':'' ?>" href="technician_mytickets.php">🎫 My Tickets</a>
    <a class="slide-link <?= $currentPage=='technician_reports.php'?'active':'' ?>" href="technician_reports.php">📑 Reports</a>
    <a class="slide-link <?= $currentPage=='technician_profile.php'?'active':'' ?>" href="technician_profile.php">👤 Profile</a>
    <div class="slide-divider"></div>
    <form action="logout.php" method="post"><button type="submit" class="logout-btn-wide">⏻ Logout</button></form>
  </div>
</aside>

<div class="wrap">

  <!-- header -->
  <section class="hero">
    <button id="panelToggleBtn" class="menu-btn" aria-label="Open menu">≡</button>
    <div>
      <h1>Reports</h1>
      <p>View your completed and rejected tickets</p>
    </div>
  </section>

  <!-- KPIs -->
  <section class="kpis">
    <div class="kpi kpi--total">
      <div class="icon">Σ</div>
      <div><div class="v"><?= (int)$totalCount ?></div><div class="l">Total Tickets</div></div>
    </div>
    <div class="kpi kpi--completed">
      <div class="icon">✅</div>
      <div><div class="v"><?= (int)$completedCount ?></div><div class="l">Completed</div></div>
    </div>
  </section>

  <!-- filters -->
  <form method="get" class="filters" id="filterForm">
    <select name="status" aria-label="Status">
      <option value="">All Status</option>
      <option value="Completed" <?= $statusFilter==='Completed'?'selected':'' ?>>Completed</option>
      <option value="Rejected"  <?= $statusFilter==='Rejected'?'selected':''  ?>>Rejected</option>
    </select>

    <select name="subcategory" aria-label="Sub-Category">
      <option value="">All Sub-Category</option>
      <?php foreach($subcats as $s): ?>
        <option value="<?= e($s) ?>" <?= $subcatFilter===$s?'selected':'' ?>><?= e($s) ?></option>
      <?php endforeach; ?>
    </select>

    <input type="date" name="start" value="<?= e($startDate) ?>" aria-label="From date">
    <input type="date" name="end"   value="<?= e($endDate)   ?>" aria-label="To date">

    <button class="btn primary" type="submit">Filter</button>
    <a class="btn ghost" href="technician_reports.php">Reset</a>
  </form>

  <div class="actions">
    <a class="btn ghost" href="?<?= e(http_build_query(array_merge($_GET,['export'=>'csv','page'=>null]))) ?>">Export CSV</a>
    <button class="btn primary" type="button" onclick="exportPDF()">Export PDF</button>
  </div>

  <!-- table -->
  <div class="table-shell" id="tableContainer">
    <table id="reportTable">
      <thead>
        <tr>
          <th>#</th>
          <th>Title</th>
          <th>Sub-Category</th>
          <th>Status</th>
          <th>Student</th>
          <th>Block/Room</th>
          <th>Completed At</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
      <?php 
      if ($tickets->num_rows > 0):
        $no = $offset + 1;
        while($row=$tickets->fetch_assoc()):
          $completedAt = ($row['status']==='Completed' && !empty($row['updated_at'])) ? date("M j, Y · g:i A", strtotime($row['updated_at'])) : '—';
      ?>
        <tr>
          <td><?= $no++ ?></td>
          <td><?= e($row['title']) ?></td>
          <td><?= e($row['subcat_clean']) ?></td>
          <td>
            <?php if ($row['status']==='Completed'): ?>
              <span class="kbadge">Completed</span>
            <?php else: ?>
              <span class="filechip" style="border-color:#fecaca;background:#fff1f2;color:#991b1b;">Rejected</span>
            <?php endif; ?>
          </td>
          <td><?= e($row['student_name']) ?></td>
          <td><?= e($row['block']) ?>/<?= e($row['room_number']) ?></td>
          <td><?= $completedAt ?></td>
          <td>
            <button class="btn primary" style="padding:6px 10px;" 
              onclick='openModal(<?= json_encode($row, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>)'>
              View
            </button>
          </td>
        </tr>
      <?php endwhile; else: ?>
        <tr><td colspan="8" style="text-align:center;">No records found</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>

  <!-- pagination -->
  <div class="actions" style="justify-content:center;">
    <?php if ($page > 1): ?><a class="btn ghost" href="?<?= e(http_build_query(array_merge($_GET,['page'=>$page-1]))) ?>">Prev</a><?php endif; ?>
    <?php for ($i=1; $i<=$totalPages; $i++): ?>
      <a class="btn <?= $i==$page?'primary':'ghost' ?>" href="?<?= e(http_build_query(array_merge($_GET,['page'=>$i]))) ?>"><?= $i ?></a>
    <?php endfor; ?>
    <?php if ($page < $totalPages): ?><a class="btn ghost" href="?<?= e(http_build_query(array_merge($_GET,['page'=>$page+1]))) ?>">Next</a><?php endif; ?>
  </div>
</div>

<!-- details modal -->
<div class="modal" id="ticketModal" role="dialog" aria-modal="true">
  <div class="modal-card">
    <button class="modal-close" onclick="closeModal()" aria-label="Close">✕</button>

    <h2 id="mTitle" class="m-title"></h2>
    <span>🕒</span><span id="mDate"></span><div id="mDate" class="m-subtle"></div>
    

    <div class="meta-grid" id="mMeta"></div>

    <div style="margin-top:10px;">
      <strong>Student Attachment:</strong>
      <div id="mFiles" class="files-grid" style="border:1px solid var(--ring); border-radius:10px; padding:10px; margin-top:6px;"></div>
    </div>

    <div style="margin-top:14px; background:#f9fafb; border:1px solid #e5e7eb; border-radius:12px; padding:12px;">
      <div id="mStatusBadge" style="margin-bottom:6px;"></div>
      <p style="margin:0 0 6px;"><strong>Technician Remark:</strong> <span id="mProofNote"></span></p>
      <p style="margin:0 0 6px;"><strong>Proof:</strong> <span id="mProofFile"></span></p>
      <p style="margin:0 0 6px;"><strong>Completed At:</strong> <span id="mCompletedAt"></span></p>
      <p style="margin:0"><strong>Admin Remark:</strong> <span id="mAdminRemark"></span></p>
    </div>
  </div>
</div>
<script>
// slide panel
function openPanel(){ document.getElementById("slidePanel").setAttribute("aria-hidden","false"); document.getElementById("slideOverlay").style.display="block"; }
function closePanel(){ document.getElementById("slidePanel").setAttribute("aria-hidden","true");  document.getElementById("slideOverlay").style.display="none"; }
document.getElementById("panelToggleBtn").addEventListener("click", openPanel);
document.getElementById("panelClose").addEventListener("click", closePanel);
document.getElementById("slideOverlay").addEventListener("click", closePanel);

// Export PDF
function exportPDF(){
  const html = `
    <html><head><title>Technician Reports</title>
    <style>
      body{ font-family:Arial, sans-serif; margin:24px; color:#111;}
      h2{ margin:0 0 8px }
      table{ width:100%; border-collapse:collapse; }
      th,td{ border:1px solid #ddd; padding:8px; font-size:12px; }
      th{ background:#f3f4f6; }
    </style></head><body>
    <h2>Technician Reports</h2>
    <div style="font-size:12px; margin:6px 0 12px;">Exported: ${new Date().toLocaleString()}</div>
    ${document.getElementById('reportTable').outerHTML}
    </body></html>`;
  const w = window.open('', '_blank');
  w.document.write(html); w.document.close();
  w.focus(); w.print();
}

// details modal
function openModal(row){
  // reset previous modal content
  document.getElementById("mStatusBadge").innerHTML = '';
  document.getElementById("mProofFile").innerHTML = '';
  document.getElementById("mProofNote").textContent = '';
  document.getElementById("mAdminRemark").textContent = '';
  document.getElementById("mFiles").innerHTML = '';

  document.getElementById("mTitle").textContent = row.title || '(no title)';
  document.getElementById("mDate").textContent  = new Date(row.created_at).toLocaleString();
  const completedAt = (row.status==='Completed' && row.updated_at) ? new Date(row.updated_at).toLocaleString() : '—';
  document.getElementById("mCompletedAt").textContent = completedAt;

  const phoneRaw  = (row.student_phone || '').trim();
  const phoneHTML = phoneRaw ? `<a class="mono" href="tel:${phoneRaw}">${phoneRaw}</a>` : '—';

  // badge
  document.getElementById("mStatusBadge").innerHTML =
    row.status==='Completed'
      ? `<span class="kbadge">Completed</span>`
      : `<span class="filechip" style="border-color:#fecaca;background:#fff1f2;color:#991b1b;">Rejected</span>`;

 // technician name (single line, remove duplicates before adding)
const oldTechLine = document.getElementById("techNameLine");
if (oldTechLine) oldTechLine.remove();

const techName = row.technician_name || 'Unknown Technician';
document.getElementById("mStatusBadge").insertAdjacentHTML('afterend', `
  <p id="techNameLine" style="margin:4px 0 6px;">
    <strong>Technician:</strong>
    <span style="color:#374151; font-weight:600;">${techName}</span>
  </p>
`);


  // meta grid
  document.getElementById("mMeta").innerHTML = `
    <div class="meta-item"><div class="meta-label">Sub Category:</div><div>${row.subcategory || '(Unspecified)'}</div></div>
    <div class="meta-item"><div class="meta-label">Student:</div><div>${row.student_name || '-'}</div></div>
    <div class="meta-item"><div class="meta-label">Phone:</div><div>${phoneHTML}</div></div>
    <div class="meta-item"><div class="meta-label">Status:</div><div>${row.status}</div></div>
    <div class="meta-item"><div class="meta-label">Block/Room:</div><div>${row.block || '-'} / ${row.room_number || '-'}</div></div>
    <div class="meta-item"><div class="meta-label">Gender:</div><div>${row.gender || '-'}</div></div>
    <div class="meta-item" style="grid-column:1 / -1">
      <div class="meta-label">Description:</div>
      <div style="white-space:pre-wrap;">${row.complaint || 'No description provided'}</div>
    </div>
  `;

  // technician remark
  document.getElementById("mProofNote").textContent = row.proof_note || 'No remark provided';

  // admin remark
  let adminRemark = row[`remark_${row.status?.toLowerCase().replace(' ','_')}`] 
                    || row.admin_remark || row.notes || '—';
  document.getElementById("mAdminRemark").textContent = adminRemark;

  // fetch attachments (student + proof)
  const filesWrap = document.getElementById("mFiles");
  const proofWrap = document.getElementById("mProofFile");
  filesWrap.innerHTML = '<span class="meta">Loading...</span>';
  proofWrap.innerHTML = '<span class="meta">Loading...</span>';

  fetch('technician_reports_fetch_files.php?id=' + encodeURIComponent(row.id))
    .then(r => r.json())
    .then(files => {
      filesWrap.innerHTML = '';
      proofWrap.innerHTML = '';
      if (!files || files.length === 0) {
        filesWrap.textContent = 'No attachment provided';
        proofWrap.textContent = 'No proof provided';
        return;
      }

      let studentCount = 0, proofCount = 0;
      files.forEach(f => {
        const path = f.path.startsWith('uploads/') ? f.path : 'uploads/' + f.path;
        const isProof = path.includes('proofs/');
        const isImage = (f.mime || '').startsWith('image/');

        const container = document.createElement('a');
        container.href = path;
        container.target = '_blank';
        container.rel = 'noopener';

        if (isImage) {
          const img = new Image();
          img.src = path;
          img.className = 'thumb';
          img.alt = 'attachment';
          container.appendChild(img);
        } else {
          container.className = 'filechip';
          container.textContent = path.split('/').pop();
        }

        if (isProof) {
          proofWrap.appendChild(container);
          proofCount++;
        } else {
          filesWrap.appendChild(container);
          studentCount++;
        }
      });

      if (studentCount === 0) filesWrap.textContent = 'No attachment provided';
      if (proofCount === 0) proofWrap.textContent = 'No proof provided';
    })
    .catch(() => {
      filesWrap.textContent = 'No attachment provided';
      proofWrap.textContent = 'No proof provided';
    });

  document.getElementById("ticketModal").style.display = "flex";
}

function closeModal(){ document.getElementById("ticketModal").style.display = "none"; }
window.addEventListener('click',(e)=>{ if(e.target.id==='ticketModal') closeModal(); });
</script>


</body>
</html>
