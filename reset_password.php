<?php
require_once 'config.php';
session_start();

// --- Validate token ---
$token = $_GET['token'] ?? '';
if (!$token) {
  die("<h2 style='text-align:center;color:red;'>Invalid link.</h2>");
}

// Check token in DB
$stmt = $conn->prepare("SELECT email, expires_at FROM password_resets WHERE token=? LIMIT 1");
$stmt->bind_param("s", $token);
$stmt->execute();
$result = $stmt->get_result();
$reset = $result->fetch_assoc();

if (!$reset) {
  die("<h2 style='text-align:center;color:red;'>Invalid or expired token.</h2>");
}

// Check expiry
if (strtotime($reset['expires_at']) < time()) {
  die("<h2 style='text-align:center;color:red;'>This reset link has expired.</h2>");
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $newPassword = $_POST['password'] ?? '';
  $confirm = $_POST['confirm_password'] ?? '';

  if ($newPassword === '' || $newPassword !== $confirm) {
    $error = "Passwords do not match!";
  } else {
    $hash = password_hash($newPassword, PASSWORD_DEFAULT);

    // Update password in profile table
    $email = $reset['email'];
    $update = $conn->prepare("UPDATE profile SET password=? WHERE email=?");
    $update->bind_param("ss", $hash, $email);
    $update->execute();

    // Delete used token
    $del = $conn->prepare("DELETE FROM password_resets WHERE token=?");
    $del->bind_param("s", $token);
    $del->execute();

    echo "
    <div style='
      display:flex;align-items:center;justify-content:center;
      height:100vh;background:url(assets/dormitory.jpg) no-repeat center/cover;
      font-family:Poppins,sans-serif;'>
      <div style='
        background:rgba(255,255,255,0.95);
        padding:40px 50px;
        border-radius:16px;
        text-align:center;
        box-shadow:0 5px 15px rgba(0,0,0,0.1);'>
        <img src=\"assets/logo.png\" alt=\"Logo\" style=\"width:90px;margin-bottom:15px;\">
        <h2 style='color:#15803d;margin-bottom:10px;font-size:1.5rem;'>✅ Password Reset Successful!</h2>
        <p style='color:#333;'>You can now log in with your new password.</p>
        <a href='index.php' style='
          display:inline-block;margin-top:20px;padding:10px 20px;
          background:#2563eb;color:white;border-radius:8px;text-decoration:none;'>
          Go to Login
        </a>
      </div>
    </div>";
    exit;
  }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Reset Password | Dormitory Ticketing System</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body class="flex items-center justify-center min-h-screen bg-gray-100 relative">

  <!-- Background -->
  <div class="absolute inset-0">
    <img src="assets/dormitory.jpg" class="w-full h-full object-cover opacity-40" alt="Dormitory">
    <div class="absolute inset-0 bg-black bg-opacity-40"></div>
  </div>

  <!-- Card -->
  <div class="relative z-10 bg-white rounded-xl shadow-lg p-8 w-full max-w-md text-center">
    <img src="assets/logo.png" alt="Logo" class="mx-auto mb-4 w-20">
    <h1 class="text-2xl font-semibold text-blue-600 mb-4">Reset Your Password</h1>
    
    <?php if (!empty($error)): ?>
      <p class="text-red-600 mb-4"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>

    <form method="POST" class="space-y-4">
      <!-- New Password -->
      <div class="text-left relative">
        <label class="block text-sm font-medium mb-1">New Password</label>
        <input type="password" name="password" id="password" required
          class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500 pr-10">
        <button type="button" onclick="togglePassword('password', 'eye1')"
          class="absolute right-3 top-9 text-gray-500">
          <i data-lucide="eye" id="eye1"></i>
        </button>
      </div>

      <!-- Confirm Password -->
      <div class="text-left relative">
        <label class="block text-sm font-medium mb-1">Confirm Password</label>
        <input type="password" name="confirm_password" id="confirm_password" required
          class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500 pr-10">
        <button type="button" onclick="togglePassword('confirm_password', 'eye2')"
          class="absolute right-3 top-9 text-gray-500">
          <i data-lucide="eye" id="eye2"></i>
        </button>
      </div>

      <button type="submit"
        class="w-full bg-blue-600 text-white py-2 rounded-lg hover:bg-blue-700 transition">
        Update Password
      </button>
    </form>
  </div>

  <script>
    lucide.createIcons();

    function togglePassword(inputId, iconId) {
      const input = document.getElementById(inputId);
      const icon = document.getElementById(iconId);
      if (input.type === "password") {
        input.type = "text";
        icon.setAttribute("data-lucide", "eye-off");
      } else {
        input.type = "password";
        icon.setAttribute("data-lucide", "eye");
      }
      lucide.createIcons();
    }
  </script>
</body>
</html>