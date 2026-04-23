<?php
session_start();
require_once 'config.php';
if (!isset($_SESSION['role']) || $_SESSION['role']!=='admin') { header("Location: ./index.php"); exit(); }
$ids = $_POST['ids'] ?? [];
if (!$ids){ $_SESSION['error_message']='Select at least one ticket.'; header('Location: admin_page.php?section=history'); exit(); }

try{
  $ids = array_values(array_filter(array_map('intval',$ids), fn($x)=>$x>0));
  $place = implode(',', array_fill(0,count($ids),'?'));
  $types = str_repeat('i', count($ids));
  $stmt = $conn->prepare("UPDATE complaints SET is_deleted=0, deleted_at=NULL WHERE is_deleted=1 AND id IN ($place)");
  $stmt->bind_param($types, ...$ids);
  $stmt->execute(); $aff=$stmt->affected_rows; $stmt->close();
  $_SESSION['success_message'] = "Restored $aff ticket(s).";
}catch(Throwable $e){ $_SESSION['error_message']='Restore failed: '.$e->getMessage(); }
header('Location: admin_page.php?section=history'); exit();
