<?php
require_once 'config.php';
@session_start();
if (!isset($_SESSION['role']) || $_SESSION['role']!=='admin') { header('Location:index.php'); exit; }

$student_id = $conn->real_escape_string($_POST['student_id'] ?? '');
$redir      = $_POST['redirect'] ?? '';
if ($student_id===''){
  $_SESSION['error_message']='Invalid student.';
} else {
  $admin_id = (int)($_SESSION['id'] ?? 0);
  $ok = $conn->query("UPDATE profile
                      SET is_banned=1, banned_at=NOW(), banned_by=$admin_id
                      WHERE student_id='$student_id'");
  $_SESSION[$ok?'success_message':'error_message'] = $ok ? 'Student banned.' : 'Failed to ban student.';
}

/* normalize redirect: prepend page if only a query string was posted */
if ($redir !== '' && strpos($redir, 'admin_page.php') === false) {
  $redir = 'admin_page.php?' . ltrim($redir, '?');
}
if ($redir === '') {
  $redir = 'admin_page.php?section=tickets';
}
header('Location: ' . $redir, true, 303);
exit;
