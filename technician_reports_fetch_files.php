<?php
require_once 'config.php';
header('Content-Type: application/json');

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$out = [];

if ($id > 0) {
  $st = $conn->prepare("
    SELECT file_path AS path, file_size AS size, mime_type AS mime
    FROM complaint_attachments
    WHERE complaint_id = ?
    ORDER BY id ASC
  ");
  $st->bind_param("i", $id);
  $st->execute();
  $r = $st->get_result();

  while ($row = $r->fetch_assoc()) {
    $row['is_proof'] = str_starts_with($row['path'], 'uploads/proofs/');
    $out[] = $row;
  }

  $st->close();
}

echo json_encode($out);
