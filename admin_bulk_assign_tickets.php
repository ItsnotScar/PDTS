<?php
session_start();
require_once 'config.php';
if (!isset($_SESSION['role']) || $_SESSION['role']!=='admin'){ header('Location: index.php'); exit(); }

$ids = $_POST['ids'] ?? [];
$ids = array_values(array_filter(array_map('intval',$ids)));
$tech_id = (int)($_POST['technician_id'] ?? 0);

if (!$ids || !$tech_id){
  $_SESSION['error_message']="Select tickets and technician."; header("Location: admin_page.php?section=tickets"); exit;
}

$tec  = $conn->query("SELECT specialty, COALESCE(assigned_block, block) AS ab FROM profile WHERE id={$tech_id} AND role='technician' AND is_deleted=0")->fetch_assoc();
if (!$tec){ $_SESSION['error_message']="Technician not found."; header("Location: admin_page.php?section=tickets"); exit; }

$in = implode(',', $ids);
$rs=$conn->query("SELECT c.id, c.category, p.block FROM complaints c JOIN profile p ON p.student_id=c.student_id WHERE c.id IN ($in) AND c.is_deleted=0");
$okList=[]; while($r=$rs->fetch_assoc()){
  if (strcasecmp($r['category'],$tec['specialty'])===0 && strcasecmp($r['block'],$tec['ab'])===0){ $okList[]=(int)$r['id']; }
}
if (!$okList){ $_SESSION['error_message']="No tickets matched technician category/block."; header("Location: admin_page.php?section=tickets"); exit; }

$in2 = implode(',', $okList);
$ok = $conn->query("UPDATE complaints SET assigned_to={$tech_id}, updated_at=NOW() WHERE id IN ($in2)");
$_SESSION[$ok?'success_message':'error_message'] = $ok ? "Assigned technician to ".count($okList)." ticket(s)." : "Bulk assign failed.";
header("Location: admin_page.php?section=tickets");
exit;
