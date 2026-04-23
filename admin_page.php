<?php
/* --- Session & guard ---------------------------------------------------- */
@ini_set('session.use_strict_mode', 1);
if (PHP_VERSION_ID >= 70300) {
  session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>isset($_SERVER['HTTPS']),'httponly'=>true,'samesite'=>'Lax']);
}
session_start();

require_once 'config.php';
require_once 'csrf.php';

/* Guard */
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
  header("Location: ./index.php"); exit();
}

/* No-cache */
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

/* Toast helpers */
$success = $_SESSION['success_message'] ?? '';
$error   = $_SESSION['error_message'] ?? '';
unset($_SESSION['success_message'], $_SESSION['error_message']);
function toast($msg, $type='success'){
  if(!$msg) return '';
  $cls = $type==='success' ? 'toast-success' : 'toast-error';
  return "<div class='toast {$cls}'><span>{$msg}</span><button type='button' class='close-btn' aria-label='Dismiss'>&times;</button></div>";
}
function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* Fixed categories */
$FIXED_CATS = ['KEJURUTERAAN AWAM','KEJURUTERAAN ELEKTRIK','KEJURUTERAAN MEKANIKAL'];
$MASTER_SUBS = [
  'KEJURUTERAAN AWAM' => [
    'Bumbung','Siling','Lantai','Dinding','Tangga','Pintu/Jejenang Pintu',
    'Tingkap/Jejenang Tingkap/Window Handle','Pagar','Gutter','RWDP (Rain Water Down Pipe',
    'Saluran Paip','Pili Paip','Sinki','Bidet','Tandas','Sistem Bekalan Air',
    'Kebocoran','Katil Pelajar','Almari Pelajar','Perabot (Kerusi/Meja/Kabinet)',
    'Tombol Pintu','Pokok/Landskap'
  ],
  'KEJURUTERAAN ELEKTRIK' => [
    'Kipas','Lampu','Pendawaian/Wiring','Plug Socket','Suis',
    'Bekalan Elektrik Terputus/Power Trip','Perangkap Kilat/Lightning Arrestor',
    'Lampu Jalan/Lampu Foyer','MSB/SSB/DB'
  ],
  'KEJURUTERAAN MEKANIKAL' => [
    'Alat Pemadam Api','Fire Alarm Panel','Heat Detector','Alarm Bell',
    'Break Glass Fire Alarm','Hose Reel'
  ],
];

/* Technician assigned block column */
$techBlockCol = 'assigned_block';
$hasAssignedBlock = false;
if ($res = $conn->query("SHOW COLUMNS FROM profile LIKE 'assigned_block'")) {
  $hasAssignedBlock = ($res && $res->num_rows > 0);
  $res->close();
}
if (!$hasAssignedBlock) $techBlockCol = 'block';

/* Ensure per-status remark columns exist */
function ensureRemarkColumns($conn){
  $needed = [
    'remark_pending'      => "TEXT NULL",
    'remark_in_progress'  => "TEXT NULL",
    'remark_completed'    => "TEXT NULL",
    'remark_rejected'     => "TEXT NULL"
  ];
  foreach($needed as $col=>$type){
    $res = $conn->query("SHOW COLUMNS FROM complaints LIKE '".$conn->real_escape_string($col)."'");
    if (!$res || $res->num_rows === 0){
      $conn->query("ALTER TABLE complaints ADD COLUMN $col $type");
    }
    if ($res) $res->close();
  }
}
ensureRemarkColumns($conn);

/* Sub-category column auto-detect */
$SUBCOL_DB = '';
$hasSubCategory = false;
foreach (['sub_category','subcategory'] as $try) {
  if ($res = $conn->query("SHOW COLUMNS FROM complaints LIKE '".$conn->real_escape_string($try)."'")) {
    if ($res->num_rows > 0) { $SUBCOL_DB = $try; $hasSubCategory = true; $res->close(); break; }
    $res->close();
  }
}

/* Attachments auto-detect */
function detectAttachmentsTable(mysqli $conn){
  foreach (['complaint_attachments','complain_attachment'] as $tbl){
    $res = $conn->query("SHOW TABLES LIKE '".$conn->real_escape_string($tbl)."'");
    if ($res && $res->num_rows > 0) { $res->close(); return $tbl; }
    if ($res) $res->close();
  }
  return '';
}
function colExists(mysqli $conn, $table, $col){
  $res = $conn->query("SHOW COLUMNS FROM `$table` LIKE '".$conn->real_escape_string($col)."'");
  $ok = ($res && $res->num_rows > 0);
  if ($res) $res->close();
  return $ok;
}
$ATT_TBL = detectAttachmentsTable($conn);
$ATT_HAS_FILE_SIZE = $ATT_TBL ? colExists($conn, $ATT_TBL, 'file_size') : false;
$ATT_HAS_MIME      = $ATT_TBL ? colExists($conn, $ATT_TBL, 'mime_type') : false;

/* Active section */
$section = $_GET['section'] ?? 'dashboard';

/* ── Dashboard filters & charts ─────────── */
$dbBlock     = trim($_GET['db_block']  ?? '');
$dbGender    = strtolower(trim($_GET['db_gender'] ?? ''));
$dbStatus    = trim($_GET['db_status'] ?? '');
$dbCategory  = trim($_GET['db_category'] ?? '');
$dbSubCat    = trim($_GET['db_subcategory'] ?? '');
$dbFrom      = trim($_GET['db_from']   ?? '');
$dbTo        = trim($_GET['db_to']     ?? '');

$qsDash = http_build_query([
  'db_block'      => $dbBlock,
  'db_gender'     => $dbGender,
  'db_status'     => $dbStatus,
  'db_category'   => $dbCategory,
  'db_subcategory'=> $dbSubCat,
  'db_from'       => $dbFrom,
  'db_to'         => $dbTo
]);

$blocksRes = $conn->query("SELECT DISTINCT block FROM profile WHERE block IS NOT NULL AND block<>'' ORDER BY block");
$allBlocks=[]; while($r=$blocksRes->fetch_assoc()){ $allBlocks[]=$r['block']; }

$catResDash = $conn->query("SELECT DISTINCT category FROM complaints WHERE is_deleted=0 AND category<>'' ORDER BY category");
$allCategories=[]; while($r=$catResDash->fetch_assoc()){ if (in_array($r['category'],$FIXED_CATS,true)) $allCategories[]=$r['category']; }

$allSubCats=[];
if ($hasSubCategory) {
  $subRes = $conn->query("SELECT DISTINCT `$SUBCOL_DB` AS sc FROM complaints WHERE is_deleted=0 AND `$SUBCOL_DB` IS NOT NULL AND `$SUBCOL_DB`<>'' ORDER BY `$SUBCOL_DB`");
  while($r=$subRes->fetch_assoc()){ $allSubCats[] = $r['sc']; }
}

$total      = (int)$conn->query("SELECT COUNT(*) c FROM complaints WHERE is_deleted=0")->fetch_assoc()['c'];
$pending    = (int)$conn->query("SELECT COUNT(*) c FROM complaints WHERE is_deleted=0 AND status='Pending'")->fetch_assoc()['c'];
$inprogress = (int)$conn->query("SELECT COUNT(*) c FROM complaints WHERE is_deleted=0 AND status='In Progress'")->fetch_assoc()['c'];
$completed  = (int)$conn->query("SELECT COUNT(*) c FROM complaints WHERE is_deleted=0 AND status='Completed'")->fetch_assoc()['c'];
$rejected   = (int)$conn->query("SELECT COUNT(*) c FROM complaints WHERE is_deleted=0 AND status='Rejected'")->fetch_assoc()['c'];

$kpiTotal=$total; $kpiPending=$pending; $kpiInProgress=$inprogress; $kpiCompleted=$completed; $kpiRejected=$rejected;

$chartCategoryLabels=$chartCategoryCounts=[];
$blkLabels=$maleCounts=$femaleCounts=[];
$maleCatLabels=$maleCatCounts=$femaleCatLabels=$femaleCatCounts=[];

/* Subcategory chart store */
$subDataByCategory = [
  'KEJURUTERAAN ELEKTRIK'=>['labels'=>[], 'counts'=>[]],
  'KEJURUTERAAN AWAM'=>['labels'=>[], 'counts'=>[]],
  'KEJURUTERAAN MEKANIKAL'=>['labels'=>[], 'counts'=>[]],
];

