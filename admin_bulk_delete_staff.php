<?php
session_start();
require_once 'config.php';
if (!isset($_SESSION['role']) || $_SESSION['role']!=='admin') { header("Location: ./index.php"); exit(); }

$ids = $_POST['ids'] ?? [];
if (!$ids){ $_SESSION['error_message']='Select at least one staff.'; header('Location: admin_page.php?section=staff'); exit(); }

try{
  $ids = array_values(array_filter(array_map('intval',$ids), fn($x)=>$x>0));
  $place = implode(',', array_fill(0,count($ids),'?'));
  $types = str_repeat('i', count($ids));

  $stmt=$conn->prepare("UPDATE profile SET is_deleted=1, deleted_at=NOW() WHERE role='technician' AND id IN ($place)");
  $stmt->bind_param($types, ...$ids); $stmt->execute(); $stmt->close();

  // Unassign tickets linked to these techs
  $stmt=$conn->prepare("UPDATE complaints SET assigned_to=NULL WHERE is_deleted=0 AND assigned_to IN ($place)");
  $stmt->bind_param($types, ...$ids); $stmt->execute(); $stmt->close();

  $_SESSION['success_message']='Selected technicians moved to History.';
}catch(mysqli_sql_exception $e){
  $_SESSION['error_message']='Bulk delete staff failed: '.$e->getMessage();
}
header('Location: admin_page.php?section=staff'); exit();
