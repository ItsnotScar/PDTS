<?php
session_start();

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'student') {
  header("Location: index.php"); exit();
}

require_once 'config.php';

function flash($msg, $ok=false){ $_SESSION['flash'] = ['msg'=>$msg,'ok'=>$ok]; }
function back(){ header("Location: student_page.php"); exit(); }

/**
 * Normalize Malaysia phone numbers to local format that starts with 0
 * Accepts:
 *   - 0XXXXXXXX… (local)
 *   - +60XXXXXXXX… (international)
 *   - 60XXXXXXXX…  (national without 0)
 * Returns "0XXXXXXXX…" (9–12 digits total) or null if invalid.
 */
function normalize_ms_phone(string $raw): ?string {
    $raw = trim($raw);
    if ($raw === '') return ''; // Treat empty as "no update" by caller if desired

    $hasPlus = str_starts_with($raw, '+');
    $digits  = preg_replace('/\D+/', '', $raw) ?? '';

    if ($digits === '') return null;

    if ($hasPlus && str_starts_with($digits, '60')) {
        $candidate = '0' . substr($digits, 2);
    } elseif (str_starts_with($digits, '60')) {
        $candidate = '0' . substr($digits, 2);
    } elseif ($digits[0] === '0') {
        $candidate = $digits;
    } else {
        return null;
    }

    // Now must be 0 followed by 8–11 more digits (9–12 total)
    if (!preg_match('/^0\d{8,11}$/', $candidate)) {
        return null;
    }
    return $candidate;
}

$studentId = $_SESSION['student_id'] ?? '';
$cid       = (int)($_POST['complaint_id'] ?? 0);
$title     = trim($_POST['title'] ?? '');
$category  = trim($_POST['category'] ?? '');
$subcat    = trim($_POST['subcategory'] ?? '');
$desc      = trim($_POST['complaint'] ?? '');
$phoneIn   = trim($_POST['phone'] ?? '');

// Load complaint; ensure ownership & pending
$st = $conn->prepare("SELECT id, student_id, status FROM complaints WHERE id = ? LIMIT 1");
$st->bind_param("i", $cid);
$st->execute();
$comp = $st->get_result()->fetch_assoc();

if (!$comp || $comp['student_id'] !== $studentId) {
  flash('Complaint not found or not yours.'); back();
}
if (strtolower($comp['status']) !== 'pending') {
  flash('Edits are only allowed while the complaint is pending.'); back();
}

// Validate basic fields
if ($title === '' || $category === '' || $subcat === '' || $desc === '') {
  flash('Missing required fields.'); back();
}

// Validate category/subcategory whitelist
$ALLOWED = [
  "KEJURUTERAAN AWAM" => [
    "Bumbung","Siling","Lantai","Dinding","Tangga","Pintu/Jejenang Pintu",
    "Tingkap/Jejenang Tingkap/Window Handle","Pagar","Gutter",
    "RWDP (Rain Water Down Pipe","Saluran Paip","Pili Paip","Sinki",
    "Bidet","Tandas","Sistem Bekalan Air","Kebocoran",
    "Katil Pelajar","Almari Pelajar","Perabot (Kerusi/Meja/Kabinet)",
    "Tombol Pintu","Pokok/Landskap"
  ],
  "KEJURUTERAAN ELEKTRIK" => [
    "Kipas","Lampu","Pendawaian/Wiring","Plug Socket","Suis",
    "Bekalan Elektrik Terputus/Power Trip","Perangkap Kilat/Lightning Arrestor",
    "Lampu Jalan/Lampu Foyer","MSB/SSB/DB"
  ],
  "KEJURUTERAAN MEKANIKAL" => [
    "Alat Pemadam Api","Fire Alarm Panel","Heat Detector",
    "Alarm Bell","Break Glass Fire Alarm","Hose Reel"
  ]
];
if (!isset($ALLOWED[$category]) || !in_array($subcat, $ALLOWED[$category], true)) {
  flash('Invalid category/sub-category.'); back();
}

/* ---------- Update phone in profile (optional, normalized) ---------- */
if ($phoneIn !== '') {
  $normalized = normalize_ms_phone($phoneIn);
  if ($normalized === null) {
    flash('Invalid phone. Use 0XXXXXXXX… or +60/60 followed by digits (total 9–12 digits).'); back();
  }
  // If you want to treat empty string as "no change", keep the condition above as !== ''.
  $qp = $conn->prepare("UPDATE profile SET phone = ? WHERE student_id = ?");
  $qp->bind_param("ss", $normalized, $studentId);
  $qp->execute();
  $qp->close();
}

