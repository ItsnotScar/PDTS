<?php
session_start();
require_once 'config.php';

/* --- Access guard --- */
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'ketua_penyelia') {
  header("Location: index.php"); exit;
}
$ketuaName = $_SESSION['name'] ?? 'Ketua Penyelia';

/* --- Routing --- */
$section = $_GET['section'] ?? 'dashboard';

/* --- Helpers --- */
function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function build_filters_qs($createdSortIn, $blockFilter,$studentIdLike,$categoryFilter,$subcategoryFilter,$statusFilter,$genderFilter,$startDate,$endDate){
  return http_build_query([
    'block'=>$blockFilter,'student'=>$studentIdLike,'category'=>$categoryFilter,'subcategory'=>$subcategoryFilter,'status'=>$statusFilter,
    'gender'=>$genderFilter,'start'=>$startDate,'end'=>$endDate,'created_sort'=>$createdSortIn,
  ]);
}
function get_attachments_full(mysqli $conn, int $complaintId): array {
  $out = [];
  $stmt = $conn->prepare("SELECT id, file_path, file_size, mime_type FROM complaint_attachments WHERE complaint_id = ? ORDER BY id ASC");
  $stmt->bind_param("i", $complaintId);
  $stmt->execute();
  $res = $stmt->get_result();
  while ($row = $res->fetch_assoc()) {
    $out[] = [
      'id'   => (int)$row['id'],
      'path' => (string)$row['file_path'],
      'size' => (int)$row['file_size'],
      'type' => (string)($row['mime_type'] ?? ''),
    ];
  }
  $stmt->close();
  return $out;
}

/* --- Shared filters (GET) --- */
$blockFilter       = trim($_GET['block']      ?? '');
$studentIdLike     = trim($_GET['student']    ?? '');
$categoryFilter    = trim($_GET['category']   ?? '');
$subcategoryFilter = trim($_GET['subcategory']?? '');
$statusFilter      = trim($_GET['status']     ?? '');
$genderFilter      = strtolower(trim($_GET['gender'] ?? ''));
$startDate         = trim($_GET['start']      ?? '');
$endDate           = trim($_GET['end']        ?? '');
$createdSortIn     = strtolower($_GET['created_sort'] ?? 'desc');
$createdSort       = $createdSortIn === 'asc' ? 'ASC' : 'DESC';

/* --- Category / Subcategory (Malay) --- */
$CATEGORIES = [
  "KEJURUTERAAN AWAM",
  "KEJURUTERAAN ELEKTRIK",
  "KEJURUTERAAN MEKANIKAL",
];
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

/* --- Block sets (policy for creation) --- */
$maleBlocks   = ['A','B','C','D','E','F'];
$femaleBlocks = ['A','B'];

/* --- Blocks (for selects/tiles elsewhere) --- */
$knownBlocks = ['A','B','C','D','E','F'];
$blocksRes = $conn->query("SELECT DISTINCT block FROM profile WHERE role='student' AND block IS NOT NULL AND block<>'' ORDER BY block");
$dbBlocks=[]; while($r=$blocksRes->fetch_assoc()){ $dbBlocks[]=$r['block']; }
$allBlocks = array_values(array_unique(array_merge($knownBlocks,$dbBlocks)));

/* --- QS for links --- */
$qsFilters = build_filters_qs($createdSortIn,$blockFilter,$studentIdLike,$categoryFilter,$subcategoryFilter,$statusFilter,$genderFilter,$startDate,$endDate);

/* --- Actions (soft delete / restore / purge for PENYELIA only) --- */
$flashMsg = '';
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['penyelia_action'])) {
  $act = $_POST['penyelia_action'];
  $pid = (int)($_POST['penyelia_id'] ?? 0);
  if ($pid > 0) {
    if ($act === 'delete') {
      $stmt = $conn->prepare("UPDATE profile SET is_deleted=1, deleted_at=NOW() WHERE id=? AND role='penyelia'");
      $stmt->bind_param("i",$pid);
      $flashMsg = $stmt->execute() ? "Penyelia has been moved to history." : "Failed to delete.";
      $stmt->close();
      $section = 'create';
    } elseif ($act === 'restore') {
      $stmt = $conn->prepare("UPDATE profile SET is_deleted=0, deleted_at=NULL WHERE id=? AND role='penyelia'");
      $stmt->bind_param("i",$pid);
      $flashMsg = $stmt->execute() ? "Penyelia restored." : "Failed to restore.";
      $stmt->close();
      $section = 'history';
    } elseif ($act === 'purge') {
      $stmt = $conn->prepare("DELETE FROM profile WHERE id=? AND role='penyelia' AND is_deleted=1");
      $stmt->bind_param("i",$pid);
      $flashMsg = $stmt->execute() ? "Penyelia permanently removed." : "Failed to purge.";
      $stmt->close();
      $section = 'history';
    }
  }
}

/* --- Build WHERE for dashboard/tickets --- */
$catLabels=$catValues=$subcatLabels=$subcatValues=$statusLabels=$statusValues=$blkLabels=$blkMale=$blkFemale=[];
$tickets=[]; $totalPages=1; $page=1; $offset=0;

if ($section === 'dashboard' || $section === 'tickets') {
  $where  = ["c.is_deleted=0", "p.role='student'"];
  $types  = ""; $params = [];
  if ($blockFilter !== '')        { $where[]="p.block=?";       $types.="s"; $params[]=$blockFilter; }
  if ($studentIdLike !== '')      { $where[]="p.student_id LIKE ?"; $types.="s"; $params[]="%{$studentIdLike}%"; }
  if ($categoryFilter !== '')     { $where[]="c.category=?";    $types.="s"; $params[]=$categoryFilter; }
  if ($subcategoryFilter !== '')  { $where[]="c.subcategory=?"; $types.="s"; $params[]=$subcategoryFilter; }
  if ($statusFilter !== '')       { $where[]="c.status=?";      $types.="s"; $params[]=$statusFilter; }
  if ($genderFilter==='male' || $genderFilter==='female'){ $where[]="p.gender=?"; $types.="s"; $params[]=$genderFilter; }
  if ($startDate !== '')          { $where[]="c.created_at>=?"; $types.="s"; $params[]=$startDate.' 00:00:00'; }
  if ($endDate   !== '')          { $where[]="c.created_at<=?"; $types.="s"; $params[]=$endDate.' 23:59:59'; }
  $whereSql = implode(" AND ", $where);
}

