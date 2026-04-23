<?php
session_start();
$currentPage = basename($_SERVER['PHP_SELF']);
require_once 'config.php';

/* Technicians only */
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'technician') { header("Location: ./index.php"); exit(); }

$techId = (int)($_SESSION['user_id'] ?? 0);
$name   = $_SESSION['name'] ?? 'Technician';
function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* ---------- schema helpers ---------- */
function table_exists(mysqli $conn, string $table): bool {
  $t = $conn->real_escape_string($table);
  $res = $conn->query("SHOW TABLES LIKE '$t'");
  $ok  = $res && $res->num_rows > 0; if ($res) $res->close(); return $ok;
}
function table_has_col(mysqli $conn, string $table, string $col): bool {
  if (!table_exists($conn,$table)) return false;
  $t = $conn->real_escape_string($table); $c = $conn->real_escape_string($col);
  $res = $conn->query("SHOW COLUMNS FROM `$t` LIKE '$c'");
  $ok  = $res && $res->num_rows > 0; if ($res) $res->close(); return $ok;
}

/* phone source like reports page */
$PROFILE_HAS_PHONE = table_has_col($conn,'profile','phone');
$VS_HAS_PHONE      = table_has_col($conn,'valid_students','phone_digits');

/* ---------- pagination ---------- */
$perPage = 6;
$page   = isset($_GET['page']) ? max(1,(int)$_GET['page']) : 1;
$offset = ($page-1)*$perPage;

