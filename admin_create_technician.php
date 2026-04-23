<?php
session_start();
require_once 'config.php';
if (!isset($_SESSION['role']) || $_SESSION['role']!=='admin'){ header("Location: ./index.php"); exit(); }

$name  = trim($_POST['name'] ?? '');
$email = trim($_POST['email'] ?? '');
$pass  = $_POST['password'] ?? '';
$gender = strtolower(trim($_POST['gender'] ?? ''));
$assigned_block = strtoupper(trim($_POST['assigned_block'] ?? ''));
$spec  = trim($_POST['tech_category'] ?? '');
$redirect = 'admin_page.php?section=staff';

$VALID_CATS = ['KEJURUTERAAN AWAM','KEJURUTERAAN ELEKTRIK','KEJURUTERAAN MEKANIKAL'];
$maleBlocks = ['A','B','C','D','E','F'];
$femaleBlocks = ['A','B'];

if ($name==='' || $email==='' || $pass==='' || !in_array($spec,$VALID_CATS,true) || !in_array($gender,['male','female'],true)){
  $_SESSION['error_message']='Invalid input.';
  header("Location: $redirect"); exit();
}
if ($gender==='male' && !in_array($assigned_block,$maleBlocks,true)){
  $_SESSION['error_message']='Invalid block for male technician. Allowed: A–F.';
  header("Location: $redirect"); exit();
}
if ($gender==='female' && !in_array($assigned_block,$femaleBlocks,true)){
  $_SESSION['error_message']='Invalid block for female technician. Allowed: A–B.';
  header("Location: $redirect"); exit();
}

/* choose column for assigned block */
$techBlockCol = 'assigned_block';
$hasAssignedBlock = false;
if ($res = $conn->query("SHOW COLUMNS FROM profile LIKE 'assigned_block'")) {
  $hasAssignedBlock = ($res && $res->num_rows > 0);
  $res->close();
}
if (!$hasAssignedBlock) $techBlockCol='block'; // fallback if you decided to reuse 'block'

/* simple email check for duplicates */
$chk = $conn->prepare("SELECT id FROM profile WHERE email=? AND is_deleted=0 LIMIT 1");
$chk->bind_param('s',$email);
$chk->execute(); $dup=$chk->get_result()->fetch_assoc(); $chk->close();
if ($dup){
  $_SESSION['error_message']='Email already exists.';
  header("Location: $redirect"); exit();
}

$hash = password_hash($pass, PASSWORD_BCRYPT);

/* insert technician */
$sql = "INSERT INTO profile (name,email,password,role,specialty,gender,{$techBlockCol},is_deleted) VALUES (?,?,?,?,?,?,?,0)";
$stmt = $conn->prepare($sql);
$stmt->bind_param('sssssss', $name,$email,$hash,$role,$spec,$gender,$assigned_block);
$role='technician';
if ($stmt->execute()){
  $_SESSION['success_message']='Technician created.';
}else{
  $_SESSION['error_message']='Failed to create technician: '.$conn->error;
}
$stmt->close();

header("Location: $redirect");
