<?php
require_once 'config.php';

// Get the token from the URL
$token = $_GET['token'] ?? '';

$status = '';
$message = '';
if ($token) {
    // Check if token exists
    $stmt = $conn->prepare("SELECT id FROM profile WHERE verification_token=? LIMIT 1");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();

    if ($user) {
        // Update user to verified
        $update = $conn->prepare("UPDATE profile SET verified=1, verification_token=NULL WHERE id=?");
        $update->bind_param("i", $user['id']);
        $update->execute();

        $status = 'success';
        $message = 'Your email has been successfully verified! You can now log in to your account.';
    } else {
        $status = 'error';
        $message = 'Invalid or expired verification link. Please check your email or contact the admin.';
    }
} else {
    $status = 'error';
    $message = 'Missing verification token.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Email Verification</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap" rel="stylesheet">
<style>
    body {
        font-family: 'Poppins', sans-serif;
        display: flex;
        justify-content: center;
        align-items: center;
        min-height: 100vh;
        background: url('assets/dormitory.jpg') no-repeat center center/cover;
        margin: 0;
    }

    .verify-container {
        background: rgba(255,255,255,0.9);
        padding: 40px;
        border-radius: 12px;
        text-align: center;
        width: 420px;
        box-shadow: 0 0 10px rgba(0,0,0,0.2);
        backdrop-filter: blur(5px);
    }

    .verify-container h2 {
        font-size: 24px;
        margin-bottom: 15px;
        font-weight: 600;
    }

    .verify-container p {
        color: #444;
        font-size: 15px;
        margin-bottom: 25px;
    }

    .btn {
        display: inline-block;
        padding: 10px 20px;
        background: #4e73df;
        color: #fff;
        border-radius: 6px;
        text-decoration: none;
        transition: 0.3s;
        font-weight: 500;
    }

    .btn:hover {
        background: #3756c5;
    }

    .success-icon {
        font-size: 50px;
        color: #28a745;
        margin-bottom: 10px;
    }

    .error-icon {
        font-size: 50px;
        color: #dc3545;
        margin-bottom: 10px;
    }
</style>
</head>
<body>
    <div class="verify-container">
        <?php if ($status === 'success'): ?>
            <div class="success-icon">✅</div>
            <h2>Email Verified Successfully!</h2>
            <p><?= htmlspecialchars($message) ?></p>
            <a href="index.php" class="btn">Go to Login</a>
        <?php else: ?>
            <div class="error-icon">❌</div>
            <h2>Verification Failed</h2>
            <p><?= htmlspecialchars($message) ?></p>
            <a href="index.php" class="btn">Back to Login</a>
        <?php endif; ?>
    </div>
</body>
</html>