<?php
session_start(); require_once 'config.php';
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') { http_response_code(403); exit; }
$ids = $_POST['ids'] ?? [];
if (!$ids) { $_SESSION['error_message'] = 'No staff selected.'; header('Location: admin_page.php?section=history'); exit; }
$in = implode(',', array_map('intval',$ids));
$q = $conn->query("DELETE FROM profile WHERE is_deleted=1 AND id IN ($in)");
$_SESSION['success_message'] = $q ? 'Selected staff purged.' : 'Failed to purge staff.';
header('Location: admin_page.php?section=history');
