
<?php
session_start();
$currentPage = basename($_SERVER['PHP_SELF']);
require_once 'config.php';

/* Technicians only */
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'technician') {
  header("Location: ./index.php"); exit();
}

$techId = (int)($_SESSION['user_id'] ?? 0);
function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* ---------- schema helpers ---------- */
function table_has_col(mysqli $conn, string $table, string $col): bool {
  $t = $conn->real_escape_string($table);
  $c = $conn->real_escape_string($col);
  $r = $conn->query("SHOW COLUMNS FROM `$t` LIKE '$c'");
  $ok = $r && $r->num_rows > 0;
  if ($r) $r->close();
  return $ok;
}

/* avatar column if one exists */
$AVATAR_COL = null;
foreach (['avatar','image','photo','profile_image','profile_photo'] as $c) {
  if (table_has_col($conn,'profile',$c)) { $AVATAR_COL = $c; break; }
}

/* optional age column */
$AGE_COL = table_has_col($conn,'profile','age') ? 'age' : null;

/* anchor year for age auto-increment (create if missing) */
$AGE_ANCHOR_COL = null;
if (table_has_col($conn,'profile','age_set_year')) {
  $AGE_ANCHOR_COL = 'age_set_year';
} else {
  // Try to add the column (safe no-op if permissions are limited)
  @$conn->query("ALTER TABLE `profile` ADD COLUMN `age_set_year` INT NULL AFTER `age`");
  if (table_has_col($conn,'profile','age_set_year')) $AGE_ANCHOR_COL = 'age_set_year';
}

/* phone last-update timestamp column (prefer these in order) */
$PHONE_TS_COL = null;
foreach (['phone_updated_at','phone_last_update','last_phone_update','updated_at'] as $c) {
  if (table_has_col($conn,'profile',$c)) { $PHONE_TS_COL = $c; break; }
}

/* ---------- read current profile ---------- */
$extra = [];
$extra[] = $AVATAR_COL ? "`$AVATAR_COL` AS avatar_path" : "NULL AS avatar_path";
$extra[] = $AGE_COL    ? "`$AGE_COL`    AS age"        : "NULL AS age";
if ($AGE_ANCHOR_COL)   $extra[] = "`$AGE_ANCHOR_COL` AS age_set_year";
if ($PHONE_TS_COL)     $extra[] = "`$PHONE_TS_COL` AS phone_ts";
$EXTRA = implode(',', $extra);

$stmt = $conn->prepare("
  SELECT id, name, email, phone, gender, assigned_block, specialty,
         COALESCE(NULLIF(block,''), assigned_block) AS blk,
         $EXTRA
  FROM profile
  WHERE id=? AND role='technician' AND is_deleted=0
");
$stmt->bind_param("i", $techId);
$stmt->execute();
$me = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$me) { die("Technician profile not found."); }

/* ---------- helpers ---------- */
function can_edit_phone_monthly(?string $lastTs): bool {
  if (!$lastTs) return true;
  $t = strtotime($lastTs);
  if ($t === false) return true;
  return (time() - $t) >= 30*24*60*60; // 30 days
}
/* phone normalization + validation for MY formats */
function clean_phone_for_check(string $s): string {
  return preg_replace('/[^0-9+]/', '', $s);
}
function is_valid_my_phone(string $raw): bool {
  $s = clean_phone_for_check($raw);
  if (preg_match('/^\+60\d{9,11}$/', $s)) return true;
  if (preg_match('/^01\d{8,9}$/', $s)) return true;
  return false;
}

/* ---------- updates (two separate forms) ---------- */
$flash = '';
$flashKind = 'ok'; // ok|err

