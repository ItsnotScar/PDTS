<?php
/* export_dashboard_csv.php */
session_start();
require_once 'config.php';

/* Allow admin OR boss_ups */
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin','boss_ups'], true)) {
  header("Location: ./index.php"); exit();
}

/* Fixed categories & subcategory detection (same logic as admin_page.php) */
$FIXED_CATS = ['KEJURUTERAAN AWAM','KEJURUTERAAN ELEKTRIK','KEJURUTERAAN MEKANIKAL'];
$SUBCOL_DB = ''; $hasSubCategory = false;
foreach (['sub_category','subcategory'] as $try) {
  $res = $conn->query("SHOW COLUMNS FROM complaints LIKE '".$conn->real_escape_string($try)."'");
  if ($res && $res->num_rows > 0) { $SUBCOL_DB = $try; $hasSubCategory = true; }
  if ($res) $res->close();
}

/* Read filters (mirrors dashboard) */
$dbBlock     = trim($_GET['db_block']  ?? '');
$dbGender    = strtolower(trim($_GET['db_gender'] ?? ''));
$dbStatus    = trim($_GET['db_status'] ?? '');
$dbCategory  = trim($_GET['db_category'] ?? '');
$dbSubCat    = trim($_GET['db_subcategory'] ?? '');
$dbFrom      = trim($_GET['db_from']   ?? '');
$dbTo        = trim($_GET['db_to']     ?? '');

/* Build WHERE and params */
$w=["c.is_deleted=0"]; $types=''; $params=[];
if ($dbBlock!==''){ $w[]="p.block=?"; $types.='s'; $params[]=$dbBlock; }
if ($dbGender==='male' || $dbGender==='female'){ $w[]="p.gender=?"; $types.='s'; $params[]=$dbGender; }
if ($dbStatus!==''){ $w[]="c.status=?"; $types.='s'; $params[]=$dbStatus; }
if ($dbCategory!==''){ $w[]="c.category=?"; $types.='s'; $params[]=$dbCategory; }
if ($hasSubCategory && $dbSubCat!==''){ $w[]="c.`$SUBCOL_DB`=?"; $types.='s'; $params[]=$dbSubCat; }
if ($dbFrom!==''){ $w[]="c.created_at>=?"; $types.='s'; $params[]=$dbFrom.' 00:00:00'; }
if ($dbTo  !==''){ $w[]="c.created_at<=?"; $types.='s'; $params[]=$dbTo.' 23:59:59'; }
$WS = implode(' AND ',$w);

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

/* Categories */
$catRows=[];
$stmt=$conn->prepare("
  SELECT c.category, COUNT(*) cnt
  FROM complaints c JOIN profile p ON p.student_id=c.student_id
  WHERE $WS
  GROUP BY c.category
  ORDER BY cnt DESC, c.category
");
if($types) $stmt->bind_param($types, ...$params);
$stmt->execute(); $rs=$stmt->get_result();
while($r=$rs->fetch_assoc()){ if (in_array($r['category'],$FIXED_CATS,true)) $catRows[]=$r; }
$stmt->close();

/* Blocks: male vs female */
$blkRows=[];
$stmt=$conn->prepare("
  SELECT p.block,
         SUM(CASE WHEN p.gender='male' THEN 1 ELSE 0 END) male_cnt,
         SUM(CASE WHEN p.gender='female' THEN 1 ELSE 0 END) female_cnt
  FROM complaints c JOIN profile p ON p.student_id=c.student_id
  WHERE $WS
  GROUP BY p.block
  ORDER BY p.block
");
if($types) $stmt->bind_param($types, ...$params);
$stmt->execute(); $rs=$stmt->get_result();
while($r=$rs->fetch_assoc()){ $blkRows[]=$r; }
$stmt->close();

/* Subcategories per category */
$subRows=[];
if ($hasSubCategory) {
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

/* CSV headers */
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="dashboard_export.csv"');

$out = fopen('php://output', 'w');
fputcsv($out, ['Dashboard Export (filtered)']);
fputcsv($out, ['Generated at', date('Y-m-d H:i:s')]);
fputcsv($out, []);

/* Filters recap */
fputcsv($out, ['Applied Filters']);
fputcsv($out, ['Block', $dbBlock ?: 'All']);
fputcsv($out, ['Gender', $dbGender ?: 'All']);
fputcsv($out, ['Status', $dbStatus ?: 'All']);
fputcsv($out, ['Category', $dbCategory ?: 'All']);
fputcsv($out, ['Sub Category', ($hasSubCategory ? ($dbSubCat ?: 'All') : '—')]);
fputcsv($out, ['From', $dbFrom ?: '—']);
fputcsv($out, ['To', $dbTo ?: '—']);
fputcsv($out, []);

/* KPI */
fputcsv($out, ['KPI']);
fputcsv($out, ['Total','Pending','In Progress','Completed','Rejected']);
fputcsv($out, [
  (int)($k['total_cnt'] ?? 0),
  (int)($k['pend_cnt']  ?? 0),
  (int)($k['prog_cnt']  ?? 0),
  (int)($k['comp_cnt']  ?? 0),
  (int)($k['rej_cnt']   ?? 0),
]);
fputcsv($out, []);

/* Categories table */
fputcsv($out, ['Tickets by Category']);
fputcsv($out, ['Category','Count']);
foreach($catRows as $r){ fputcsv($out, [$r['category'], (int)$r['cnt']]); }
fputcsv($out, []);

/* Blocks male/female */
fputcsv($out, ['Tickets by Block — Male vs Female']);
fputcsv($out, ['Block','Male','Female']);
foreach($blkRows as $r){ fputcsv($out, [$r['block'] ?: 'N/A', (int)$r['male_cnt'], (int)$r['female_cnt']]); }
fputcsv($out, []);

/* Subcategories */
if ($hasSubCategory){
  fputcsv($out, ['Sub-Categories (grouped by Category)']);
  fputcsv($out, ['Category','Sub Category','Count']);
  foreach($subRows as $r){ fputcsv($out, [$r['category'], $r['subcat'], (int)$r['cnt']]); }
}

fclose($out);
exit;
