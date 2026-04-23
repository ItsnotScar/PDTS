<?php
session_start();
require_once 'config.php';

// --- Access: penyelia only
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'penyelia') {
  http_response_code(403);
  exit('Forbidden');
}

$block  = $_SESSION['block']  ?? '';
$gender = strtolower(trim((string)($_SESSION['gender'] ?? '')));
if ($block === '' || !in_array($gender, ['male','female'], true)) {
  http_response_code(400);
  exit('Session missing block/gender');
}

function val($k){ return trim($_GET[$k] ?? ''); }

// Filters from query string
$studentId   = val('student_id');
$category    = val('category');
$subcategory = val('subcategory');
$status      = val('status');
$startDate   = val('start_date');
$endDate     = val('end_date');
$createdSort = strtolower($_GET['created_sort'] ?? 'desc');
$createdSort = $createdSort === 'asc' ? 'ASC' : 'DESC';

// WHERE builder
$where  = ["c.is_deleted = 0", "p.block = ?", "p.gender = ?", "p.role = 'student'"];
$types  = "ss";
$params = [$block, $gender];

if ($studentId   !== '') { $where[] = "p.student_id LIKE ?"; $types .= "s"; $params[] = "%{$studentId}%"; }
if ($category    !== '') { $where[] = "c.category = ?";       $types .= "s"; $params[] = $category; }
if ($subcategory !== '') { $where[] = "c.subcategory = ?";    $types .= "s"; $params[] = $subcategory; }
if ($status      !== '') { $where[] = "c.status = ?";         $types .= "s"; $params[] = $status; }
if ($startDate   !== '') { $where[] = "c.created_at >= ?";    $types .= "s"; $params[] = $startDate . " 00:00:00"; }
if ($endDate     !== '') { $where[] = "c.created_at <= ?";    $types .= "s"; $params[] = $endDate   . " 23:59:59"; }

$whereSql = implode(" AND ", $where);

// Query (priority/attachment removed; subcategory added)
$sql = "
  SELECT
    c.id, c.title, c.category, c.subcategory, c.complaint, c.status, c.created_at,
    p.name AS student_name, p.student_id, p.block, p.room_number, p.gender AS student_gender
  FROM complaints c
  JOIN profile p ON p.student_id = c.student_id
  WHERE {$whereSql}
  ORDER BY c.created_at {$createdSort}, c.id " . ($createdSort === 'ASC' ? 'ASC' : 'DESC');

$stmt = $conn->prepare($sql);
if ($types) { $stmt->bind_param($types, ...$params); }
$stmt->execute();
$res = $stmt->get_result();

// CSV headers
$filename = "tickets_block_{$block}_{$gender}_" . date('Ymd_His') . ".csv";
header('Content-Type: text/csv; charset=utf-8');
header("Content-Disposition: attachment; filename={$filename}");
header('Pragma: no-cache');
header('Expires: 0');

$out = fopen('php://output', 'w');

// Header row (Priority/Attachment removed, Sub-Category added)
fputcsv($out, [
  '#','Student','Student ID','Gender','Block','Room',
  'Title','Category','Sub-Category','Status','Created'
]);

// Rows
$i=1;
while ($row = $res->fetch_assoc()) {
  fputcsv($out, [
    $i++,
    $row['student_name'],
    $row['student_id'],
    ucfirst($row['student_gender']),
    $row['block'],
    $row['room_number'],
    $row['title'],
    $row['category'],
    $row['subcategory'] !== '' ? $row['subcategory'] : '—',
    $row['status'],
    $row['created_at'],
  ]);
}
fclose($out);
exit;
