<?php
require_once 'config.php';
header('Content-Type: application/json');

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
  http_response_code(400);
  echo json_encode(['error' => 'Invalid ticket ID']);
  exit;
}

$stmt = $conn->prepare("
  SELECT 
    c.*, 
    p.name AS student_name, 
    p.student_id, 
    p.phone,                  -- ✅ added phone
    p.gender, 
    p.block, 
    p.room_number,
    t.name AS technician_name
  FROM complaints c
  JOIN profile p ON p.student_id = c.student_id
  LEFT JOIN profile t ON t.id = c.assigned_to
  WHERE c.id = ?
  AND c.is_deleted = 0
  LIMIT 1
");
$stmt->bind_param('i', $id);
$stmt->execute();
$res = $stmt->get_result();
$data = $res->fetch_assoc();

if ($data) {
  // Attachments
  $attStmt = $conn->prepare("
    SELECT file_path, file_size, mime_type 
    FROM complaint_attachments 
    WHERE complaint_id=? 
    ORDER BY id ASC
  ");
  $attStmt->bind_param('i', $id);
  $attStmt->execute();
  $attRes = $attStmt->get_result();
  $files = [];
  while ($f = $attRes->fetch_assoc()) {
    $files[] = $f;
  }
  $attStmt->close();

  $data['attachments'] = $files;
}

echo json_encode($data ?: []);
