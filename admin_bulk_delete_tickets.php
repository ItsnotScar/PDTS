<?php
session_start();
require_once 'config.php';
if (!isset($_SESSION['role']) || $_SESSION['role']!=='admin'){ header('Location: index.php'); exit(); }

$ids = $_POST['ids'] ?? [];
$ids = array_values(array_filter(array_map('intval',$ids)));
if ($ids){
  $in = implode(',', $ids);
  $ok = $conn->query("UPDATE complaints SET is_deleted=1, deleted_at=NOW() WHERE id IN ($in) AND is_deleted=0");
  $_SESSION[$ok?'success_message':'error_message'] = $ok ? "Selected tickets moved to History." : "Bulk delete failed.";
} else {
  $_SESSION['error_message']="No tickets selected.";
}
header("Location: admin_page.php?section=tickets");
exit;
