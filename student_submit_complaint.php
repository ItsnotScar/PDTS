<?php
session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'student') {
  header("Location: index.php");
  exit();
}
require_once 'config.php';

$studentId   = $_SESSION['student_id'] ?? '';
$title       = trim((string)($_POST['title'] ?? ''));
$category    = trim((string)($_POST['category'] ?? ''));
$subcategory = trim((string)($_POST['subcategory'] ?? ''));
$complaint   = trim((string)($_POST['complaint'] ?? ''));
$status      = 'Pending'; // use capitalized to match admin filters/UI

/* ───────────── Ban check ───────────── */
if ($studentId !== '') {
  $sidEsc = $conn->real_escape_string($studentId);
  $rowB = $conn->query("SELECT is_banned FROM profile WHERE student_id='$sidEsc'")->fetch_assoc();
  if (!empty($rowB['is_banned'])) {
    $_SESSION['flash'] = ['msg'=>'Your account is banned due to repeated fake tickets. Please contact admin.','ok'=>false];
    header("Location: student_page.php"); exit();
  }
}

/* ───────────── Validation ───────────── */
if ($studentId==='' || $title==='' || $category==='' || $subcategory==='' || $complaint==='') {
  $_SESSION['flash'] = ['msg'=>'Missing required fields.','ok'=>false];
  header("Location: student_page.php"); exit();
}

/* ───────────── Whitelist ───────────── */
$ALLOWED = [
  "KEJURUTERAAN AWAM" => ["Bumbung","Siling","Lantai","Dinding","Tangga","Pintu/Jejenang Pintu","Tingkap/Jejenang Tingkap/Window Handle","Pagar","Gutter","RWDP (Rain Water Down Pipe","Saluran Paip","Pili Paip","Sinki","Bidet","Tandas","Sistem Bekalan Air","Kebocoran","Katil Pelajar","Almari Pelajar","Perabot (Kerusi/Meja/Kabinet)","Tombol Pintu","Pokok/Landskap"],
  "KEJURUTERAAN ELEKTRIK" => ["Kipas","Lampu","Pendawaian/Wiring","Plug Socket","Suis","Bekalan Elektrik Terputus/Power Trip","Perangkap Kilat/Lightning Arrestor","Lampu Jalan/Lampu Foyer","MSB/SSB/DB"],
  "KEJURUTERAAN MEKANIKAL" => ["Alat Pemadam Api","Fire Alarm Panel","Heat Detector","Alarm Bell","Break Glass Fire Alarm","Hose Reel"]
];
if (!isset($ALLOWED[$category]) || !in_array($subcategory, $ALLOWED[$category], true)) {
  $_SESSION['flash'] = ['msg'=>'Invalid category/subcategory.','ok'=>false];
  header("Location: student_page.php"); exit();
}

/* ───────────── Attachments Check (20 MB total) ───────────── */
$MAX_TOTAL = 20 * 1024 * 1024;
$totalSize = 0;

if (!empty($_FILES['attachments']) && is_array($_FILES['attachments']['name'])) {
  $names = $_FILES['attachments']['name'];
  $sizes = $_FILES['attachments']['size'];
  $errs  = $_FILES['attachments']['error'];
  $count = count($names);
  for ($i=0; $i<$count; $i++) {
    if (($errs[$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
      $totalSize += (int)($sizes[$i] ?? 0);
    }
  }
}
if ($totalSize > $MAX_TOTAL) {
  $_SESSION['flash'] = ['msg'=>'Total attachments exceed 20 MB. Please remove some files.','ok'=>false];
  header("Location: student_page.php"); exit();
}

/* ───────────── Anti-Spam: cooldown + daily limit ───────────── */
$cooldownSeconds = 60; // 1 minute
$limitPerDay     = 5;  // max 5 per day

// Cooldown (last submission time)
$res = $conn->prepare("SELECT created_at FROM complaints WHERE student_id=? ORDER BY id DESC LIMIT 1");
$res->bind_param("s", $studentId);
$res->execute();
$row = $res->get_result()->fetch_assoc();
$res->close();

if ($row) {
  $lastTime = strtotime($row['created_at']);
  if (time() - $lastTime < $cooldownSeconds) {
    $_SESSION['flash'] = ['msg'=>'Please wait a minute before submitting another complaint.','ok'=>false];
    header("Location: student_page.php"); exit();
  }
}

// Daily limit
$check = $conn->prepare("SELECT COUNT(*) AS total FROM complaints WHERE student_id=? AND DATE(created_at)=CURDATE()");
$check->bind_param("s", $studentId);
$check->execute();
$count = (int)($check->get_result()->fetch_assoc()['total'] ?? 0);
$check->close();

if ($count >= $limitPerDay) {
  $_SESSION['flash'] = ['msg'=>'You have reached the daily limit (5 complaints per day).','ok'=>false];
  header("Location: student_page.php"); exit();
}

/* ───────────── Insert Complaint ───────────── */
$stmt = $conn->prepare("
  INSERT INTO complaints (student_id, title, category, subcategory, complaint, status)
  VALUES (?,?,?,?,?,?)
");
$stmt->bind_param("ssssss", $studentId, $title, $category, $subcategory, $complaint, $status);
$stmt->execute();
$complaintId = (int)$stmt->insert_id;
$stmt->close();

/* ───────────── Save Attachments ───────────── */
if (!empty($_FILES['attachments']) && is_array($_FILES['attachments']['name'])) {
  $dir = __DIR__ . '/uploads';
  if (!is_dir($dir)) mkdir($dir, 0775, true);

  $names = $_FILES['attachments']['name'];
  $tmps  = $_FILES['attachments']['tmp_name'];
  $sizes = $_FILES['attachments']['size'];
  $types = $_FILES['attachments']['type'];
  $errs  = $_FILES['attachments']['error'];
  $count = count($names);

  for ($i=0; $i<$count; $i++) {
    if (($errs[$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;

    $orig = $names[$i];
    $tmp  = $tmps[$i];
    $size = (int)$sizes[$i];
    $type = $types[$i] ?? null;

    $safe = time().'_'.preg_replace('/[^a-zA-Z0-9._-]/','_', $orig);
    $dest = $dir . '/' . $safe;

    if (move_uploaded_file($tmp, $dest)) {
      $relPath = 'uploads/' . $safe;
      $ins = $conn->prepare("INSERT INTO complaint_attachments (complaint_id, file_path, file_size, mime_type) VALUES (?,?,?,?)");
      $ins->bind_param("isis", $complaintId, $relPath, $size, $type);
      $ins->execute();
      $ins->close();
    }
  }
}

/* ───────────── Done ───────────── */
$_SESSION['flash'] = ['msg'=>'Complaint submitted successfully.','ok'=>true];
header("Location: student_page.php");
exit();
