<?php
session_start(); require_once 'config.php';
if (!isset($_SESSION['role']) || $_SESSION['role']!=='admin') { header("Location: ./index.php"); exit(); }
$id = isset($_POST['id'])?(int)$_POST['id']:0;
try{
  $stmt=$conn->prepare("DELETE FROM complaints WHERE id=? AND is_deleted=1");
  $stmt->bind_param('i',$id); $stmt->execute(); $stmt->close();
  $_SESSION['success_message']='Ticket purged.';
}catch(mysqli_sql_exception $e){ $_SESSION['error_message']='Purge failed: '.$e->getMessage(); }
header('Location: admin_page.php?section=history'); exit();
