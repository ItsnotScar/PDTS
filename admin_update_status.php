
<?php
// admin_update_status.php  (Option A: no email here)
@ini_set('display_errors', 0);
@session_start();
require_once 'config.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
  $_SESSION['error_message'] = 'Forbidden';
  header("Location: admin_page.php?section=tickets"); exit;
}

$ticketId  = (int)($_POST['ticket_id'] ?? 0);
$newStatus = trim((string)($_POST['new_status'] ?? ''));
$redirect  = $_POST['redirect'] ?? 'section=tickets';

if ($ticketId <= 0 || $newStatus === '') {
  $_SESSION['error_message'] = 'Missing data.';
  header("Location: admin_page.php?$redirect"); exit;
}

$allowed = ['Pending','In Progress','Completed','Rejected'];
if (!in_array($newStatus, $allowed, true)) {
  $_SESSION['error_message'] = 'Invalid status.';
  header("Location: admin_page.php?$redirect"); exit;
}

$conn->begin_transaction();
try {
  // Lock & fetch current data
  $q = $conn->query("SELECT status, student_id FROM complaints WHERE id=$ticketId FOR UPDATE");
  $row = $q->fetch_assoc(); $q->close();
  $prev = $row ? (string)$row['status'] : '';
  $sid  = $row ? (string)$row['student_id'] : '';

  // Update complaint status
  $stmt = $conn->prepare("UPDATE complaints SET status=?, updated_at=NOW() WHERE id=? AND is_deleted=0");
  $stmt->bind_param('si', $newStatus, $ticketId);
  $stmt->execute(); 
  $stmt->close();

  // Auto-warning for rejected
  if ($sid !== '' && strcasecmp($newStatus,'Rejected')===0 && strcasecmp($prev,'Rejected')!==0) {
    $conn->query("
      CREATE TABLE IF NOT EXISTS student_warnings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id VARCHAR(64) NOT NULL,
        complaint_id INT NOT NULL,
        reason TEXT,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_warning_ticket (complaint_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $stmt = $conn->prepare("INSERT IGNORE INTO student_warnings (student_id, complaint_id, reason) VALUES (?,?,?)");
    $reason = 'Auto-warning: status set to Rejected';
    $stmt->bind_param('sis', $sid, $ticketId, $reason);
    $stmt->execute(); 
    $stmt->close();
  }

  $conn->commit();
  $_SESSION['success_message'] = 'Status updated.';
  
  //  ───────────────────────────────
  //  Email removed for Option A
  //  (Email now handled in admin_add_remark.php)
  //  ───────────────────────────────

} catch (Throwable $e) {
  $conn->rollback();
  $_SESSION['error_message'] = 'Failed to update status.';
}

header("Location: admin_page.php?$redirect");
exit;
?>