/* ---------- Apply complaint field updates ---------- */
$up = $conn->prepare("UPDATE complaints SET title = ?, category = ?, subcategory = ?, complaint = ? WHERE id = ? AND student_id = ?");
$up->bind_param("ssssis", $title, $category, $subcat, $desc, $cid, $studentId);
$up->execute();
$up->close();

/* ---------- Handle removals of existing attachments ---------- */
$toRemove = array_map('intval', $_POST['existing_remove'] ?? []);
if (!empty($toRemove)) {
  // Fetch those that belong to this complaint
  $in = implode(',', array_fill(0, count($toRemove), '?'));
  $types = str_repeat('i', count($toRemove));
  $stmt = $conn->prepare("SELECT id, file_path FROM complaint_attachments WHERE complaint_id = ? AND id IN ($in)");
  $bindTypes = 'i'.$types;
  $params = array_merge([$cid], $toRemove);
  $stmt->bind_param($bindTypes, ...$params);
  $stmt->execute();
  $res = $stmt->get_result();

  $ids = [];
  while ($row = $res->fetch_assoc()) {
    $ids[] = (int)$row['id'];
    $abs = __DIR__ . '/' . $row['file_path'];
    if (is_file($abs)) @unlink($abs);
  }
  $stmt->close();

  if ($ids) {
    $in2 = implode(',', array_fill(0, count($ids), '?'));
    $types2 = str_repeat('i', count($ids));
    $del = $conn->prepare("DELETE FROM complaint_attachments WHERE complaint_id = ? AND id IN ($in2)");
    $bindTypes2 = 'i'.$types2;
    $params2 = array_merge([$cid], $ids);
    $del->bind_param($bindTypes2, ...$params2);
    $del->execute();
    $del->close();
  }
}

/* ---------- Current total size of remaining existing files ---------- */
$ts = $conn->prepare("SELECT COALESCE(SUM(file_size),0) AS s FROM complaint_attachments WHERE complaint_id = ?");
$ts->bind_param("i", $cid);
$ts->execute();
$exTotal = (int)($ts->get_result()->fetch_assoc()['s'] ?? 0);
$ts->close();

/* ---------- Sum new uploads (combined limit: 15 MB) ---------- */
$MAX_TOTAL = 15 * 1024 * 1024; // 15 MB cap
$newTotal = 0;

if (!empty($_FILES['attachments']) && is_array($_FILES['attachments']['name'])) {
  $names = $_FILES['attachments']['name'];
  $sizes = $_FILES['attachments']['size'];
  $errs  = $_FILES['attachments']['error'];
  $count = count($names);
  for ($i=0; $i<$count; $i++) {
    if (($errs[$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
      $newTotal += (int)($sizes[$i] ?? 0);
    }
  }
}

// Check combined size
if ($exTotal + $newTotal > $MAX_TOTAL) {
  $exMB  = number_format($exTotal / (1024*1024), 2);
  $newMB = number_format($newTotal / (1024*1024), 2);
  $maxMB = number_format($MAX_TOTAL / (1024*1024), 0);
  flash("Attachments too large: existing {$exMB} MB + new {$newMB} MB exceed {$maxMB} MB. Remove some files."); back();
}

/* ---------- Save new files ---------- */
if ($newTotal > 0) {
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

    // Basic allowlist (optional): accept only common images/videos
    // if (!preg_match('/^(image|video)\//', (string)$type)) { continue; }

    $safe = time().'_'.preg_replace('/[^a-zA-Z0-9._-]/','_', $orig);
    $dest = $dir . '/' . $safe;

    if (move_uploaded_file($tmp, $dest)) {
      $relPath = 'uploads/' . $safe;
      $ins = $conn->prepare("INSERT INTO complaint_attachments (complaint_id, file_path, file_size, mime_type) VALUES (?,?,?,?)");
      $ins->bind_param("isis", $cid, $relPath, $size, $type);
      $ins->execute();
      $ins->close();
    }
  }
}




flash('Complaint updated successfully.', true);
back();