/* --- DASHBOARD data --- */
if ($section === 'dashboard') {
  // Category counts
  $stmt = $conn->prepare("
    SELECT c.category, COUNT(*) cnt
    FROM complaints c JOIN profile p ON p.student_id=c.student_id
    WHERE $whereSql GROUP BY c.category ORDER BY cnt DESC, c.category ASC
  "); if($types) $stmt->bind_param($types, ...$params); $stmt->execute();
  $res=$stmt->get_result(); while($r=$res->fetch_assoc()){ $catLabels[]=$r['category']; $catValues[]=(int)$r['cnt']; } $stmt->close();

  // Subcategory counts
  $stmt = $conn->prepare("
    SELECT c.subcategory, COUNT(*) cnt
    FROM complaints c JOIN profile p ON p.student_id=c.student_id
    WHERE $whereSql GROUP BY c.subcategory ORDER BY cnt DESC, c.subcategory ASC
  "); if($types) $stmt->bind_param($types, ...$params); $stmt->execute();
  $res=$stmt->get_result(); while($r=$res->fetch_assoc()){ $subcatLabels[]=$r['subcategory']; $subcatValues[]=(int)$r['cnt']; } $stmt->close();

  // Status counts
  $stmt = $conn->prepare("
    SELECT c.status, COUNT(*) cnt
    FROM complaints c JOIN profile p ON p.student_id=c.student_id
    WHERE $whereSql GROUP BY c.status
  "); if($types) $stmt->bind_param($types, ...$params); $stmt->execute();
  $res=$stmt->get_result(); while($r=$res->fetch_assoc()){ $statusLabels[]=$r['status']; $statusValues[]=(int)$r['cnt']; } $stmt->close();

  // Block x gender
  $stmt = $conn->prepare("
    SELECT p.block,
           SUM(CASE WHEN p.gender='male' THEN 1 ELSE 0 END) male_cnt,
           SUM(CASE WHEN p.gender='female' THEN 1 ELSE 0 END) female_cnt
    FROM complaints c JOIN profile p ON p.student_id=c.student_id
    WHERE $whereSql GROUP BY p.block ORDER BY p.block
  "); if($types) $stmt->bind_param($types, ...$params); $stmt->execute();
  $res=$stmt->get_result();
  while($r=$res->fetch_assoc()){ $blkLabels[]=$r['block']??'N/A'; $blkMale[]=(int)$r['male_cnt']; $blkFemale[]=(int)$r['female_cnt']; }
  $stmt->close();
}

/* --- TICKETS data --- */
if ($section === 'tickets') {
  $perPage = 20;
  $page    = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
  $offset  = ($page - 1) * $perPage;

  $stmt = $conn->prepare("
    SELECT COUNT(*) c
    FROM complaints c JOIN profile p ON p.student_id=c.student_id
    WHERE $whereSql
  "); if($types) $stmt->bind_param($types, ...$params); $stmt->execute();
  $total = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0); $stmt->close();
  $totalPages = max(1, (int)ceil($total/$perPage));

  $rTypes = $types.'ii';
  $rParams= $params; $rParams[]=$perPage; $rParams[]=$offset;
  $stmt = $conn->prepare("
    SELECT c.*, p.name AS student_name, p.student_id, p.block, p.room_number, p.gender AS student_gender
    FROM complaints c JOIN profile p ON p.student_id=c.student_id
    WHERE $whereSql
    ORDER BY c.created_at $createdSort, c.id ".($createdSort==='ASC'?'ASC':'DESC')."
    LIMIT ? OFFSET ?
  ");
  $stmt->bind_param($rTypes, ...$rParams);
  $stmt->execute();
  $tickets = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
  $stmt->close();
}

/* --- CREATE / HISTORY lists --- */
$createMsg = '';
$activePenyelia = [];
$deletedPenyelia = [];

if ($section === 'create') {
  if (isset($_POST['create_penyelia'])) {
    $name   = trim($_POST['name']??'');
    $email  = trim($_POST['email']??'');
    $pass   = trim($_POST['password']??'');
    $gender = strtolower(trim($_POST['gender']??''));
    $block  = strtoupper(trim($_POST['block']??''));

    $okGender = in_array($gender,['male','female'], true);
    $allowedBlocks = $gender==='female' ? $femaleBlocks : ($gender==='male' ? $maleBlocks : []);
    $okBlock  = in_array($block, $allowedBlocks, true);

    if ($name && $email && $pass && $okGender && $okBlock) {
      $hash = password_hash($pass, PASSWORD_BCRYPT);
      $role = 'penyelia';
      $ins  = $conn->prepare("INSERT INTO profile (name,email,password,role,block,gender,is_deleted) VALUES (?,?,?,?,?,?,0)");
      $ins->bind_param("ssssss", $name,$email,$hash,$role,$block,$gender);
      $createMsg = $ins->execute()
        ? "✅ Penyelia created for Block {$block} (".ucfirst($gender).")."
        : "❌ Failed to create. Email may exist.";
      $ins->close();
    } else {
      if (!$okGender)     $createMsg = "❌ Please select a valid gender.";
      elseif (!$okBlock)  $createMsg = "❌ Invalid block for selected gender.";
      else                $createMsg = "❌ Please fill all fields correctly.";
    }
  }

  $q = $conn->query("SELECT id,name,email,block,gender,created_at FROM profile WHERE role='penyelia' AND is_deleted=0 ORDER BY gender, block, name");
  $activePenyelia = $q->fetch_all(MYSQLI_ASSOC);
}

if ($section === 'history') {
  $q = $conn->query("SELECT id,name,email,role,deleted_at FROM profile WHERE role='penyelia' AND is_deleted=1 ORDER BY deleted_at DESC");
  $deletedPenyelia = $q->fetch_all(MYSQLI_ASSOC);
}

/* --- RESET REQUESTS (optional section) --- */
$resetRows = [];
if ($section === 'resets') {
  $q = $conn->query("
    SELECT r.*, u.name, u.email, u.block, u.gender
    FROM password_reset_requests r
    JOIN profile u ON u.id = r.penyelia_id
    ORDER BY (r.status='pending') DESC, r.created_at DESC
  ");
  if ($q) { $resetRows = $q->fetch_all(MYSQLI_ASSOC); }
}

/* --- QS shortcuts --- */
$baseTickets = 'ketua_penyelia_page.php?section=tickets' . ($qsFilters ? '&'.$qsFilters.'&' : '&');
$qsForExport = $qsFilters;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Ketua Penyelia</title>

<link rel="icon" type="image/png" href="assets/favicon.png" sizes="32x32">
<link rel="icon" type="image/png" href="assets/favicon.png" sizes="16x16">
<link rel="apple-touch-icon" href="assets/favicon.png">

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
  html,body{height:100%} body{margin:0;background:#e5eefc;min-height:100vh;position:relative}
  body::before{content:"";position:fixed;inset:0;background:url('assets/dormitory.jpg') center/cover no-repeat;filter:blur(8px) brightness(.92) saturate(90%);transform:scale(1.06);z-index:-2}
  body::after{content:"";position:fixed;inset:0;background:rgba(0,0,0,.40);z-index:-1}

  .header-wrap{position:sticky;top:0;z-index:10;padding:18px}
  .topbar{
    display:grid;grid-template-columns:auto 1fr auto;align-items:center;
    background:rgba(255,255,255,.96);border:1px solid rgba(2,6,23,.06);
    border-radius:16px;padding:12px 16px;box-shadow:0 8px 26px rgba(0,0,0,.08);max-width:1200px;margin:0 auto;
  }
  .gear-btn{border:0;background:#f1f5f9;width:42px;height:42px;border-radius:12px;cursor:pointer;display:flex;align-items:center;justify-content:center}
  .gear-btn:hover{background:#e2e8f0}
  .title-wrap{justify-self:center;text-align:center}
  .page-title{font-size:20px;font-weight:900;color:#0f172a}
  .subtle{font-size:13px;color:#475569;margin-top:2px}
  .focus-pill{display:inline-block;margin-left:8px;padding:4px 10px;border-radius:999px;background:#eef2ff;color:#1e40af;border:1px solid #e0e7ff;font-weight:700}

  .container{max-width:1200px;margin:0 auto;padding:0 18px 28px}
  .stack>*+*{margin-top:18px}
  .grid-2{display:grid;grid-template-columns:1fr 1fr;gap:18px}
  @media (max-width:1100px){.grid-2{grid-template-columns:1fr}}
  .card{background:rgba(255,255,255,.97);border:1px solid rgba(2,6,23,.06);border-radius:14px;padding:16px;box-shadow:0 8px 26px rgba(0,0,0,.08)}
  .card h3{margin:0 0 8px}

  .filter-bar{display:flex;gap:12px;flex-wrap:wrap;align-items:end}
  .filter-bar .field{display:flex;flex-direction:column;gap:6px}
  .filter-bar input,.filter-bar select{padding:9px 10px;border:1px solid #cbd5e1;border-radius:10px;min-width:160px;background:#fff}

  .block-grid{display:grid;gap:14px;grid-template-columns:repeat(auto-fill,minmax(140px,1fr))}
  .block-tile{background:linear-gradient(180deg,#fff 0%,#f9fbff 100%);border:1px solid #e5e7eb;border-radius:12px;padding:14px;text-align:center;cursor:pointer;transition:.15s ease;box-shadow:0 8px 22px rgba(0,0,0,.06)}
  .block-tile:hover{transform:translateY(-2px);box-shadow:0 14px 26px rgba(0,0,0,.10)}
  .block-title{font-weight:800;font-size:16px;color:#111827}
  .block-sub{font-size:12px;color:#475569;margin-top:4px}

  .table-shell{background:#fff;border-radius:14px;box-shadow:0 10px 28px rgba(0,0,0,.08);overflow:hidden;border:1px solid #e5e7eb}
  table{width:100%;border-collapse:separate;border-spacing:0}
  thead th{position:sticky;top:0;background:#f8fafc;font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:#0f172a;border-bottom:1px solid #e5e7eb;padding:11px 10px;text-align:center}
  tbody td{padding:12px 10px;border-bottom:1px solid #f1f5f9;text-align:center;font-size:14px;color:#0f172a}
  tbody tr:nth-child(even){background:#fcfcfd}
  tbody tr:hover{background:#f5f7fb}
  .badge{display:inline-block;padding:6px 10px;border-radius:999px;font-size:12px;font-weight:700;background:#e2e8f0;color:#0f172a}
  .status-pending{background:#fff7ed;color:#92400e;border:1px solid #fed7aa}
  .status-in-progress{background:#eff6ff;color:#1e40af;border:1px solid #bfdbfe}
  .status-completed{background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0}
  .status-rejected{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}
  .btn{background:#7494ec;color:#fff;border:0;padding:9px 12px;border-radius:10px;font-weight:700;cursor:pointer}
  .btn:hover{background:#5e7ad9}

  .chart-wrap{width:100%;height:300px}

  .export-actions{display:flex;gap:10px;flex-wrap:wrap}
  .export-actions a{text-decoration:none;padding:9px 12px;border-radius:10px;font-weight:700}
  .btn-csv{background:#065f46;color:#fff}.btn-csv:hover{background:#047857}
  .btn-pdf{background:#7c3aed;color:#fff}.btn-pdf:hover{background:#6d28d9}

  .pagination{display:flex;justify-content:center;gap:8px;flex-wrap:wrap;margin-top:14px}
  .page-btn{background:#e2e8f0;color:#0f172a;padding:8px 12px;border-radius:10px;text-decoration:none;font-weight:700}
  .page-btn:hover{background:#cbd5e1}
  .page-btn.active{background:#5e7ad9;color:#fff}
  .page-btn.disabled{opacity:.55;pointer-events:none}
  .page-ellipsis{align-self:center;color:#475569}

  /* Slide-in */
  .slide-overlay{position:fixed;inset:0;background:rgba(0,0,0,.25);opacity:0;pointer-events:none;transition:opacity .2s ease;z-index:30}
  .slide-panel{position:fixed;top:0;left:0;height:100vh;width:min(340px,92vw);background:#fff;box-shadow:4px 0 18px rgba(0,0,0,.25);transform:translateX(-100%);transition:transform .25s ease;z-index:31;display:flex;flex-direction:column;border-right:1px solid #e5e7eb}
  .slide-header{display:flex;align-items:center;justify-content:space-between;padding:14px 16px;border-bottom:1px solid #e5e7eb}
  .slide-body{padding:12px 14px;display:flex;flex-direction:column;gap:10px}
  .slide-link{display:flex;align-items:center;gap:10px;padding:12px 14px;border:1px solid #eef2f7;border-radius:12px;text-decoration:none;color:#0f172a;font-weight:800}
  .slide-link:hover{background:#f8fafc}
  .slide-divider{height:1px;background:#f1f5f9;margin:8px 0}
  .logout-btn-wide{background:#ef4444;color:#fff;border:0;padding:10px 14px;border-radius:12px;cursor:pointer;font-weight:800}
  .logout-btn-wide:hover{background:#dc2626}
  .slide-open .slide-panel{transform:translateX(0)}
  .slide-open .slide-overlay{opacity:1;pointer-events:auto}

  /* Details modal */
  .btn-detail{background:#7da2ff;color:#fff;border:0;padding:8px 12px;border-radius:10px;font-weight:800;cursor:pointer}
  .btn-detail:hover{background:#5e7ad9}
  .modal{position:fixed;inset:0;background:rgba(0,0,0,.35);display:none;align-items:center;justify-content:center;z-index:40}
.modal .sheet{
  width:min(640px,92vw);
  max-height:90vh;           /* ✅ prevent overflow */
  overflow-y:auto;           /* ✅ enable vertical scroll */
  background:#fff;
  border-radius:14px;
  padding:18px 20px;
  box-shadow:0 20px 40px rgba(0,0,0,.3);
  position:relative;
  scrollbar-width:thin;      /* optional: thin scrollbar */
}

.modal {
  position:fixed;
  inset:0;
  background:rgba(0,0,0,.35);
  display:none;
  align-items:center;
  justify-content:center;
  z-index:40;
  overflow:auto; /* helps if sheet is super tall */
  padding:20px;
}




  .modal .closex{position:absolute;top:10px;right:14px;background:#0000;border:0;font-size:26px;cursor:pointer}
  .modal h2{margin:0 0 8px}
  .modal .grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}
  @media (max-width:700px){ .modal .grid{grid-template-columns:1fr} }

  .remark-box { margin-top: 12px; padding: 12px; border-left: 4px solid #d1d5db; background: #f9fafb; border-radius: 6px; }
  .attachment-list{ margin:6px 0 0 18px; padding:0; }
  .attachment-list li{ margin:3px 0; }

  .card {
  background:#fff;
  border:1px solid #e5e7eb;
  border-radius:12px;
  box-shadow:0 6px 20px rgba(0,0,0,.06);
  padding:14px 16px;
}

/* Details modal */
.btn-detail {
  background:#7da2ff;
  color:#fff;
  border:0;
  padding:8px 12px;
  border-radius:10px;
  font-weight:800;
  cursor:pointer;
}
.btn-detail:hover { background:#5e7ad9; }

.modal {
  position:fixed;
  inset:0;
  background:rgba(0,0,0,.35);
  display:none;
  align-items:center;
  justify-content:center;
  z-index:40;
  overflow:auto;          /* ✅ ensures scroll if modal taller than screen */
  padding:20px;
}

.modal .sheet {
  width:min(640px,92vw);
  max-height:90vh;        /* ✅ limits modal height on all devices */
  overflow-y:auto;        /* ✅ scrolls inside modal if content too long */
  background:#fff;
  border-radius:14px;
  padding:18px 20px;
  box-shadow:0 20px 40px rgba(0,0,0,.3);
  position:relative;
  scrollbar-width:thin;   /* ✅ cleaner scrollbar look */
}

.modal .sheet::-webkit-scrollbar {
  width:8px;
}
.modal .sheet::-webkit-scrollbar-thumb {
  background:#cbd5e1;
  border-radius:6px;
}
.modal .sheet::-webkit-scrollbar-thumb:hover {
  background:#94a3b8;
}

.modal .closex {
  position:absolute;
  top:10px;
  right:14px;
  background:#0000;
  border:0;
  font-size:26px;
  cursor:pointer;
}

.modal h2 {
  margin:0 0 8px;
  font-size:18px;
  font-weight:800;
}

.modal .grid {
  display:grid;
  grid-template-columns:1fr 1fr;
  gap:10px;
}

/* ✅ Mobile-friendly behavior */
@media (max-width:700px) {
  .modal .sheet {
    width:92vw;
    max-height:92vh;
    padding:14px 16px;
  }
  .modal .grid { grid-template-columns:1fr; }
  .modal h2 { font-size:16px; }
}

.modal .card {
  background:#f9fafb;
  border-radius:12px;
  box-shadow:0 2px 8px rgba(0,0,0,.08);
}

/* ✅ Inner boxes for better clarity */
.inner-card {
  background:#fff;
  border:1px solid #e5e7eb;
  border-radius:10px;
  padding:12px 14px;
  margin-top:14px;
  box-shadow:0 4px 10px rgba(0,0,0,.04);
}

.inner-card h4 {
  font-weight:700;
  font-size:15px;
  margin:0 0 8px;
}

/* ✅ Status badges */
#detailsModal .badge {
  border-radius:999px;
  padding:6px 10px;
  font-weight:700;
}
.status-pending { background:#fff7ed; color:#92400e; border:1px solid #fed7aa; }
.status-in-progress { background:#eff6ff; color:#1e40af; border:1px solid #bfdbfe; }
.status-completed { background:#ecfdf5; color:#065f46; border:1px solid #a7f3d0; }
.status-rejected { background:#fef2f2; color:#991b1b; border:1px solid #fecaca; }



</style>
</head>
<body>

<!-- Header -->
<div class="header-wrap">
  <div class="topbar">
    <button id="panelToggleBtn" class="gear-btn" aria-label="Open menu" title="Menu">
      <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#0f172a" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <circle cx="12" cy="12" r="3"></circle>
        <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06A1.65 1.65 0  0 0 15 19.4a1.65 1.65 0  0 0-1 .6 1.65 1.65 0  0 0-.33 1.82 2 2 0 1 1-3.34 0 1.65 1.65 0  0 0-.33-1.82 1.65 1.65 0  0 0-1-.6 1.65 1.65 0  0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0  0 0 4.6 15a1.65 1.65 0  0 0-.6-1 1.65 1.65 0  0 0-1.82-.33 2  2 0  1 1 0-3.34 1.65 1.65 0  0 0 1.82-.33 1.65 1.65 0  0 0 .6-1 1.65 1.65 0  0 0-.33-1.82l-.06-.06A2 2 0 1 1 6.94 3.6l.06.06A1.65 1.65 0  0 0 8 4.6a1.65 1.65 0  0 0 1-.6 1.65 1.65 0  0 0 .33-1.82 2 2 0 1 1 3.34 0 1.65 1.65 0  0 0 .33 1.82 1.65 1.65 0  0 0 1 .6 1.65 1.65 0  0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0  0 0-.6 1 1.65 1.65 0  0 0 .6 1z"></path>
      </svg>
    </button>
    <div class="title-wrap">
      <div class="page-title">Welcome, Ketua Penyelia</div>
      <div class="subtle">
        Hello <?= e($ketuaName) ?>
        <?php if ($blockFilter): ?><span class="focus-pill">Focus: Block <?= e($blockFilter) ?></span><?php endif; ?>
      </div>
    </div>
    <div></div>
  </div>
</div>

<!-- Slide-in -->
<div class="slide-overlay" id="slideOverlay"></div>
<aside class="slide-panel" id="slidePanel" aria-hidden="true">
  <div class="slide-header">
    <strong>Quick Menu</strong>
    <button id="panelClose" style="border:0;background:#0000;font-size:22px;cursor:pointer" aria-label="Close">&times;</button>
  </div>
  <div class="slide-body">
    <a class="slide-link" href="ketua_penyelia_page.php?section=dashboard<?= $qsFilters?('&'.$qsFilters):'' ?>">📊 Dashboard</a>
    <a class="slide-link" href="ketua_penyelia_page.php?section=tickets<?= $qsFilters?('&'.$qsFilters):'' ?>">🎫 Tickets</a>
    <a class="slide-link" href="ketua_penyelia_page.php?section=create<?= $qsFilters?('&'.$qsFilters):'' ?>">👤 Create Penyelia</a>
    <a class="slide-link" href="ketua_penyelia_page.php?section=history">🗄️ History</a>
    <div class="slide-divider"></div>
    <form action="logout.php" method="post"><button type="submit" class="logout-btn-wide">⏻ Logout</button></form>
  </div>
</aside>

<div class="container">
  <?php if ($flashMsg): ?>
    <div class="card" style="border-left:4px solid #2563eb;"><strong><?= e($flashMsg) ?></strong></div>
  <?php endif; ?>

<?php if ($section === 'dashboard'): ?>

  <div class="card">
    <div class="stack">
      <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
        <h3 style="margin:0;">Blocks</h3>
        <div class="subtle">Click a block to focus its tickets & charts</div>
      </div>
      <div class="block-grid">
        <a class="block-tile" href="ketua_penyelia_page.php?section=dashboard">
          <div class="block-title">All Blocks</div><div class="block-sub">Show everything</div>
        </a>
        <?php foreach($allBlocks as $b): ?>
          <a class="block-tile" href="ketua_penyelia_page.php?section=dashboard&block=<?= urlencode($b) ?>">
            <div class="block-title"><?= e($b) ?></div><div class="block-sub">Focus block <?= e($b) ?></div>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <div class="card">
    <h3>Filters</h3>
    <form method="get" class="filter-bar">
      <input type="hidden" name="section" value="dashboard">
      <div class="field"><label>Block</label>
        <select name="block"><option value="">All</option>
          <?php foreach($allBlocks as $b): ?><option value="<?= e($b) ?>" <?= $blockFilter===$b?'selected':'' ?>><?= e($b) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="field"><label>Student ID</label><input type="text" name="student" value="<?= e($studentIdLike) ?>" placeholder="e.g. 05DDT23F0001"></div>
      <div class="field"><label>Category</label>
        <select name="category">
          <option value="">All</option>
          <?php foreach ($CATEGORIES as $c): ?>
            <option value="<?= e($c) ?>" <?= $categoryFilter===$c?'selected':'' ?>><?= e($c) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field"><label>Sub-Category</label>
        <select name="subcategory" id="subcatSelect" <?= $categoryFilter ? '' : 'disabled' ?>>
          <option value="">All</option>
          <?php if ($categoryFilter && isset($SUBCATS[$categoryFilter])): ?>
            <?php foreach ($SUBCATS[$categoryFilter] as $s): ?>
              <option value="<?= e($s) ?>" <?= $subcategoryFilter===$s?'selected':'' ?>><?= e($s) ?></option>
            <?php endforeach; ?>
          <?php endif; ?>
        </select>
      </div>
      <div class="field"><label>Status</label>
        <select name="status"><?php $sts=[''=>'All','Pending'=>'Pending','In Progress'=>'In Progress','Completed'=>'Completed','Rejected'=>'Rejected'];
          foreach($sts as $val=>$label){ $sel=$statusFilter===$val?'selected':''; echo "<option value=\"".e($val)."\" $sel>".e($label)."</option>"; } ?>
        </select>
      </div>
      <div class="field"><label>Gender</label>
        <select name="gender">
          <option value="">All</option>
          <option value="male"   <?= $genderFilter==='male'?'selected':'' ?>>Male</option>
          <option value="female" <?= $genderFilter==='female'?'selected':'' ?>>Female</option>
        </select>
      </div>
      <div class="field"><label>From</label><input type="date" name="start" value="<?= e($startDate) ?>"></div>
      <div class="field"><label>To</label><input type="date" name="end" value="<?= e($endDate) ?>"></div>
      <div class="field"><label>Created</label>
        <select name="created_sort">
          <option value="desc" <?= $createdSort==='DESC'?'selected':'' ?>>Newest first</option>
          <option value="asc"  <?= $createdSort==='ASC' ?'selected':'' ?>>Oldest first</option>
        </select>
      </div>
      <div class="field" style="gap:8px;">
        <label>&nbsp;</label>
        <div style="display:flex;gap:8px;">
          <button class="btn" type="submit">Apply</button>
          <a class="btn" href="ketua_penyelia_page.php?section=dashboard">Reset</a>
        </div>
      </div>
    </form>
  </div>

  <div class="grid-2">
    <div class="card">
      <h3>Categories <?= $blockFilter?('— Block '.e($blockFilter)):'(All Blocks)' ?></h3>
      <?php if ($catLabels): ?><div class="chart-wrap"><canvas id="catChart"></canvas></div><?php else: ?><p>No tickets to chart.</p><?php endif; ?>
    </div>
    <div class="card">
      <h3>Sub-Categories <?= $categoryFilter?('— '.e($categoryFilter)):'(All Categories)' ?></h3>
      <?php if ($subcatLabels): ?><div class="chart-wrap"><canvas id="subcatChart"></canvas></div><?php else: ?><p>No sub-category data.</p><?php endif; ?>
    </div>
  </div>

  <div class="card">
    <h3>Status <?= $blockFilter?('— Block '.e($blockFilter)):'(All Blocks)' ?></h3>
    <?php if ($statusLabels): ?><div class="chart-wrap"><canvas id="statusChart"></canvas></div><?php else: ?><p>No tickets to chart.</p><?php endif; ?>
  </div>

  <div class="card">
    <h3>Blocks — Male vs Female <?= $blockFilter?(' (filtered to '.e($blockFilter).')'):'' ?></h3>
    <?php if ($blkLabels): ?><div class="chart-wrap"><canvas id="blockChart"></canvas></div><?php else: ?><p>No data.</p><?php endif; ?>
  </div>

<?php elseif ($section === 'tickets'): ?>

  <div class="card">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
      <h3 style="margin:0;">Student Tickets <?= $blockFilter?('— Block '.e($blockFilter)):'(All Blocks)' ?></h3>
      <div class="export-actions">
        <a class="btn-csv" href="export_ketua_csv.php?<?= $qsForExport ?>" target="_blank" rel="noopener">Export CSV</a>
        <a class="btn-pdf" href="export_ketua_pdf.php?<?= $qsForExport ?>" target="_blank" rel="noopener">Export PDF</a>
      </div>
    </div>

    <!-- Filters -->
    <form method="get" class="filter-bar" style="margin-top:12px;">
      <input type="hidden" name="section" value="tickets">
      <div class="field"><label>Block</label>
        <select name="block"><option value="">All</option>
          <?php foreach($allBlocks as $b): ?><option value="<?= e($b) ?>" <?= $blockFilter===$b?'selected':'' ?>><?= e($b) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="field"><label>Student ID</label><input type="text" name="student" value="<?= e($studentIdLike) ?>"></div>
      <div class="field"><label>Category</label>
        <select name="category">
          <option value="">All</option>
          <?php foreach ($CATEGORIES as $c): ?>
            <option value="<?= e($c) ?>" <?= $categoryFilter===$c?'selected':'' ?>><?= e($c) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field"><label>Sub-Category</label>
        <select name="subcategory" id="tSubcatSelect" <?= $categoryFilter ? '' : 'disabled' ?>>
          <option value="">All</option>
          <?php if ($categoryFilter && isset($SUBCATS[$categoryFilter])): ?>
            <?php foreach ($SUBCATS[$categoryFilter] as $s): ?>
              <option value="<?= e($s) ?>" <?= $subcategoryFilter===$s?'selected':'' ?>><?= e($s) ?></option>
            <?php endforeach; ?>
          <?php endif; ?>
        </select>
      </div>
      <div class="field"><label>Status</label>
        <select name="status"><?php $sts=[''=>'All','Pending'=>'Pending','In Progress'=>'In Progress','Completed'=>'Completed','Rejected'=>'Rejected'];
          foreach($sts as $val=>$label){ $sel=$statusFilter===$val?'selected':''; echo "<option value=\"".e($val)."\" $sel>".e($label)."</option>"; } ?>
        </select>
      </div>
      <div class="field"><label>Gender</label>
        <select name="gender">
          <option value="">All</option>
          <option value="male"   <?= $genderFilter==='male'?'selected':'' ?>>Male</option>
          <option value="female" <?= $genderFilter==='female'?'selected':'' ?>>Female</option>
        </select>
      </div>
      <div class="field"><label>From</label><input type="date" name="start" value="<?= e($startDate) ?>"></div>
      <div class="field"><label>To</label><input type="date" name="end" value="<?= e($endDate) ?>"></div>
      <div class="field"><label>Created</label>
        <select name="created_sort">
          <option value="desc" <?= $createdSort==='DESC'?'selected':'' ?>>Newest first</option>
          <option value="asc"  <?= $createdSort==='ASC' ?'selected':'' ?>>Oldest first</option>
        </select>
      </div>
      <div class="field" style="gap:8px;">
        <label>&nbsp;</label>
        <div style="display:flex;gap:8px;">
          <button class="btn" type="submit">Apply</button>
          <a class="btn" href="ketua_penyelia_page.php?section=tickets">Reset</a>
        </div>
      </div>
    </form>

    <!-- Table -->
    <?php if ($tickets): ?>
      <div class="table-shell" style="margin-top:12px;overflow:auto;">
        <table class="stackable table--tickets">
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
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
          <?php $i=$offset+1; foreach($tickets as $t): ?>
            <?php
              $atts = get_attachments_full($conn, (int)$t['id']);
              $attsJson = e(json_encode($atts));
            ?>
            <tr>
              <td><?= $i++ ?></td>
              <td><?= e($t['student_name']) ?></td>
              <td><?= e($t['student_id']) ?></td>
              <td><?= e(ucfirst($t['student_gender'])) ?></td>
              <td><?= e($t['block']) ?></td>
              <td><?= e($t['room_number']) ?></td>
              <td><?= e($t['title']) ?></td>
              <td><?= e($t['category']) ?></td>
              <td><?= e($t['subcategory'] ?? '—') ?></td>
              <td><span class="badge status-<?= strtolower(str_replace(' ','-',$t['status'])) ?>"><?= e($t['status']) ?></span></td>
              <td><?= e($t['created_at'] ?? '') ?></td>
              <td>
               <button class="btn-detail"
                  onclick="openDetails(this)"
                  data-id="<?= (int)$t['id'] ?>"
                  data-title="<?= e($t['title']) ?>"
                  data-category="<?= e($t['category']) ?>"
                  data-subcategory="<?= e($t['subcategory'] ?? '') ?>"
                  data-status="<?= e($t['status']) ?>"
                  data-created="<?= e($t['created_at']) ?>"
                  data-student="<?= e($t['student_name']) ?>"
                  data-studentid="<?= e($t['student_id']) ?>"
                  data-gender="<?= e($t['student_gender']) ?>"
                  data-block="<?= e($t['block']) ?>"
                  data-room="<?= e($t['room_number']) ?>"
                >Details</button>

              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <div class="pagination">
        <?php $window=3; $start=max(1,$page-$window); $end=min($totalPages,$page+$window); ?>
        <a href="<?= $page<=1 ? 'javascript:void(0)' : ($baseTickets.'page='.($page-1)) ?>" class="page-btn <?= $page<=1?'disabled':'' ?>">Prev</a>
        <?php if ($start>1): ?>
          <a class="page-btn" href="<?= $baseTickets.'page=1' ?>">1</a>
          <?php if ($start>2): ?><span class="page-ellipsis">…</span><?php endif; ?>
        <?php endif; ?>
        <?php for ($p=$start; $p<=$end; $p++): ?>
          <a class="page-btn <?= $p==$page?'active':'' ?>" href="<?= $baseTickets.'page='.$p ?>"><?= $p ?></a>
        <?php endfor; ?>
        <?php if ($end<$totalPages): ?>
          <?php if ($end<$totalPages-1): ?><span class="page-ellipsis">…</span><?php endif; ?>
          <a class="page-btn" href="<?= $baseTickets.'page='.$totalPages ?>"><?= $totalPages ?></a>
        <?php endif; ?>
        <a href="<?= $page>=$totalPages ? 'javascript:void(0)' : ($baseTickets.'page='.($page+1)) ?>" class="page-btn <?= $page>=$totalPages?'disabled':'' ?>">Next</a>
      </div>
    <?php else: ?>
      <p style="margin-top:10px;">No tickets match your filters.</p>
    <?php endif; ?>
  </div>

<!-- Details Modal -->
<div id="detailsModal" class="modal" role="dialog" aria-modal="true">
  <div class="sheet">


<div style="padding:18px 20px;">

  <!-- Header -->
  <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;margin-bottom:4px;">
    <h2 id="dTitle" style="margin:0;font-weight:800;">Ticket Title</h2>
    <span id="dSta" class="badge status-pending">Status</span>
  </div>

  <div id="dCreated" style="color:#64748b;font-size:14px;margin-bottom:10px;">
    Submitted: —
  </div>

  <!-- Info Grid -->
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:12px;">
    <p><strong>Category:</strong> <span id="dCat">—</span></p>
    <p><strong>Sub-Category:</strong> <span id="dSub">—</span></p>
   
    <p><strong>Block / Room:</strong> <span id="dBR">—</span></p>
    <p><strong>Gender:</strong> <span id="dGen">—</span></p>
    <p><strong>Student:</strong> <span id="dStud">—</span> (<span id="dStudId">—</span>)</p>
    <p><strong>Phone:</strong> <a id="dPhone" href="#" style="color:#2563eb;text-decoration:none;">—</a></p>
    
  </div>

  <!-- Description -->
  <p><strong>Description:</strong> <span id="dDesc">—</span></p>

  <!-- Student Attachments Box -->
  <div class="inner-card">
    <h4>Student Attachment</h4>
    <ul id="dFiles" class="attachment-list" style="margin-left:18px;"></ul>
  </div>

  <!-- Technician Details Box -->
  <div class="inner-card">
    <h4>Technician Details</h4>
    <div style="display:grid;grid-template-columns:160px 1fr;gap:6px;font-size:14px;">
      <div><strong>Technician:</strong></div><div id="dTech">—</div>
      <div><strong>Technician Remark:</strong></div><div id="dRemark">—</div>
      <div><strong>Completed At:</strong></div><div id="dCompletedAt">—</div>
    </div>

    <div style="margin-top:12px;">
      <strong>Technician Proof:</strong>
      <div id="dProofContainer" class="files-grid" style="margin-top:6px;"></div>
    </div>
  </div>

  <!-- Admin Remarks Box -->
  <div class="inner-card">
    <h4>Remarks by Status</h4>
    <div style="display:grid;grid-template-columns:160px 1fr;gap:6px;font-size:14px;">
      <div><strong>Pending</strong></div><div id="rPending">—</div>
      <div><strong>In Progress</strong></div><div id="rInProgress">—</div>
      <div><strong>Completed</strong></div><div id="rCompleted">—</div>
      <div><strong>Rejected</strong></div><div id="rRejected">—</div>
    </div>
  </div>

  <!-- Close Button -->
  <div style="text-align:right;margin-top:14px;">
    <button onclick="closeDetails()" class="btn" style="background:#e2e8f0;color:#111827;font-weight:700;">Close</button>
  </div>
</div>




<?php elseif ($section === 'create'): ?>

  <div class="card">
    <h3>Create Block Penyelia</h3>
    <?php if ($createMsg): ?><p style="margin:8px 0;font-weight:700;"><?= e($createMsg) ?></p><?php endif; ?>

    <form method="post" class="filter-bar" style="align-items:flex-end;" id="createForm">
      <input type="hidden" name="create_penyelia" value="1">
      <div class="field"><label>Name</label><input type="text" name="name" required value="<?= e($_POST['name'] ?? '') ?>"></div>
      <div class="field"><label>Email</label><input type="email" name="email" required value="<?= e($_POST['email'] ?? '') ?>"></div>
      <div class="field"><label>Password</label><input type="password" name="password" required></div>

      <div class="field"><label>Gender</label>
        <select name="gender" id="genderSelect" required>
          <option value="">-- Select Gender --</option>
          <option value="male"   <?= (($_POST['gender'] ?? '')==='male')?'selected':''  ?>>Male (boys block)</option>
          <option value="female" <?= (($_POST['gender'] ?? '')==='female')?'selected':''?>>Female (girls block)</option>
        </select>
      </div>

      <div class="field"><label>Block</label>
        <select name="block" id="blockSelect" required disabled>
          <option value="">-- Select Block --</option>
        </select>
      </div>

      <div class="field"><label>&nbsp;</label><button class="btn" type="submit">Create Penyelia</button></div>
    </form>

    <p class="subtle" style="margin-top:6px;">
      Policy: Female blocks available: <strong>A, B</strong>. Male blocks available: <strong>A, B, C, D, E, F</strong>.
    </p>
  </div>

  <div class="card">
    <h3>Active Penyelia (Delete moves to History)</h3>
    <?php if ($activePenyelia): ?>
      <div class="table-shell" style="margin-top:10px;overflow:auto;">
        <table class="stackable">
          <thead><tr><th>No.</th><th>Name</th><th>Email</th><th>Gender</th><th>Block</th><th>Created</th><th>Action</th></tr></thead>
          <tbody>
            <?php $i=1; foreach($activePenyelia as $p): ?>
              <tr>
                <td><?= $i++ ?></td>
                <td><?= e($p['name']) ?></td>
                <td><?= e($p['email']) ?></td>
                <td><?= e(ucfirst($p['gender'])) ?></td>
                <td><?= e($p['block']) ?></td>
                <td><?= e($p['created_at'] ?? '') ?></td>
                <td>
                  <form method="post" onsubmit="return confirm('Move this penyelia to history?');" style="display:inline">
                    <input type="hidden" name="penyelia_action" value="delete">
                    <input type="hidden" name="penyelia_id" value="<?= (int)$p['id'] ?>">
                    <button class="btn" style="background:#ef4444">Delete</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php else: ?><p style="margin-top:8px;">No penyelia found.</p><?php endif; ?>
  </div>

<?php elseif ($section === 'history'): ?>

  <div class="card">
    <h3>Deleted Penyelia (History)</h3>
    <?php if ($deletedPenyelia): ?>
      <div class="table-shell" style="margin-top:10px;overflow:auto;">
        <table class="stackable">
          <thead><tr><th>No.</th><th>Name</th><th>Email</th><th>Role</th><th>Deleted At</th><th>Action</th></tr></thead>
          <tbody>
            <?php $i=1; foreach($deletedPenyelia as $p): ?>
              <tr>
                <td><?= $i++ ?></td>
                <td><?= e($p['name']) ?></td>
                <td><?= e($p['email']) ?></td>
                <td><?= e($p['role']) ?></td>
                <td><?= e($p['deleted_at'] ?? '') ?></td>
                <td style="display:flex;gap:8px;justify-content:center;">
                  <form method="post" onsubmit="return confirm('Restore this penyelia?');">
                    <input type="hidden" name="penyelia_action" value="restore">
                    <input type="hidden" name="penyelia_id" value="<?= (int)$p['id'] ?>">
                    <button class="btn" style="background:#16a34a">Restore</button>
                  </form>
                  <form method="post" onsubmit="return confirm('Permanently purge this penyelia? This cannot be undone.');">
                    <input type="hidden" name="penyelia_action" value="purge">
                    <input type="hidden" name="penyelia_id" value="<?= (int)$p['id'] ?>">
                    <button class="btn" style="background:#b91c1c">Purge</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php else: ?><p style="margin-top:8px;">No deleted penyelia.</p><?php endif; ?>
  </div>

<?php elseif ($section === 'resets'): ?>

  <div class="card">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
      <h3 style="margin:0;">🔐 Password Reset Requests</h3>
      <div class="subtle">Generate a temporary password or decline the request.</div>
    </div>

    <?php if ($resetRows): ?>
      <div class="table-shell" style="margin-top:12px;overflow:auto;">
        <table class="stackable">
          <thead>
            <tr>
              <th>No.</th><th>Penyelia</th><th>Email</th><th>Gender</th><th>Block</th>
              <th>Status</th><th>Requested</th><th>Handled</th><th>Temp Password</th><th>Action</th>
            </tr>
          </thead>
          <tbody>
          <?php $i=1; foreach ($resetRows as $r): ?>
            <tr>
              <td><?= $i++ ?></td>
              <td><?= e($r['name']) ?></td>
              <td><?= e($r['email']) ?></td>
              <td><?= e(ucfirst($r['gender'])) ?></td>
              <td><?= e($r['block']) ?></td>
              <td><span class="badge <?= $r['status']==='pending'?'status-in-progress':($r['status']==='done'?'status-completed':'status-rejected') ?>"><?= e(ucfirst($r['status'])) ?></span></td>
              <td><?= e($r['created_at']) ?></td>
              <td><?= e($r['handled_at'] ?? '—') ?></td>
              <td style="font-family:ui-monospace,Menlo,Consolas,monospace;"><?= $r['status']==='done' ? e($r['temp_password']) : '—' ?></td>
              <td>
                <?php if ($r['status']==='pending'): ?>
                  <form method="post" action="process_reset.php" style="display:inline-block;margin-right:6px;">
                    <input type="hidden" name="action" value="set_temp">
                    <input type="hidden" name="req_id" value="<?= (int)$r['id'] ?>">
                    <input type="hidden" name="penyelia_id" value="<?= (int)$r['penyelia_id'] ?>">
                    <button class="btn" type="submit">Set temporary</button>
                  </form>
                  <form method="post" action="process_reset.php" style="display:inline-block;">
                    <input type="hidden" name="action" value="decline">
                    <input type="hidden" name="req_id" value="<?= (int)$r['id'] ?>">
                    <input type="hidden" name="penyelia_id" value="<?= (int)$r['penyelia_id'] ?>">
                    <button class="btn" type="submit" style="background:#ef4444">Decline</button>
                  </form>
                <?php else: ?><span class="subtle">No action</span><?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php else: ?><p style="margin-top:10px;">No reset requests yet.</p><?php endif; ?>
  </div>

<?php endif; ?>
</div>

<script>
/* Enable sub-category select only when category is chosen (Dashboard + Tickets) */
(function(){
  const map = <?= json_encode($SUBCATS, JSON_UNESCAPED_UNICODE) ?>;
  function hookup(subSelId){
    const cat = document.querySelector(`form select[name="category"]`);
    const sub = document.getElementById(subSelId);
    if (!cat || !sub) return;
    function refresh(){
      const v = cat.value;
      sub.innerHTML = '<option value="">All</option>';
      if (!v || !map[v]) { sub.disabled = true; return; }
      map[v].forEach(s=>{
        const opt=document.createElement('option');
        opt.value=s; opt.textContent=s;
        <?php if ($subcategoryFilter): ?>
        if (s === <?= json_encode($subcategoryFilter) ?>) opt.selected = true;
        <?php endif; ?>
        sub.appendChild(opt);
      });
      sub.disabled = false;
    }
    cat.addEventListener('change', refresh);
  }
  <?php if ($section === 'dashboard'): ?>hookup('subcatSelect');<?php endif; ?>
  <?php if ($section === 'tickets'): ?>hookup('tSubcatSelect');<?php endif; ?>
})();

/* Charts (dashboard) */
<?php if ($section === 'dashboard'): ?>
(function(){
  const catLabels    = <?= json_encode($catLabels, JSON_UNESCAPED_UNICODE) ?>;
  const catValues    = <?= json_encode($catValues, JSON_NUMERIC_CHECK) ?>;
  const subcatLabels = <?= json_encode($subcatLabels, JSON_UNESCAPED_UNICODE) ?>;
  const subcatValues = <?= json_encode($subcatValues, JSON_NUMERIC_CHECK) ?>;
  const statusLabels = <?= json_encode($statusLabels, JSON_UNESCAPED_UNICODE) ?>;
  const statusValues = <?= json_encode($statusValues, JSON_NUMERIC_CHECK) ?>;
  const blkLabels    = <?= json_encode($blkLabels, JSON_UNESCAPED_UNICODE) ?>;
  const blkMale      = <?= json_encode($blkMale, JSON_NUMERIC_CHECK) ?>;
  const blkFemale    = <?= json_encode($blkFemale, JSON_NUMERIC_CHECK) ?>;

  if (catLabels.length) {
    const cctx = document.getElementById('catChart')?.getContext('2d');
    if (cctx) new Chart(cctx, {
      type: 'bar',
      data: { labels: catLabels, datasets: [{ label: 'Tickets', data: catValues, borderWidth: 1, backgroundColor:'#2563eb' }] },
      options: { responsive:true, maintainAspectRatio:false, scales:{ y:{ beginAtZero:true, ticks:{ precision:0 } } }, plugins:{ legend:{ display:false } } }
    });
  }

  if (subcatLabels.length) {
    const sctx = document.getElementById('subcatChart')?.getContext('2d');
    if (sctx) new Chart(sctx, {
      type: 'bar',
      data: { labels: subcatLabels, datasets: [{ label: 'Tickets', data: subcatValues, backgroundColor:'#7c3aed' }] },
      options: { indexAxis:'y', responsive:true, maintainAspectRatio:false, scales:{ x:{ beginAtZero:true, ticks:{ precision:0 } } }, plugins:{ legend:{ display:false } } }
    });
  }

  if (statusLabels.length) {
    const palette = { 'Pending':'#f59e0b', 'In Progress':'#38bdf8', 'Completed':'#22c55e', 'Rejected':'#ef4444' };
    const colors  = statusLabels.map(l => palette[l] ?? '#94a3b8');
    const stx = document.getElementById('statusChart')?.getContext('2d');
    if (stx) new Chart(stx, {
      type: 'doughnut',
      data: { labels: statusLabels, datasets: [{ label: 'Tickets', data: statusValues, backgroundColor: colors }] },
      options: { responsive:true, maintainAspectRatio:false, plugins:{ legend:{ position:'bottom' } }, cutout:'60%' }
    });
  }

  if (blkLabels.length) {
    const bctx = document.getElementById('blockChart')?.getContext('2d');
    if (bctx) new Chart(bctx, {
      type: 'bar',
      data: { labels: blkLabels, datasets: [
        { label:'Male', data: blkMale, backgroundColor:'#2563eb' },
        { label:'Female', data: blkFemale, backgroundColor:'#ef4444' },
      ]},
      options: { responsive:true, maintainAspectRatio:false, scales:{ y:{ beginAtZero:true, ticks:{ precision:0 } } } }
    });
  }
})();
<?php endif; ?>

/* Slide-in */
const toggleBtn = document.getElementById('panelToggleBtn');
const panel     = document.getElementById('slidePanel');
const overlay   = document.getElementById('slideOverlay');
const closeBtn  = document.getElementById('panelClose');
function openPanel(){ document.body.classList.add('slide-open'); panel.setAttribute('aria-hidden','false'); }
function closePanel(){ document.body.classList.remove('slide-open'); panel.setAttribute('aria-hidden','true'); }
toggleBtn?.addEventListener('click', openPanel);
closeBtn?.addEventListener('click', closePanel);
overlay?.addEventListener('click', closePanel);
document.addEventListener('keydown', (e)=>{ if(e.key==='Escape') closePanel(); });

/* Tickets: modal with multiple attachments (EXPOSE handlers globally) */
<?php if ($section === 'tickets'): ?>
const dModal   = document.getElementById('detailsModal');
const dTitle   = document.getElementById('dTitle');
const dCat     = document.getElementById('dCat');
const dSub     = document.getElementById('dSub');
const dSta     = document.getElementById('dSta');
const dCreated = document.getElementById('dCreated');
const dStud    = document.getElementById('dStud');
const dStudId  = document.getElementById('dStudId');
const dGen     = document.getElementById('dGen');
const dBR      = document.getElementById('dBR');
const dDesc    = document.getElementById('dDesc');
const dFiles   = document.getElementById('dFiles');
const dSta2    = document.getElementById('dSta2');

function setText(el, val){ if (el) el.textContent = val ?? '—'; }
function setBadge(el, val, base='badge'){
  if (!el) return;
  const txt = val ?? '—';
  el.textContent = txt;
  el.className   = `${base} status-` + txt.toLowerCase().replace(/\s+/g,'-');
}

/* --- Live Fetch for Ketua Modal --- */
window.openDetails = async function(btn) {
  const id = btn.dataset.id;
  if (!id) return alert("Missing ticket ID");

  const dModal = document.getElementById('detailsModal');
  const dTitle = document.getElementById('dTitle');
  const dCat = document.getElementById('dCat');
  const dSub = document.getElementById('dSub');
  const dSta = document.getElementById('dSta');
  const dCreated = document.getElementById('dCreated');
  const dStud = document.getElementById('dStud');
  const dStudId = document.getElementById('dStudId');
  const dGen = document.getElementById('dGen');
  const dBR = document.getElementById('dBR');
  const dDesc = document.getElementById('dDesc');
  const dFiles = document.getElementById('dFiles');
  const dTech = document.getElementById('dTech');
  const dRemark = document.getElementById('dRemark');
  const dCompletedAt = document.getElementById('dCompletedAt');
  const dProofContainer = document.getElementById('dProofContainer');
  const rPending = document.getElementById('rPending');
  const rInProgress = document.getElementById('rInProgress');
  const rCompleted = document.getElementById('rCompleted');
  const rRejected = document.getElementById('rRejected');

  function setText(el, val){ if (el) el.textContent = val && val.trim() ? val : '—'; }
  function setBadge(el, val){
    if (!el) return;
    el.textContent = val || '—';
    el.className = 'badge status-' + (val ? val.toLowerCase().replace(/\s+/g,'-') : 'none');
  }

  try {
    const res = await fetch(`ketua_fetch_details.php?id=${id}`);
    const data = await res.json();
    if (!data || data.error) throw new Error(data.error || 'Failed to fetch details');

    setText(dTitle, data.title || 'Ticket');
    setText(dCat, data.category);
    setText(dSub, data.subcategory);
    setBadge(dSta, data.status);
    setText(dCreated, data.created_at);
    setText(dStud, data.student_name);
    setText(dStudId, data.student_id);
    const phoneEl = document.getElementById('dPhone');
if (phoneEl) {
  if (data.phone && data.phone.trim()) {
    phoneEl.textContent = data.phone;
    phoneEl.href = 'tel:' + data.phone.trim();
  } else {
    phoneEl.textContent = '—';
    phoneEl.removeAttribute('href');
  }
}

    setText(dGen, data.gender ? data.gender.charAt(0).toUpperCase() + data.gender.slice(1) : '—');
    setText(dBR, `${data.block || '—'} / ${data.room_number || '—'}`);
    setText(dDesc, data.complaint);
    setText(dTech, data.technician_name);
    setText(dRemark, data.proof_note);
    setText(dCompletedAt, data.status === 'Completed' ? data.updated_at : '—');

   // Technician Proof attachments (with thumbnails)
dProofContainer.innerHTML = '';
const proofs = (data.attachments || []).filter(f => f.file_path.includes('uploads/proofs/'));
if (!proofs.length) {
  dProofContainer.innerHTML = '<em>—</em>';
} else {
  proofs.forEach(f => {
    const container = document.createElement('div');
    container.style.marginBottom = '8px';

    const ext = (f.file_path.split('.').pop() || '').toLowerCase();
    const isImg = ['jpg','jpeg','png','gif','webp'].includes(ext);

    if (isImg) {
      const img = document.createElement('img');
      img.src = f.file_path;
      img.alt = f.file_path.split('/').pop();
      img.style.maxWidth = '160px';
      img.style.borderRadius = '6px';
      img.style.marginBottom = '4px';
      img.style.display = 'block';
      img.style.cursor = 'pointer';
      img.onclick = () => window.open(f.file_path, '_blank');
      container.appendChild(img);
    }

    const a = document.createElement('a');
    a.href = f.file_path;
    a.target = '_blank';
    a.textContent = f.file_path.split('/').pop();
    a.style.display = 'block';
    a.style.fontSize = '13px';
    a.style.color = '#2563eb';
    container.appendChild(a);

    dProofContainer.appendChild(container);
  });
}


   // Student attachments (with thumbnails)
dFiles.innerHTML = '';
const files = (data.attachments || []).filter(f => !f.file_path.includes('uploads/proofs/'));
if (!files.length) {
  dFiles.innerHTML = '<li>—</li>';
} else {
  files.forEach(f => {
    const li = document.createElement('li');
    const ext = (f.file_path.split('.').pop() || '').toLowerCase();
    const isImg = ['jpg','jpeg','png','gif','webp'].includes(ext);

    if (isImg) {
      const img = document.createElement('img');
      img.src = f.file_path;
      img.alt = f.file_path.split('/').pop();
      img.style.maxWidth = '120px';
      img.style.borderRadius = '6px';
      img.style.marginRight = '8px';
      img.style.display = 'inline-block';
      img.style.cursor = 'pointer';
      img.onclick = () => window.open(f.file_path, '_blank');
      li.appendChild(img);
    }

    const a = document.createElement('a');
    a.href = f.file_path;
    a.target = '_blank';
    a.textContent = f.file_path.split('/').pop();
    a.style.fontSize = '13px';
    a.style.color = '#2563eb';
    li.appendChild(a);

    const size = document.createElement('small');
    size.style.marginLeft = '6px';
    size.textContent = `(${(f.file_size/1024/1024).toFixed(2)} MB)`;
    li.appendChild(size);

    dFiles.appendChild(li);
  });
}


    // Admin remarks
    setText(rPending, data.remark_pending);
    setText(rInProgress, data.remark_in_progress);
    setText(rCompleted, data.remark_completed);
    setText(rRejected, data.remark_rejected);

    dModal.style.display = 'flex';
  } catch (err) {
    alert('Error: ' + err.message);
  }
};

window.closeDetails = function(){ 
  const dModal = document.getElementById('detailsModal'); 
  if (dModal) dModal.style.display = 'none'; 
};

<?php endif; ?>
</script>
</body>
</html>
