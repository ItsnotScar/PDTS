<?php
@ini_set('session.use_strict_mode', 1);
session_start();
require_once 'config.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
  header("Location: ./index.php"); exit();
}

$ticketId = (int)($_POST['ticket_id'] ?? 0);
$newCat   = trim($_POST['new_category'] ?? '');
$newSub   = trim($_POST['new_subcategory'] ?? '');
$redirect = $_POST['redirect'] ?? 'section=tickets';

if ($ticketId <= 0 || $newCat === '') {
  $_SESSION['error_message'] = 'Invalid request.';
  header('Location: admin_page.php?' . $redirect);
  exit;
}

/* Update category */
$stmt = $conn->prepare("UPDATE complaints SET category=? WHERE id=?");
$stmt->bind_param('si', $newCat, $ticketId);
$stmt->execute();
$stmt->close();

/* If table has a sub-category column, update it too */
$subCol = '';
foreach (['sub_category','subcategory'] as $try) {
  $rs = $conn->query("SHOW COLUMNS FROM complaints LIKE '".$conn->real_escape_string($try)."'");
  if ($rs && $rs->num_rows > 0) { $subCol = $try; $rs->close(); break; }
  if ($rs) $rs->close();
}
if ($subCol !== '') {
  $stmt = $conn->prepare("UPDATE complaints SET `$subCol`=? WHERE id=?");
  $stmt->bind_param('si', $newSub, $ticketId);
  $stmt->execute();
  $stmt->close();
}

$_SESSION['success_message'] = 'Category updated' . ($subCol ? ' (with sub-category)' : '') . '.';
header('Location: admin_page.php?' . $redirect);
