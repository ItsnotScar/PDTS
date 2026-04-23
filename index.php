<?php
session_start();

// Collect messages from session
$errors = [
    'login'    => $_SESSION['login_error'] ?? '',
    'register' => $_SESSION['register_error'] ?? ''
];
$success     = $_SESSION['register_success'] ?? '';
$page_error  = $_SESSION['error_message'] ?? ''; 
$activeForm  = $_SESSION['active_form'] ?? 'login';

// Clear flash messages
session_unset();

function showError($msg) {
    return !empty($msg) ? "<p class='text-red-600 mb-2 text-center'>$msg</p>" : '';
}
function showSuccess($msg) {
    return !empty($msg) ? "<p class='text-green-600 mb-2 text-center'>$msg</p>" : '';
}
?>
<!DOCTYPE html>
<html lang="en">  
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Dormitory Ticketing System</title>

  <!-- ✅ Favicon -->
  <link rel="icon" type="image/png" sizes="32x16" href="assets/favicon.png">

  <!-- ✅ Tailwind CSS -->
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="h-screen flex items-center justify-center bg-gray-100">

  <!-- Background -->
  <div class="absolute inset-0">
    <img src="assets/dormitory.jpg" alt="Dormitory" class="w-full h-full object-cover opacity-40">
    <div class="absolute inset-0 bg-black bg-opacity-40"></div>
  </div>

  <!-- Card -->
  <div class="relative z-10 w-full max-w-lg p-8 bg-white rounded-xl shadow-lg">
    <!-- Logo + Title -->
    <div class="flex flex-col items-center mb-6">
      <img src="assets/logo2.png" alt="Logo" class="w-25 h-20 mb-3">
      <h1 class="text-2xl font-bold text-center text-blue-600">Dormitory Ticketing System</h1>
    </div>

    <!-- Tabs -->
    <div class="flex mb-6 border-b">
      <button onclick="showForm('login')" id="tab-login"
        class="flex-1 py-2 text-center font-semibold border-b-2 <?= $activeForm=='login' ? 'border-blue-600 text-blue-600' : 'border-transparent' ?>">
        Login
      </button>
      <button onclick="showForm('register')" id="tab-register"
        class="flex-1 py-2 text-center font-semibold border-b-2 <?= $activeForm=='register' ? 'border-blue-600 text-blue-600' : 'border-transparent' ?>">
        Register
      </button>
    </div>

    <!-- Alerts -->
    <?= showError($errors['login']); ?>
    <?= showError($errors['register']); ?>
    <?= showError($page_error); ?>
    <?= showSuccess($success); ?>

    <!-- Login Form -->
    <form action="login_register.php" method="post" id="form-login" class="<?= $activeForm=='register' ? 'hidden' : '' ?>">
      <div class="mb-4">
        <label class="block text-sm font-medium">Email</label>
        <input type="email" name="email" required class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500">
      </div>
      <div class="mb-4">
        <label class="block text-sm font-medium">Password</label>
        <input type="password" name="password" required class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500">
      </div>
      <button type="submit" name="login" class="w-full bg-blue-600 text-white py-2 rounded-lg hover:bg-blue-700">
        Login
      </button>

      <!-- ✅ Forgot password -->
      <p class="text-center mt-3">
        <a href="forgot_password.php" class="text-blue-600 hover:underline">Forgot your password?</a>
      </p>
    </form>

    <!-- Register Form -->
    <form action="login_register.php" method="post" id="form-register" class="<?= $activeForm=='register' ? '' : 'hidden' ?>">
      <div class="grid grid-cols-2 gap-4">
        <div class="col-span-2">
          <label class="block text-sm font-medium">Full Name (UPPERCASE)</label>
          <input type="text" name="name" required class="w-full px-4 py-2 border rounded-lg">
        </div>
        <div class="col-span-2">
          <label class="block text-sm font-medium">Email</label>
          <input type="email" name="email" required class="w-full px-4 py-2 border rounded-lg">
        </div>
        <div>
          <label class="block text-sm font-medium">Student ID</label>
          <input type="text" name="student_id" required class="w-full px-4 py-2 border rounded-lg">
        </div>
        <div>
          <label class="block text-sm font-medium">Phone</label>
          <input type="text" name="phone" required class="w-full px-4 py-2 border rounded-lg">
        </div>
        <div class="col-span-2">
          <label class="block text-sm font-medium">Gender</label>
          <select name="gender" id="gender" required class="w-full px-4 py-2 border rounded-lg">
            <option value="">Select</option>
            <option value="male">Male</option>
            <option value="female">Female</option>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium">Block</label>
          <select name="block" id="block" required disabled class="w-full px-4 py-2 border rounded-lg">
            <option value="">--Select Block--</option>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium">Room Number</label>
          <input type="number" name="room_number" id="room_number" required disabled class="w-full px-4 py-2 border rounded-lg">
        </div>
        <div>
          <label class="block text-sm font-medium">Password</label>
          <input type="password" name="password" required class="w-full px-4 py-2 border rounded-lg">
        </div>
        <div>
          <label class="block text-sm font-medium">Confirm Password</label>
          <input type="password" name="confirm_password" required class="w-full px-4 py-2 border rounded-lg">
        </div>
      </div>
      <button type="submit" name="register" class="w-full bg-green-600 text-white py-2 rounded-lg hover:bg-green-700 mt-4">
        Register
      </button>
    </form>
  </div>

  <script>
  document.addEventListener("DOMContentLoaded", function () {
    // Toggle forms
    window.showForm = function(form) {
      document.getElementById("form-login").classList.add("hidden");
      document.getElementById("form-register").classList.add("hidden");
      document.getElementById("tab-login").classList.remove("border-blue-600","text-blue-600");
      document.getElementById("tab-register").classList.remove("border-blue-600","text-blue-600");

      if (form === "login") {
        document.getElementById("form-login").classList.remove("hidden");
        document.getElementById("tab-login").classList.add("border-blue-600","text-blue-600");
      } else {
        document.getElementById("form-register").classList.remove("hidden");
        document.getElementById("tab-register").classList.add("border-blue-600","text-blue-600");
      }
    };

    // Gender → Block → Room logic
    const genderSelect = document.getElementById("gender");
    const blockSelect = document.getElementById("block");
    const roomInput = document.getElementById("room_number");

    genderSelect.addEventListener("change", function() {
      blockSelect.innerHTML = "<option value=''>--Select Block--</option>";
      roomInput.value = "";
      roomInput.disabled = true;

      if (this.value === "male") {
        blockSelect.disabled = false;
        ["A","B","C","D","E","F"].forEach(b => {
          blockSelect.add(new Option("Block " + b, b));
        });
      } else if (this.value === "female") {
        blockSelect.disabled = false;
        ["A","B"].forEach(b => {
          blockSelect.add(new Option("Block " + b, b));
        });
      } else {
        blockSelect.disabled = true;
      }
    });

    blockSelect.addEventListener("change", function() {
      roomInput.disabled = (this.value === "");
      if (this.value === "") roomInput.value = "";
    });
  });
  </script>
</body>
</html>
