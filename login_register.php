<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require __DIR__ . '/PHPMailer-master/src/Exception.php';
require __DIR__ . '/PHPMailer-master/src/PHPMailer.php';
require __DIR__ . '/PHPMailer-master/src/SMTP.php';

session_start();
require_once 'config.php';


// (Optional in dev)
// mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function back($form, $err = '', $ok = '') {
  $_SESSION['active_form'] = $form;
  if ($err) $_SESSION[$form . '_error'] = $err;
  if ($ok)  $_SESSION['register_success'] = $ok;
  header("Location: index.php");
  exit();
}

/* ===================== REGISTER (students only) ===================== */
if (isset($_POST['register'])) {
  $rawName     = trim($_POST['name'] ?? '');
  $name        = strtoupper($rawName);
  $email       = strtolower(trim($_POST['email'] ?? ''));
  $phone       = trim($_POST['phone'] ?? '');
  $gender      = trim($_POST['gender'] ?? '');
  $role        = 'student';
  $password    = $_POST['password'] ?? '';
  $confirm     = $_POST['confirm_password'] ?? '';
  $student_id  = strtoupper(trim($_POST['student_id'] ?? ''));
  $block       = strtoupper(trim($_POST['block'] ?? ''));
  $room_number = (int)($_POST['room_number'] ?? 0);

  // --- Validation checks ---
  if ($name !== $rawName) back('register', "Name must be in UPPERCASE.");
  if ($password === '' || $password !== $confirm) back('register', "Passwords do not match.");

  /* ===== Password policy (students): min 5 chars, must include letters AND numbers ===== */
  $lenOK  = strlen($password) >= 5;
  $hasLet = (bool) preg_match('/[A-Za-z]/', $password);
  $hasNum = (bool) preg_match('/\d/', $password);

  if (!($lenOK && $hasLet && $hasNum)) {
    // Build a compact checklist for the UI (✅/❌)
    $checklist = '
  <div style="margin-top:6px; font-size:12px; line-height:1.35; color:#64748b;">
    <div style="font-weight:700; margin-bottom:4px;">Password requirements</div>
    <ul style="list-style:none; padding:0; margin:0; display:grid; gap:4px;">
      <li>'.($lenOK ? '✅' : '❌').' at least <strong>5 characters</strong></li>
      <li>'.($hasLet ? '✅' : '❌').' contains <strong>letters</strong> (A–Z)</li>
      <li>'.($hasNum ? '✅' : '❌').' contains <strong>numbers</strong> (0–9)</li>
    </ul>
  </div>';

    back('register', "Please meet the password requirements below:$checklist");
  }
  /* ===== End password policy ===== */

  // ✅ Check if details match with valid_student table
  $stmt = $conn->prepare("
    SELECT 1 
    FROM valid_student 
    WHERE student_id = ? 
      AND UPPER(name) = ? 
      AND LOWER(gender) = LOWER(?) 
      AND UPPER(block) = ? 
      AND room_number = ?
    LIMIT 1
  ");
  $stmt->bind_param("ssssi", $student_id, $name, $gender, $block, $room_number);
  $stmt->execute();
  $result = $stmt->get_result();

  if (!$result->fetch_row()) {
    back('register', "Your details do not match our student records. Please contact the admin.");
  }

  // ✅ Prevent duplicate Student ID
  $stmt = $conn->prepare("SELECT 1 FROM profile WHERE student_id=? LIMIT 1");
  $stmt->bind_param("s", $student_id);
  $stmt->execute();
  if ($stmt->get_result()->fetch_row()) back('register', "This Student ID is already registered.");

  // ✅ Prevent duplicate email
  $stmt = $conn->prepare("SELECT 1 FROM profile WHERE email=? LIMIT 1");
  $stmt->bind_param("s", $email);
  $stmt->execute();
  if ($stmt->get_result()->fetch_row()) back('register', "This email is already registered.");

  // ✅ Prevent more than 2 students in same room
  $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM profile WHERE room_number=? AND block=? AND is_deleted=0");
  $stmt->bind_param("is", $room_number, $block);
  $stmt->execute();
  $cnt = (int)($stmt->get_result()->fetch_assoc()['cnt'] ?? 0);
  if ($cnt >= 2) back('register', "Room $room_number in Block $block already has 2 students.");

  // ✅ Generate verification token
  $verification_token = bin2hex(random_bytes(32));
  $hash = password_hash($password, PASSWORD_DEFAULT);

  // ✅ Insert new student into profile
  $stmt = $conn->prepare("
    INSERT INTO profile 
      (name, email, password, phone, gender, student_id, block, room_number, role, verified, verification_token)
    VALUES (?,?,?,?,?,?,?,?,?,0,?)
  ");
  $stmt->bind_param(
    "sssssssiss",
    $name,
    $email,
    $hash,
    $phone,
    $gender,
    $student_id,
    $block,
    $room_number,
    $role,
    $verification_token
  );
  $stmt->execute();

  // ✅ Send verification email
  $mail = new PHPMailer(true);
  $mail->SMTPDebug = 0;
  $mail->isSMTP();
  $mail->Host = 'smtp.gmail.com';
  $mail->SMTPAuth = true;
  $mail->Username = 'oscartuak@gmail.com'; // your Gmail
  $mail->Password = 'vupc bjly nwdg cgkn'; // Gmail app password
  $mail->SMTPSecure = 'tls';
  $mail->Port = 587;

  $mail->setFrom('yourgmail@gmail.com', 'Dormitory Ticketing System');
  $mail->addAddress($email, $name);
  $mail->isHTML(true);
  $mail->Subject = 'Verify your email address';
  $mail->Body = "
    <h3>Welcome, {$name}!</h3>
    <p>Click the link below to verify your email:</p>
    <a href='https://pdts.cloud/verify.php?token={$verification_token}'>
      Verify My Email
    </a>
    <br><br>
    <small>If you didn’t sign up, ignore this email.</small>
  ";

  if ($mail->send()) {
    back('login', '', "Registration successful! Please check your email to verify your account.");
  } else {
    back('register', "Registration successful, but email failed to send. Try verifying manually.");
  }
}

/* ===================== LOGIN (all roles) ===================== */
if (isset($_POST['login'])) {
  $email = strtolower(trim($_POST['email'] ?? ''));
  $pass  = $_POST['password'] ?? '';

  if ($email === '' || $pass === '') {
    back('login', "Please enter email and password.");
  }

  // fetch by email; ignore soft-deleted accounts
  $stmt = $conn->prepare("
    SELECT id, name, email, password, role, student_id, block, room_number, gender, verified, is_deleted
    FROM profile
    WHERE email=? LIMIT 1
  ");
  $stmt->bind_param("s", $email);
  $stmt->execute();
  $user = $stmt->get_result()->fetch_assoc();

  if (!$user || (int)$user['is_deleted'] === 1 || !password_verify($pass, $user['password'])) {
    back('login', "Incorrect email or password.");
  }

  // ✅ Require verification only for students
  if ($user['role'] === 'student' && (int)$user['verified'] === 0) {
    back('login', "Please verify your email before logging in.");
  }

  // ✅ Set sessions
  $_SESSION['profile_id']  = (int)$user['id'];
  $_SESSION['user_id']     = (int)$user['id'];
  $_SESSION['name']        = $user['name'];
  $_SESSION['email']       = $user['email'];
  $_SESSION['role']        = $user['role'];
  $_SESSION['block']       = $user['block'];
  $_SESSION['student_id']  = $user['student_id'];
  $_SESSION['room_number'] = $user['room_number'];
  $_SESSION['gender']      = strtolower(trim((string)($user['gender'] ?? '')));

  // ✅ Redirect by role
  switch ($user['role']) {
    case 'ketua_penyelia': header("Location: ketua_penyelia_page.php"); break;
    case 'boss_ups':        header("Location: boss_ups_page.php");       break;
    case 'penyelia':       header("Location: penyelia_page.php");       break;
    case 'technician':     header("Location: technician_page.php");     break;
    case 'student':        header("Location: student_page.php");        break;
    case 'admin':          header("Location: admin_page.php");          break;
    default:               header("Location: index.php");               break;
  }
  exit();
}

// Fallback
header("Location: index.php");
exit();
?>
