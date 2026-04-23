<?php
session_start();
require_once 'config.php';

/* Allow admin OR boss_ups (CSV lacked a guard before) */
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin','boss_ups'], true)) {
  http_response_code(403); exit('Forbidden');
}

// Filters from query string (same as staff tab)
$search = $_GET['search'] ?? '';
$f_block = $_GET['f_block'] ?? '';
$f_gender = $_GET['f_gender'] ?? '';
$f_spec = $_GET['f_spec'] ?? '';

$techBlockCol = 'assigned_block';
$hasAssignedBlock = false;
if ($res = $conn->query("SHOW COLUMNS FROM profile LIKE 'assigned_block'")) { $hasAssignedBlock = ($res && $res->num_rows > 0); $res->close(); }
if (!$hasAssignedBlock) $techBlockCol = 'block';

$where = ["role='technician'","is_deleted=0"];
if ($search)  { $s=$conn->real_escape_string($search); $where[]="(name LIKE '%$s%' OR email LIKE '%$s%')"; }
if ($f_block!=='')  { $where[]="$techBlockCol='".$conn->real_escape_string($f_block)."'"; }
if ($f_gender==='male' || $f_gender==='female') { $where[]="gender='".$conn->real_escape_string($f_gender)."'"; }
if ($f_spec!=='')   { $where[]="specialty='".$conn->real_escape_string($f_spec)."'"; }
$whereSql = implode(' AND ',$where);

$q = $conn->query("SELECT id,name,email,role,gender,$techBlockCol AS assigned_block,specialty FROM profile WHERE $whereSql ORDER BY name");

// precompute open & done
$rows=[]; $ids = [];
while($r=$q->fetch_assoc()){ $ids[] = (int)$r['id']; $rows[]=$r; }
$open = $done = [];
if ($ids){
  $csv = implode(',', array_map('intval',$ids));
  $rs = $conn->query("SELECT assigned_to, COUNT(*) c FROM complaints WHERE is_deleted=0 AND assigned_to IN ($csv) AND status NOT IN ('Completed','Rejected') GROUP BY assigned_to");
  while($r=$rs->fetch_assoc()) $open[(int)$r['assigned_to']] = (int)$r['c'];
  $rs = $conn->query("SELECT assigned_to, COUNT(*) c FROM complaints WHERE is_deleted=0 AND assigned_to IN ($csv) AND status='Completed' GROUP BY assigned_to");
  while($r=$rs->fetch_assoc()) $done[(int)$r['assigned_to']] = (int)$r['c'];
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="staff_export.csv"');
$out = fopen('php://output', 'w');

// Visible columns: Name, Email, Role, Block Gender, Specialty, Open, Completed
fputcsv($out, ['Name','Email','Role','Block / Gender','Specialty','Open','Completed']);

foreach ($rows as $r){
  $sid=(int)$r['id'];
  $blockGender = 'Blok '.($r['assigned_block'] ?: '—').' — '.($r['gender'] ?: '—');
  fputcsv($out, [
    $r['name'], $r['email'], ucfirst($r['role']),
    $blockGender, ($r['specialty'] ?: ''),
    ($open[$sid] ?? 0), ($done[$sid] ?? 0)
  ]);
}
fclose($out);
exit;
