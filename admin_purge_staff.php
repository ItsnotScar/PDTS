<?php
session_start(); require_once 'config.php';
if (!isset($_SESSION['role']) || $_SESSION['role']!=='admin') { header("Location: ./index.php"); exit(); }
$id = isset($_POST['id'])?(int)$_POST['id']:0;
try{
  // Only allow purge if already soft-deleted
  $stmt=$conn->prepare("DELETE FROM profile WHERE id=? AND role='technician' AND is_deleted=1");
  $stmt->bind_param('i',$id); $stmt->execute(); $stmt->close();
  $_SESSION['success_message']='Technician purged.';
}catch(mysqli_sql_exception $e){ $_SESSION['error_message']='Purge failed: '.$e->getMessage(); }
header('Location: admin_page.php?section=history'); exit();
