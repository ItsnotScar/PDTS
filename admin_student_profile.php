<?php
// admin_student_profile.php (hardened)
@ini_set('display_errors', 0); // avoid leaking HTML on JSON endpoint
session_start();
require_once 'config.php';

header('Content-Type: application/json; charset=UTF-8');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
  http_response_code(403);
  echo json_encode(['error' => 'forbidden']); exit;
}

$studentId = trim($_GET['student_id'] ?? '');
$wantRejected = isset($_GET['rejected']);
if ($studentId === '') {
  echo json_encode(['error' => 'missing id']); exit;
}

// Helpers
function colExists(mysqli $conn, string $table, string $col): bool {
  $q = $conn->query("SHOW COLUMNS FROM `{$conn->real_escape_string($table)}` LIKE '".$conn->real_escape_string($col)."'");
  $ok = ($q && $q->num_rows > 0);
  if ($q) $q->close();
  return $ok;
}
function tableExists(mysqli $conn, string $table): bool {
  $q = $conn->query("SHOW TABLES LIKE '".$conn->real_escape_string($table)."'");
  $ok = ($q && $q->num_rows > 0);
  if ($q) $q->close();
  return $ok;
}

$out = [
  'student_id' => $studentId,
  'name' => null,
  'email' => null,
  'phone' => null,
  'block' => null,
  'room_number' => null,
  'gender' => null,
  'avatar_url' => null,
  'warnings_count' => 0,
  'is_banned' => 0,
  'stats' => ['total'=>0,'pending'=>0,'in_progress'=>0,'completed'=>0,'rejected'=>0],
  'recent_warnings' => [],
  'rejected_tickets' => [],
];

// Build profile SELECT only with existing columns
$profileCols = ['name','email','phone','block','room_number','gender'];
$optCols     = ['avatar_url','warnings_count','is_banned'];

foreach ($optCols as $c) {
  if (colExists($conn, 'profile', $c)) $profileCols[] = $c;
}

// Fallback aliases if optional cols missing
$hasAvatar   = in_array('avatar_url', $profileCols, true);
$hasWarns    = in_array('warnings_count', $profileCols, true);
$hasBanned   = in_array('is_banned', $profileCols, true);

$selectList = implode(',', array_map(fn($c) => "`$c`", $profileCols));
$p = $conn->prepare("SELECT $selectList FROM profile WHERE student_id=? LIMIT 1");
if ($p) {
  $p->bind_param('s', $studentId);
  if ($p->execute()) {
    $pr = $p->get_result()->fetch_assoc();
    if ($pr) {
      foreach (['name','email','phone','block','room_number','gender'] as $k) {
        if (array_key_exists($k, $pr)) $out[$k] = $pr[$k];
      }
      $out['avatar_url']    = $hasAvatar ? ($pr['avatar_url'] ?? null) : null;
      $out['warnings_count']= $hasWarns ? (int)($pr['warnings_count'] ?? 0) : 0;
      $out['is_banned']     = $hasBanned ? (int)($pr['is_banned'] ?? 0) : 0;
    }
  }
  $p->close();
}

// Stats (complaints)
$s = $conn->prepare("
  SELECT
    COUNT(*) total,
    SUM(CASE WHEN status='Pending' THEN 1 ELSE 0 END) AS pend,
    SUM(CASE WHEN status='In Progress' THEN 1 ELSE 0 END) AS prog,
    SUM(CASE WHEN status='Completed' THEN 1 ELSE 0 END) AS comp,
    SUM(CASE WHEN status='Rejected' THEN 1 ELSE 0 END) AS rej
  FROM complaints
  WHERE is_deleted=0 AND student_id=?");
if ($s) {
  $s->bind_param('s', $studentId);
  if ($s->execute()) {
    $st = $s->get_result()->fetch_assoc() ?: [];
    $out['stats'] = [
      'total'       => (int)($st['total'] ?? 0),
      'pending'     => (int)($st['pend'] ?? 0),
      'in_progress' => (int)($st['prog'] ?? 0),
      'completed'   => (int)($st['comp'] ?? 0),
      'rejected'    => (int)($st['rej'] ?? 0),
    ];
  }
  $s->close();
}

// Recent warnings (optional table)
if (tableExists($conn, 'student_warnings')) {

    if (!array_key_exists('is_banned', $pr ?? [])) {
        $out['is_banned'] = ($out['warnings_count'] >= 3) ? 1 : 0;
    }
    $out['is_banned'] = (int)($out['is_banned'] ?? 0);

    // LIVE warnings count: only distinct complaints that are CURRENTLY rejected
    $wc = $conn->prepare("
      SELECT COUNT(DISTINCT sw.complaint_id) AS c
      FROM student_warnings sw
      JOIN complaints c
        ON c.id = sw.complaint_id
      AND c.is_deleted = 0
      AND c.status = 'Rejected'
      WHERE sw.student_id = ?
    ");
    if ($wc) {
      $wc->bind_param('s', $studentId);
      if ($wc->execute()) {
        $wcr = $wc->get_result()->fetch_assoc();
        $out['warnings_count'] = (int)($wcr['c'] ?? 0);
      }
      $wc->close();
    }



  $w = $conn->prepare("
    SELECT sw.complaint_id, sw.reason, sw.created_at
    FROM student_warnings sw
    JOIN complaints c
      ON c.id = sw.complaint_id
    AND c.is_deleted = 0
    AND c.status = 'Rejected'
    WHERE sw.student_id = ?
    ORDER BY sw.created_at DESC
    LIMIT 5
  ");

  if ($w) {
    $w->bind_param('s', $studentId);
    if ($w->execute()) {
      $wr = $w->get_result();
      while ($row = $wr->fetch_assoc()) {
        $out['recent_warnings'][] = [
          'complaint_id' => (int)($row['complaint_id'] ?? 0),
          'reason'       => (string)($row['reason'] ?? ''),
          'created_at'   => (string)($row['created_at'] ?? ''),
        ];
      }
    }
    $w->close();
  }
}

// Rejected tickets (on demand)
if ($wantRejected) {
  $r = $conn->prepare("
    SELECT id, title, created_at
    FROM complaints
    WHERE is_deleted=0 AND student_id=? AND status='Rejected'
    ORDER BY id DESC
    LIMIT 50");
  if ($r) {
    $r->bind_param('s', $studentId);
    if ($r->execute()) {
      $rr = $r->get_result();
      while ($row = $rr->fetch_assoc()) {
        $out['rejected_tickets'][] = [
          'id'         => (int)($row['id'] ?? 0),
          'title'      => (string)($row['title'] ?? ''),
          'created_at' => (string)($row['created_at'] ?? ''),
        ];
      }
    }
    $r->close();
  }
}

echo json_encode($out, JSON_UNESCAPED_UNICODE);
