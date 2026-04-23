<?php
require_once 'config.php';
session_start();

/* Allow admin OR boss_ups */
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin','boss_ups'], true)){
  http_response_code(403); exit('Forbidden');
}

/* ---- detect subcategory column (sub_category or subcategory) ---- */
$SUBCOL_DB = ''; $hasSubCategory = false;
foreach (['sub_category','subcategory'] as $try) {
  $res = $conn->query("SHOW COLUMNS FROM complaints LIKE '".$conn->real_escape_string($try)."'");
  if ($res && $res->num_rows > 0) { $SUBCOL_DB = $try; $hasSubCategory = true; }
  if ($res) $res->close();
}

/* ---- headers ---- */
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="tickets.csv"');

/* Write UTF-8 BOM so Excel renders non-ASCII correctly */
$out = fopen('php://output','w');
fwrite($out, "\xEF\xBB\xBF");

/* ---- filters (mirror Ticket Management page) ---- */
$status    = $_GET['status']    ?? '';
$category  = $_GET['category']  ?? '';
$block     = $_GET['block']     ?? '';
$gender    = strtolower(trim($_GET['gender'] ?? ''));
$room      = trim($_GET['room'] ?? '');
$tech      = $_GET['technician'] ?? '';
$from      = $_GET['from'] ?? '';
$to        = $_GET['to']   ?? '';

$cond = ["c.is_deleted=0"];
if ($status)   $cond[] = "c.status='".$conn->real_escape_string($status)."'";
if ($category) $cond[] = "c.category='".$conn->real_escape_string($category)."'";
if ($block)    $cond[] = "p.block='".$conn->real_escape_string($block)."'";
if ($gender==='male' || $gender==='female') $cond[] = "p.gender='".$conn->real_escape_string($gender)."'";
if ($room!=='') $cond[] = "p.room_number LIKE '%".$conn->real_escape_string($room)."%'";
if ($tech!=='') $cond[] = "c.assigned_to=".(int)$tech;
if ($from && $to) $cond[] = "DATE(c.created_at) BETWEEN '".$conn->real_escape_string($from)."' AND '".$conn->real_escape_string($to)."'";
$whereSql = implode(" AND ", $cond);

/* ---- select list (include subcategory + phone + attachments) ---- */
$selectSub = $hasSubCategory ? ", c.`$SUBCOL_DB` AS subcategory" : ", '' AS subcategory";

$sql = "
  SELECT
    c.id,
    c.title,
    c.category
    $selectSub,
    c.status,
    c.created_at,
    p.name       AS student,
    p.student_id AS student_id,
    p.gender     AS gender,
    p.phone      AS phone,
    p.block      AS block,
    p.room_number AS room_number,
    t.name       AS technician,
    (
      SELECT GROUP_CONCAT(a.file_path ORDER BY a.id SEPARATOR '; ')
      FROM complaint_attachments a
      WHERE a.complaint_id = c.id
    ) AS attachments
  FROM complaints c
  JOIN profile p ON p.student_id=c.student_id
  LEFT JOIN profile t ON t.id=c.assigned_to
  WHERE $whereSql
  ORDER BY c.id DESC
";

$res = $conn->query($sql);

/* ---- header row ---- */
fputcsv($out, [
  'ID','Title','Category','Sub-Category','Status','Created At',
  'Student','Student ID','Gender','Phone','Block','Room','Technician','Attachments'
]);

/* ---- rows ---- */
while($r = $res->fetch_assoc()){
  fputcsv($out, [
    $r['id'],
    $r['title'],
    $r['category'],
    $r['subcategory'] ?? '',
    $r['status'],
    $r['created_at'],
    $r['student'],
    $r['student_id'],
    ucfirst($r['gender'] ?? ''),
    $r['phone'] ?? '',
    $r['block'],
    $r['room_number'],
    $r['technician'],
    $r['attachments'] ?? ''
  ]);
}
fclose($out);