$action = $_POST['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if ($action === 'save_phone_age') {
    // name, email, specialty are read-only by requirement
    $newPhone = trim($_POST['phone'] ?? '');
    $ageInput = isset($_POST['age']) ? trim($_POST['age']) : '';

    // PHONE: monthly edit + prefix validation
    $allowPhoneEdit = true;
    if ($PHONE_TS_COL) $allowPhoneEdit = can_edit_phone_monthly($me['phone_ts'] ?? null);

    if ($newPhone !== '') {
      if (!is_valid_my_phone($newPhone)) {
        $flash = 'Invalid phone. Use +60… or 01… (digits/spaces/hyphens allowed).';
        $flashKind = 'err';
      }
    }

    if ($flash === '') {
      $sets=[]; $types=''; $vals=[];
      if ($allowPhoneEdit) {
        $sets[] = "phone=?"; $types.='s'; $vals[] = $newPhone;
        if ($PHONE_TS_COL && $PHONE_TS_COL !== 'updated_at') $sets[] = "`$PHONE_TS_COL`=NOW()";
      } else {
        $flash = 'You can update your phone number only once every 30 days.'; $flashKind='err';
      }

      // AGE: one-time set only if column exists and currently empty/zero
      if ($flash === '' && $AGE_COL) {
        $currentAge = (int)($me['age'] ?? 0);
        if ($currentAge <= 0 && $ageInput !== '') {
          if (!preg_match('/^\d{1,3}$/', $ageInput) || (int)$ageInput < 10 || (int)$ageInput > 100) {
            $flash = 'Please enter a valid age (10–100).'; $flashKind='err';
          } else {
            // set age once
            $sets[] = "`$AGE_COL`=?";  $types.='i'; $vals[]=(int)$ageInput;
            // stamp the anchor year (for auto-increment each Jan 1)
            if ($AGE_ANCHOR_COL) {
              $sets[] = "`$AGE_ANCHOR_COL`=?"; $types.='i'; $vals[]=(int)date('Y');
            }
          }
        }
      }

      if ($flash === '' && $sets) {
        $types .= 'i'; $vals[] = $techId;
        $sql = "UPDATE profile SET ".implode(',', $sets)." WHERE id=? AND role='technician' AND is_deleted=0";
        $up = $conn->prepare($sql);
        $up->bind_param($types, ...$vals);
        $ok = $up->execute();
        $up->close();

        if ($ok) {
          // re-read
          $stmt = $conn->prepare("
            SELECT id, name, email, phone, gender, assigned_block, specialty,
                   COALESCE(NULLIF(block,''), assigned_block) AS blk,
                   $EXTRA
            FROM profile WHERE id=? AND role='technician' AND is_deleted=0
          ");
          $stmt->bind_param("i", $techId);
          $stmt->execute();
          $me = $stmt->get_result()->fetch_assoc();
          $stmt->close();

          $flash = 'Profile updated.'; $flashKind = 'ok';
        } else { $flash = 'Nothing changed or database error.'; $flashKind='err'; }
      }
    }

  } elseif ($action === 'save_avatar') {
    if ($AVATAR_COL && isset($_FILES['avatar']) && is_uploaded_file($_FILES['avatar']['tmp_name'])) {
      $f = $_FILES['avatar'];
      if ($f['error'] === UPLOAD_ERR_OK) {
        $mime = mime_content_type($f['tmp_name']);
        if (preg_match('#^image/(png|jpe?g|gif|webp)$#i', $mime)) {
          @mkdir(__DIR__ . '/uploads/avatars', 0775, true);
          $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION) ?: 'jpg');
          $fname = 'tech_'.$techId.'_'.date('Ymd_His').'.'.$ext;
          $destRel = 'uploads/avatars/'.$fname;
          $destAbs = __DIR__.'/'.$destRel;
          if (move_uploaded_file($f['tmp_name'], $destAbs)) {
            $sql = "UPDATE profile SET `$AVATAR_COL`=? WHERE id=? AND role='technician' AND is_deleted=0";
            $up = $conn->prepare($sql);
            $up->bind_param("si", $destRel, $techId);
            $ok = $up->execute();
            $up->close();

            if ($ok) {
              // re-read
              $stmt = $conn->prepare("
                SELECT id, name, email, phone, gender, assigned_block, specialty,
                       COALESCE(NULLIF(block,''), assigned_block) AS blk,
                       $EXTRA
                FROM profile WHERE id=? AND role='technician' AND is_deleted=0
              ");
              $stmt->bind_param("i", $techId);
              $stmt->execute();
              $me = $stmt->get_result()->fetch_assoc();
              $stmt->close();

              $flash = 'Profile picture updated.'; $flashKind='ok';
            } else { $flash='Failed to update profile picture in database.'; $flashKind='err'; }
          } else { $flash='Failed to save uploaded image.'; $flashKind='err'; }
        } else { $flash='Unsupported image type. Upload PNG/JPG/GIF/WEBP.'; $flashKind='err'; }
      } else {
        $flash = ($f['error'] === UPLOAD_ERR_NO_FILE) ? 'No file selected.' : ('Image upload error (code '.$f['error'].').');
        $flashKind='err';
      }
    } else {
      $flash = $AVATAR_COL ? 'Please choose an image file.' : 'No avatar column found in your profile table.';
      $flashKind='err';
    }
  }
}

