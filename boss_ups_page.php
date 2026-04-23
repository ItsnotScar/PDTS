<?php
/* --- Session & guard ---------------------------------------------------- */
@ini_set('session.use_strict_mode', 1);
if (PHP_VERSION_ID >= 70300) {
  session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>isset($_SERVER['HTTPS']),'httponly'=>true,'samesite'=>'Lax']);
}
session_start();

require_once 'config.php';

/* Minimal CSRF helpers (inline, no extra file) */
if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(16)); }
function csrf_field(){ return '<input type="hidden" name="csrf" value="'.htmlspecialchars($_SESSION['csrf'],ENT_QUOTES).'">'; }
function csrf_validate(){ return isset($_POST['csrf'], $_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $_POST['csrf']); }


/* Guard: boss_ups only for this page */
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'boss_ups') {
  header("Location: ./index.php"); exit();
}
$isBoos = true; // this page is dedicated to boss_ups

/* No-cache */
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

/* Toast helpers (read-only page, but keep for UX messages) */
/* ─────────────────────────────────────────────────────────────────────
   POST: Create admin / Soft-delete admin
   ───────────────────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['action'])) {
  if (!csrf_validate()) {
    $_SESSION['error_message'] = 'Invalid request. Please try again.';
    header('Location: boss_ups_page.php?section=admins'); exit();
  }

  if ($_POST['action']==='create_admin') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = (string)($_POST['password'] ?? '');

    if ($name==='' || $email==='' || $password==='') {
      $_SESSION['error_message'] = 'Please fill in name, email and password.';
      header('Location: boss_ups_page.php?section=admins'); exit();
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
      $_SESSION['error_message'] = 'Invalid email address.';
      header('Location: boss_ups_page.php?section=admins'); exit();
    }

    // unique email?
    $stmt = $conn->prepare("SELECT id FROM profile WHERE email=? AND is_deleted=0 LIMIT 1");
    $stmt->bind_param('s',$email);
    $stmt->execute(); $stmt->store_result();
    if ($stmt->num_rows>0) {
      $stmt->close();
      $_SESSION['error_message'] = 'Email already exists.';
      header('Location: boss_ups_page.php?section=admins'); exit();
    }
    $stmt->close();

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $role = 'admin';
    $stmt = $conn->prepare("INSERT INTO profile (name,email,password,role,is_deleted) VALUES (?,?,?,?,0)");
    $stmt->bind_param('ssss',$name,$email,$hash,$role);
    if ($stmt->execute()) {
      $_SESSION['success_message'] = 'Admin account created.';
    } else {
      $_SESSION['error_message'] = 'Failed to create admin.';
    }
    $stmt->close();

    header('Location: boss_ups_page.php?section=admins'); exit();
  }

  if ($_POST['action']==='soft_delete_admin') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id<=0) {
      $_SESSION['error_message'] = 'Invalid admin id.';
      header('Location: boss_ups_page.php?section=admins'); exit();
    }
    // only admins here
    $conn->query("UPDATE profile SET is_deleted=1 WHERE id=$id AND role='admin'");
    if ($conn->affected_rows>0) {
      $_SESSION['success_message'] = 'Admin deleted (soft).';
    } else {
      $_SESSION['error_message'] = 'Delete failed.';
    }
    header('Location: boss_ups_page.php?section=admins'); exit();
  }
}

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

/* Sub-category column auto-detect */
$SUBCOL_DB = '';
$hasSubCategory = false;
foreach (['sub_category','subcategory'] as $try) {
  if ($res = $conn->query("SHOW COLUMNS FROM complaints LIKE '".$conn->real_escape_string($try)."'")) {
    if ($res->num_rows > 0) { $SUBCOL_DB = $try; $hasSubCategory = true; $res->close(); break; }
    $res->close();
  }
}

