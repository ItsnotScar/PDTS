<?php
// admin_ticket_details.php — JSON details for a single ticket (active or soft-deleted)
@ini_set('display_errors', 0);
@session_start();
require_once 'config.php';
header('Content-Type: application/json; charset=UTF-8');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
  http_response_code(403);
  echo json_encode(['error' => 'forbidden']); exit;
}

$ticketId = (int)($_GET['id'] ?? 0);
if ($ticketId <= 0) {
  http_response_code(400);
  echo json_encode(['error' => 'invalid id']); exit;
}

/* helpers */
function colExists(mysqli $conn, $table, $col){
  $res = $conn->query("SHOW COLUMNS FROM `".$conn->real_escape_string($table)."` LIKE '".$conn->real_escape_string($col)."'");
  $ok = ($res && $res->num_rows > 0);
  if ($res) $res->close();
  return $ok;
}
function tableExists(mysqli $conn, $table){
  $res = $conn->query("SHOW TABLES LIKE '".$conn->real_escape_string($table)."'");
  $ok = ($res && $res->num_rows > 0);
  if ($res) $res->close();
  return $ok;
}

/* detect subcategory column */
$subcol = '';
foreach (['sub_category','subcategory'] as $c) {
  if (colExists($conn, 'complaints', $c)) { $subcol = $c; break; }
}

/* detect attachment table + optional cols */
$attTbl = '';
foreach (['complaint_attachments','complain_attachment'] as $t) {
  if (tableExists($conn, $t)) { $attTbl = $t; break; }
}
$attHasSize = $attTbl ? colExists($conn, $attTbl, 'file_size') : false;
$attHasMime = $attTbl ? colExists($conn, $attTbl, 'mime_type') : false;

/* load main row (complaint + student + technician)
   IMPORTANT: no filter on is_deleted so soft-deleted rows work */
$selSub = $subcol ? ", COALESCE(NULLIF(c.`$subcol`,''),'') AS subcat" : ", '' AS subcat";
$sql = "
  SELECT
    c.id, c.title, c.category, c.status, c.created_at, c.updated_at, c.complaint AS description,
    c.student_id,
    c.remark_pending, c.remark_in_progress, c.remark_completed, c.remark_rejected,
    c.proof_note AS tech_remark,
    p.name AS student_name, p.phone, p.block, p.room_number, p.gender,
    t.name AS tech_name
    $selSub
  FROM complaints c
  LEFT JOIN profile p ON p.student_id = c.student_id
  LEFT JOIN profile t ON t.id = c.assigned_to
  WHERE c.id=?
  LIMIT 1
";
$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $ticketId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
  http_response_code(404);
  echo json_encode(['error' => 'not found']); exit;
}

/* attachments */
$studentFiles = [];
$techFiles    = [];
if ($attTbl) {
  $selSize = $attHasSize ? "a.file_size" : "0 AS file_size";
  $selMime = $attHasMime ? "a.mime_type" : "'' AS mime_type";
  $q = $conn->query("
    SELECT a.id, a.file_path, $selSize, $selMime
    FROM `$attTbl` a
    WHERE a.complaint_id = ".(int)$row['id']."
    ORDER BY a.id ASC
  ");
  if ($q) {
    while ($a = $q->fetch_assoc()) {
      $item = [
        'id'   => (int)$a['id'],
        'path' => (string)$a['file_path'],
        'size' => (int)$a['file_size'],
        'mime' => (string)$a['mime_type'],
      ];
      if (stripos((string)$a['file_path'], 'uploads/proofs/') !== false) {
        $techFiles[] = $item;
      } else {
        $studentFiles[] = $item;
      }
    }
    $q->close();
  }
}

/* build output compatible with openDetails() */
$out = [
  'id'            => (int)$row['id'],
  'title'         => (string)($row['title'] ?? ''),
  'category'      => (string)($row['category'] ?? ''),
  'status'        => (string)($row['status'] ?? ''),              // History UI sets "(Deleted)" itself
  'submitted'     => (string)($row['created_at'] ?? ''),
  'updated_at'    => (string)($row['updated_at'] ?? ''),
  'description'   => (string)($row['description'] ?? ''),
  'student'       => (string)($row['student_name'] ?? ''),
  'phone'         => (string)($row['phone'] ?? ''),
  'subcat'        => (string)($row['subcat'] ?? ''),
  'block'         => (string)($row['block'] ?? ''),
  'room'          => (string)($row['room_number'] ?? ''),
  'gender'        => (string)($row['gender'] ?? ''),
  'techname'      => (string)($row['tech_name'] ?? ''),
  'techremark'    => (string)($row['tech_remark'] ?? ''),
  'techcompleted' => (string)($row['updated_at'] ?? ''),
  'attachments'   => $studentFiles,
  'tech_attachments' => $techFiles,
  'remarks' => [
    'pending'      => (string)($row['remark_pending'] ?? ''),
    'in_progress'  => (string)($row['remark_in_progress'] ?? ''),
    'completed'    => (string)($row['remark_completed'] ?? ''),
    'rejected'     => (string)($row['remark_rejected'] ?? ''),
  ],
];

echo json_encode($out, JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