/* avatar & cover */
$avatar = ($me['avatar_path'] ?: 'assets/avatar-default.png');
$hasRealAvatar = ($me['avatar_path'] && file_exists(__DIR__.'/'.$me['avatar_path']));
$customCover = file_exists(__DIR__.'/assets/profile-cover.jpg') ? 'assets/profile-cover.jpg' : null;

/* cover style priority (applied to the inner .cover-bg element) */
if ($hasRealAvatar) {
  $coverStyle = "background-image:url('".e($avatar)."'); background-size:cover; background-position:center;";
} elseif ($customCover) {
  $coverStyle = "background-image:url('".$customCover."'); background-size:cover; background-position:center;";
} else {
  $coverStyle = "background-image:linear-gradient(120deg,#1e40af,#22c55e);";
}

/* ========= Compute visible age that increases every Jan 1 ========= */
$storedAge  = ($AGE_COL && (int)($me['age'] ?? 0) > 0) ? (int)$me['age'] : 0;
$ageSetYear = ($AGE_ANCHOR_COL && (int)($me['age_set_year'] ?? 0) > 0) ? (int)$me['age_set_year'] : 0;

if ($storedAge > 0) {
  if (!$ageSetYear && $AGE_ANCHOR_COL) {
    // Backfill anchor to this year if missing
    $nowYear = (int)date('Y');
    $bf = $conn->prepare("UPDATE profile SET `$AGE_ANCHOR_COL`=? WHERE id=? AND role='technician' AND is_deleted=0");
    $bf->bind_param("ii", $nowYear, $techId);
    $bf->execute(); $bf->close();
    $ageSetYear = $nowYear;
  }
  $yearsPassed = max(0, (int)date('Y') - ($ageSetYear ?: (int)date('Y')));
  $ageDisplay = $storedAge + $yearsPassed;
} else {
  $ageDisplay = 0;
}

$blockGenderText = trim(($me['blk'] ?: '-')).' - '.ucfirst((string)$me['gender']);

