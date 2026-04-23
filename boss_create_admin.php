<?php
@ini_set('session.use_strict_mode', 1);
if (PHP_VERSION_ID >= 70300) {
  session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>isset($_SERVER['HTTPS']),'httponly'=>true,'samesite'=>'Lax']);
}
session_start();

require_once 'config.php';
require_once 'csrf.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'boss_ups') {
  http_response_code(403);
  exit('Forbidden');
}

function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Handle POST (create)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_validate()) {
    http_response_code(400);
    exit('Invalid CSRF token');
  }

  $name  = trim($_POST['name']  ?? '');
  $email = trim($_POST['email'] ?? '');
  $pass  = (string)($_POST['password'] ?? '');

  if ($name === '' || $email === '' || $pass === '') {
    $_SESSION['error_message'] = 'All fields are required.';
    header('Location: admin_create_admin.php');
    exit;
  }

  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $_SESSION['error_message'] = 'Invalid email format.';
    header('Location: admin_create_admin.php');
    exit;
  }

  // Check existing email
  $stmt = $conn->prepare("SELECT id FROM profile WHERE email=? LIMIT 1");
  $stmt->bind_param('s', $email);
  $stmt->execute();
  $stmt->store_result();
  if ($stmt->num_rows > 0) {
    $stmt->close();
    $_SESSION['error_message'] = 'Email already exists.';
    header('Location: admin_create_admin.php');
    exit;
  }
  $stmt->close();

  $hash = password_hash($pass, PASSWORD_DEFAULT);

  // Insert admin
  $stmt = $conn->prepare("
    INSERT INTO profile (name, email, password, role, is_deleted)
    VALUES (?, ?, ?, 'admin', 0)
  ");
  $stmt->bind_param('sss', $name, $email, $hash);

  if ($stmt->execute()) {
    $stmt->close();
    $_SESSION['success_message'] = 'Admin account created.';
    // Send user back to Staff tab by default (you can change if you like)
    header('Location: admin_page.php?section=staff');
    exit;
  } else {
    $err = $conn->error;
    $stmt->close();
    $_SESSION['error_message'] = 'Failed to create admin: '.$err;
    header('Location: admin_create_admin.php');
    exit;
  }
}

// Otherwise GET: render a small form so opening this URL doesn’t throw “Invalid request method”.
$success = $_SESSION['success_message'] ?? '';
$error   = $_SESSION['error_message'] ?? '';
unset($_SESSION['success_message'], $_SESSION['error_message']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Create Admin</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;background:#f8fafc;margin:0;padding:24px;color:#0f172a}
    .wrap{max-width:520px;margin:0 auto;background:#fff;border:1px solid #e5e7eb;border-radius:14px;box-shadow:0 8px 24px rgba(0,0,0,.08);padding:18px}
    h1{margin:0 0 10px;font-size:20px}
    .muted{color:#64748b;margin-bottom:12px;font-size:13px}
    .row{display:grid;gap:10px;margin-top:8px}
    input{padding:10px;border:1px solid #d5dbe3;border-radius:10px;width:100%}
    .btn{background:#2563eb;color:#fff;border:0;padding:10px 12px;border-radius:10px;font-weight:700;cursor:pointer}
    .btn-ghost{background:#fff;border:1px solid #cbd5e1;color:#0f172a}
    .bar{display:flex;gap:8px;align-items:center;margin-top:12px}
    .toast{padding:10px;border-radius:10px;margin-bottom:10px;font-weight:600}
    .ok{background:#ecfdf5;border:1px solid #bbf7d0;color:#065f46}
    .err{background:#fef2f2;border:1px solid #fecaca;color:#991b1b}
  </style>
</head>
<body>
  <div class="wrap">
    <h1>Create Admin</h1>
    <div class="muted">Add another administrator account.</div>

    <?php if ($success): ?><div class="toast ok"><?= e($success) ?></div><?php endif; ?>
    <?php if ($error):   ?><div class="toast err"><?= e($error) ?></div><?php endif; ?>

    <form action="admin_create_admin.php" method="post" class="row" autocomplete="off">
      <?= csrf_field() ?>
      <input type="text"     name="name"     placeholder="Full name" required>
      <input type="email"    name="email"    placeholder="Email" required>
      <input type="password" name="password" placeholder="Password" required>
      <div class="bar">
        <button class="btn" type="submit">Create</button>
        <a class="btn-ghost" href="admin_page.php?section=staff">Back</a>
      </div>
    </form>
  </div>
</body>
</html>
