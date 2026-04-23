<?php
session_start();
require_once 'config.php';
if (!isset($_SESSION['role']) || $_SESSION['role']!=='admin'){ header('Location: index.php'); exit(); }

$ticket_id = (int)($_POST['ticket_id'] ?? 0);
$tech_id   = (int)($_POST['technician_id'] ?? 0);

if (!$ticket_id) { $_SESSION['error_message']="Invalid ticket."; header("Location: admin_page.php?section=tickets"); exit; }

if ($tech_id===0){
  $stmt=$conn->prepare("UPDATE complaints SET assigned_to=NULL, updated_at=NOW() WHERE id=? AND is_deleted=0");
  $stmt->bind_param('i',$ticket_id);
  $ok=$stmt->execute(); $stmt->close();
  $_SESSION[$ok?'success_message':'error_message'] = $ok ? "Unassigned." : "Failed to unassign.";
  header("Location: admin_page.php?section=tickets"); exit;
}

/* server-side guard: tech specialty & block must match ticket category & student block */
$info = $conn->query("SELECT c.category, p.block FROM complaints c JOIN profile p ON p.student_id=c.student_id WHERE c.id={$ticket_id} AND c.is_deleted=0")->fetch_assoc();
$tec  = $conn->query("SELECT specialty, COALESCE(assigned_block, block) AS ab FROM profile WHERE id={$tech_id} AND role='technician' AND is_deleted=0")->fetch_assoc();

if (!$info || !$tec){
  $_SESSION['error_message']="Technician or ticket not found.";
} else {
  if (strcasecmp($info['category'],$tec['specialty'])===0 && strcasecmp($info['block'],$tec['ab'])===0){
    $stmt=$conn->prepare("UPDATE complaints SET assigned_to=?, updated_at=NOW() WHERE id=? AND is_deleted=0");
    $stmt->bind_param('ii',$tech_id,$ticket_id);
    $ok=$stmt->execute(); $stmt->close();
    $_SESSION[$ok?'success_message':'error_message'] = $ok ? "Technician assigned." : "Failed to assign.";
  } else {
    $_SESSION['error_message']="Technician category/block mismatch.";
  }
}
header("Location: admin_page.php?section=tickets");
exit;