/* Technician remark column auto-detect (try a few common names) */
$TECH_REMARK_COL = '';
foreach (['technician_remark','remark_technician','tech_remark','technicianremarks','technician_remarks','remark'] as $tryTR) {
  $res = $conn->query("SHOW COLUMNS FROM complaints LIKE '".$conn->real_escape_string($tryTR)."'");
  if ($res && $res->num_rows > 0) { $TECH_REMARK_COL = $tryTR; $res->close(); break; }
  if ($res) $res->close();
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

/* Active section (no 'history' for boss) */
$validSections = ['dashboard','tickets','staff','admins'];
$section = $_GET['section'] ?? 'dashboard';
if (!in_array($section, $validSections, true)) $section = 'dashboard';

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
$selectTechRemark = $TECH_REMARK_COL ? ", c.`$TECH_REMARK_COL` AS tech_remark" : ", '' AS tech_remark";

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

/* Preload attachments for current page (read only) */
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
$ticketsBase = 'boss_ups_page.php'.($qsTickets?('?'.$qsTickets.'&'):'?');

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

/* ── Admins section (pagination + list) ─────────────────────────────── */
$adminsPerPage  = 10;
$adminsPage     = isset($_GET['admins_page']) ? max(1,(int)$_GET['admins_page']) : 1;
$adminsOffset   = ($adminsPage-1)*$adminsPerPage;

$totalAdmins = (int)$conn->query("SELECT COUNT(*) c FROM profile WHERE role='admin' AND is_deleted=0")->fetch_assoc()['c'];
$totalAdminsPages = max(1, (int)ceil($totalAdmins/$adminsPerPage));

$admins = $conn->query("
  SELECT id,name,email,role
  FROM profile
  WHERE role='admin' AND is_deleted=0
  ORDER BY name
  LIMIT $adminsPerPage OFFSET $adminsOffset
");

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Boss UPS Dashboard</title>
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
  <link rel="icon" type="image/png" href="assets/favicon.png" sizes="32x32">
  <link rel="icon" type="image/png" href="assets/favicon.png" sizes="16x16">
  <link rel="apple-touch-icon" href="assets/favicon.png">

  <link rel="stylesheet" href="admin.css">
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

  <style>
    html,body{height:100%;max-width:100%;overflow-x:hidden;}
    body.boss{margin:0; min-height:100vh; position:relative; display:block;}
    body.boss::before{content:""; position:fixed; inset:0; background:url('assets/dormitory.jpg') center/cover no-repeat; filter:blur(8px) brightness(.92) saturate(90%); transform:scale(1.06); z-index:-2;}
    body.boss::after{ content:""; position:fixed; inset:0; background:rgba(0,0,0,.40); z-index:-1; }

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
    .modal .hd .closex{ display:none !important; }
    .sheet .hd{display:flex;align-items:center;gap:10px;padding:14px 16px;border-bottom:1px solid #eef2f7;background:linear-gradient(180deg,#fff,#fafafa)}
    .sheet .bd{padding:16px}
    .sheet .ft{display:flex;gap:8px;justify-content:flex-end;padding:12px 16px;border-top:1px solid #eef2f7;background:#fafafa}
    .kv{display:grid;grid-template-columns:160px 1fr;gap:6px 12px}
    .kv .k, .kv .v { text-align:left; }
    .kv .k{color:#64748b;font-weight:700}
    .kv .v{color:#0f172a}
    .attach a{color:#2563eb;text-decoration:none;font-weight:700}
    .remark-box{border:1px solid #e5e7eb;border-radius:12px;padding:10px;background:#fbfdff}
    .remark-box h4{margin:0 0 8px;font-size:13px;color:#334155}
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
  </style>
</head>
<body class="boss">

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
      <div class="page-title">Welcome, Boss UPS</div>
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
    <a class="slide-link<?= $section==='dashboard'?' active':'' ?>" href="boss_ups_page.php?section=dashboard">📊 Dashboard</a>
    <a class="slide-link<?= $section==='tickets'?' active':'' ?>" href="boss_ups_page.php?section=tickets">🎫 Ticket Management</a>
    <a class="slide-link<?= $section==='staff'?' active':'' ?>" href="boss_ups_page.php?section=staff">👥 Staff</a>
    <!-- No History link for boss -->
    <a class="slide-link<?= $section==='admins'?' active':'' ?>" href="boss_ups_page.php?section=admins">🔐 Admin Accounts</a>
    <div class="slide-divider"></div>
    <form action="logout.php" method="post"><button type="submit" class="logout-btn-wide">⏻ Logout</button></form>
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
          <a class="btn btn-ghost" href="boss_ups_page.php?section=dashboard">Reset</a>
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
        <h3 style="margin:0;">Ticket Management </h3>
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
          <a class="btn btn-ghost" href="boss_ups_page.php?section=tickets">Reset</a>
        </div>
      </form>

      <!-- No bulk actions for boss (read only) -->

      <div class="table-shell" style="margin-top:10px;overflow-x:auto;">
        <table>
          <thead>
            <tr>
              <th>No.</th><th>Student</th><th>Gender</th><th>Block</th><th>Room</th>
              <th>Title</th><th>Category</th><th>Status</th><th>Assigned</th>
              <th class="col-sticky-right">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php
              $seq=$offset+1;
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
                <td><?= $seq++ ?></td>
                <td><?= e($row['name']) ?></td>
                <td><?= e(ucfirst($row['gender'])) ?></td>
                <td><?= e($row['block']) ?></td>
                <td><?= e($row['room_number']) ?></td>
                <td><?= e($row['title']) ?></td>

                <!-- Category: read only -->
                <td><?= e($row['category']) ?></td>

                <!-- Status: read only badge -->
                <td>
                  <?php $cls='status-'.strtolower(str_replace(' ','-',$row['status'])); ?>
                  <div><span class="badge <?= $cls ?>"><?= e($row['status']) ?></span></div>
                </td>

                <!-- Assigned technician (read only) -->
                <td>
                  <div><?= !empty($row['tech_name']) ? e($row['tech_name']) : '—' ?></div>
                  <?php if (!empty($row['tech_name'])): ?>
                    <div style="margin-top:6px;font-size:12px;color:#555;">
                      → <?= e($row['tech_name']) ?><?= $row['tech_spec']?' • '.e($row['tech_spec']):'' ?><?= $row['tech_block']?' • Blok '.e($row['tech_block']):'' ?><?= $row['tech_gender']?' • '.e(ucfirst($row['tech_gender'])):'' ?>
                    </div>
                  <?php endif; ?>
                </td>

                <!-- Actions: Details only -->
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
                      data-attachments='<?= $attsJson ?>'
                      data-remarks='<?= $remarksJson ?>'
                    >Details</button>
                    <!-- No Remark / Delete for boss-->
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
        <h3 style="margin:0;">Staff </h3>
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

      <!-- No create/bulk delete in boss view -->

      <div class="table-shell" style="margin-top:10px;overflow-x:auto;">
        <table>
          <thead><tr>
            <th>No.</th><th>Name</th><th>Email</th><th>Role</th><th>Assigned Gender Block</th><th>Specialty</th><th>Open</th><th>Completed</th>
          </tr></thead>
          <tbody>
            <?php $staff->data_seek(0); $i=1; while($row=$staff->fetch_assoc()):
              $sid=(int)$row['id']; $open=$staffStats[$sid]['open']??0; $done=$staffStats[$sid]['done']??0; ?>
              <tr>
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

  <?php if ($section==='admins'): ?>
  <div class="card">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
      <h3 style="margin:0;">Admin Accounts</h3>
    </div>

    <!-- Create Admin (simple form; matches your UI controls) -->
    <form method="post" style="margin-top:12px;">
      <input type="hidden" name="action" value="create_admin">
      <?= csrf_field() ?>
      <div class="filter-grid">
        <input type="text" name="name" placeholder="Full Name" required>
        <input type="email" name="email" placeholder="Email" required>
        <input type="password" name="password" placeholder="Password" required>
      </div>
      <div class="filter-actions" style="margin-top:10px">
        <button type="submit" class="btn">Create Admin</button>
      </div>
    </form>

    <!-- List -->
    <div class="table-shell" style="margin-top:14px;overflow-x:auto;">
      <table>
        <thead>
          <tr>
            <th>No.</th>
            <th>Name</th>
            <th>Email</th>
            <th>Role</th>
            <th class="col-sticky-right">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php $i=$adminsOffset+1; while($row=$admins->fetch_assoc()): ?>
            <tr>
              <td><?= $i++ ?></td>
              <td><?= e($row['name']) ?></td>
              <td><?= e($row['email']) ?></td>
              <td><?= e(ucfirst($row['role'])) ?></td>
              <td class="col-sticky-right">
                <form method="post" onsubmit="return confirm('Soft delete this admin?');" style="display:inline;">
                  <input type="hidden" name="action" value="soft_delete_admin">
                  <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                  <?= csrf_field() ?>
                  <button class="btn" style="background:#ef4444">Delete</button>
                </form>
              </td>
            </tr>
          <?php endwhile; ?>
        </tbody>
      </table>
    </div>

    <div class="pagination">
      <?php if ($adminsPage>1): ?>
        <a class="page-btn" href="?section=admins&admins_page=<?= $adminsPage-1 ?>">Prev</a>
      <?php endif; ?>
      <?php for($p=1;$p<=$totalAdminsPages;$p++): ?>
        <a class="page-btn <?= $p==$adminsPage?'active':'' ?>" href="?section=admins&admins_page=<?= $p ?>"><?= $p ?></a>
      <?php endfor; ?>
      <?php if ($adminsPage<$totalAdminsPages): ?>
        <a class="page-btn" href="?section=admins&admins_page=<?= $adminsPage+1 ?>">Next</a>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

</div>

<!-- Details Modal -->
<div id="detailsModal" class="modal" role="dialog" aria-modal="true">
  <div class="sheet">
    <div class="hd">
      <h2 id="modalTitle">Ticket</h2>
      <span id="modalStatusBadge" class="badge" style="margin-left:auto">—</span>
      <button class="closex" onclick="closeDetails()" aria-label="Close">&times;</button>
    </div>
    <div class="bd">
      <div class="kv">
        <div class="k">Category</div><div class="v"><span id="modalCategory">—</span></div>
        <div class="k">Sub Category</div><div class="v"><span id="modalSubcat">—</span></div>
        <div class="k">Submitted</div><div class="v"><span id="modalSubmitted">—</span></div>
        <div class="k">Student</div><div class="v"><span id="modalStudent">—</span></div>
        <div class="k">Phone</div><div class="v"><span id="modalPhone">—</span></div>
        <div class="k">Block/Room</div><div class="v"><span id="modalBR">—</span></div>

        <div class="k">Attachments</div>
        <div class="v">
          <ul id="modalAttachmentsList" class="att-list"></ul>
          <div id="modalGallery" class="gallery"></div>
          <div id="modalNoAtt" class="tiny" style="display:none">No attachments</div>
        </div>
      </div>

      <div style="margin-top:12px">
        <div class="remark-box">
          <h4>Description</h4>
          <div id="modalDescription">—</div>
        </div>
      </div>
      <div style="margin-top:12px" class="remark-box">
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

  const ul   = document.getElementById('modalAttachmentsList');
  const gal  = document.getElementById('modalGallery');
  const none = document.getElementById('modalNoAtt');
  ul.innerHTML = ''; gal.innerHTML = ''; none.style.display = 'none';

  let arr = [];
  try { arr = JSON.parse(btn.dataset.attachments || '[]'); } catch(e){ arr=[]; }
  if (!arr.length){ none.style.display = 'block';
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
      if (size){ const s = document.createElement('span'); s.className='tiny'; s.textContent = ' ('+(size/1024/1024).toFixed(2)+' MB)'; li.appendChild(s); }
      ul.appendChild(li);
      const isImg = mime.startsWith('image/') || /\.(jpg|jpeg|png|gif|webp)$/i.test(path);
      if (isImg){
        const wrap = document.createElement('a');
        wrap.href = path; wrap.target='_blank'; wrap.rel='noopener';
        const img = document.createElement('img');
        img.src = path; img.alt = name || 'attachment';
        wrap.appendChild(img);
        gal.appendChild(wrap);
      }
    });
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

/* Staff profile modal: fetch and fill from admin_technician_profile.php */
async function openStaffProfile(el){
  const id = el.dataset.staffid;
  try{
    const res = await fetch(`admin_technician_profile.php?json=1&id=${encodeURIComponent(id)}`, {credentials:'same-origin'});
    if(!res.ok) throw new Error('HTTP '+res.status);
    const d = await res.json();

    const avatar = (d.avatar_url || d.avatar || 'assets/avatar-fallback.png');
    document.getElementById('staffAvatar').src = avatar;
    document.getElementById('staffName').textContent = d.name || '—';

    const chips = [];
    if (d.role) chips.push(`<span class="chip">${String(d.role).toUpperCase()}</span>`);
    if (d.specialty) chips.push(`<span class="chip">${d.specialty}</span>`);
    if (d.assigned_block || d.block) chips.push(`<span class="chip">Blok ${(d.assigned_block||d.block)}</span>`);
    if (d.gender) chips.push(`<span class="chip">${d.gender.charAt(0).toUpperCase()+d.gender.slice(1)}</span>`);
    document.getElementById('staffChips').innerHTML = chips.join('');

    document.getElementById('staffEmail').textContent   = d.email || '—';
    document.getElementById('staffPhone').textContent   = d.phone || '—';
    document.getElementById('staffSpec').textContent    = d.specialty || '—';

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
    alert('Could not load technician profile. Please ensure admin_technician_profile.php returns JSON and allows boss_ups.');
  }
}
function closeStaff(){ document.getElementById('staffModal').style.display='none'; }

/* Charts init */
const catLabels = <?= json_encode($chartCategoryLabels) ?>;
const catCounts = <?= json_encode($chartCategoryCounts) ?>;
const blockLabels = <?= json_encode($blkLabels) ?>;
const maleData = <?= json_encode($maleCounts) ?>;
const femaleData = <?= json_encode($femaleCounts) ?>;
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

  // toasts auto-hide
  document.querySelectorAll('.toast').forEach(t=>{
    t.querySelector('.close-btn')?.addEventListener('click',()=>{ t.classList.add('hide'); setTimeout(()=>t.remove(),500); });
    setTimeout(()=>{ t.classList.add('hide'); setTimeout(()=>t.remove(),500); }, 4000);
  });
});
</script>
</body>
</html>
