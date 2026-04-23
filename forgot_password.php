<?php
// Load PHPMailer + config
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require __DIR__ . '/PHPMailer-master/src/Exception.php';
require __DIR__ . '/PHPMailer-master/src/PHPMailer.php';
require __DIR__ . '/PHPMailer-master/src/SMTP.php';
require_once 'config.php';

session_start();
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $email = trim($_POST['email'] ?? '');

  if ($email === '') {
    $message = "<p class='text-red-600'>Please enter your email.</p>";
  } else {
    // Check if email exists in profile table
    $stmt = $conn->prepare("SELECT id FROM profile WHERE email=? LIMIT 1");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();

    if (!$user) {
      $message = "<p class='text-red-600'>No account found with that email.</p>";
    } else {
      // Generate token
      $token = bin2hex(random_bytes(32));
      $expires_at = date('Y-m-d H:i:s', strtotime('+1 hour'));

      // Delete old tokens
      $conn->query("DELETE FROM password_resets WHERE email='" . $conn->real_escape_string($email) . "'");

      // Insert new token
      $stmt = $conn->prepare("INSERT INTO password_resets (email, token, expires_at) VALUES (?, ?, ?)");
      $stmt->bind_param("sss", $email, $token, $expires_at);
      $stmt->execute();

      // Send email
      $mail = new PHPMailer(true);
      try {
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'oscartuak@gmail.com';
        $mail->Password = 'vupc bjly nwdg cgkn'; // Gmail App Password
        $mail->SMTPSecure = 'tls';
        $mail->Port = 587;

        $mail->setFrom('oscartuak@gmail.com', 'Dormitory Ticketing System');
        $mail->addAddress($email);
        $mail->isHTML(true);
        $mail->Subject = 'Password Reset Request';
        $mail->Body = "
          <h3>Password Reset Request</h3>
          <p>Click the link below to reset your password. This link will expire in 1 hour:</p>
          <a href='http://localhost/PDTS/reset_password.php?token={$token}'>
            Reset My Password
          </a>
          <br><br>
          <small>If you didn’t request this, ignore this email.</small>
        ";

        $mail->send();
        $message = "<p class='text-green-600'>Reset link sent! Check your email inbox.</p>";
      } catch (Exception $e) {
        $message = "<p class='text-red-600'>Failed to send reset link. Try again later.</p>";
      }
    }
  }
}
?>

<!DOCTYPE html>
<html lang="en">

<link rel="icon" type="image/png" href="assets/favicon.png" sizes="32x32">
<link rel="icon" type="image/png" href="assets/favicon.png" sizes="16x16">
<link rel="apple-touch-icon" href="assets/favicon.png">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Forgot Password | Dormitory Ticketing System</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="flex items-center justify-center min-h-screen bg-gray-100 relative">

  <!-- Background -->
  <div class="absolute inset-0">
    <img src="assets/dormitory.jpg" class="w-full h-full object-cover opacity-40" alt="Dormitory">
    <div class="absolute inset-0 bg-black bg-opacity-40"></div>
  </div>

  <div class="relative z-10 bg-white rounded-xl shadow-lg p-8 w-full max-w-md text-center">
    <img src="assets/logo.png" alt="Logo" class="mx-auto mb-4 w-20">
    <h1 class="text-2xl font-semibold text-blue-600 mb-4">Forgot Password</h1>

    <p class="text-gray-600 mb-4">Enter your registered email address and we’ll send you a password reset link.</p>

    <?= $message ?>

    <form method="POST">
      <div class="mb-6 text-left">
        <label class="block text-sm font-medium mb-1">Email Address</label>
        <input type="email" name="email" required
          class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500">
      </div>
      <button type="submit"
        class="w-full bg-blue-600 text-white py-2 rounded-lg hover:bg-blue-700 transition">
        Send Reset Link
      </button>
    </form>

    <div class="mt-4">
      <a href="index.php" class="text-blue-600 text-sm hover:underline">Back to Login</a>
    </div>
  </div>
</body>
</html>