if ($section==='dashboard'){
  $w = ["c.is_deleted=0"]; $types=''; $params=[];
  if ($dbBlock!==''){ $w[]="p.block=?"; $types.='s'; $params[]=$dbBlock; }
  if ($dbGender==='male' || $dbGender==='female'){ $w[]="p.gender=?"; $types.='s'; $params[]=$dbGender; }
  if ($dbStatus!==''){ $w[]="c.status=?"; $types.='s'; $params[]=$dbStatus; }
  if ($dbCategory!==''){ $w[]="c.category=?"; $types.='s'; $params[]=$dbCategory; }
  if ($hasSubCategory && $dbSubCat!==''){ $w[]="c.`$SUBCOL_DB`=?"; $types.='s'; $params[]=$dbSubCat; }
  if ($dbFrom!==''){ $w[]="c.created_at>=?"; $types.='s'; $params[]=$dbFrom.' 00:00:00'; }
  if ($dbTo  !==''){ $w[]="c.created_at<=?"; $types.='s'; $params[]=$dbTo.' 23:59:59'; }
  $WS = implode(" AND ", $w);

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
  $kpiTotal      = (int)($k['total_cnt'] ?? 0);
  $kpiPending    = (int)($k['pend_cnt']  ?? 0);
  $kpiInProgress = (int)($k['prog_cnt']  ?? 0);
  $kpiCompleted  = (int)($k['comp_cnt']  ?? 0);
  $kpiRejected   = (int)($k['rej_cnt']   ?? 0);

  /* Categories */
  $stmt=$conn->prepare("SELECT c.category, COUNT(*) cnt
                        FROM complaints c JOIN profile p ON p.student_id=c.student_id
                        WHERE $WS GROUP BY c.category ORDER BY cnt DESC, c.category");
  if($types) $stmt->bind_param($types, ...$params);
  $stmt->execute(); $rs=$stmt->get_result();
  while($r=$rs->fetch_assoc()){
    if (in_array($r['category'],$FIXED_CATS,true)){
      $chartCategoryLabels[]=$r['category']; $chartCategoryCounts[]=(int)$r['cnt'];
    }
  }
  $stmt->close();

  /* Blocks, male vs female */
  $stmt=$conn->prepare("SELECT p.block,
                  SUM(CASE WHEN p.gender='male' THEN 1 ELSE 0 END) male_cnt,
                  SUM(CASE WHEN p.gender='female' THEN 1 ELSE 0 END) female_cnt
                FROM complaints c JOIN profile p ON p.student_id=c.student_id
                WHERE $WS GROUP BY p.block ORDER BY p.block");
  if($types) $stmt->bind_param($types, ...$params);
  $stmt->execute(); $rs=$stmt->get_result();
  while($r=$rs->fetch_assoc()){ $blkLabels[]=$r['block']?:'N/A'; $maleCounts[]=(int)$r['male_cnt']; $femaleCounts[]=(int)$r['female_cnt']; }
  $stmt->close();

  /* Gender focus — categories */
  $mTypes=$types.'s'; $mParams=$params; $mParams[]='male';
  $stmt=$conn->prepare("SELECT c.category, COUNT(*) cnt
                        FROM complaints c JOIN profile p ON p.student_id=c.student_id
                        WHERE $WS AND p.gender=? GROUP BY c.category ORDER BY cnt DESC, c.category");
  $stmt->bind_param($mTypes, ...$mParams);
  $stmt->execute(); $rs=$stmt->get_result();
  while($r=$rs->fetch_assoc()){
    if (in_array($r['category'],$FIXED_CATS,true)){ $maleCatLabels[]=$r['category']; $maleCatCounts[]=(int)$r['cnt']; }
  }
  $stmt->close();

  $fTypes=$types.'s'; $fParams=$params; $fParams[]='female';
  $stmt=$conn->prepare("SELECT c.category, COUNT(*) cnt
                        FROM complaints c JOIN profile p ON p.student_id=c.student_id
                        WHERE $WS AND p.gender=? GROUP BY c.category ORDER BY cnt DESC, c.category");
  $stmt->bind_param($fTypes, ...$fParams);
  $stmt->execute(); $rs=$stmt->get_result();
  while($r=$rs->fetch_assoc()){
    if (in_array($r['category'],$FIXED_CATS,true)){ $femaleCatLabels[]=$r['category']; $femaleCatCounts[]=(int)$r['cnt']; }
  }
  $stmt->close();

  /* Sub-categories per category */
  if ($hasSubCategory) {
    $stmt = $conn->prepare("
      SELECT c.category,
             COALESCE(NULLIF(c.`$SUBCOL_DB`,''),'(Unspecified)') AS subcat,
             COUNT(*) AS cnt
      FROM complaints c
      JOIN profile p ON p.student_id=c.student_id
      WHERE $WS
      GROUP BY c.category, subcat
      ORDER BY c.category, cnt DESC, subcat
    ");
    if($types) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rs = $stmt->get_result();
    $tmp = ['KEJURUTERAAN ELEKTRIK'=>[], 'KEJURUTERAAN AWAM'=>[], 'KEJURUTERAAN MEKANIKAL'=>[]];
    while($r=$rs->fetch_assoc()){
      $cat = (string)$r['category'];
      if (!isset($tmp[$cat])) $tmp[$cat]=[];
      $tmp[$cat][] = ['label'=>$r['subcat'], 'count'=>(int)$r['cnt']];
    }
    $stmt->close();
    foreach ($tmp as $cat=>$list){
      $subDataByCategory[$cat]['labels'] = array_column($list,'label');
      $subDataByCategory[$cat]['counts'] = array_column($list,'count');
    }
  }
}

/* ── Tickets section (filters + pagination) ─────────────────────────── */
$perPage = 20;
$page   = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page-1)*$perPage;

$blocksRes2 = $conn->query("SELECT DISTINCT block FROM profile WHERE block IS NOT NULL AND block<>'' ORDER BY block");

$statusFilter   = $_GET['status']    ?? '';
$categoryFilter = $_GET['category']  ?? '';
$blockFilter    = $_GET['block']     ?? '';
$genderFilter   = strtolower(trim($_GET['gender'] ?? ''));
$roomFilter     = trim($_GET['room'] ?? '');
$techFilter     = $_GET['technician'] ?? '';
$dateFromFilter = $_GET['from'] ?? '';
$dateToFilter   = $_GET['to']   ?? '';

$cond = ["c.is_deleted=0"];
if ($statusFilter)   $cond[] = "c.status='".$conn->real_escape_string($statusFilter)."'";
if ($categoryFilter) $cond[] = "c.category='".$conn->real_escape_string($categoryFilter)."'";
if ($blockFilter)    $cond[] = "p.block='".$conn->real_escape_string($blockFilter)."'";
if ($genderFilter==='male' || $genderFilter==='female') $cond[] = "p.gender='".$conn->real_escape_string($genderFilter)."'";
if ($roomFilter!=='') $cond[] = "p.room_number LIKE '%".$conn->real_escape_string($roomFilter)."%'";
if ($techFilter!=='') $cond[] = "c.assigned_to=".(int)$techFilter;
if ($dateFromFilter && $dateToFilter) {
  $cond[] = "DATE(c.created_at) BETWEEN '".$conn->real_escape_string($dateFromFilter)."' AND '".$conn->real_escape_string($dateToFilter)."'";
}
$whereSql = implode(" AND ", $cond);

$totalTickets = (int)$conn->query("
  SELECT COUNT(*) c
  FROM complaints c JOIN profile p ON c.student_id=p.student_id
  WHERE $whereSql
")->fetch_assoc()['c'];
$totalPages = max(1, (int)ceil($totalTickets/$perPage));

$selectSub = $hasSubCategory ? ", c.`$SUBCOL_DB` AS subcat" : ", '' AS subcat";

$tickets = $conn->query("
  SELECT
    c.*
    $selectSub,
    p.name, p.block, p.room_number, p.gender, p.phone,
    t.name AS tech_name, t.specialty AS tech_spec, t.gender AS tech_gender, t.$techBlockCol AS tech_block
  FROM complaints c
  JOIN profile p ON c.student_id = p.student_id
  LEFT JOIN profile t ON c.assigned_to = t.id
  WHERE $whereSql
  ORDER BY c.id DESC
  LIMIT $perPage OFFSET $offset
");

/* Preload attachments for current page */
$ticketRows = []; $ticketIds = [];
while($row = $tickets->fetch_assoc()){ $ticketRows[] = $row; $ticketIds[] = (int)$row['id']; }
$attachmentsMap = [];
if ($ATT_TBL && $ticketIds){
  $csv = implode(',', array_map('intval', $ticketIds));
  $selSize = $ATT_HAS_FILE_SIZE ? "a.file_size" : "0 AS file_size";
  $selMime = $ATT_HAS_MIME      ? "a.mime_type" : "'' AS mime_type";
  $sql = "SELECT a.id, a.complaint_id, a.file_path, $selSize, $selMime
          FROM `$ATT_TBL` a
          WHERE a.complaint_id IN ($csv)
          ORDER BY a.complaint_id ASC, a.id ASC";
  if ($res = $conn->query($sql)){
    while($r = $res->fetch_assoc()){
      $cid = (int)$r['complaint_id'];
      if (!isset($attachmentsMap[$cid])) $attachmentsMap[$cid] = [];
      $attachmentsMap[$cid][] = [
        'id'   => (int)$r['id'],
        'path' => (string)$r['file_path'],
        'size' => (int)$r['file_size'],
        'mime' => (string)$r['mime_type'],
      ];
    }
    $res->close();
  }
}

$qsTickets = http_build_query([
  'section'=>'tickets','status'=>$statusFilter,'category'=>$categoryFilter,
  'block'=>$blockFilter,'gender'=>$genderFilter,
  'room'=>$roomFilter,'technician'=>$techFilter,'from'=>$dateFromFilter,'to'=>$dateToFilter
]);
$ticketsBase = 'admin_page.php'.($qsTickets?('?'.$qsTickets.'&'):'?');

/* Staff tab data + filters */
$staffPerPage = 10;
$staffPage = isset($_GET['staff_page']) ? max(1,(int)$_GET['staff_page']) : 1;
$staffOffset = ($staffPage-1)*$staffPerPage;
$searchFilter = $_GET['search'] ?? '';

$f_block  = $_GET['f_block']  ?? '';
$f_gender = $_GET['f_gender'] ?? '';
$f_spec   = $_GET['f_spec']   ?? '';

$staffWhere = ["role='technician'","is_deleted=0"];
if ($searchFilter) { $s=$conn->real_escape_string($searchFilter); $staffWhere[]="(name LIKE '%$s%' OR email LIKE '%$s%')"; }
if ($f_block!=='')  { $staffWhere[]="$techBlockCol='".$conn->real_escape_string($f_block)."'"; }
if ($f_gender==='male' || $f_gender==='female') { $staffWhere[]="gender='".$conn->real_escape_string($f_gender)."'"; }
if ($f_spec!=='')   { $staffWhere[]="specialty='".$conn->real_escape_string($f_spec)."'"; }
$staffWhereSql = implode(" AND ", $staffWhere);

$totalStaff = (int)$conn->query("SELECT COUNT(*) c FROM profile WHERE $staffWhereSql")->fetch_assoc()['c'];
$totalStaffPages = max(1, (int)ceil($totalStaff/$staffPerPage));
$staff = $conn->query("SELECT id,name,email,role,specialty,gender,$techBlockCol AS assigned_block FROM profile WHERE $staffWhereSql ORDER BY name LIMIT $staffPerPage OFFSET $staffOffset");

/* preload staff stats for visible page */
$staffStats=[]; $ids=[]; foreach($staff as $s){ $ids[]=(int)$s['id']; } $staff->data_seek(0);
if ($ids){
  $csv=implode(',', array_map('intval',$ids));
  $rs=$conn->query("SELECT assigned_to, COUNT(*) open_cnt
                    FROM complaints
                    WHERE is_deleted=0 AND assigned_to IN ($csv)
                      AND status NOT IN ('Completed','Rejected')
                    GROUP BY assigned_to");
  while($r=$rs->fetch_assoc()){ $staffStats[(int)$r['assigned_to']]['open']=(int)$r['open_cnt']; }

  $rs=$conn->query("SELECT assigned_to, COUNT(*) done_cnt
                    FROM complaints
                    WHERE is_deleted=0 AND assigned_to IN ($csv) AND status='Completed'
                    GROUP BY assigned_to");
  while($r=$rs->fetch_assoc()){ $staffStats[(int)$r['assigned_to']]['done']=(int)$r['done_cnt']; }
}

$qsStaff = http_build_query([
  'section'=>'staff','search'=>$searchFilter,'f_block'=>$f_block,'f_gender'=>$f_gender,'f_spec'=>$f_spec
]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Admin Dashboard</title>
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
  <link rel="icon" type="image/png" href="assets/favicon.png" sizes="32x32">
  <link rel="icon" type="image/png" href="assets/favicon.png" sizes="16x16">
  <link rel="apple-touch-icon" href="assets/favicon.png">

  <link rel="stylesheet" href="admin.css">
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

  

  <style>

    /* Name chip like screenshot #1 */
    .name-chip {
      display:inline-block;
      padding:10px 14px;
      border-radius:12px;
      background:#e9f0ff;        /* soft blue like pic #1 */
      border:1px solid #dbe4ff;
      font-weight:900;
      color:#0f172a;
      line-height:1.1;
      text-transform:uppercase;
    }

    /* Category pill like screenshot #1 */
    .badge.badge-cat {
      background:#ffffff;
      color:#0f172a;
      border:1.5px solid #cbd5e1;
      padding:6px 12px;
      font-weight:800;
      border-radius:999px;
      letter-spacing:.02em;
    }

    /* Keep the little "More" button compact */
    td .btn.more { padding:4px 8px !important; font-size:12px; font-weight:700; }


    /* Make sub-category in table small/muted and the More button compact */
      td .subcat {
        font-size: 12px;
        color: #475569;
        font-weight: 600;
      }
      td .btn.more {
        padding: 4px 8px !important;
        font-size: 12px;
        font-weight: 700;
      }


    /* (A) Make modal taller + sticky footer so Close is always visible */
      .modal .sheet,
      .modal-card {
        background:#fff;
        border-radius:14px;
        width:min(680px, 92vw);
        /* CHANGED max-height from 85vh → 92vh */
        max-height:92vh;
        overflow:auto;
        padding:16px 20px;
        border:1px solid #e5e7eb;
        box-shadow:0 10px 32px rgba(0,0,0,.18);
        position:relative;
        font-size:14px;
      }
      .sheet .ft {
        /* keep Close button pinned even on long content */
        position: sticky;
        bottom: 0;
        background:#fafafa;
        z-index: 1;
      }

      /* (B) Normalize stat number size so it doesn’t look oversized */
      .staff-modal .stat .n {
        font-size:24px; /* was 26px */
        font-weight:900;
      }

      /* (C) Student "Tickets Overview": 2×2 grid
        Top row: Pending, In Progress
        Bottom row: Completed (under Pending), Rejected (under In Progress)
      */
      .student-stats {
        display:grid;
        grid-template-columns:repeat(2,1fr);
        grid-auto-rows:1fr;
        gap:10px;
        margin-bottom:10px;
      }

    .student-modal .name { font-size: 20px; }

    /* === Student modal: make it larger, compact, and keep Close button visible === */
    .student-modal .sheet {
      width: min(920px, 94vw);     /* wider like the technician profile */
      max-height: 90vh;            /* a bit taller so content fits */
      display: flex;               /* allow sticky footer */
      flex-direction: column;
    }

    .student-modal .bd {
      flex: 1 1 auto;
      overflow: auto;              /* content scrolls, header/footer remain */
    }

    .student-modal .ft {
      position: sticky;            /* close button always reachable */
      bottom: 0;
      background: #fafafa;
      z-index: 1;
    }

    /* stats grid: prevent overflow and keep “Rejected” inside the box */
    .student-modal .stats {
      grid-template-columns: repeat(4, minmax(0, 1fr));
    }

    @media (max-width: 760px) {
      .student-modal .stats {
        grid-template-columns: repeat(2, minmax(0, 1fr));
      }
    }

    /* make the big numbers a little smaller so they don't dominate */
    .student-modal .stat .n {
      font-size: 22px;
    }

    /* keep tiny text truly tiny and consistent */
    .student-modal .tiny {
      font-size: 12px;
    }

    /* ensure chips/wrapping looks tidy on smaller widths */
    .student-modal .chips {
      gap: 6px;
    }

    /* ensure stacking order of modals */
    #studentModal      { z-index: 80; }
    #studentRejModal   { z-index: 85; }
    #detailsModal      { z-index: 95; }   /* details is always on top */

    html,body{height:100%;max-width:100%;overflow-x:hidden;}
    body.ketua{margin:0; min-height:100vh; position:relative; display:block;}
    /* Slight blur background */
    body.ketua::before{content:""; position:fixed; inset:0; background:url('assets/dormitory.jpg') center/cover no-repeat; filter:blur(8px) brightness(.92) saturate(90%); transform:scale(1.06); z-index:-2;}
    body.ketua::after{ content:""; position:fixed; inset:0; background:rgba(0,0,0,.40); z-index:-1; }

    .header-wrap{position:sticky;top:0;z-index:50;padding:16px}
    .topbar{display:grid;grid-template-columns:auto 1fr auto;align-items:center;background:rgba(255,255,255,.96);border:1px solid rgba(2,6,23,.06);border-radius:16px;padding:10px 14px;box-shadow:0 8px 26px rgba(0,0,0,.08);max-width:1200px;margin:0 auto;}

    .menu-btn{border:0;background:linear-gradient(180deg,#f8fafc,#eef2ff); width:48px;height:48px;border-radius:14px;cursor:pointer;display:grid;place-items:center;box-shadow:0 8px 18px rgba(31,41,55,.12), inset 0 0 0 1px #e5e7eb; transition:transform .12s ease, box-shadow .12s ease}
    .menu-btn:hover{box-shadow:0 12px 24px rgba(31,41,55,.18), inset 0 0 0 1px #d1d5db; transform:translateY(-1px)}
    .menu-btn:active{transform:translateY(0)}
    .menu-ico{width:22px;height:22px}
    .menu-ico rect{rx:2;fill:#0f172a}

    .title-wrap{justify-self:center;text-align:center}
    .page-title{font-size:20px;font-weight:900;color:#0f172a}
    .subtle{font-size:13px;color:#475569;margin-top:2px}

    .container{max-width:1200px;margin:0 auto;padding:0 16px 24px}
    #app{ --gap:18px; }
    #app > * + *{ margin-top: var(--gap); }
    .row{ display:grid; gap: var(--gap); }
    .row.cols-2{ grid-template-columns:1fr 1fr; }
    .row.cols-5{ grid-template-columns:repeat(5,1fr); }
    @media (max-width:1100px){ .row.cols-2,.row.cols-5{ grid-template-columns:1fr; } }

    .card{background:rgba(255,255,255,.97);border:1px solid rgba(2,6,23,.07);border-radius:14px;padding:16px;box-shadow:0 8px 26px rgba(0,0,0,.08)}
    .card h3{margin:0 0 8px}

    .kpi{display:flex;align-items:center;gap:12px;padding:14px;border:1px solid #eef2f7;border-radius:12px;background:linear-gradient(180deg,#fff,#fbfdff)}
    .kpi .icon{width:38px;height:38px;border-radius:10px;display:grid;place-items:center;background:#ffffffb3;font-weight:900}
    .kpi .value{font-size:22px;font-weight:900;color:#0f172a;line-height:1}
    .kpi .label{font-size:12px;color:#64748b;margin-top:2px}

    :root{
      --st-pend-bg:#fff7ed; --st-pend-br:#fed7aa; --st-pend-fg:#92400e;
      --st-prog-bg:#eff6ff; --st-prog-br:#bfdbfe; --st-prog-fg:#1e40af;
      --st-comp-bg:#ecfdf5; --st-comp-br:#a7f3d0; --st-comp-fg:#065f46;
      --st-rej-bg:#fef2f2; --st-rej-br:#fecaca; --st-rej-fg:#991b1b;
    }
    .badge{display:inline-block;font-size:11px;padding:4px 8px;border-radius:999px;font-weight:700}
    .status-pending{background:var(--st-pend-bg);color:var(--st-pend-fg);border:1px solid var(--st-pend-br)}
    .status-in-progress{background:var(--st-prog-bg);color:var(--st-prog-fg);border:1px solid var(--st-prog-br)}
    .status-completed{background:var(--st-comp-bg);color:var(--st-comp-fg);border:1px solid var(--st-comp-br)}
    .status-rejected{background:var(--st-rej-bg);color:var(--st-rej-fg);border:1px solid var(--st-rej-br)}
    .tiny{font-size:12px;color:#64748b}

    .filter-card{padding:14px 16px}
    .filter-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px}
    .filter-grid select,.filter-grid input[type="date"],.filter-grid input[type="text"]{padding:9px 10px;border:1px solid #d5dbe3;border-radius:10px;background:#fff}
    .filter-actions{display:flex;gap:8px;flex-wrap:wrap}
    .btn{background:#4f6bed;color:#fff;border:0;padding:8px 12px;border-radius:10px;font-weight:700;cursor:pointer}
    .btn:hover{background:#3f56c7}
    .btn-ghost{background:#fff;border:1px solid #cbd5e1;color:#0f172a}

    /* Table */
    .table-shell{background:#fff;border-radius:14px;box-shadow:0 10px 28px rgba(0,0,0,.08);overflow:hidden;border:1px solid #e5e7eb}
    table{width:100%;border-collapse:separate;border-spacing:0;min-width:1024px}
    thead th{position:sticky;top:0;background:#f8fafc;font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:#0f172a;border-bottom:1px solid #e5e7eb;padding:10px;text-align:center;z-index:1}
    tbody td{padding:10px;border-bottom:1px solid #f1f5f9;text-align:center;font-size:14px;color:#0f172a}
    tbody tr:nth-child(even){background:#fcfcfd}
    tbody tr:hover{background:#f5f7fb}

    .col-sticky-left{position:sticky;left:0;background:inherit;z-index:2}
    .col-sticky-right{position:sticky;right:0;background:#fff;z-index:2;box-shadow:-6px 0 10px rgba(0,0,0,.04)}
    tbody td { position: relative; z-index: 1; }

    /* Exports & pagination */
    .export-actions{display:flex;gap:10px;flex-wrap:wrap}
    .export-actions a{text-decoration:none;padding:9px 12px;border-radius:10px;font-weight:700}
    .btn-csv{background:#065f46;color:#fff}.btn-csv:hover{background:#047857}
    .btn-pdf{background:#7c3aed;color:#fff}.btn-pdf:hover{background:#6d28d9}
    .pagination{display:flex;justify-content:center;gap:8px;flex-wrap:wrap;margin-top:14px}
    .page-btn{background:#e2e8f0;color:#0f172a;padding:8px 12px;border-radius:10px;text-decoration:none;font-weight:700}
    .page-btn:hover{background:#cbd5e1}
    .page-btn.active{background:#5e7ad9;color:#fff}
    .page-btn.disabled{opacity:.55;pointer-events:none}

    /* Slide panel & overlay */
    .slide-overlay{position:fixed;inset:0;background:rgba(0,0,0,.25);opacity:0;pointer-events:none;transition:opacity .2s ease;z-index:60}
    .slide-panel{position:fixed;top:0;left:0;height:100vh;width:min(340px,92vw);background:#fff;box-shadow:4px 0 18px rgba(0,0,0,.25);transform:translateX(-100%);transition:transform .25s ease;z-index:61;display:flex;flex-direction:column;border-right:1px solid #e5e7eb}
    .slide-header{display:flex;align-items:center;justify-content:space-between;padding:14px 16px;border-bottom:1px solid #e5e7eb}
    .slide-body{padding:12px 14px;display:flex;flex-direction:column;gap:10px}
    .slide-link{display:flex;align-items:center;gap:10px;padding:12px 14px;border:1px solid #eef2f7;border-radius:12px;text-decoration:none;color:#0f172a;font-weight:800}
    .slide-open .slide-panel{transform:translateX(0)}
    .slide-open .slide-overlay{opacity:1;pointer-events:auto}

    /* Modals */
    .modal{position:fixed;inset:0;background:rgba(0,0,0,.35);display:none;align-items:center;justify-content:center;z-index:70}
    .modal .sheet{width:min(860px,92vw);background:#fff;border-radius:18px;overflow:hidden;box-shadow:0 20px 40px rgba(0,0,0,.3)}
    .sheet .hd{display:flex;align-items:center;gap:10px;padding:14px 16px;border-bottom:1px solid #eef2f7;background:linear-gradient(180deg,#fff,#fafafa)}
    .sheet .bd{padding:16px}
    .sheet .ft{display:flex;gap:8px;justify-content:flex-end;padding:12px 16px;border-top:1px solid #eef2f7;background:#fafafa}
    .kv{display:grid;grid-template-columns:160px 1fr;gap:6px 12px}
    /* NEW: left-align labels and values */
    .kv .k, .kv .v { text-align:left; }
    .kv .k{color:#64748b;font-weight:700}
    .kv .v{color:#0f172a}
    .attach a{color:#2563eb;text-decoration:none;font-weight:700}
    .remark-box{border:1px solid #e5e7eb;border-radius:12px;padding:10px;background:#fbfdff}
    .remark-box h4{margin:0 0 8px;font-size:13px;color:#334155}
    textarea.pretty{width:100%;height:150px;border:1px solid #e5e7eb;border-radius:12px;padding:10px;outline:none}
    textarea.pretty:focus{box-shadow:0 0 0 4px rgba(37,99,235,.12);border-color:#93c5fd}

    .att-list{list-style:disc; margin:6px 0 0 18px; padding:0;}
    .gallery{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:10px;margin-top:10px}
    .gallery img{width:100%;height:120px;object-fit:cover;border:1px solid #e5e7eb;border-radius:12px}

    /* Staff modal (popup profile) */
    .staff-modal .sheet{width:min(920px,94vw);border-radius:18px;overflow:hidden;box-shadow:0 20px 40px rgba(0,0,0,.3)}
    .staff-modal .cover{position:relative;height:160px;background:url('assets/dormitory.jpg') center/cover no-repeat}
    .staff-modal .avatar{
      position:absolute;left:36px;bottom:-48px;width:120px;height:120px;border-radius:999px;
      background:#fff;border:6px solid #fff;box-shadow:0 10px 30px rgba(0,0,0,.18);object-fit:cover
    }
    .staff-modal .bd{padding:64px 18px 16px;background:#fff}
    .staff-modal .hd-row{display:flex;align-items:flex-start;gap:16px;flex-wrap:wrap}
    .staff-modal .name{font-size:22px;font-weight:900;color:#0f172a;margin:0}
    .staff-modal .chips{display:flex;gap:8px;flex-wrap:wrap}
    .staff-modal .chip{display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border-radius:999px;background:#eef2ff;border:1px solid #e5e7eb;font-weight:800}
    .staff-modal .two-col{display:grid;grid-template-columns:1.1fr .9fr;gap:14px;margin-top:12px}
    @media (max-width:760px){ .staff-modal .two-col{grid-template-columns:1fr} .staff-modal .avatar{left:18px} }
    .staff-modal .card{padding:14px;border:1px solid #e5e7eb;border-radius:14px;background:#fff}
    .staff-modal .kv{display:grid;grid-template-columns:140px 1fr;gap:6px 10px}
    .staff-modal .k{color:#64748b;font-weight:700}
    .staff-modal .v{color:#0f172a;word-break:break-word}
    .staff-modal .stats{display:grid;grid-template-columns:repeat(3,1fr);gap:10px}
    .staff-modal .stat{padding:12px;border:1px solid #e5e7eb;border-radius:12px;text-align:center}
    .staff-modal .stat .n{font-size:26px;font-weight:900}
    .staff-modal .bar{height:12px;border-radius:999px;background:#f1f5f9;overflow:hidden}
    .staff-modal .bar > span{display:block;height:100%;background:linear-gradient(90deg,#22c55e,#16a34a)}
    .staff-modal .badge-ok{display:inline-block;padding:6px 10px;border-radius:999px;background:#ecfdf5;border:1px solid #bbf7d0;color:#065f46;font-weight:800}

    @media (max-width:560px){
      .kv{grid-template-columns:120px 1fr}
      table{min-width:960px}
      .modal .sheet{width:95vw}
    }


    .modal .sheet,
    .modal-card {
      background:#fff;
      border-radius:16px;
      width:min(820px, 95vw);     /* wider so everything fits */
      max-height:92vh;            /* taller so less scrolling */
      overflow:auto;
      padding:0;                  /* we'll let header/body/footer control their padding */
      border:1px solid #e5e7eb;
      box-shadow:0 10px 32px rgba(0,0,0,.18);
      position:relative;
      font-size:14px;
    }

    /* sticky header/footer inside modal so Close is always reachable */
    .sheet .hd{
      position:sticky;
      top:0;
      z-index:2;
      padding:14px 18px;
      background:linear-gradient(180deg,#fff,#fafafa);
      border-bottom:1px solid #eef2f7;
    }
    .sheet .bd{
      padding:16px 18px;
    }
    .sheet .ft{
      position:sticky;
      bottom:0;
      z-index:2;
      padding:12px 18px;
      background:#fafafa;
      border-top:1px solid #eef2f7;
    }

    /* make badges + numbers consistent with technician card */
    .badge{ font-size:11px; padding:4px 10px; border-radius:999px; font-weight:800 }

    /* tidy up ticket stat cards (used for both staff & student popups) */
    .stats{ display:grid; gap:10px }
    .stats-2x2{ grid-template-columns:repeat(2,1fr); }      /* 2×2 layout */
    .stat{
      padding:12px;
      border:1px solid #e5e7eb;
      border-radius:12px;
      text-align:center;
      background:#fff;
    }
    .stat .n{ font-size:24px; font-weight:900; line-height:1 }  /* smaller, consistent number */
    .stat .tiny{ font-size:12px; color:#64748b }

    /* clamp long text so layouts don’t stretch */
    #studentModal .kv .v{ word-break:break-word }

    .modal .sheet h2,
    .modal-card h2 {
      font-size:18px;              /* smaller title */
      margin:0 0 6px;
    }

    .modal .sheet .kv .k,
    .modal-card .meta-label {
      font-weight:600;
      color:#374151;
      font-size:13px;
    }

    .modal .sheet .v,
    .modal-card .meta-item div:last-child {
      font-size:13px;
      color:#111827;
    }

    .ticket-header {
      display: flex;
      justify-content: space-between;
      gap: 20px;
      flex-wrap: wrap;
      margin-bottom: 10px;
    }
    .ticket-header .left,
    .ticket-header .right {
      flex: 1;
      min-width: 260px;
    }
    .ticket-header div {
      font-size: 13px;
      line-height: 1.5;
      color: #111827;
    }
    .ticket-header strong {
      color: #374151;
    }
    .divider {
      border: 0;
      border-top: 1px solid #e5e7eb;
      margin: 8px 0 0;
    }


  </style>
  
</head>
<body class="ketua">

<!-- Header -->
<div class="header-wrap">
  <div class="topbar">
    <button id="panelToggleBtn" type="button" class="menu-btn" aria-label="Open menu" title="Menu">
      <svg class="menu-ico" viewBox="0 0 24 24">
        <rect x="3" y="6"  width="18" height="3"></rect>
        <rect x="3" y="10.5" width="18" height="3"></rect>
        <rect x="3" y="15" width="18" height="3"></rect>
      </svg>
    </button>
    <div class="title-wrap">
      <div class="page-title">Welcome, Admin</div>
      <div class="subtle">System overview & management</div>
    </div>
    <div></div>
  </div>
</div>

<!-- Slide-in menu -->
<div class="slide-overlay" id="slideOverlay"></div>
<aside class="slide-panel" id="slidePanel" aria-hidden="true">
  <div class="slide-header">
    <strong>Quick Menu</strong>
    <button id="panelClose" style="border:0;background:#0000;font-size:22px;cursor:pointer" aria-label="Close">&times;</button>
  </div>
  <div class="slide-body" id="slideBody">
    <a class="slide-link<?= $section==='dashboard'?' active':'' ?>" href="admin_page.php?section=dashboard">📊 Dashboard</a>
    <a class="slide-link<?= $section==='tickets'?' active':'' ?>" href="admin_page.php?section=tickets">🎫 Ticket Management</a>
    <a class="slide-link<?= $section==='staff'?' active':'' ?>" href="admin_page.php?section=staff">👥 Staff</a>
    <a class="slide-link<?= $section==='history'?' active':'' ?>" href="admin_page.php?section=history">🗄️ History</a>
    <div class="slide-divider"></div>
    <form action="logout.php" method="post"><button type="submit" class="logout-btn-wide">⏻ Logout</button></form>
      <?= csrf_field() ?>
  </div>
</aside>

<!-- Toasts -->
<div id="toast-container">
  <?= toast($success,'success'); ?>
  <?= toast($error,'error'); ?>
</div>

<div id="app" class="container">

  <?php if ($section==='dashboard'): ?>
    <!-- Filters -->
    <div class="card filter-card">
      <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;">
        <h3 style="margin:0;">Filters</h3>
        <div class="export-actions" style="margin-left:auto">
          <a class="btn-pdf" href="export_dashboard_pdf.php?<?= $qsDash ?>" target="_blank" rel="noopener">Export PDF</a>
        </div>
        <div class="filter-actions">
          <button class="btn" onclick="document.getElementById('dbForm').requestSubmit()">Apply</button>
          <a class="btn btn-ghost" href="admin_page.php?section=dashboard">Reset</a>
        </div>
      </div>
      <form id="dbForm" method="get" style="margin-top:10px;">
        <input type="hidden" name="section" value="dashboard">
        <div class="filter-grid">
          <select name="db_block"><option value="">All blocks</option><?php foreach($allBlocks as $b): ?><option value="<?= e($b) ?>" <?= $dbBlock===$b?'selected':'' ?>><?= e($b) ?></option><?php endforeach; ?></select>
          <select name="db_gender"><option value="">All genders</option><option value="male" <?= $dbGender==='male'?'selected':'' ?>>Male</option><option value="female" <?= $dbGender==='female'?'selected':'' ?>>Female</option></select>
          <select name="db_status"><option value="">All status</option><?php foreach(['Pending','In Progress','Completed','Rejected'] as $s): ?><option value="<?= e($s) ?>" <?= $dbStatus===$s?'selected':'' ?>><?= e($s) ?></option><?php endforeach; ?></select>
          <select id="db_category" name="db_category"><option value="">All categories</option> <?php foreach($FIXED_CATS as $c): ?> <option value="<?= e($c) ?>" <?= $dbCategory===$c?'selected':'' ?>><?= e($c) ?></option> <?php endforeach; ?></select>
          <?php
            $initialSubOptions = [];
            if ($dbCategory && isset($MASTER_SUBS[$dbCategory])) {
              $initialSubOptions = $MASTER_SUBS[$dbCategory];
            }
            $disabledAttr = $dbCategory ? '' : 'disabled';
          ?>
          <select id="db_subcategory" name="db_subcategory" <?= $disabledAttr ?>> <option value="">All sub categories</option> <?php foreach($initialSubOptions as $sc): ?><option value="<?= e($sc) ?>" <?= $dbSubCat===$sc?'selected':'' ?>><?= e($sc) ?></option><?php endforeach; ?></select>
          <input type="date" name="db_from" value="<?= e($dbFrom) ?>"><input type="date" name="db_to" value="<?= e($dbTo) ?>">
        </div>
      </form>
    </div>

    <!-- KPIs -->
    <div class="row cols-5">
      <div class="kpi"><div class="icon">Σ</div><div><div class="value"><?= $kpiTotal ?></div><div class="label">Total Tickets</div></div></div>
      <div class="kpi" style="background:var(--st-pend-bg);border:1px solid var(--st-pend-br)"><div class="icon">⏳</div><div><div class="value"><?= $kpiPending ?></div><div class="label" style="color:var(--st-pend-fg)">Pending</div></div></div>
      <div class="kpi" style="background:var(--st-prog-bg);border:1px solid var(--st-prog-br)"><div class="icon">🔧</div><div><div class="value"><?= $kpiInProgress ?></div><div class="label" style="color:var(--st-prog-fg)">In Progress</div></div></div>
      <div class="kpi" style="background:var(--st-comp-bg);border:1px solid var(--st-comp-br)"><div class="icon">✅</div><div><div class="value"><?= $kpiCompleted ?></div><div class="label" style="color:var(--st-comp-fg)">Completed</div></div></div>
      <div class="kpi" style="background:var(--st-rej-bg);border:1px solid var(--st-rej-br)"><div class="icon">⛔</div><div><div class="value"><?= $kpiRejected ?></div><div class="label" style="color:var(--st-rej-fg)">Rejected</div></div></div>
    </div>

    <!-- Charts -->
    <div class="row cols-2">
      <div class="card"><h3 style="margin:0;">Tickets by Category</h3><?php if ($chartCategoryLabels): ?><div style="height:300px"><canvas id="chartCategory"></canvas></div><?php else: ?><div class="tiny">No category data</div><?php endif; ?></div>
      <div class="card"><h3 style="margin:0;">Tickets by Block — Male vs Female</h3><?php if ($blkLabels): ?><div style="height:300px"><canvas id="chartBlockGender"></canvas></div><?php else: ?><div class="tiny">No block data</div><?php endif; ?></div>
    </div>

    <?php if ($hasSubCategory): ?>
      <div class="card">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
          <h3 style="margin:0;">Sub-Categories</h3>
          <div style="display:flex;gap:0;border:1px solid #cbd5e1;border-radius:10px;overflow:hidden">
            <button type="button" id="btnScElec" class="btn" style="border-radius:0;background:#2563eb" onclick="setSubcatView('KEJURUTERAAN ELEKTRIK')">Kejuruteraan Elektrik</button>
            <button type="button" id="btnScAwam" class="btn" style="border-radius:0;background:#94a3b8" onclick="setSubcatView('KEJURUTERAAN AWAM')">Kejuruteraan Awam</button>
            <button type="button" id="btnScMek" class="btn" style="border-radius:0;background:#94a3b8" onclick="setSubcatView('KEJURUTERAAN MEKANIKAL')">Kejuruteraan Mekanikal</button>
          </div>
        </div>
        <div style="height:320px;margin-top:10px;"><canvas id="chartSubCategoryByCat"></canvas></div>
      </div>
    <?php endif; ?>

    <div class="card"><h3 style="margin:0;">Status Overview</h3><div style="height:320px"><canvas id="chartStatus"></canvas></div></div>

  <?php endif; ?>

  <?php if ($section==='tickets'): ?>
    <div class="card">
      <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
        <h3 style="margin:0;">Ticket Management</h3>
        <div class="export-actions">
          <a class="btn-csv" href="export_admin_csv.php?<?= $qsTickets ?>" target="_blank" rel="noopener">Export CSV</a>
          <a class="btn-pdf" href="export_admin_pdf.php?<?= $qsTickets ?>" target="_blank" rel="noopener">Export PDF</a>
        </div>
      </div>

      <!-- Filters -->
      <form method="GET" class="filter-card" style="margin-top:12px;">
        <input type="hidden" name="section" value="tickets">
        <div class="filter-grid">
          <select name="gender"><option value="">Gender (All)</option><option value="male" <?= $genderFilter==='male'?'selected':'' ?>>Male</option><option value="female" <?= $genderFilter==='female'?'selected':'' ?>>Female</option></select>
          <select name="block"><option value="">Block (All)</option><?php $blocksRes2->data_seek(0); while($b=$blocksRes2->fetch_assoc()): ?><option value="<?= e($b['block']) ?>" <?= $blockFilter===$b['block']?'selected':'' ?>><?= e($b['block']) ?></option><?php endwhile; ?></select>
          <input type="text" name="room" placeholder="Room" value="<?= e($roomFilter) ?>">
          <select name="category"><option value="">Category (All)</option><?php foreach($FIXED_CATS as $c): ?><option value="<?= e($c) ?>" <?= $categoryFilter===$c?'selected':'' ?>><?= e($c) ?></option><?php endforeach; ?></select>
          <select name="status"><option value="">Status (All)</option><?php foreach(['Pending','In Progress','Completed','Rejected'] as $s): ?><option value="<?= e($s) ?>" <?= $statusFilter===$s?'selected':'' ?>><?= e($s) ?></option><?php endforeach; ?></select>
          <select name="technician"><option value="">Assigned (All)</option><?php $techList = $conn->query("SELECT id,name,specialty,gender,$techBlockCol AS assigned_block FROM profile WHERE role='technician' AND is_deleted=0 ORDER BY name"); $techList->data_seek(0); while($t=$techList->fetch_assoc()): ?><option value="<?= (int)$t['id'] ?>" <?= (string)$techFilter===(string)$t['id']?'selected':'' ?>><?= e($t['name']) ?><?= $t['specialty']?' — '.e($t['specialty']):'' ?><?= $t['assigned_block']?' • Blok '.e($t['assigned_block']):'' ?></option><?php endwhile; ?></select>
        </div>
        <div class="filter-actions" style="margin-top:10px">
          <button type="submit" class="btn">Apply</button>
          <a class="btn btn-ghost" href="admin_page.php?section=tickets">Reset</a>
        </div>
      </form>

      <!-- Bulk -->
      <form id="ticketsBulkForm" class="filter-grid" style="margin-top:12px; grid-template-columns: auto auto auto 1fr auto;">
        <label style="display:flex;align-items:center;gap:8px;">
          <input type="checkbox" id="tickets-select-all"> Select all
        </label>
        <select id="tickets-bulk-action"><option value="" disabled selected>Bulk action…</option><option value="delete">Soft Delete (to History)</option><option value="assign">Assign Technician</option></select>
        <select id="tickets-tech" style="display:none"><option value="" disabled selected>Select technician…</option>
          <?php $techAll = $conn->query("SELECT id,name,specialty,gender,$techBlockCol AS assigned_block FROM profile WHERE role='technician' AND is_deleted=0 ORDER BY name");
            while ($t=$techAll->fetch_assoc()): ?>
              <option value="<?= (int)$t['id'] ?>" data-specialty="<?= e($t['specialty']) ?>" data-block="<?= e($t['assigned_block']) ?>" data-gender="<?= e($t['gender']) ?>">
                <?= e($t['name']) ?> — <?= e($t['specialty']) ?> • Blok <?= e($t['assigned_block']) ?> • <?= e(ucfirst($t['gender'])) ?>
              </option>
          <?php endwhile; ?>
        </select>
        <span></span><button type="submit" class="btn">Apply</button>
      </form>

      <div class="table-shell" style="margin-top:10px;overflow-x:auto;">
        <table>
          <thead>
            <tr>
              <th class="col-sticky-left"><input type="checkbox" id="tickets-th-cb"></th>
              <th>No.</th>
              <th>Student</th>
              <th>Gender</th>
              <th>Block</th>
              <th>Room</th>
              <th>Title</th>
              <th>Category</th>
              <th>Status</th>
              <th>Assigned</th>
              <th class="col-sticky-right">Actions</th>
            </tr>
          </thead>

          <tbody>
            <?php
              $seq=$offset+1;
              $techListAll = $conn->query("SELECT id,name,specialty,gender,$techBlockCol AS assigned_block FROM profile WHERE role='technician' AND is_deleted=0 ORDER BY name");
              foreach($ticketRows as $row):
                $cid = (int)$row['id'];
                $atts = $attachmentsMap[$cid] ?? [];
                $attsJson = e(json_encode($atts));
                $remarksJson = e(json_encode([
                  'pending'      => (string)($row['remark_pending'] ?? ''),
                  'in_progress'  => (string)($row['remark_in_progress'] ?? ''),
                  'completed'    => (string)($row['remark_completed'] ?? ''),
                  'rejected'     => (string)($row['remark_rejected'] ?? ''),
                ]));
            ?>
              <tr>
                <td class="col-sticky-left" style="background:#fff;">
                  <input type="checkbox" class="tickets-row-cb"
                         value="<?= (int)$row['id'] ?>"
                         data-category="<?= e($row['category']) ?>"
                         data-block="<?= e($row['block']) ?>"
                         data-gender="<?= e($row['gender']) ?>">
                </td>
                <td><?= $seq++ ?></td>
                <td>
                  <button type="button"
                          class="name-chip"
                          data-studentid="<?= e($row['student_id']) ?>"
                          onclick="openStudentProfile(this)">
                    <?= e($row['name']) ?>
                  </button>
                </td>

                <td><?= e(ucfirst($row['gender'])) ?></td>
                <td><?= e($row['block']) ?></td>
                <td><?= e($row['room_number']) ?></td>
                <td><?= e($row['title']) ?></td>
                <!-- Category (show current + open modal button) -->
              <td>
                <div style="display:flex;flex-direction:column;align-items:center;gap:4px">
                  <span class="badge badge-cat"><?= e($row['category']) ?></span>
                  <?php if ($hasSubCategory): ?>
                    <span class="subcat"><?= $row['subcat'] ? e($row['subcat']) : '(no sub-category)' ?></span>
                  <?php endif; ?>
                  <!-- More button unchanged -->
                  <button
                    type="button"
                    class="btn btn-ghost more"
                    onclick='openCatModal(
                      <?= (int)$row["id"] ?>,
                      <?= json_encode($row["category"]) ?>,
                      <?= json_encode($row["subcat"] ?? "") ?>
                    )'
                  >
                    More
                  </button>

              </td>
                <!-- Status -->
                <td>
                  <form action="admin_update_status.php" method="post" style="display:flex;gap:6px;align-items:center;justify-content:center;">
                    <input type="hidden" name="ticket_id" value="<?= (int)$row['id'] ?>">
                    <input type="hidden" name="redirect" value="<?= e($qsTickets) ?>">
                    <select name="new_status" onchange="this.form.submit()">
                      <?php foreach(['Pending','In Progress','Completed','Rejected'] as $s): ?>
                        <option value="<?= e($s) ?>" <?= $row['status']===$s ? 'selected':'' ?>><?= e($s) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </form>
                  <?php $cls='status-'.strtolower(str_replace(' ','-',$row['status'])); ?>
                  <div style="margin-top:6px;"><span class="badge <?= $cls ?>"><?= e($row['status']) ?></span></div>
                </td>

                <!-- Assigned technician (strict match gender+block+category) -->
                <td>
                  <form action="admin_assign_technician.php" method="post">
                    <input type="hidden" name="ticket_id" value="<?= (int)$row['id'] ?>">
                    <input type="hidden" name="section" value="tickets">
                    <select name="technician_id" onchange="this.form.submit()">
                      <option value="">-- Unassigned --</option>
                      <?php
                        $ticketCat = trim((string)$row['category']);
                        $studentBlock = trim((string)$row['block']);
                        $studentGender = trim((string)$row['gender']);
                        $techListAll->data_seek(0);
                        while($t=$techListAll->fetch_assoc()):
                          $okCat   = (strcasecmp((string)$t['specialty'], $ticketCat) === 0);
                          $okBlock = (strcasecmp((string)$t['assigned_block'], $studentBlock) === 0);
                          $okGender= (strcasecmp((string)$t['gender'], $studentGender) === 0);
                          $ok = $okCat && $okBlock && $okGender;
                          $sel = ($row['assigned_to']===$t['id'])?'selected':''; ?>
                        <?php if ($ok || $sel): ?>
                          <option value="<?= (int)$t['id'] ?>" <?= $sel ?>>
                            <?= e($t['name']) ?><?= $ok ? '' : ' (⚠︎ mismatch)' ?> — <?= e($t['specialty']) ?> • Blok <?= e($t['assigned_block']) ?> • <?= e(ucfirst($t['gender'])) ?>
                          </option>
                        <?php endif; endwhile; ?>
                    </select>
                    <div class="tiny" style="margin-top:4px;">Options are filtered by <strong>gender</strong>, <strong>block</strong> &amp; <strong>category</strong>.</div>
                  </form>
                  <?php if (!empty($row['tech_name'])): ?>
                    <div style="margin-top:6px;font-size:12px;color:#555;">→ <?= e($row['tech_name']) ?><?= $row['tech_spec']?' • '.e($row['tech_spec']):'' ?><?= $row['tech_block']?' • Blok '.e($row['tech_block']):'' ?><?= $row['tech_gender']?' • '.e(ucfirst($row['tech_gender'])):'' ?></div>
                  <?php endif; ?>
                </td>

                <!-- Actions -->
                <td class="col-sticky-right">
                  <div style="display:flex;gap:6px;flex-wrap:wrap;justify-content:center;background:#fff;">
                    <button type="button" class="btn" title="Details"
                      onclick="openDetails(this)"
                      data-title="<?= e($row['title']) ?>"
                      data-category="<?= e($row['category']) ?>"
                      data-status="<?= e($row['status']) ?>"
                      data-submitted="<?= e($row['created_at'] ?? '') ?>"
                      data-description="<?= e($row['complaint']) ?>"
                      data-student="<?= e($row['name']) ?>"
                      data-phone="<?= e($row['phone'] ?? '') ?>"
                      data-subcat="<?= e($row['subcat'] ?? '') ?>"
                      data-block="<?= e($row['block']) ?>"
                      data-room="<?= e($row['room_number']) ?>"
                       data-remarks='<?= $remarksJson ?>'
                       data-techname="<?= e($row['tech_name'] ?? '') ?>"
                       data-techremark="<?= e($row['proof_note'] ?? '') ?>"
                          data-gender="<?= e($row['gender']) ?>"

                        data-techcompleted="<?= e($row['updated_at'] ?? '') ?>"
                          
                     <?php
$studentFiles = [];
$techFiles = [];
if (!empty($attachmentsMap[$cid])) {
  foreach ($attachmentsMap[$cid] as $a) {
   if (stripos($a['path'], 'uploads/proofs/') !== false) {
  $techFiles[] = $a;
} else {
  $studentFiles[] = $a;
}

  }
}
?>
data-attachments='<?= e(json_encode($studentFiles)) ?>'
data-tech_attachments='<?= e(json_encode($techFiles)) ?>'

                    >Details</button>
                    <button 
                      type="button" 
                      class="btn" 
                      style="background:#0ea5e9" 
                      title="Add Remark"
                      onclick='openRemark(
                        <?= (int)$row["id"] ?>,
                        <?= json_encode($row["status"]) ?>,
                        <?= json_encode($row["remark_pending"] ?? "") ?>,
                        <?= json_encode($row["remark_in_progress"] ?? "") ?>,
                        <?= json_encode($row["remark_completed"] ?? "") ?>,
                        <?= json_encode($row["remark_rejected"] ?? "") ?>
                      )'>
                      Remark
                    </button>

                    <form action="admin_delete_ticket.php" method="post" onsubmit="return confirm('Move ticket to History?');">
                        <?= csrf_field() ?>
                      <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                      <input type="hidden" name="section" value="tickets">
                      <button type="submit" class="btn" style="background:#ef4444">Delete</button>
                    </form>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="pagination">
        <?php $window=3; $start=max(1,$page-$window); $end=min($totalPages,$page+$window); ?>
        <a href="<?= $page<=1?'javascript:void(0)':($ticketsBase.'page='.($page-1)) ?>" class="page-btn <?= $page<=1?'disabled':'' ?>">Prev</a>
        <?php if ($start>1): ?><a class="page-btn" href="<?= $ticketsBase.'page=1' ?>">1</a><?php if ($start>2): ?>…<?php endif; ?><?php endif; ?>
        <?php for($p=$start;$p<=$end;$p++): ?><a class="page-btn <?= $p==$page?'active':'' ?>" href="<?= $ticketsBase.'page='.$p ?>"><?= $p ?></a><?php endfor; ?>
        <?php if ($end<$totalPages): ?><?php if ($end<$totalPages-1): ?>…<?php endif; ?><a class="page-btn" href="<?= $ticketsBase.'page='.$totalPages ?>"><?= $totalPages ?></a><?php endif; ?>
        <a href="<?= $page>=$totalPages?'javascript:void(0)':($ticketsBase.'page='.($page+1)) ?>" class="page-btn <?= $page>=$totalPages?'disabled':'' ?>">Next</a>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($section==='staff'): ?>
    <div class="card">
      <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
        <h3 style="margin:0;">Staff</h3>
        <div class="export-actions">
          <a class="btn-csv" href="export_staff_csv.php?<?= $qsStaff ?>" target="_blank" rel="noopener">Export CSV</a>
          <a class="btn-pdf" href="export_staff_pdf.php?<?= $qsStaff ?>" target="_blank" rel="noopener">Export PDF</a>
        </div>
      </div>

      <?php
        $blkRes = $conn->query("SELECT DISTINCT $techBlockCol AS b FROM profile WHERE role='technician' AND is_deleted=0 AND $techBlockCol IS NOT NULL AND $techBlockCol<>'' ORDER BY $techBlockCol");
        $specRes = $conn->query("SELECT DISTINCT specialty AS s FROM profile WHERE role='technician' AND is_deleted=0 AND specialty IS NOT NULL AND specialty<>'' ORDER BY specialty");
      ?>

      <!-- Staff Filters -->
      <form method="GET" class="filter-grid" style="margin-top:12px;">
        <input type="hidden" name="section" value="staff">
        <input type="text" name="search" placeholder="Search name/email" value="<?= e($searchFilter) ?>">
        <select name="f_block">
          <option value="">Block (All)</option>
          <?php $blkRes->data_seek(0); while($b=$blkRes->fetch_assoc()): $B=$b['b']; ?>
            <option value="<?= e($B) ?>" <?= $f_block===$B?'selected':'' ?>><?= e($B) ?></option>
          <?php endwhile; ?>
        </select>
        <select name="f_gender">
          <option value="">Gender (All)</option>
          <option value="male" <?= $f_gender==='male'?'selected':'' ?>>Male</option>
          <option value="female" <?= $f_gender==='female'?'selected':'' ?>>Female</option>
        </select>
        <select name="f_spec">
          <option value="">Specialty (All)</option>
          <?php $specRes->data_seek(0); while($s=$specRes->fetch_assoc()): $S=$s['s']; ?>
            <option value="<?= e($S) ?>" <?= $f_spec===$S?'selected':'' ?>><?= e($S) ?></option>
          <?php endwhile; ?>
        </select>
        <button type="submit" class="btn">Filter</button>
        <a href="?section=staff" class="btn btn-ghost">Reset</a>
      </form>

      <!-- Create technician -->
      <form action="admin_create_technician.php" method="post" class="filter-grid" style="align-items:end;margin-top:10px;">
          <?= csrf_field() ?>
        <input type="hidden" name="section" value="staff">
        <input type="text" name="name" placeholder="Full Name" required>
        <input type="email" name="email" placeholder="Email" required>
        <input type="password" name="password" placeholder="Password" required>
        <select name="gender" id="techGender" required><option value="" disabled selected>Block Gender…</option><option value="male">Male</option><option value="female">Female</option></select>
        <select name="assigned_block" id="techBlock" required><option value="" disabled selected>Assigned Block…</option></select>
        <select name="tech_category" required><option value="" disabled selected>Choose category…</option><?php foreach($FIXED_CATS as $c): ?><option value="<?= e($c) ?>"><?= e($c) ?></option><?php endforeach; ?></select>
        <button type="submit" class="btn">Create Technician</button>
      </form>

      <!-- Bulk delete -->
      <form id="staffBulkForm" class="filter-grid" style="margin-top:12px; grid-template-columns: auto 1fr auto;">
        <label style="display:flex;align-items:center;gap:8px;"><input type="checkbox" id="staff-select-all"> Select all</label>
        <span></span><button type="submit" class="btn" style="background:#ef4444">Soft Delete</button>
      </form>

      <div class="table-shell" style="margin-top:10px;overflow-x:auto;">
        <table>
          <thead><tr>
            <th><input type="checkbox" id="staff-th-cb"></th>
            <th>No.</th><th>Name</th><th>Email</th><th>Role</th><th>Assigned Gender Block</th><th>Specialty</th><th>Open</th><th>Completed</th><th>Action</th>
          </tr></thead>
          <tbody>
            <?php $staff->data_seek(0); $i=1; while($row=$staff->fetch_assoc()):
              $sid=(int)$row['id']; $open=$staffStats[$sid]['open']??0; $done=$staffStats[$sid]['done']??0; ?>
              <tr>
                <td><input type="checkbox" class="staff-row-cb" value="<?= $sid ?>"></td>
                <td><?= $i++ ?></td>
                <td>
                  <button type="button" class="btn btn-ghost" style="background:#e2e8f0;color:#0f172a"
                          data-staffid="<?= $sid ?>"
                          onclick="openStaffProfile(this)"><?= e($row['name']) ?></button>
                </td>
                <td><?= e($row['email']) ?></td>
                <td><?= e(ucfirst($row['role'])) ?></td>
                <td>
                  <?php
                    $blk = trim((string)($row['assigned_block'] ?? ''));
                    $gen = trim((string)($row['gender'] ?? ''));
                    $genPretty = $gen ? ucfirst($gen) : '';
                    $parts = [];
                    if ($blk !== '') $parts[] = 'Block '.$blk;
                    if ($genPretty !== '') $parts[] = $genPretty;
                    echo e($parts ? implode('-', $parts) : '—');
                  ?>
                </td>
                <td><?= e($row['specialty'] ?? '') ?></td>
                <td><?= $open ?></td><td><?= $done ?></td>
                <td>
                  <form action="admin_delete_staff.php" method="post" onsubmit="return confirm('Move staff to History?');">
                      <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= $sid ?>">
                    <input type="hidden" name="section" value="staff">
                    <button type="submit" class="btn" style="background:#ef4444">Delete</button>
                  </form>
                </td>
              </tr>
            <?php endwhile; ?>
          </tbody>
        </table>
      </div>

      <div class="pagination">
        <?php if ($staffPage>1): ?><a class="page-btn" href="?section=staff&staff_page=<?= $staffPage-1 ?>&search=<?= urlencode($searchFilter) ?>&f_block=<?= urlencode($f_block) ?>&f_gender=<?= urlencode($f_gender) ?>&f_spec=<?= urlencode($f_spec) ?>">Prev</a><?php endif; ?>
        <?php for($p=1;$p<=$totalStaffPages;$p++): ?><a class="page-btn <?= $p==$staffPage?'active':'' ?>" href="?section=staff&staff_page=<?= $p ?>&search=<?= urlencode($searchFilter) ?>&f_block=<?= urlencode($f_block) ?>&f_gender=<?= urlencode($f_gender) ?>&f_spec=<?= urlencode($f_spec) ?>"><?= $p ?></a><?php endfor; ?>
        <?php if ($staffPage<$totalStaffPages): ?><a class="page-btn" href="?section=staff&staff_page=<?= $staffPage+1 ?>&search=<?= urlencode($searchFilter) ?>&f_block=<?= urlencode($f_block) ?>&f_gender=<?= urlencode($f_gender) ?>&f_spec=<?= urlencode($f_spec) ?>">Next</a><?php endif; ?>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($section==='history'): ?>
    <div class="card">
      <h3>History (Soft-deleted)</h3>
      <h3 style="margin-top:6px;">Tickets</h3>
      <form id="histTicketsForm" class="filter-grid" style="margin-top:12px; grid-template-columns:auto auto 1fr auto;">
        <label style="display:flex;align-items:center;gap:8px;"><input type="checkbox" id="hist-tickets-select-all"> Select all</label>
        <select id="hist-tickets-action" required><option value="" disabled selected>Bulk action…</option><option value="restore">Restore</option><option value="purge">Purge permanently</option></select>
        <span></span><button type="submit" class="btn">Apply</button>
      </form>
      <div class="table-shell" style="margin-top:10px;overflow-x:auto;">
        <table>
          <thead>
            <tr>
              <th><input type="checkbox" id="hist-tickets-th-cb"></th>
              <th>No.</th>
              <th>Name</th>
              <th>Title</th>
              <th>Category</th>
              <th>Deleted At</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <?php $histT = $conn->query("
                    SELECT
                      c.id, c.title, c.category, c.deleted_at,
                      c.student_id,
                      p.name, p.block, p.room_number, p.gender, p.phone
                    FROM complaints c
                    JOIN profile p ON p.student_id = c.student_id
                    WHERE c.is_deleted = 1
                    ORDER BY c.deleted_at DESC
                  ");

            $n=1; while($r=$histT->fetch_assoc()): ?>
              <tr>
                <td><input type="checkbox" class="hist-tickets-row-cb" value="<?= (int)$r['id'] ?>"></td>
                <td><?= $n++ ?></td>

                <!-- NEW: Student name (clickable to open profile popup) -->
                <td>
                  <button type="button"
                          class="btn btn-ghost"
                          style="background:#e2e8f0;color:#0f172a"
                          data-studentid="<?= e($r['student_id']) ?>"
                          onclick="openStudentProfile(this)">
                    <?= e($r['name'] ?: '—') ?>
                  </button>
                  <div class="tiny" style="margin-top:2px">
                    <?= e($r['block']) ?> / <?= e($r['room_number']) ?> • <?= e(ucfirst($r['gender'])) ?>
                  </div>
                </td>

                <td><?= e($r['title']) ?></td>
                <td><?= e($r['category']) ?></td>
                <td><?= e($r['deleted_at']) ?></td>

                <td style="display:flex;gap:8px;justify-content:center;flex-wrap:wrap;">
                  <!-- NEW: Full details viewer (opens the same Details modal) -->
                  <button type="button" class="btn" onclick="showDeletedDetails(<?= (int)$r['id'] ?>)">View</button>

                  <form action="admin_restore_ticket_student.php" method="post"
                        onsubmit="return confirm('Restore this ticket?');">
                       <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                    <button type="submit" class="btn">Restore</button>
                  </form>

                  <form action="admin_purge_ticket.php" method="post"
                        onsubmit="return confirm('Permanently delete this ticket?');">
                         <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                    <button type="submit" class="btn" style="background:#ef4444">Purge</button>
                  </form>
                </td>
              </tr>

            <?php endwhile; ?>
          </tbody>
        </table>
      </div>

      <h3 style="margin-top:6px;">Staff</h3>
      <form id="histStaffForm" class="filter-grid" style="margin-top:12px; grid-template-columns:auto auto 1fr auto;">
        <label style="display:flex;align-items:center;gap:8px;"><input type="checkbox" id="hist-staff-select-all"> Select all</label>
        <select id="hist-staff-action" required><option value="" disabled selected>Bulk action…</option><option value="restore">Restore</option><option value="purge">Purge permanently</option></select>
        <span></span><button type="submit" class="btn">Apply</button>
      </form>
      <div class="table-shell" style="margin-top:10px;overflow-x:auto;">
        <table>
          <thead><tr><th><input type="checkbox" id="hist-staff-th-cb"></th><th>No.</th><th>Name</th><th>Email</th><th>Category</th><th>Deleted At</th><th>Action</th></tr></thead>
          <tbody>
            <?php $histS=$conn->query("SELECT id,name,email,role,specialty,deleted_at FROM profile WHERE is_deleted=1 AND role='technician' ORDER BY deleted_at DESC");
            $n=1; while($r=$histS->fetch_assoc()): ?>
              <tr>
                <td><input type="checkbox" class="hist-staff-row-cb" value="<?= (int)$r['id'] ?>"></td>
                <td><?= $n++ ?></td>
                <td><?= e($r['name']) ?></td>
                <td><?= e($r['email']) ?></td>
                <td><?= e($r['role']) ?></td>
                <td><?= e($r['deleted_at']) ?></td>
                <td style="display:flex;gap:8px;justify-content:center;">
                  <form action="admin_restore_technician.php" method="post" onsubmit="return confirm('Restore this staff account?');">
                      <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button type="submit" class="btn">Restore</button>
                  </form>
                  <form action="admin_purge_staff.php" method="post" onsubmit="return confirm('Permanently delete this staff?');">
                      <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button type="submit" class="btn" style="background:#ef4444">Purge</button></form>
                </td>
              </tr>
            <?php endwhile; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>
</div>

<!-- Details Modal (Updated for Consistency with Technician Layout) -->
<div id="detailsModal" class="modal" role="dialog" aria-modal="true">
  <div class="sheet">
    <div class="hd">
      <div>
        <h2 id="modalTitle" style="margin:0;font-weight:900;">Ticket Details</h2>
        <div class="tiny">Submitted: <span id="modalSubmitted">—</span></div>
      </div>
      <span id="modalStatusBadge" class="badge" style="margin-left:auto">—</span>
    </div>

    <div class="bd">
      <!-- fallback elements to stop JS from breaking -->
<div style="display:none">
  <span id="modalCategory">—</span>
  <span id="modalDescription">—</span>
</div>

     <!-- Basic Info (New Layout) -->
<div class="ticket-header">
  <div class="left">
    <div><strong>Sub Category:</strong> <span id="modalSubcat">—</span></div>
    <div><strong>Phone:</strong> <a href="#" id="modalPhone">—</a></div>
    <div><strong>Block/Room:</strong> <span id="modalBR">—</span></div>
    <div><strong>Description:</strong> <span id="modalDescriptionShort">—</span></div>
  </div>
  <div class="right">
    <div><strong>Student:</strong> <span id="modalStudent">—</span></div>
    <div><strong>Status:</strong> <span id="modalStatusText">—</span></div>
    <div><strong>Gender:</strong> <span id="modalGender">—</span></div>
  </div>
</div>
<hr class="divider">

<!-- Student Attachment Section -->
<div style="margin-top:14px" class="remark-box">
  <h4>Student Attachment:</h4>
  <ul id="modalAttachmentsList" class="att-list"></ul>
  <div id="modalGallery" class="gallery"></div>
  <div id="modalNoAtt" class="tiny" style="display:none">No attachments</div>
</div>


    <!-- Technician Details -->
<div style="margin-top:16px" class="remark-box">
  <h4>Technician Details</h4>
  <div class="kv">
    <div class="k">Technician</div><div class="v" id="modalTechName">—</div>
    <div class="k">Technician Remark</div><div class="v" id="modalTechRemark">—</div>
    <div class="k">Completed At</div><div class="v" id="modalTechCompleted">—</div>
  </div>

  <h4 style="margin-top:12px;">Technician Proof</h4>
  <div id="modalTechProof" class="gallery"></div>
  <div id="modalNoProof" class="tiny" style="display:none">No proof uploaded yet.</div>
</div>


      <!-- Remarks by Status -->
      <div style="margin-top:16px" class="remark-box">
        <h4>Remarks by Status</h4>
        <div class="kv">
          <div class="k">Pending</div><div class="v" id="mrPending">—</div>
          <div class="k">In Progress</div><div class="v" id="mrProgress">—</div>
          <div class="k">Completed</div><div class="v" id="mrCompleted">—</div>
          <div class="k">Rejected</div><div class="v" id="mrRejected">—</div>
        </div>
      </div>
    </div>

    <div class="ft">
      <button class="btn btn-ghost" onclick="closeDetails()">Close</button>
    </div>
  </div>
</div>


<!-- Remark Modal -->


<div id="remarkModal" class="modal" role="dialog" aria-modal="true" style="display:none;">
  <div class="sheet">
    <div class="hd">
      <h2>Add / Update Remark</h2>
      <span id="remarkStatusBadge" class="badge" style="margin-left:auto">—</span>
    </div>
    <div class="bd">
      <form method="post" action="admin_add_remark.php" onsubmit="return confirm('Save remark for this ticket?');">
          <?= csrf_field() ?>
        <input type="hidden" name="ticket_id" id="remarkTicketId">
        <input type="hidden" name="current_status" id="remarkCurrentStatus">
        <div class="remark-box">
          <h4>New Remark (for current status)</h4>
          <textarea name="admin_remark" id="remarkTextarea" required class="pretty"></textarea>
  

        </div>
        <div style="margin-top:12px" class="remark-box">
          <h4>Existing Remarks</h4>
          <div class="kv">
            <div class="k">Pending</div><div class="v" id="rPrevPending">—</div>
            <div class="k">In Progress</div><div class="v" id="rPrevProgress">—</div>
            <div class="k">Completed</div><div class="v" id="rPrevCompleted">—</div>
            <div class="k">Rejected</div><div class="v" id="rPrevRejected">—</div>
          </div>
        </div>
        <div class="ft">
          <button type="button" class="btn btn-ghost" onclick="closeRemark()">Cancel</button>
          <button type="submit" class="btn">Save Remark</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Staff Profile Modal -->
<div id="staffModal" class="modal staff-modal" style="display:none" role="dialog" aria-modal="true">
  <div class="sheet">
    <div class="cover">
      <img id="staffAvatar" class="avatar" src="assets/avatar-fallback.png" alt="avatar">
    </div>
    <div class="bd">
      <div class="hd-row">
        <div style="margin-left:148px">
          <h2 id="staffName" class="name">—</h2>
          <div id="staffChips" class="chips"></div>
        </div>
      </div>

      <div class="two-col">
        <!-- Left: Contact & Assignment -->
        <div class="card">
          <h3 style="margin:0 0 8px">Technician Info</h3>
          <div class="kv">
            <div class="k">Email</div><div class="v" id="staffEmail">—</div>
            <div class="k">Phone</div><div class="v" id="staffPhone">—</div>
            <div class="k">Specialty</div><div class="v" id="staffSpec">—</div>

            <!-- Combined Assigned Gender Block -->
            <div class="k">Assigned Gender Block</div>
            <div class="v" id="staffAssignCombo">—</div>

            <div class="k">Role</div><div class="v" id="staffRole">—</div>
          </div>
        </div>

        <!-- Right: Work stats -->
        <div class="card">
          <h3 style="margin:0 0 8px">Workload & Performance</h3>
          <div class="stats" style="margin-bottom:10px">
            <div class="stat"><div class="n" id="stPending">0</div><div class="tiny">Pending</div></div>
            <div class="stat"><div class="n" id="stProgress">0</div><div class="tiny">In Progress</div></div>
            <div class="stat"><div class="n" id="stCompleted">0</div><div class="tiny">Completed</div></div>
          </div>
          <div class="tiny" style="margin:6px 0 4px">Completion rate</div>
          <div class="bar" aria-label="Completion rate">
            <span id="perfBar" style="width:0%"></span>
          </div>
          <div style="margin-top:8px"><span id="staffPerf" class="badge-ok">0%</span></div>
        </div>
      </div>
    </div>
    <div class="ft" style="display:flex;gap:8px;justify-content:flex-end;padding:12px 16px;border-top:1px solid #eef2f7;background:#fafafa">
      <button class="btn" onclick="closeStaff()">Close</button>
    </div>
  </div>
</div>


<script>

  function initialsFromName(name){
  const n = String(name||'').trim();
  if(!n) return '?';
  const parts = n.split(/\s+/).slice(0,2);
  return parts.map(p => p[0].toUpperCase()).join('');
}
function svgInitialAvatar(initials){
  const bg = '#2563eb';      // calm blue to match the UI
  const fg = '#ffffff';
  const svg = `
    <svg xmlns="http://www.w3.org/2000/svg" width="160" height="160">
      <rect width="100%" height="100%" rx="999" ry="999" fill="${bg}"/>
      <text x="50%" y="54%" dominant-baseline="middle" text-anchor="middle"
            font-family="ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto"
            font-size="64" font-weight="800" fill="${fg}">${initials}</text>
    </svg>`;
  return 'data:image/svg+xml;base64,' + btoa(svg);
}

// --- Student Profile modal logic ---
let CURRENT_STUDENT_ID = null;

function badgeClass(isBanned){
  return isBanned ? 'badge status-rejected' : 'badge status-completed';
}
function yesNo(b){ return b ? 'Banned' : 'Active'; }

function makeInitialAvatar(name, bg='#eef2ff', fg='#0f172a'){
  name = (name||'').trim();
  const parts = name.split(/\s+/).filter(Boolean);
  const init = parts.slice(0,2).map(s => s[0]?.toUpperCase() || '').join('') || 'ST';
  const svg = `
    <svg xmlns="http://www.w3.org/2000/svg" width="160" height="160">
      <rect width="100%" height="100%" rx="80" ry="80" fill="${bg}"/>
      <text x="50%" y="54%" dominant-baseline="middle" text-anchor="middle"
            font-family="ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto"
            font-size="64" font-weight="800" fill="${fg}">${init}</text>
    </svg>`;
  return 'data:image/svg+xml;utf8,' + encodeURIComponent(svg);
}
async function openStudentProfile(btn){
  const sid = btn.dataset.studentid || '';
  if(!sid){ alert('Missing student id'); return; }
  CURRENT_STUDENT_ID = sid;

  try{
    const res = await fetch(`admin_student_profile.php?json=1&student_id=${encodeURIComponent(sid)}`, {credentials:'same-origin'});
    if(!res.ok) throw new Error('HTTP '+res.status);
    const d = await res.json();

    // identity + chips
    let avatar = (d.avatar_url || d.avatar) || '';
    if(!avatar){
      const ini = initialsFromName(d.name || d.student_id || 'Student');
      avatar = svgInitialAvatar(ini);
    }
    document.getElementById('studentAvatar').src = avatar;
    document.getElementById('studentName').textContent = d.name || '—';

    const chips = [];
    if (d.gender) chips.push(`<span class="chip">${d.gender.charAt(0).toUpperCase()+d.gender.slice(1)}</span>`);
    if (d.block) chips.push(`<span class="chip">Blok ${d.block}</span>`);
    if (d.room_number) chips.push(`<span class="chip">Room ${d.room_number}</span>`);
    document.getElementById('studentChips').innerHTML = chips.join('') || '—';

    // basic fields
    document.getElementById('stStudentId').textContent = d.student_id || '—';
    document.getElementById('stEmail').textContent     = d.email || '—';
    document.getElementById('stPhone').textContent     = d.phone || '—';
    document.getElementById('stBlockRoom').textContent = (d.block||'—') + ' / ' + (d.room_number||'—');

    // totals / status
    const stats = d.stats || {};
    const total = Number(stats.total || 0);
    const pend  = Number(stats.pending || 0);
    const prog  = Number(stats.in_progress || 0);
    const comp  = Number(stats.completed || 0);
    const rej   = Number(stats.rejected || 0);

    document.getElementById('stTotal').textContent = total;
    document.getElementById('stPend').textContent  = pend;
    document.getElementById('stProg').textContent  = prog;
    document.getElementById('stComp').textContent  = comp;
    document.getElementById('stRej').textContent   = rej;

    /* Warnings == rejected by default; if backend provides warnings_count, clamp to total so it never exceeds #tickets */
    let warns = (Number.isFinite(Number(d.warnings_count)) ? Number(d.warnings_count) : rej);
    warns = Math.max(0, Math.min(total, warns));

    const banned = !!d.is_banned;
    document.getElementById('stWarnings').textContent = warns;
    const banBadge = document.getElementById('stBanBadge');
    banBadge.textContent = yesNo(banned);
    banBadge.className = badgeClass(banned);

    // show/hide Ban / Unban
    document.getElementById('banStudentId').value = d.student_id || '';
    document.getElementById('unbanStudentId').value = d.student_id || '';
    document.getElementById('banBtn').style.display   = (!banned && warns >= 3) ? '' : 'none';
    document.getElementById('unbanBtn').style.display = banned ? '' : 'none';
    document.getElementById('banHint').style.display  = (!banned && warns < 3) ? '' : 'none';

    // recent warnings list
    const box = document.getElementById('stWarningsList');
    const list = Array.isArray(d.recent_warnings) ? d.recent_warnings : [];
    if (!list.length){
      box.textContent = '—';
    } else {
      box.innerHTML = '<ul style="margin:6px 0 0 18px;padding:0;list-style:disc;">' +
        list.map(w => {
          const dt = (w.created_at||'').toString();
          const rs = (w.reason||'').toString();
          const cid= (w.complaint_id||'').toString();
          return `<li><strong>${dt}</strong> — ${rs}${cid ? ` (ticket #${cid})` : ''}</li>`;
        }).join('') + '</ul>';
    }

    // open modal
    document.getElementById('studentModal').style.display='flex';
  }catch(err){
    console.error(err);
    alert('Could not load student profile. Make sure admin_student_profile.php exists and returns JSON.');
  }
}

function closeStudent(){ document.getElementById('studentModal').style.display='none'; }

// Rejected tickets popup (uses same backend with &rejected=1)
async function openRejectedTicketsInline(){
  if (!CURRENT_STUDENT_ID){ alert('Open a student profile first.'); return; }
  const box = document.getElementById('rejInline');
  box.textContent = 'Loading…';
  try {
    const res = await fetch(`admin_student_profile.php?json=1&student_id=${encodeURIComponent(CURRENT_STUDENT_ID)}&rejected=1`, {credentials:'same-origin'});
    const d = await res.json();
    const items = Array.isArray(d.rejected_tickets) ? d.rejected_tickets : [];
    box.innerHTML = items.length
      ? '<ul style="margin:6px 0 0 18px;list-style:disc">' +
          items.map(x => {
            const url = `admin_page.php?section=tickets&status=Rejected&ticket_id=${encodeURIComponent(x.id)}`;
            return `<li><a class="btn btn-ghost" style="padding:0 6px" href="${url}">#${x.id}</a> — ${x.title || '(no title)'} <span class="tiny">(${x.created_at || '-'})</span></li>`;
          }).join('') + '</ul>'
      : 'No rejected tickets.';
  } catch {
    box.textContent = 'Failed to load rejected tickets.';
  }
}

async function openRejectedTickets(){
  if (!CURRENT_STUDENT_ID){ alert('Open a student profile first.'); return; }
  const listBox = document.getElementById('rejList');
  listBox.textContent = 'Loading…';
  try{
    const res = await fetch(`admin_student_profile.php?json=1&student_id=${encodeURIComponent(CURRENT_STUDENT_ID)}&rejected=1`, {credentials:'same-origin'});
    if(!res.ok) throw new Error('HTTP '+res.status);
    const d = await res.json();
    const items = Array.isArray(d.rejected_tickets) ? d.rejected_tickets : [];
    if (!items.length){
      listBox.textContent = 'No rejected tickets.';
    } else {
      listBox.innerHTML = '<ul style="margin:0 0 0 18px;list-style:disc">' +
        items.map(x => {
          const url = `admin_page.php?section=tickets&status=Rejected&ticket_id=${encodeURIComponent(x.id)}`;
          return `<li>
            <a href="${url}" class="btn btn-ghost" style="padding:0 6px">#${x.id}</a>
            — ${x.title || '(no title)'}
            <span class="tiny">(${x.created_at || '-'})</span>
            <button type="button" class="btn btn-ghost" style="margin-left:8px" onclick="showRejectedDetails(${Number(x.id)})">Details</button>
          </li>`;
        }).join('') +
      '</ul>';


    }
  }catch(e){
    listBox.textContent = 'Failed to load rejected tickets.';
  }
  document.getElementById('studentRejModal').style.display='flex';
}
function closeStudentRej(){ document.getElementById('studentRejModal').style.display='none'; }
async function showRejectedDetails(id){
  try{
    const res = await fetch(`admin_ticket_details.php?id=${encodeURIComponent(id)}&json=1`, {credentials:'same-origin'});
    if(!res.ok) throw new Error('HTTP '+res.status);
    const d = await res.json();
    if (d && !d.error) {
      // build a temporary element with the data-* attributes expected by openDetails()
      const tmp = document.createElement('button');
      tmp.dataset.title         = d.title || '';
      tmp.dataset.category      = d.category || '';
      tmp.dataset.status        = d.status || '';
      tmp.dataset.submitted     = d.submitted || '';
      tmp.dataset.description   = d.description || '';
      tmp.dataset.student       = d.student || '';
      tmp.dataset.phone         = d.phone || '';
      tmp.dataset.subcat        = d.subcat || '';
      tmp.dataset.block         = d.block || '';
      tmp.dataset.room          = d.room || '';
      tmp.dataset.gender        = d.gender || '';
      tmp.dataset.techname      = d.techname || '';
      tmp.dataset.techremark    = d.techremark || '';
      tmp.dataset.techcompleted = d.techcompleted || '';

      // arrays → JSON strings for the existing openDetails() logic
      tmp.setAttribute('data-attachments', JSON.stringify(d.attachments || []));
      tmp.setAttribute('data-tech_attachments', JSON.stringify(d.tech_attachments || []));
      tmp.setAttribute('data-remarks', JSON.stringify(d.remarks || {}));

      openDetails(tmp); // reuse your existing modal filler
    }else{
      alert('Could not load ticket details.');
    }
  }catch(e){
    console.error(e);
    alert('Failed to load ticket details.');
  }
}
async function showDeletedDetails(id){
  try{
    const res = await fetch(`admin_ticket_details.php?id=${encodeURIComponent(id)}&json=1`,
                            {credentials:'same-origin'});
    if(!res.ok) throw new Error('HTTP '+res.status);
    const d = await res.json();
    if (d && !d.error) {
      // Build a temporary button with the data-* that openDetails() expects
      const tmp = document.createElement('button');
      tmp.dataset.title         = d.title || '';
      tmp.dataset.category      = d.category || '';
      tmp.dataset.status        = d.status || '(Deleted)';
      tmp.dataset.submitted     = d.submitted || '';
      tmp.dataset.description   = d.description || '';
      tmp.dataset.student       = d.student || '';
      tmp.dataset.phone         = d.phone || '';
      tmp.dataset.subcat        = d.subcat || '';
      tmp.dataset.block         = d.block || '';
      tmp.dataset.room          = d.room || '';
      tmp.dataset.gender        = d.gender || '';
      tmp.dataset.techname      = d.techname || '';
      tmp.dataset.techremark    = d.techremark || '';
      tmp.dataset.techcompleted = d.techcompleted || '';
      tmp.setAttribute('data-attachments', JSON.stringify(d.attachments || []));
      tmp.setAttribute('data-tech_attachments', JSON.stringify(d.tech_attachments || []));
      tmp.setAttribute('data-remarks', JSON.stringify(d.remarks || {}));
      openDetails(tmp);
    } else {
      alert('Could not load ticket details.');
    }
  }catch(e){
    console.error(e);
    alert('Failed to load ticket details.');
  }
}

</script>


<script>
window.MASTER_SUBS = <?= json_encode($MASTER_SUBS, JSON_UNESCAPED_UNICODE) ?>;

/* Slide panel */
const toggleBtn=document.getElementById('panelToggleBtn');

const panel=document.getElementById('slidePanel');
const overlay=document.getElementById('slideOverlay');
const closeBtn=document.getElementById('panelClose');
const links = Array.from(document.querySelectorAll('.slide-link'));
function openPanel(){ document.body.classList.add('slide-open'); panel.setAttribute('aria-hidden','false'); }
function closePanel(){ document.body.classList.remove('slide-open'); panel.setAttribute('aria-hidden','true'); }
toggleBtn?.addEventListener('click',openPanel);
closeBtn?.addEventListener('click',closePanel);
overlay?.addEventListener('click',closePanel);
document.addEventListener('click',(e)=>{ if (!document.body.classList.contains('slide-open')) return; if (!panel.contains(e.target) && e.target !== toggleBtn) closePanel(); }, true);
links.forEach(a=>a.addEventListener('click',()=>{ closePanel(); }));
document.addEventListener('keydown',(e)=>{ if(e.key==='Escape') closePanel(); });

/* Dependent Sub Category filter (Dashboard) */
const MASTER_SUBS = <?= json_encode($MASTER_SUBS, JSON_UNESCAPED_UNICODE) ?>;
function populateDbSubcats(cat, selected) {
  const subSel = document.getElementById('db_subcategory');
  if (!subSel) return;
  subSel.innerHTML = '';
  const optAll = document.createElement('option');
  optAll.value = ''; optAll.textContent = 'All sub categories';
  subSel.appendChild(optAll);
  if (!cat || !MASTER_SUBS[cat]) { subSel.disabled = true; return; }
  MASTER_SUBS[cat].forEach(label => {
    const o = document.createElement('option');
    o.value = label; o.textContent = label;
    if (selected && selected === label) o.selected = true;
    subSel.appendChild(o);
  });
  subSel.disabled = false;
}

  // --- Edit Category Modal logic ---
  const CAT_MODAL_ID = 'catModal';
  const CAT_FORM_ID  = 'catForm';

  // open with current values
  function openCatModal(ticketId, currentCat, currentSub){
    const modal = document.getElementById(CAT_MODAL_ID);
    const f = document.getElementById(CAT_FORM_ID);
    if(!modal || !f) return;

    // set ticket id
    document.getElementById('cat_ticket_id').value = ticketId;

    // set category
    const catSel = document.getElementById('cat_category');
    const subSel = document.getElementById('cat_subcategory');

    // set current category in select
    Array.from(catSel.options).forEach(o => { o.selected = (o.value === currentCat); });

    // populate subcats for that category
    fillCatSubOptions(subSel, currentCat, currentSub);

    // show
    modal.style.display = 'flex';
  }

  function closeCatModal(){
    const modal = document.getElementById(CAT_MODAL_ID);
    if (modal) modal.style.display = 'none';
  }

  // when category changes, refresh subcats
  function catSyncSubcats(){
    const catSel = document.getElementById('cat_category');
    const subSel = document.getElementById('cat_subcategory');
    fillCatSubOptions(subSel, catSel.value, '');
  }

function fillCatSubOptions(selectEl, category, selected){
  if (!selectEl) return;

  const canonKeys = Object.keys(window.MASTER_SUBS || {});

  const t = String(category || '').trim().toLowerCase();

  // exact (case-insensitive) first
  let key = canonKeys.find(k => k.toLowerCase() === t);

  // relaxed match (contains) as a fallback
  if (!key) key = canonKeys.find(k => t.includes(k.toLowerCase()));

  selectEl.innerHTML = '';
  const def = document.createElement('option');
  def.value = '';
  def.textContent = '(no sub-category)';
  selectEl.appendChild(def);

  const list = key ? (window.MASTER_SUBS[key] || []) : [];

  list.forEach(label => {
    const o = document.createElement('option');
    o.value = label;
    o.textContent = label;
    if (String(selected || '') === label) o.selected = true;
    selectEl.appendChild(o);
  });

  const hint = document.getElementById('cat_hint');
  if (hint) hint.textContent = list.length
    ? 'Pick an appropriate sub-category for the selected category.'
    : 'Choose a category to see its sub-categories.';
}



  // close on ESC or overlay click (like your other modals)
  document.addEventListener('keydown', e => { if (e.key === 'Escape') closeCatModal(); });
  document.addEventListener('click', e => {
    const modal = document.getElementById(CAT_MODAL_ID);
    if (!modal || modal.style.display === 'none') return;
    const sheet = modal.querySelector('.sheet');
    if (sheet && !sheet.contains(e.target) && e.target === modal) closeCatModal();
  });


/* Status badge helper */
function statusClass(s){
  const x = (s||'').toLowerCase();
  if (x==='pending') return 'badge status-pending';
  if (x==='in progress') return 'badge status-in-progress';
  if (x==='completed') return 'badge status-completed';
  if (x==='rejected') return 'badge status-rejected';
  return 'badge';
}

/* Details modal */
function openDetails(btn){
  const st = btn.dataset.status || '—';
  document.getElementById('modalTitle').textContent   = btn.dataset.title || 'Ticket';
  document.getElementById('modalCategory').textContent= btn.dataset.category || '—';
  document.getElementById('modalSubcat').textContent  = btn.dataset.subcat || '—';
  document.getElementById('modalSubmitted').textContent= btn.dataset.submitted || '—';
  document.getElementById('modalDescription').textContent= btn.dataset.description || '—';
  document.getElementById('modalStudent').textContent = btn.dataset.student || '—';
  document.getElementById('modalPhone').textContent   = btn.dataset.phone || '—';
  document.getElementById('modalBR').textContent      = (btn.dataset.block||'—') + ' / ' + (btn.dataset.room||'—');
  // 🔥 New fields for the new layout
document.getElementById('modalGender').textContent = btn.dataset.gender || '—';
document.getElementById('modalStatusText').textContent = btn.dataset.status || '—';
document.getElementById('modalDescriptionShort').textContent = btn.dataset.description || '—';

  const ul   = document.getElementById('modalAttachmentsList');
  const gal  = document.getElementById('modalGallery');
  const none = document.getElementById('modalNoAtt');
  // Technician info (name, remark, completed time)
document.getElementById('modalTechName').textContent =
  btn.dataset.techname || '—';
document.getElementById('modalTechRemark').textContent =
  btn.dataset.techremark || '—';
// show completed time only if the ticket is actually Completed
const statusNow = (btn.dataset.status || '').toLowerCase();
const compEl = document.getElementById('modalTechCompleted');
const compLbl = compEl ? compEl.previousElementSibling : null; // the "Completed At" label
const compVal = (btn.dataset.techcompleted || '').trim();

const showCompleted = (statusNow === 'completed' && compVal);
compEl.textContent = showCompleted ? compVal : '—';

// show/hide row
if (compEl) compEl.style.display = showCompleted ? '' : 'none';
if (compLbl) compLbl.style.display = showCompleted ? '' : 'none';


  ul.innerHTML = ''; gal.innerHTML = ''; none.style.display = 'none';


  
let arr = [];
try { arr = JSON.parse(btn.dataset.attachments || '[]'); } catch(e){ arr=[]; }
if (!arr.length){
  none.style.display = 'block';
} else {
  arr.forEach(o => {
    const path = (o.path||'').trim();
    const name = path.split('/').pop();
    const size = Number(o.size||0);
    const mime = (o.mime||'').toLowerCase();
    const li = document.createElement('li');
    const a  = document.createElement('a');
    a.href = path; a.target='_blank'; a.rel='noopener';
    a.textContent = name || 'Attachment';
    li.appendChild(a);
    if (size){
      const s = document.createElement('span');
      s.className='tiny';
      s.textContent = ' ('+(size/1024/1024).toFixed(2)+' MB)';
      li.appendChild(s);
    }
    ul.appendChild(li);
    const isImg = mime.startsWith('image/') || /\.(jpg|jpeg|png|gif|webp)$/i.test(path);
    if (isImg){
      const wrap = document.createElement('a');
      wrap.href = path; wrap.target='_blank'; wrap.rel='noopener';
      const img = document.createElement('img');
      img.src = path;
      img.alt = name || 'attachment';
      wrap.appendChild(img);
      gal.appendChild(wrap);
    }
  }); // <-- End of forEach
} // <-- End of if/else block

// ✅ Technician proof section (optional for consistency)
const techWrap = document.getElementById('modalTechProof');
const techNone = document.getElementById('modalNoProof');
techWrap.innerHTML = '';
techNone.style.display = 'none';

try {
  const techArr = JSON.parse(btn.getAttribute('data-tech_attachments') || '[]');

  if (!techArr.length) {
    techNone.style.display = 'block';
  } else {
 techArr.forEach(o => {
  const path = o.path || '';
  const a = document.createElement('a');
  a.href = path;
  a.target = '_blank';
  a.rel = 'noopener';

  const img = document.createElement('img');
  img.src = path;
  img.alt = 'proof';
  img.style.objectFit = 'cover';
  a.appendChild(img);

  techWrap.appendChild(a);
});

  }
} catch (e) {
  techNone.style.display = 'block';
}



  

  let R={}; try { R = JSON.parse(btn.dataset.remarks || '{}'); } catch(e){}
  document.getElementById('mrPending').textContent   = (R.pending||'').trim()      || '—';
  document.getElementById('mrProgress').textContent  = (R.in_progress||'').trim()  || '—';
  document.getElementById('mrCompleted').textContent = (R.completed||'').trim()    || '—';
  document.getElementById('mrRejected').textContent  = (R.rejected||'').trim()     || '—';

  const sb = document.getElementById('modalStatusBadge');
  sb.textContent = st; sb.className = statusClass(st);

  document.getElementById('detailsModal').style.display='flex';
}
function closeDetails(){ document.getElementById('detailsModal').style.display='none'; }

/* Remark modal */
function openRemark(id,currentStatus,prevPend,prevProg,prevComp,prevRej){
  document.getElementById('remarkTicketId').value=id;
  document.getElementById('remarkCurrentStatus').value=currentStatus||'';
  const badge=document.getElementById('remarkStatusBadge');
  badge.textContent = currentStatus||'—';
  badge.className = statusClass(currentStatus);

  document.getElementById('rPrevPending').textContent  = prevPend || '—';
  document.getElementById('rPrevProgress').textContent = prevProg || '—';
  document.getElementById('rPrevCompleted').textContent= prevComp || '—';
  document.getElementById('rPrevRejected').textContent = prevRej  || '—';
  let prefill=''; const s=(currentStatus||'').toLowerCase();
  if (s==='pending') prefill = prevPend||''; else if (s==='in progress') prefill = prevProg||''; else if (s==='completed') prefill = prevComp||''; else if (s==='rejected') prefill = prevRej||''; 
  document.getElementById('remarkTextarea').value = prefill;
  document.getElementById('remarkModal').style.display='flex';
}
function closeRemark(){ document.getElementById('remarkModal').style.display='none'; }

/* Staff profile modal: fetch and fill from admin_technician_profile.php */
async function openStaffProfile(el){
  const id = el.dataset.staffid;
  try{
    const res = await fetch(`admin_technician_profile.php?json=1&id=${encodeURIComponent(id)}`, {credentials:'same-origin'});
    if(!res.ok) throw new Error('HTTP '+res.status);
    const d = await res.json();

    // Basic identity
    const avatar = (d.avatar_url || d.avatar || 'assets/avatar-fallback.png');
    document.getElementById('staffAvatar').src = avatar;
    document.getElementById('staffName').textContent = d.name || '—';

    // Chips
    const chips = [];
    if (d.role) chips.push(`<span class="chip">${String(d.role).toUpperCase()}</span>`);
    if (d.specialty) chips.push(`<span class="chip">${d.specialty}</span>`);
    if (d.assigned_block || d.block) chips.push(`<span class="chip">Blok ${(d.assigned_block||d.block)}</span>`);
    if (d.gender) chips.push(`<span class="chip">${d.gender.charAt(0).toUpperCase()+d.gender.slice(1)}</span>`);
    document.getElementById('staffChips').innerHTML = chips.join('');

    // Contact/assignment
    document.getElementById('staffEmail').textContent   = d.email || '—';
    document.getElementById('staffPhone').textContent   = d.phone || '—';
    document.getElementById('staffSpec').textContent    = d.specialty || '—';

    // NEW: Combined Assigned Gender Block => "Block C-Male"
    const block  = (d.assigned_block || d.block || '').toString().trim();
    const gender = (d.gender || '').toString().trim();
    const genderPretty = gender ? (gender.charAt(0).toUpperCase() + gender.slice(1)) : '';
    let combo = '—';
    if (block || genderPretty) {
      const b = block ? `Block ${block}` : '';
      combo = [b, genderPretty].filter(Boolean).join('-');
    }
    document.getElementById('staffAssignCombo').textContent = combo || '—';

    document.getElementById('staffRole').textContent    = d.role ? (d.role.charAt(0).toUpperCase()+d.role.slice(1)) : '—';

    // Stats
    const p = Number(d.stats?.pending || 0);
    const ip = Number(d.stats?.in_progress || 0);
    const c = Number(d.stats?.completed || 0);
    document.getElementById('stPending').textContent  = p;
    document.getElementById('stProgress').textContent = ip;
    document.getElementById('stCompleted').textContent= c;

    const total = p + ip + c;
    const perf = total > 0 ? Math.round((c/total)*100) : 0;
    document.getElementById('staffPerf').textContent = perf + '%';
    document.getElementById('perfBar').style.width = Math.min(100, Math.max(0, perf)) + '%';

    document.getElementById('staffModal').style.display='flex';
  }catch(e){
    alert('Could not load technician profile. Please ensure admin_technician_profile.php returns JSON.');
  }
}
function closeStaff(){ document.getElementById('staffModal').style.display='none'; }

/* Charts init */
const catLabels = <?= json_encode($chartCategoryLabels) ?>;
const catCounts = <?= json_encode($chartCategoryCounts) ?>;
const blockLabels = <?= json_encode($blkLabels) ?>;
const maleData = <?= json_encode($maleCounts) ?>;
const femaleData = <?= json_encode($femaleCounts) ?>;
const maleCatLabels = <?= json_encode($maleCatLabels) ?>;
const maleCatCounts = <?= json_encode($maleCatCounts) ?>;
const femaleCatLabels = <?= json_encode($femaleCatLabels) ?>;
const femaleCatCounts = <?= json_encode($femaleCatCounts) ?>;
const statusLabels = ['Pending','In Progress','Completed','Rejected'];
const statusCounts = [<?= $kpiPending ?>, <?= $kpiInProgress ?>, <?= $kpiCompleted ?>, <?= $kpiRejected ?>];
const statusColors = ['#f59e0b', '#2563eb', '#22c55e', '#ef4444'];
const categoryPalette = ['#6366f1','#a855f7','#06b6d4','#14b8a6','#8b5cf6','#f472b6','#22d3ee','#c084fc','#0ea5e9','#4ade80'];
function buildColorArray(len){ const out=[]; for(let i=0;i<len;i++){ out.push(categoryPalette[i % categoryPalette.length]); } return out; }

/* Sub-category by category data */
const subDataByCategory = <?= json_encode($subDataByCategory, JSON_UNESCAPED_UNICODE|JSON_NUMERIC_CHECK) ?>;
let chartSubByCat;

function setSubcatView(cat){
  const bElec=document.getElementById('btnScElec');
  const bAwam=document.getElementById('btnScAwam');
  const bMek=document.getElementById('btnScMek');
  [bElec,bAwam,bMek].forEach(b=>{ if(b) b.style.background='#94a3b8'; });
  if (cat==='KEJURUTERAAN ELEKTRIK' && bElec) bElec.style.background='#2563eb';
  if (cat==='KEJURUTERAAN AWAM' && bAwam) bAwam.style.background='#2563eb';
  if (cat==='KEJURUTERAAN MEKANIKAL' && bMek) bMek.style.background='#2563eb';

  const d = subDataByCategory[cat] || {labels:[],counts:[]};
  const ctx = document.getElementById('chartSubCategoryByCat')?.getContext('2d');
  if (!ctx) return;
  if (chartSubByCat) chartSubByCat.destroy();
  chartSubByCat = new Chart(ctx, {
    type: 'bar',
    data: { labels: d.labels, datasets: [{ label: '', data: d.counts, backgroundColor: buildColorArray(d.labels.length) }] },
    options: { indexAxis:'y', responsive:true, maintainAspectRatio:false, plugins:{ legend:{ display:false }, title:{ display:true, text: cat } }, scales:{ x:{ beginAtZero:true, ticks:{ precision:0 } } } }
  });
}

document.addEventListener('DOMContentLoaded', ()=>{
  const params = new URLSearchParams(location.search);
  const jumpId = params.get('ticket_id');
  if (jumpId) {
    const row = document.getElementById('ticket-' + jumpId);
    if (row) {
      row.scrollIntoView({ behavior: 'smooth', block: 'center' });
      row.style.transition = 'background 0.6s ease';
      const oldBg = row.style.backgroundColor;
      row.style.backgroundColor = '#fff7d6';
      setTimeout(() => { row.style.backgroundColor = oldBg || ''; }, 1600);
    }
  }

  const c1=document.getElementById('chartCategory')?.getContext('2d');
  if(c1 && catLabels.length){ new Chart(c1,{ type:'pie', data:{ labels:catLabels, datasets:[{ data:catCounts, backgroundColor: buildColorArray(catLabels.length) }] }, options:{ maintainAspectRatio:false, plugins:{ legend:{ position:'bottom' } } } });}

  const catSel = document.getElementById('db_category');
  const subSel = document.getElementById('db_subcategory');
  if (catSel && subSel) {
    const currentCat = catSel.value || '';
    const currentSub = subSel.value || '';
    populateDbSubcats(currentCat, currentSub);
    catSel.addEventListener('change', () => { populateDbSubcats(catSel.value, ''); });
  }
 
  const c2=document.getElementById('chartBlockGender')?.getContext('2d');
  if(c2 && blockLabels.length){ new Chart(c2,{ type:'bar', data:{ labels:blockLabels, datasets:[{label:'Male',data:maleData,backgroundColor:'#2563eb'},{label:'Female',data:femaleData,backgroundColor:'#ef4444'}]}, options:{ responsive:true, scales:{ y:{ beginAtZero:true } }, plugins:{ legend:{ position:'bottom' } } } });}

  const cs = document.getElementById('chartStatus')?.getContext('2d');
  if (cs) { new Chart(cs, { type:'bar', data:{ labels:statusLabels, datasets:[{ label:'Tickets', data:statusCounts, backgroundColor: statusColors, borderColor: statusColors, borderWidth:1 }] }, options:{ responsive:true, maintainAspectRatio:false, scales:{ y:{ beginAtZero:true, ticks:{precision:0} } }, plugins:{ legend:{ display:false } } } }); }

  setSubcatView('KEJURUTERAAN ELEKTRIK');

  // tech creation: gender -> blocks
  const g=document.getElementById('techGender'), b=document.getElementById('techBlock');
  function fillBlocks(){ const v=g.value; let opts=[]; if(v==='male'){ opts=['A','B','C','D','E','F']; } else if(v==='female'){ opts=['A','B']; } b.innerHTML='<option value="" disabled selected>Assigned Block…</option>'; opts.forEach(x=>{ const o=document.createElement('option'); o.value=x; o.textContent=x; b.appendChild(o); });}
  g?.addEventListener('change', fillBlocks);

  // toasts auto-hide
  document.querySelectorAll('.toast').forEach(t=>{ t.querySelector('.close-btn')?.addEventListener('click',()=>{ t.classList.add('hide'); setTimeout(()=>t.remove(),500); }); setTimeout(()=>{ t.classList.add('hide'); setTimeout(()=>t.remove(),500); }, 4000); });
});

/* Bulk helpers */
// Tickets
const ticketsThCb = document.getElementById('tickets-th-cb');
const ticketsSelAll = document.getElementById('tickets-select-all');
const ticketsRowCbs = () => Array.from(document.querySelectorAll('.tickets-row-cb'));
[ticketsThCb, ticketsSelAll].forEach(master => { if (master) master.addEventListener('change', e => ticketsRowCbs().forEach(cb => cb.checked = e.target.checked));});
const ticketsAction = document.getElementById('tickets-bulk-action');
const ticketsTech = document.getElementById('tickets-tech');
if (ticketsAction) ticketsAction.addEventListener('change', ()=>{ ticketsTech.style.display = (ticketsAction.value === 'assign') ? 'block' : 'none'; });
const ticketsForm = document.getElementById('ticketsBulkForm');
if (ticketsForm) ticketsForm.addEventListener('submit', (e) => {
  e.preventDefault();
  const ids = ticketsRowCbs().filter(cb => cb.checked).map(cb => cb.value);
  if (!ids.length) return alert('Please select at least one ticket.');
  const action = ticketsAction?.value || '';
  if (!action) return alert('Choose a bulk action.');
  if (action === 'assign' && !ticketsTech.value) return alert('Select a technician.');

  if (action === 'assign') {
    const techOpt = ticketsTech.options[ticketsTech.selectedIndex];
    const spec   = (techOpt?.dataset?.specialty || '').trim().toLowerCase();
    const tblock = (techOpt?.dataset?.block || '').trim().toLowerCase();
    const tgender= (techOpt?.dataset?.gender || '').trim().toLowerCase();
    for (const id of ids){
      const cb = document.querySelector(`.tickets-row-cb[value="${id}"]`);
      const cat = (cb?.dataset?.category || '').trim().toLowerCase();
      const sblk= (cb?.dataset?.block || '').trim().toLowerCase();
      const sgen= (cb?.dataset?.gender || '').trim().toLowerCase();
      if (cat !== spec || sblk !== tblock || sgen !== tgender){
        alert('Bulk assign blocked: each selected ticket must match technician’s gender, block, and category.');
        return;
      }
    }
  }
  const f = document.createElement('form'); f.method='POST'; f.action = action === 'delete' ? 'admin_bulk_delete_tickets.php' : 'admin_bulk_assign_tickets.php';
  ids.forEach(v => { const i=document.createElement('input'); i.type='hidden'; i.name='ids[]'; i.value=v; f.appendChild(i); });
  if (action === 'assign') { const t=document.createElement('input'); t.type='hidden'; t.name='technician_id'; t.value=ticketsTech.value; f.appendChild(t); }
  document.body.appendChild(f); f.submit();
});

// Staff bulk soft delete
const staffThCb = document.getElementById('staff-th-cb'), staffSelAll = document.getElementById('staff-select-all');
const staffRowCbs = () => Array.from(document.querySelectorAll('.staff-row-cb'));
[staffThCb, staffSelAll].forEach(master => { if (master) master.addEventListener('change', e => staffRowCbs().forEach(cb => cb.checked = e.target.checked)); });
const staffForm = document.getElementById('staffBulkForm');
if (staffForm) staffForm.addEventListener('submit', (e)=>{
  e.preventDefault();
  const ids = staffRowCbs().filter(cb=>cb.checked).map(cb=>cb.value);
  if (!ids.length) return alert('Please select at least one staff.');
  const f=document.createElement('form'); f.method='POST'; f.action='admin_bulk_delete_staff.php';
  ids.forEach(v=>{ const i=document.createElement('input'); i.type='hidden'; i.name='ids[]'; i.value=v; f.appendChild(i); });
  document.body.appendChild(f); f.submit();
});

// History bulk helpers
['tickets','staff'].forEach(kind=>{
  const th = document.getElementById(`hist-${kind}-th-cb`);
  const sa = document.getElementById(`hist-${kind}-select-all`);
  const rows = () => Array.from(document.querySelectorAll(`.hist-${kind}-row-cb`));
  [th, sa].forEach(m => { if (m) m.addEventListener('change', e => rows().forEach(cb => cb.checked = e.target.checked)); });
  const form = document.getElementById(kind==='tickets'?'histTicketsForm':'histStaffForm');
  const actionSel = document.getElementById(kind==='tickets'?'hist-tickets-action':'hist-staff-action');
  if (form) form.addEventListener('submit',(e)=>{
    e.preventDefault();
    const ids = rows().filter(cb=>cb.checked).map(cb=>cb.value);
    if (!ids.length) return alert('Select at least one deleted '+kind+'.');
    const action = actionSel.value;
    const f=document.createElement('form'); f.method='POST';
    f.action = (action==='restore')
      ? (kind==='tickets' ? 'admin_bulk_restore_tickets.php' : 'admin_bulk_restore_staff.php')
      : (kind==='tickets' ? 'admin_bulk_purge_tickets.php'   : 'admin_bulk_purge_staff.php');
    ids.forEach(v=>{ const i=document.createElement('input'); i.type='hidden'; i.name='ids[]'; i.value=v; f.appendChild(i); });
    document.body.appendChild(f); f.submit();
  });
});

</script>

<!-- Student Profile Modal -->
 <div id="studentModal" class="modal staff-modal student-modal" style="display:none" role="dialog" aria-modal="true">
  <div class="sheet">
    <div class="cover">
      <img id="studentAvatar" class="avatar" src="assets/avatar-fallback.png" alt="avatar">
    </div>
    <div class="bd">
      <div class="hd-row">
        <div style="margin-left:148px">
          <h2 id="studentName" class="name">—</h2>
          <div id="studentChips" class="chips"></div>
        </div>
      </div>

      <div class="two-col">
        <div class="card">
          <h3 style="margin:0 0 8px">Student Info</h3>
          <div class="kv">
            <div class="k">Student ID</div><div class="v" id="stStudentId">—</div>
            <div class="k">Email</div><div class="v" id="stEmail">—</div>
            <div class="k">Phone</div><div class="v" id="stPhone">—</div>
            <div class="k">Block / Room</div><div class="v" id="stBlockRoom">—</div>
            <div class="k">Total Tickets</div><div class="v" id="stTotal">0</div>
            <div class="k">Warnings</div><div class="v" id="stWarnings">0</div>
            <div class="k">Ban Status</div>
            <div class="v">
              <span id="stBanBadge" class="badge">—</span>
              <div style="margin-top:8px;display:flex;gap:8px;flex-wrap:wrap">
                <form id="banForm" action="admin_ban_student.php" method="post" onsubmit="return confirm('Ban this student?');">
                    <?= csrf_field() ?>
                  <input type="hidden" name="student_id" id="banStudentId">
                  <input type="hidden" name="redirect" value="<?= e($qsTickets) ?>">
                  <button id="banBtn" type="submit" class="btn" style="background:#ef4444">Ban</button>
                </form>
                <form id="unbanForm" action="admin_unban_student.php" method="post" onsubmit="return confirm('Unban this student?');">
                    <?= csrf_field() ?>
                  <input type="hidden" name="student_id" id="unbanStudentId">
                  <input type="hidden" name="redirect" value="<?= e($qsTickets) ?>">
                  <button id="unbanBtn" type="submit" class="btn">Unban</button>
                </form>
              </div>
              <div id="banHint" class="tiny" style="margin-top:6px;display:none">Ban is enabled after 3 warnings.</div>
            </div>
          </div>
        </div>

        <div class="card">
          <h3 style="margin:0 0 8px">Tickets Overview</h3>

          <!-- 2×2 grid: (row1) Pending | In Progress ; (row2) Completed | Rejected -->
          <div class="student-stats">
            <div class="stat">
              <div class="n" id="stPend">0</div>
              <div class="tiny">Pending</div>
            </div>
            <div class="stat">
              <div class="n" id="stProg">0</div>
              <div class="tiny">In Progress</div>
            </div>
            <div class="stat">
              <div class="n" id="stComp">0</div>
              <div class="tiny">Completed</div>
            </div>
            <div class="stat">
              <div class="n" id="stRej">0</div>
              <div class="tiny">Rejected</div>
            </div>
          </div>

          <!-- Inline list goes here -->
          <div id="rejInline" class="tiny" style="margin-top:8px"></div>

          <button type="button" class="btn btn-ghost" style="margin-top:8px"
                  onclick="openRejectedTickets()">View Rejected Tickets</button>
        </div>


      </div>

      <div class="card" style="margin-top:12px">
        <h3 style="margin:0 0 8px">Recent Warnings</h3>
        <div id="stWarningsList" class="tiny">—</div>
      </div>
    </div>

    <div class="ft">
      <button class="btn" onclick="closeStudent()">Close</button>
    </div>
  </div>
</div>

<!-- Rejected Tickets Modal -->
<div id="studentRejModal" class="modal" style="display:none" role="dialog" aria-modal="true">
  <div class="sheet">
    <div class="hd">
      <h2>Rejected Tickets</h2>
      <button class="closex" onclick="closeStudentRej()" aria-label="Close">&times;</button>
    </div>
    <div class="bd">
      <div id="rejList">Loading…</div>
    </div>
    <div class="ft">
      <button class="btn btn-ghost" onclick="closeStudentRej()">Close</button>
    </div>
  </div>
</div>
<!-- Edit Category Modal -->
<div id="catModal" class="modal" role="dialog" aria-modal="true" style="display:none">
  <div class="sheet modal-card">
    <div class="hd">
      <h2 style="margin:0">Edit Category</h2>
    </div>

    <div class="bd">
      <form id="catForm" action="admin_update_category.php" method="post" onsubmit="return validateCatForm()">
          <?= csrf_field() ?>
        <input type="hidden" name="ticket_id" id="cat_ticket_id">
        <input type="hidden" name="redirect" value="<?= e($qsTickets) ?>">

        <div class="row cols-2" style="margin-top:4px">
          <div>
            <div class="tiny" style="margin-bottom:6px">Category</div>
            <select id="cat_category" name="new_category" class="prettySel" required
                    onchange="catSyncSubcats()">
              <option value="" disabled selected>Choose category…</option>
              <?php foreach ($FIXED_CATS as $c): ?>
                <option value="<?= e($c) ?>"><?= e($c) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div>
            <div class="tiny" style="margin-bottom:6px">Sub-category</div>
            <select id="cat_subcategory" name="new_subcategory" class="prettySel">
              <!-- options filled by JS -->
            </select>
          </div>
        </div>

        <div class="tiny" id="cat_hint" style="margin-top:10px;color:#64748b">
          Choose a category to see its sub-categories.
        </div>
      </form>
    </div>

    <div class="ft">
      <button class="btn btn-ghost" type="button" onclick="closeCatModal()">Cancel</button>
      <button class="btn" type="submit" form="catForm">Save</button>
    </div>
  </div>
</div>

</body>
</html>
