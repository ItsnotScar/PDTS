<?php
session_start(); require_once 'config.php';
if (!isset($_SESSION['role']) || $_SESSION['role']!=='admin') { header("Location: ./index.php"); exit(); }
$id = isset($_POST['id'])?(int)$_POST['id']:0;
try{
  $stmt=$conn->prepare("UPDATE profile SET is_deleted=0, deleted_at=NULL WHERE id=? AND role='technician'");
  $stmt->bind_param('i',$id); $stmt->execute(); $stmt->close();
  $_SESSION['success_message']='Technician restored.';
}catch(mysqli_sql_exception $e){ $_SESSION['error_message']='Restore failed: '.$e->getMessage(); }
header('Location: admin_page.php?section=history'); exit();
