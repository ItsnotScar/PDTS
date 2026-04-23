<?php
@ini_set('session.use_strict_mode', 1);
session_start();
require_once 'config.php';

/* --- Guard: admins & boss_ups only --- */
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin','boss_ups'], true)) {
  http_response_code(403);
  header('Content-Type: application/json');
  echo json_encode(['error' => 'Forbidden']);
  exit;
}

/* --- helpers --- */
function col_exists(mysqli $conn, string $table, string $col): bool {
  $t = $conn->real_escape_string($table);
  $c = $conn->real_escape_string($col);
  $res = $conn->query("SHOW COLUMNS FROM `$t` LIKE '$c'");
  $ok  = $res && $res->num_rows > 0;
  if ($res) $res->close();
  return $ok;
}
function first_existing_col(mysqli $conn, string $table, array $candidates): ?string {
  foreach ($candidates as $c) if (col_exists($conn, $table, $c)) return $c;
  return null;
}
function epath(string $p): string { return ltrim($p, './'); }

/* --- input --- */
$techId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($techId <= 0) {
  http_response_code(400);
  header('Content-Type: application/json');
  echo json_encode(['error' => 'Invalid id']);
  exit;
}

/* --- schema detection --- */
$avatarCol = first_existing_col($conn, 'profile', ['avatar','image','photo','profile_image','profile_photo']);
$blockCol  = col_exists($conn, 'profile', 'assigned_block') ? 'assigned_block'
          : (col_exists($conn, 'profile', 'block') ? 'block' : null);

/* --- load technician row --- */
$selects = ["id","name","email","phone","gender","role","specialty"];
if ($blockCol)  $selects[] = "`$blockCol` AS assigned_block";
if ($avatarCol) $selects[] = "`$avatarCol` AS avatar_path";
$selectSQL = implode(',', $selects);

$stmt = $conn->prepare("SELECT $selectSQL FROM profile WHERE id=? AND role='technician' AND is_deleted=0");
$stmt->bind_param("i", $techId);
$stmt->execute();
$tech = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$tech) {
  http_response_code(404);
  header('Content-Type: application/json');
  echo json_encode(['error' => 'Technician not found']);
  exit;
}

/* --- stats: count complaints by status --- */
$stats = ['pending'=>0, 'in_progress'=>0, 'completed'=>0, 'rejected'=>0];

$qr = $conn->prepare("
  SELECT status, COUNT(*) AS c
  FROM complaints
  WHERE is_deleted=0 AND assigned_to=?
  GROUP BY status
");
$qr->bind_param("i", $techId);
$qr->execute();
$res = $qr->get_result();
while ($r = $res->fetch_assoc()) {
  $s = strtolower(trim($r['status'] ?? ''));
  if ($s === 'pending')         $stats['pending']      = (int)$r['c'];
  elseif ($s === 'in progress') $stats['in_progress']  = (int)$r['c'];
  elseif ($s === 'completed')   $stats['completed']    = (int)$r['c'];
  elseif ($s === 'rejected')    $stats['rejected']     = (int)$r['c'];
}
$qr->close();

/* --- avatar url (don’t file_exists; just return stored path) --- */
$avatar_url = 'assets/avatar-fallback.png';
if (!empty($tech['avatar_path'])) {
  $rel = epath((string)$tech['avatar_path']);
  $avatar_url = $rel;
}

/* --- response --- */
$out = [
  'id'             => (int)$tech['id'],
  'name'           => (string)($tech['name'] ?? ''),
  'email'          => (string)($tech['email'] ?? ''),
  'phone'          => (string)($tech['phone'] ?? ''),
  'gender'         => (string)($tech['gender'] ?? ''),
  'role'           => (string)($tech['role'] ?? ''),
  'specialty'      => (string)($tech['specialty'] ?? ''),
  // may come from assigned_block or block depending on schema
  'assigned_block' => (string)($tech['assigned_block'] ?? ''),
  'block'          => (string)($tech['assigned_block'] ?? ''), // UI fallback
  'avatar_url'     => $avatar_url,
  'stats'          => $stats,
];

header('Content-Type: application/json');
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
