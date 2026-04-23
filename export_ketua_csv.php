<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'ketua_penyelia') {
  header("Location: index.php"); exit;
}

// Read same filters as page
$block       = trim($_GET['block']       ?? '');
$student     = trim($_GET['student']     ?? '');
$cat         = trim($_GET['category']    ?? '');
$subcat      = trim($_GET['subcategory'] ?? '');
$status      = trim($_GET['status']      ?? '');
$gender      = strtolower(trim($_GET['gender'] ?? ''));
$start       = trim($_GET['start']       ?? '');
$end         = trim($_GET['end']         ?? '');

$where  = ["c.is_deleted=0", "p.role='student'"];
$types  = "";
$params = [];

if ($block !== '')                       { $where[]="p.block=?";           $types.="s"; $params[]=$block; }
if ($student !== '')                     { $where[]="p.student_id LIKE ?"; $types.="s"; $params[]="%{$student}%"; }
if ($cat !== '')                         { $where[]="c.category=?";        $types.="s"; $params[]=$cat; }
if ($subcat !== '')                      { $where[]="c.subcategory=?";     $types.="s"; $params[]=$subcat; }
if ($status !== '')                      { $where[]="c.status=?";          $types.="s"; $params[]=$status; }
if ($gender==='male'||$gender==='female'){ $where[]="p.gender=?";          $types.="s"; $params[]=$gender; }
if ($start !== '')                       { $where[]="c.created_at>=?";     $types.="s"; $params[]=$start.' 00:00:00'; }
if ($end !== '')                         { $where[]="c.created_at<=?";     $types.="s"; $params[]=$end.' 23:59:59'; }

$whereSql = implode(" AND ", $where);

// NOTE: no c.priority, no c.attachment
// Build attachments list from complaint_attachments via subquery (if exists)
$sql = "
  SELECT
    c.id,
    c.title,
    c.category,
    c.subcategory,
    c.status,
    c.created_at,
    p.name  AS student_name,
    p.student_id,
    p.gender,
    p.block,
    p.room_number,
    (
      SELECT GROUP_CONCAT(a.file_path ORDER BY a.id SEPARATOR '; ')
      FROM complaint_attachments a
      WHERE a.complaint_id = c.id
    ) AS attachments
  FROM complaints c
  JOIN profile p ON p.student_id=c.student_id
  WHERE {$whereSql}
  ORDER BY c.created_at DESC, c.id DESC
";
$stmt = $conn->prepare($sql);
if ($types) $stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="ketua_tickets.csv"');

$out = fopen('php://output', 'w');
// Removed "Priority", kept Sub-Category, added "Attachments"
fputcsv($out, [
  '#','Student','Student ID','Gender','Block','Room',
  'Title','Category','Sub-Category','Status','Created','Attachments'
]);

$i=1;
while($r = $res->fetch_assoc()){
  fputcsv($out, [
    $i++,
    $r['student_name'],
    $r['student_id'],
    ucfirst($r['gender'] ?? ''),
    $r['block'],
    $r['room_number'],
    $r['title'],
    $r['category'],
    $r['subcategory'] ?? '',
    $r['status'],
    $r['created_at'],
    $r['attachments'] ?? ''
  ]);
}
fclose($out);
exit;
