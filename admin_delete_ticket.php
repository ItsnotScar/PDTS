<?php
session_start();
require_once 'config.php';
if (!isset($_SESSION['role']) || $_SESSION['role']!=='admin'){ header('Location: index.php'); exit(); }

$id = (int)($_POST['id'] ?? 0);
if ($id){
  $stmt=$conn->prepare("UPDATE complaints SET is_deleted=1, deleted_at=NOW() WHERE id=? AND is_deleted=0");
  $stmt->bind_param('i',$id);
  $ok=$stmt->execute(); $stmt->close();
  $_SESSION[$ok?'success_message':'error_message'] = $ok ? "Ticket moved to History." : "Failed to delete ticket.";
} else {
  $_SESSION['error_message']="Invalid ticket.";
}
header("Location: admin_page.php?section=tickets");
exit;
