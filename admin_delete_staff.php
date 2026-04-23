<?php
session_start();
require_once 'config.php';
if (!isset($_SESSION['role']) || $_SESSION['role']!=='admin'){ header('Location: index.php'); exit(); }

$id = (int)($_POST['id'] ?? 0);
if ($id){
  // Soft delete only (don’t update phone or other columns)
  $stmt=$conn->prepare("UPDATE profile SET is_deleted=1, deleted_at=NOW() WHERE id=? AND role='technician' AND is_deleted=0");
  $stmt->bind_param('i',$id);
  $ok=$stmt->execute(); $stmt->close();
  $_SESSION[$ok?'success_message':'error_message'] = $ok ? "Technician moved to History." : "Failed to delete technician.";
} else {
  $_SESSION['error_message']="Invalid staff id.";
}
header("Location: admin_page.php?section=staff");
exit;