$stmt=$conn->prepare("
  SELECT COUNT(*) AS cnt
  FROM complaints
  WHERE assigned_to=? AND is_deleted=0 AND status IN ('Pending','In Progress')
");
$stmt->bind_param("i",$techId); $stmt->execute();
$totalTickets = (int)($stmt->get_result()->fetch_assoc()['cnt'] ?? 0);
$stmt->close();

$totalPages = max(1,(int)ceil($totalTickets/$perPage));
if ($page > $totalPages) { $page=$totalPages; $offset=($page-1)*$perPage; }

/* dynamic phone select + optional join */
$phoneSelect = "NULL AS student_phone";
$leftJoinVS  = "";
if ($PROFILE_HAS_PHONE) { $phoneSelect = "p.phone AS student_phone"; }
elseif ($VS_HAS_PHONE)  { $phoneSelect = "vs.phone_digits AS student_phone"; $leftJoinVS  = "LEFT JOIN valid_students vs ON vs.student_id=c.student_id"; }

/* tickets list (priority intentionally removed) */
$sql = "
  SELECT 
    c.*,
    COALESCE(NULLIF(c.subcategory,''),'(Unspecified)') AS subcat_clean,
    p.name AS student_name, p.block, p.room_number, p.gender,
    $phoneSelect
  FROM complaints c
  JOIN profile p ON p.student_id=c.student_id
  $leftJoinVS
  WHERE c.assigned_to=? AND c.is_deleted=0 AND c.status IN ('Pending','In Progress')
  ORDER BY c.id DESC
  LIMIT ? OFFSET ?
";
$st = $conn->prepare($sql);
$st->bind_param("iii", $techId, $perPage, $offset);
$st->execute();
$tickets = $st->get_result();

/* attachments helper (no file_role column) */
function get_attachments(mysqli $conn, int $complaintId): array {
  $files=[]; static $ok=null;
  if ($ok===null){ $r=$conn->query("SHOW TABLES LIKE 'complaint_attachments'"); $ok=$r && $r->num_rows>0; }
  if (!$ok) return $files;
  $sql="SELECT file_path, file_size, mime_type FROM complaint_attachments WHERE complaint_id=? ORDER BY id ASC";
  $st=$conn->prepare($sql); $st->bind_param("i",$complaintId); $st->execute(); $res=$st->get_result();
  while($row=$res->fetch_assoc()){
    $files[]=['path'=>(string)$row['file_path'],'size'=>(int)($row['file_size']??0),'mime'=>(string)($row['mime_type']??'')];
  }
  $st->close(); return $files;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<title>My Tickets</title>
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
<link rel="icon" type="image/png" href="assets/favicon.png" sizes="32x32">
<link rel="apple-touch-icon" href="assets/favicon.png">
<link rel="stylesheet" href="admin.css" />
<style>
:root{
  --ring:#e5e7eb; --card:#fff; --shadow:0 10px 26px rgba(0,0,0,.12); --soft:0 8px 22px rgba(0,0,0,.10);
  --blue:#2563eb; --blue-ghost:#eef2ff; --green:#16a34a;
}
body.technician::before{ content:""; position:fixed; inset:0; background:url('assets/dormitory.jpg') center/cover no-repeat; filter:blur(8px) brightness(.9); z-index:-2; }
body.technician::after{ content:""; position:fixed; inset:0; background:rgba(0,0,0,.40); z-index:-1; }

/* Slide panel */
.slide-panel{ position:fixed; top:0; right:-320px; width:320px; max-width:90vw; height:100vh; background:#fff; border-left:1px solid var(--ring); box-shadow:-8px 0 24px rgba(0,0,0,.15); transition:right .25s; z-index:1001; padding:14px; display:flex; flex-direction:column; }
.slide-panel[aria-hidden="false"]{ right:0; }
.slide-overlay{ display:none; position:fixed; inset:0; background:rgba(0,0,0,.35); z-index:1000; }
.slide-link{ display:block; padding:10px 12px; border-radius:10px; text-decoration:none; color:#111; }
.slide-link.active,.slide-link:hover{ background:var(--blue-ghost); }
.logout-btn-wide{ width:100%; padding:10px 12px; border:0; background:#ef4444; color:#fff; border-radius:10px; cursor:pointer; }

/* Centered wrapper & hero */
.wrap{ width:min(1160px, 94vw); margin:26px auto 60px; position:relative; }
.hero{ background:#fff; border:1px solid var(--ring); border-radius:28px; padding:22px; box-shadow:var(--shadow); text-align:center; position:relative; }
.hero h1{ margin:0; font-size:26px; font-weight:800; color:#111827; }
.hero p{ margin:6px 0 0; color:#6b7280; }

/* menu button in header (like before) */
.menu-btn{ position:absolute; left:12px; top:50%; transform:translateY(-50%); width:44px; height:44px; display:grid; place-items:center; background:#2b7de9; color:#fff; border:0; border-radius:12px; font-size:20px; font-weight:800; cursor:pointer; box-shadow:0 10px 24px rgba(43,125,233,.28); }

/* Grid of centered cards */
.grid{ display:grid; grid-template-columns:repeat(3,minmax(280px,1fr)); gap:18px; margin-top:18px; }
@media (max-width:1024px){ .grid{ grid-template-columns:repeat(2,minmax(280px,1fr)); } }
@media (max-width:640px){ .grid{ grid-template-columns:1fr; } }

.card{ background:#fff; border:1px solid var(--ring); border-radius:18px; padding:14px 16px; box-shadow:var(--soft); }
.card h3{ margin:0 0 6px; font-size:18px; }
.meta{ font-size:12px; color:#6b7280; display:flex; align-items:center; gap:8px; }
.hr{ border:none; border-top:1px solid var(--ring); margin:10px 0; }
.row{ font-size:13px; color:#1f2937; margin:6px 0; display:flex; gap:6px; flex-wrap:wrap; }

/* Status badge – forced small for consistency */
.badge{ display:inline-block; padding:4px 8px; border-radius:999px; font-size:12px !important; font-weight:700; line-height:1; }
.status-pending{ background:#fff7ed; color:#92400e; border:1px solid #fed7aa; }
.status-in-progress{ background:#eff6ff; color:#1e40af; border:1px solid #bfdbfe; }

/* Buttons */
.btn{ background:var(--blue); color:#fff; border:0; border-radius:10px; padding:10px 12px; font-weight:700; cursor:pointer; font-size:14px; }
.btn:active{ transform:scale(.98); }

/* Modal */
.modal{ display:none; position:fixed; inset:0; z-index:1050; background:rgba(0,0,0,.45); align-items:center; justify-content:center; padding:14px; }
.modal-card{ background:#fff; border:1px solid var(--ring); border-radius:18px; width:min(820px,96vw); max-height:92vh; overflow:auto; padding:18px; box-shadow:0 18px 48px rgba(0,0,0,.22); position:relative; }

/* Sticky right corner cluster (date + close) */
.m-topbar-right{ position:sticky; float:right; top:0; display:flex; gap:10px; align-items:center; justify-content:flex-end; padding-top:4px; }
.m-datepill{ display:inline-flex; align-items:center; gap:6px; padding:6px 10px; border-radius:999px; background:#f1f5f9; border:1px solid var(--ring); color:#334155; font-size:12px; }
.m-close{ width:42px; height:42px; border:0; border-radius:12px; background:var(--blue); color:#fff; font-weight:800; font-size:20px; display:grid; place-items:center; cursor:pointer; }

/* Title starts on *next row* under the top-right cluster */
.m-title{ clear:both; margin:10px 0 6px; font-size:18px; font-weight:800; color:#0f172a; }

/* Meta */
.meta-grid{ display:grid; grid-template-columns:1fr 1fr; gap:8px 20px; font-size:13px; color:#334155; margin-top:8px; }
.meta-item{ display:grid; grid-template-columns:120px 1fr; gap:6px; }
.meta-label{ color:#64748b; font-weight:600; }
.kbadge{ display:inline-block; padding:6px 10px; border-radius:999px; font-size:12px; font-weight:800; border:1px solid #a7f3d0; background:#ecfdf5; color:#065f46; }

/* Attachments */
.files-grid{ display:flex; flex-wrap:wrap; gap:8px; margin-top:6px; }
.thumb{ width:100px; height:76px; border:1px solid var(--ring); border-radius:8px; object-fit:cover; background:#f3f4f6; }
.filechip{ display:inline-block; padding:6px 10px; border:1px solid #cbd5e1; border-radius:999px; background:#f8fafc; font-size:12px; }
.mono{ font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; }

/* Mobile */
@media (max-width:720px){
  .meta-grid{ grid-template-columns:1fr; }
  .meta-item{ grid-template-columns:110px 1fr; }
  .btn{ width:100%; }
  input, select, textarea, button{ font-size:16px !important; }
}

/* Pagination – compact & mobile-friendly */
.pager{ display:flex; justify-content:center; gap:8px; flex-wrap:wrap; margin:16px 0 6px; }
.pbtn{ display:inline-flex; align-items:center; justify-content:center; min-width:40px; padding:8px 12px; border-radius:12px; text-decoration:none; font-weight:700; border:1px solid var(--ring); background:#fff; color:#0f172a; }
.pbtn.active{ background:var(--blue); color:#fff; border-color:var(--blue); }
@media (max-width:480px){ .pbtn{ min-width:36px; padding:8px 10px; } }
</style>
</head>
<body class="dashboard technician">

<!-- Slide menu -->
<div class="slide-overlay" id="slideOverlay"></div>
<aside class="slide-panel" id="slidePanel" aria-hidden="true">
  <div class="slide-header">
    <strong>Quick Menu</strong>
    <button id="panelClose" style="border:0;background:#0000;font-size:22px;cursor:pointer">&times;</button>
  </div>
  <div class="slide-body">
    <a class="slide-link <?= $currentPage=='technician_page.php'?'active':'' ?>" href="technician_page.php">📊 Dashboard</a>
    <a class="slide-link <?= $currentPage=='technician_mytickets.php'?'active':'' ?>" href="technician_mytickets.php">🎫 My Tickets</a>
    <a class="slide-link <?= $currentPage=='technician_reports.php'?'active':'' ?>" href="technician_reports.php">📑 Reports</a>
    <a class="slide-link <?= $currentPage=='technician_profile.php'?'active':'' ?>" href="technician_profile.php">👤 Profile</a>

    <div style="height:1px;background:#e5e7eb;margin:8px 0;"></div>
    <form action="logout.php" method="post"><button type="submit" class="logout-btn-wide">⏻ Logout</button></form>
  </div>
</aside>

<div class="wrap">
  <section class="hero">
    <button id="panelToggleBtn" class="menu-btn" aria-label="Open menu">≡</button>
    <h1>💳 My Tickets</h1>
    <p>All tickets currently assigned to you</p>
  </section>

  <section class="grid">
    <?php while($row=$tickets->fetch_assoc()): ?>
      <?php
        $cid   = (int)$row['id'];
        $files = get_attachments($conn,$cid);
        $studentFiles = []; $proofFiles=[];
        foreach($files as $f){
          $p = $f['path'] ?? '';
          $isProof = (strpos($p,'proofs/')===0) || (strpos($p,'uploads/proofs/')===0);
          if ($isProof) $proofFiles[]=$f; else $studentFiles[]=$f;
        }
        $phone = trim((string)($row['student_phone'] ?? ''));
      ?>
      <article class="card">
        <h3><?= e($row['title']) ?></h3>
        <div class="meta">🕒 <?= date("M j, Y · g:i A", strtotime((string)$row['created_at'])) ?></div>

        <div class="row"><strong>Sub-Category:</strong> <?= e($row['subcat_clean']) ?></div>
        <div class="row"><strong>Status:</strong> <span class="badge status-<?= strtolower(str_replace(' ','-',$row['status'])) ?>"><?= e($row['status']) ?></span></div>

        <div class="hr"></div>
        <div class="row"><strong>Student:</strong> <?= e($row['student_name']) ?></div>
        <div class="row"><strong>Phone:</strong> <?= $phone ? '<a class="mono" href="tel:'.e($phone).'">'.e($phone).'</a>' : '—' ?></div>
        <div class="row"><strong>Gender:</strong> <?= e($row['gender']) ?></div>
        <div class="row"><strong>Block:</strong> <?= e($row['block']) ?> &nbsp;|&nbsp; <strong>Room:</strong> <?= e($row['room_number']) ?></div>

        <div style="margin-top:10px;">
          <button class="btn" onclick="openModal(<?= $cid ?>)">View Details</button>
        </div>
      </article>

      <!-- Details Modal -->
      <div id="modal-<?= $cid ?>" class="modal" role="dialog" aria-modal="true">
        <div class="modal-card">
          <!-- top-right cluster stays fixed visually -->
          <div class="m-topbar-right">
            <div class="m-datepill">🕒 <?= date("M d, Y · g:i A", strtotime((string)$row['created_at'])) ?></div>
            <button class="m-close" onclick="closeModal(<?= $cid ?>)" aria-label="Close">✕</button>
          </div>

          <!-- title below the cluster -->
          <h3 class="m-title"><?= e($row['title']) ?></h3>

          <div class="meta-grid">
            <div class="meta-item"><div class="meta-label">Sub Category:</div><div><?= e($row['subcat_clean']) ?></div></div>
            <div class="meta-item"><div class="meta-label">Student:</div><div><?= e($row['student_name']) ?></div></div>

            <div class="meta-item"><div class="meta-label">Phone:</div><div><?= $phone ? '<a class="mono" href="tel:'.e($phone).'">'.e($phone).'</a>' : '—' ?></div></div>
            <div class="meta-item"><div class="meta-label">Gender:</div><div><?= e($row['gender']) ?></div></div>

            <div class="meta-item"><div class="meta-label">Block/Room:</div><div><?= e($row['block']) ?> / <?= e($row['room_number']) ?></div></div>
            <div class="meta-item"><div class="meta-label">Status:</div><div><span class="badge status-<?= strtolower(str_replace(' ','-',$row['status'])) ?>"><?= e($row['status']) ?></span></div></div>

            <div class="meta-item" style="grid-column:1 / -1;">
              <div class="meta-label">Description:</div>
              <div style="white-space:pre-wrap;"><?= nl2br(e($row['complaint'])) ?></div>
            </div>
          </div>

          <div style="margin-top:12px;">
            <strong>Student Attachment(s):</strong>
            <div class="files-grid">
              <?php
                $printed=false;
                if (!empty($studentFiles)) {
                  foreach($studentFiles as $f){
                    $p=$f['path']; if ($p && strpos($p,'uploads/')!==0) $p='uploads/'.$p;
                    if (strpos((string)$f['mime'],'image/')===0){
                      echo '<a href="'.e($p).'" target="_blank" rel="noopener"><img class="thumb" src="'.e($p).'" alt="attachment"></a>';
                    } else {
                      $nm=basename($p) ?: 'file';
                      echo '<a class="filechip" href="'.e($p).'" target="_blank" rel="noopener">'.e($nm).'</a>';
                    }
                    $printed=true;
                  }
                }
                if (!$printed) echo '<span class="meta" style="padding-left:2px;">No attachment</span>';
              ?>
            </div>
          </div>

          <div style="margin-top:14px;">
            <?php if ($row['status']==='Pending'): ?>
              <form method="post" action="technician_update.php">
                <input type="hidden" name="id" value="<?= $cid ?>">
                <input type="hidden" name="status" value="In Progress">
                <button class="btn" style="width:100%;">▶ Start Work</button>
              </form>
            <?php elseif ($row['status']==='In Progress'): ?>
              <button class="btn" style="width:100%;background:var(--green);" onclick="openActionModal('complete',<?= $cid ?>)">✅ Complete</button>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- Complete Modal (upload proof enabled) -->
      <div id="complete-modal-<?= $cid ?>" class="modal" role="dialog" aria-modal="true">
        <div class="modal-card">
          <div class="m-topbar-right">
            <button class="m-close" onclick="closeActionModal('complete',<?= $cid ?>)" aria-label="Close">✕</button>
          </div>
          <h3 class="m-title">Complete Ticket</h3>
          <form method="post" action="technician_update.php" enctype="multipart/form-data" onsubmit="return confirm('Mark this ticket as Completed?');">
            <input type="hidden" name="id" value="<?= $cid ?>">
            <input type="hidden" name="status" value="Completed">
            <label style="font-size:13px;"><strong>Remarks:</strong></label>
            <textarea name="proof_note" required style="width:100%;padding:10px;margin:8px 0;border:1px solid var(--ring);border-radius:8px;"></textarea>
            <label style="font-size:13px;"><strong>Upload Proof (optional):</strong></label>
            <input type="file" name="proof_attachment" accept="image/*,video/*,application/pdf" style="margin:6px 0 12px;">
            <button type="submit" class="btn" style="width:100%;background:var(--green);">✅ Confirm Complete</button>
          </form>
        </div>
      </div>

    <?php endwhile; ?>
  </section>

  <!-- Pagination -->
  <nav class="pager" aria-label="Pagination">
    <?php if ($page>1): ?><a class="pbtn" href="?page=<?= $page-1 ?>">Prev</a><?php endif; ?>
    <?php for($i=1;$i<=$totalPages;$i++): ?>
      <a class="pbtn <?= $i==$page?'active':'' ?>" href="?page=<?= $i ?>"><?= $i ?></a>
    <?php endfor; ?>
    <?php if ($page<$totalPages): ?><a class="pbtn" href="?page=<?= $page+1 ?>">Next</a><?php endif; ?>
  </nav>
</div>

<script>
  function openPanel(){ document.getElementById("slidePanel").setAttribute("aria-hidden","false"); document.getElementById("slideOverlay").style.display="block"; }
  function closePanel(){ document.getElementById("slidePanel").setAttribute("aria-hidden","true");  document.getElementById("slideOverlay").style.display="none"; }
  document.getElementById("panelToggleBtn")?.addEventListener("click", openPanel);
  document.getElementById("panelClose")?.addEventListener("click", closePanel);
  document.getElementById("slideOverlay")?.addEventListener("click", closePanel);

  function openModal(id){ document.getElementById("modal-"+id).style.display="flex"; }
  function closeModal(id){ document.getElementById("modal-"+id).style.display="none"; }
  function openActionModal(type,id){ document.getElementById(type+"-modal-"+id).style.display="flex"; }
  function closeActionModal(type,id){ document.getElementById(type+"-modal-"+id).style.display="none"; }
</script>
</body>
</html>