/* phone edit info */
$canEditPhoneNow = true; $nextEditMsg = '';
if ($PHONE_TS_COL) {
  $canEditPhoneNow = can_edit_phone_monthly($me['phone_ts'] ?? null);
  if (!$canEditPhoneNow && !empty($me['phone_ts'])) {
    $next = date('M j, Y g:i A', strtotime($me['phone_ts'].' +30 days'));
    $nextEditMsg = 'You can edit your phone again after '.$next.'.';
  }
} else {
  $nextEditMsg = 'Monthly phone edit limit requires a timestamp column (e.g., phone_updated_at).';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Technician Profile</title>
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
  <link rel="icon" type="image/png" href="assets/favicon.png" sizes="32x32">
  <link rel="apple-touch-icon" href="assets/favicon.png">
  <link rel="stylesheet" href="admin.css" />
  <style>
    :root{
      --card:#fff; --ring:#e5e7eb; --ink:#0f172a; --muted:#64748b;
      --shadow:0 10px 26px rgba(0,0,0,.12); --soft:0 8px 22px rgba(0,0,0,.10);
      --blue:#2563eb; --blue-ghost:#eef2ff;
    }
    html,body{ overflow-x:hidden; }
    body{ margin:0; }
    body.technician::before{
      content:""; position:fixed; inset:0;
      background:url('assets/dormitory.jpg') center/cover no-repeat;
      filter:blur(8px) brightness(.9); z-index:-2;
    }
    body.technician::after{
      content:""; position:fixed; inset:0; background:rgba(0,0,0,.40); z-index:-1;
    }

    .slide-panel{ position:fixed; top:0; right:-320px; width:320px; max-width:90vw; height:100vh;
      background:#fff; border-left:1px solid var(--ring); box-shadow:-8px 0 24px rgba(0,0,0,.15);
      transition:right .25s ease; z-index:1001; padding:14px; display:flex; flex-direction:column; }
    .slide-panel[aria-hidden="false"]{ right:0; }
    .slide-overlay{ display:none; position:fixed; inset:0; background:rgba(0,0,0,.35); z-index:1000; }
    .slide-header{ display:flex; align-items:center; justify-content:space-between; margin-bottom:10px; }
    .slide-link{ display:block; padding:10px 12px; text-decoration:none; color:#111; border-radius:10px; }
    .slide-link.active,.slide-link:hover{ background:var(--blue-ghost); }
    .slide-divider{ height:1px; background:#e5e7eb; margin:8px 0; }
    .logout-btn-wide{ width:100%; padding:10px 12px; border:0; background:#ef4444; color:#fff; border-radius:10px; cursor:pointer; }

    .wrap{ width:min(1100px, 94vw); margin:26px auto 60px; }
    .hero{ position:relative; background:var(--card); border:1px solid var(--ring); border-radius:28px;
      box-shadow:var(--shadow); padding:22px 24px; padding-left:72px; text-align:center; }
    .menu-btn{
      position:absolute; left:18px; top:50%; transform:translateY(-50%);
      width:48px; height:48px; border-radius:14px; border:0; cursor:pointer;
      background:#3b82f6; color:#fff; font-size:22px; display:grid; place-items:center;
      box-shadow:0 8px 18px rgba(59,130,246,.32);
    }
    .hero h1{ margin:0; font-size:26px; font-weight:800; color:#1f2937; }
    .hero p{ margin:6px 0 0; color:#6b7280; }

    .profile{ margin-top:16px; background:var(--card); border:1px solid var(--ring); border-radius:18px;
      box-shadow:var(--soft); overflow:visible; position:relative; }

    .cover-outer{ padding:14px 14px 0; }
    .cover{ width:96%; margin:0 auto; height:280px; position:relative; }
    .cover-bg{ position:absolute; inset:0; border-radius:16px; overflow:hidden; <?php echo $coverStyle; ?> }
    .cover-bg::after{
      content:""; position:absolute; inset:0;
      background:linear-gradient(to bottom, rgba(0,0,0,.15), rgba(0,0,0,.35));
      <?php if($hasRealAvatar || $customCover): ?> backdrop-filter: blur(6px); <?php endif; ?>
    }

    .avatar-wrap{
      width:160px; height:160px; border-radius:999px; border:6px solid #fff; overflow:hidden;
      position:absolute; left:50%; transform:translateX(-50%); bottom:-80px;
      box-shadow:0 16px 36px rgba(0,0,0,.22), 0 0 0 6px rgba(255,255,255,.5);
      cursor:pointer; background:#fff; z-index:2;
    }
    .avatar-wrap img{ width:100%; height:100%; object-fit:cover; display:block; }
    .cam-strip{
      position:absolute; left:0; right:0; bottom:0; height:42%;
      background:rgba(15,23,42,.62); color:#fff; display:flex; align-items:center; justify-content:center;
      transform:translateY(100%); transition:transform .25s ease; font-weight:700; font-size:13px; gap:8px;
    }
    .avatar-wrap:hover .cam-strip, .avatar-wrap:focus-within .cam-strip{ transform:translateY(0); }
    .hidden-input{ display:none; }

    .head{ padding:104px 16px 12px; text-align:center; }
    .name{ font-size:22px; font-weight:900; color:#0f172a; margin:0 0 10px; text-transform:capitalize; }
    .chips{ display:flex; gap:8px; flex-wrap:wrap; justify-content:center; }
    .chip{
      display:inline-flex; align-items:center; gap:6px;
      background:#eaf1ff; border:1px solid #dbeafe; color:#0f172a;
      padding:6px 10px; border-radius:999px; font-size:12px; font-weight:700;
    }

    .form-wrap{ padding:6px 14px 16px; display:flex; justify-content:center; }
    .form{ width:100%; max-width:760px; display:grid; grid-template-columns:1fr; gap:12px; }
    .label{ font-size:12px; color:#64748b; margin:2px 2px 4px; }
    .input, .ro{
      width:100%; border:1px solid var(--ring); background:#f8fafc; padding:12px; border-radius:12px; font-size:14px;
    }
    .ro{ background:#f1f5f9; }

    .actions{ display:flex; gap:10px; justify-content:flex-end; padding:8px 14px 16px; }
    .btn{ padding:12px 16px; border-radius:12px; border:0; cursor:pointer; font-weight:800; }
    .primary{ background:var(--blue); color:#fff; }
    .ghost{ background:var(--blue-ghost); color:#111; }

    .toast{ position:fixed; top:18px; left:50%; transform:translateX(-50%); z-index:3000; display:none; }
    .toast .box{
      display:flex; align-items:center; gap:10px;
      background:#ffffff; border:2px solid #111827; color:#111827;
      padding:12px 16px; border-radius:14px; box-shadow:0 18px 48px rgba(0,0,0,.35);
      font-weight:800; backdrop-filter: blur(6px);
    }
    .toast.ok  .box{ border-color:#16a34a; background:#ecfdf5; color:#065f46; }
    .toast.err .box{ border-color:#b91c1c; background:#fff1f2; color:#7f1d1d; }
    .toast .close-x{ background:transparent; border:0; cursor:pointer; font-size:16px; line-height:1; }

    @media (max-width:640px){
      .wrap{ width:100%; margin:16px auto 48px; }
      .cover-outer{ padding:10px 10px 0; }
      .cover{ width:100%; height:200px; }
      .avatar-wrap{ width:120px; height:120px; bottom:-60px; border-width:5px; }
      .head{ padding-top:88px; }
      .name{ font-size:20px; }
      .chips{ gap:6px; }
      .chip{ font-size:11px; padding:5px 8px; }
      .form-wrap{ padding:0 10px 14px; }
      .form{ max-width:100%; gap:10px; }
      .input, .ro{ border-radius:10px; padding:12px; }
      .toast{ top:12px; }
    }
    @media (max-width:380px){
      .cover{ height:180px; }
      .avatar-wrap{ width:108px; height:108px; bottom:-54px; }
      .name{ font-size:18px; }
      .chip{ font-size:10.5px; padding:4px 7px; }
    }
  </style>
</head>
<body class="dashboard technician">

<!-- Slide menu -->
<div class="slide-overlay" id="slideOverlay"></div>
<aside class="slide-panel" id="slidePanel" aria-hidden="true">
  <div class="slide-header">
    <strong>Quick Menu</strong>
    <button id="panelClose" style="border:0;background:#0000;font-size:22px;cursor:pointer" aria-label="Close">&times;</button>
  </div>
  <div class="slide-body">
    <a class="slide-link <?= $currentPage=='technician_page.php'?'active':'' ?>" href="technician_page.php">📊 Dashboard</a>
    <a class="slide-link <?= $currentPage=='technician_mytickets.php'?'active':'' ?>" href="technician_mytickets.php">🎫 My Tickets</a>
    <a class="slide-link <?= $currentPage=='technician_reports.php'?'active':'' ?>" href="technician_reports.php">📑 Reports</a>
    <a class="slide-link <?= $currentPage=='technician_profile.php'?'active':'' ?>" href="technician_profile.php">👤 Profile</a>
    <div class="slide-divider"></div>
    <form action="logout.php" method="post"><button type="submit" class="logout-btn-wide">⏻ Logout</button></form>
  </div>
</aside>

<div class="wrap">
  <!-- Header -->
  <section class="hero">
    <button id="panelToggleBtn" class="menu-btn" aria-label="Open menu">≡</button>
    <div>
      <h1>My Profile</h1>
      <p>Keep your contact info and photo up to date</p>
    </div>
  </section>

  <!-- Profile -->
  <section class="profile">
    <div class="cover-outer">
      <div class="cover">
        <div class="cover-bg" style="<?php echo $coverStyle; ?>"></div>

        <!-- Avatar form -->
        <form id="avatarForm" method="post" enctype="multipart/form-data">
          <input type="hidden" name="action" value="save_avatar">
          <label class="avatar-wrap" for="avatarInput" title="Change photo">
            <img id="avatarPreview" src="<?= e($avatar) ?>" alt="profile photo">
            <div class="cam-strip">📷 Change photo</div>
          </label>
          <input id="avatarInput" class="hidden-input" type="file" name="avatar" accept="image/*">
        </form>
      </div>
    </div>

    <div class="head">
      <div class="name"><?= e($me['name']) ?></div>
      <div class="chips">
        <?php if ($AGE_COL): ?>
          <span class="chip">Age: <?= $ageDisplay ?: '—' ?></span>
        <?php endif; ?>
        <span class="chip">Assigned Block: <?= e($blockGenderText) ?></span>
      </div>
    </div>

    <!-- Phone & Age -->
    <div class="form-wrap">
      <form id="profileForm" method="post" class="form">
        <input type="hidden" name="action" value="save_phone_age">

        <div>
          <div class="label">Name</div>
          <div class="ro"><?= e($me['name']) ?></div>
        </div>
        <div>
          <div class="label">Email</div>
          <div class="ro"><?= e($me['email']) ?></div>
        </div>

        <div>
          <label class="label" for="ph">Phone Number</label>
          <input id="ph" class="input" type="text" name="phone"
                 value="<?= e($me['phone']) ?>"
                 placeholder="+60 1X-XXXXXXX"
                 <?= $PHONE_TS_COL && !$canEditPhoneNow ? 'disabled' : '' ?>>
          <?php if ($PHONE_TS_COL): ?>
            <div style="font-size:12px;color:<?= $canEditPhoneNow?'#b45309':'#64748b' ?>;margin-top:4px;">
              <?= $canEditPhoneNow ? '⚠ You can edit your phone number at most once every 30 days.' : e($nextEditMsg) ?>
            </div>
          <?php else: ?>
            <div style="font-size:12px;color:#b45309;margin-top:4px;">
              ⚠ Monthly phone edit limit requires a timestamp column (e.g., <code>phone_updated_at</code>) in <code>profile</code>.
            </div>
          <?php endif; ?>
        </div>

        <?php if ($AGE_COL): ?>
          <?php if ($ageDisplay): ?>
            <div>
              <div class="label">Age</div>
              <div class="ro">
                <?= (int)$ageDisplay ?>
                <span style="font-size:12px;color:#64748b;margin-left:8px;">(auto-updates every year)</span>
              </div>
            </div>
          <?php else: ?>
            <div>
              <label class="label" for="age">Age</label>
              <input id="age" class="input" type="number" min="10" max="100" name="age" placeholder="Enter your age (one-time)">
              <div style="font-size:12px;color:#b45309;margin-top:4px;">
                ⚠ You can set your age only once. It will increase automatically every year.
              </div>
            </div>
          <?php endif; ?>
        <?php endif; ?>

        <div>
          <div class="label">Specialty / Category</div>
          <div class="ro"><?= e($me['specialty'] ?: '-') ?></div>
        </div>

        <div class="actions">
          <button type="submit" class="btn primary">Save Changes</button>
          <a class="btn ghost" href="technician_profile.php">Reset</a>
        </div>
      </form>
    </div>

    <?php if ($flash): ?>
      <div id="flashText" data-kind="<?= e($flashKind) ?>" style="display:none"><?= e($flash) ?></div>
    <?php endif; ?>
  </section>
</div>

<!-- Toast -->
<div id="toast" class="toast">
  <div class="box" id="toastBox">
    <span id="toastIcon">ℹ️</span>
    <span id="toastMsg">Notice</span>
    <button class="close-x" onclick="hideToast()" aria-label="Close">✕</button>
  </div>
</div>

<script>
  // Slide menu
  function openPanel(){ document.getElementById("slidePanel").setAttribute("aria-hidden","false"); document.getElementById("slideOverlay").style.display="block"; }
  function closePanel(){ document.getElementById("slidePanel").setAttribute("aria-hidden","true");  document.getElementById("slideOverlay").style.display="none"; }
  document.getElementById("panelToggleBtn").addEventListener("click", openPanel);
  document.getElementById("panelClose").addEventListener("click", closePanel);
  document.getElementById("slideOverlay").addEventListener("click", closePanel);

  // Toast
  let toastTimer = null;
  function showToast(msg, kind='ok', ms=5000){
    const t = document.getElementById('toast');
    const m = document.getElementById('toastMsg');
    const i = document.getElementById('toastIcon');
    t.className = 'toast ' + (kind==='ok'?'ok':'err');
    i.textContent = (kind==='ok'?'✅':'⚠️');
    m.textContent = msg;
    t.style.display = 'block';
    if (toastTimer) clearTimeout(toastTimer);
    toastTimer = setTimeout(()=>{ t.style.display='none'; }, ms);
  }
  function hideToast(){
    const t = document.getElementById('toast');
    t.style.display='none';
    if (toastTimer) clearTimeout(toastTimer);
  }

  // Avatar auto-submit
  const avatarInput = document.getElementById('avatarInput');
  const avatarForm  = document.getElementById('avatarForm');
  const avatarPreview = document.getElementById('avatarPreview');

  avatarInput.addEventListener('change', function(){
    if (!this.files || !this.files[0]) return;
    const url = URL.createObjectURL(this.files[0]);
    avatarPreview.src = url;
    avatarPreview.onload = () => URL.revokeObjectURL(url);
    showToast('Uploading profile picture…','ok');
    avatarForm.submit();
  });

  // Client-side phone validation
  function isValidMYPhone(raw){
    const s = (raw || '').replace(/[^0-9+]/g,'');
    if (/^\+60\d{9,11}$/.test(s)) return true;
    if (/^01\d{8,9}$/.test(s)) return true;
    return false;
  }
  document.getElementById('profileForm')?.addEventListener('submit', function(e){
    const ph = document.getElementById('ph');
    if (ph && !ph.disabled){
      if (ph.value.trim() !== '' && !isValidMYPhone(ph.value.trim())){
        e.preventDefault();
        showToast('Invalid phone. Use +60… or 01… (digits/space/hyphen allowed).','err');
        ph.focus();
        return false;
      }
    }
  });

  // Show server flash as toast
  (function(){
    const el = document.getElementById('flashText');
    if (el){
      const kind = el.getAttribute('data-kind') === 'ok' ? 'ok' : 'err';
      showToast(el.textContent.trim(), kind);
    }
  })();
</script>

</body>
</html>
