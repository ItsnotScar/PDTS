<?php
require_once 'config.php';
@session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') { header('Location: index.php'); exit; }

$complaint_id = (int)($_POST['complaint_id'] ?? 0);
$reason       = trim((string)($_POST['reason'] ?? 'Fake ticket'));
$redirect     = $_POST['redirect'] ?? 'admin_page.php?section=tickets';

if ($complaint_id <= 0) { $_SESSION['error_message'] = 'Invalid complaint.'; header("Location: $redirect"); exit; }

$res = $conn->query("SELECT student_id FROM complaints WHERE id=$complaint_id AND is_deleted=0");
if (!$res || !$res->num_rows) { $_SESSION['error_message'] = 'Complaint not found.'; header("Location: $redirect"); exit; }
$student_id = $conn->real_escape_string($res->fetch_assoc()['student_id']);

$admin_id = (int)($_SESSION['id'] ?? 0);

$conn->begin_transaction();
try {
  // record warning
  $stmt = $conn->prepare("INSERT INTO student_warnings (student_id, complaint_id, reason, created_by) VALUES (?,?,?,?)");
  $stmt->bind_param('sisi', $student_id, $complaint_id, $reason, $admin_id);
  $stmt->execute(); $stmt->close();

  // bump counter
  $conn->query("UPDATE profile SET warnings_count = warnings_count + 1 WHERE student_id='$student_id'");

  // check count + autoban rule
  $row = $conn->query("SELECT warnings_count, is_banned FROM profile WHERE student_id='$student_id' FOR UPDATE")->fetch_assoc();
  $cnt = (int)$row['warnings_count'];
  $isB = (int)$row['is_banned'];

  if ($cnt >= 3 && !$isB) {
    $conn->query("UPDATE profile SET is_banned=1, banned_at=NOW(), banned_by=$admin_id WHERE student_id='$student_id'");
    $_SESSION['success_message'] = "Warning added (now $cnt). Student auto-banned (3 warnings).";
  } else {
    $_SESSION['success_message'] = "Warning added (now $cnt).";
  }

  $conn->commit();
} catch (Throwable $t) {
  $conn->rollback();
  $_SESSION['error_message'] = 'Failed to add warning.';
}
header("Location: $redirect");
