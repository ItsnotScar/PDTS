<?php
// config.php
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT); // helpful errors in dev


$host = "localhost";          // or "127.0.0.1" if you prefer TCP
$user = "root";
$password = "";
$database = "profile_db";   // ✅ make sure this DB contains your profile table

$conn = new mysqli($host, $user, $password, $database);
$conn->set_charset('utf8mb4');    // ✅ proper charset for names/emails

// (optional) consistent PHP timezone for timestamps
date_default_timezone_set('Asia/Kuala_Lumpur');



// Count today's tickets for the student
$dailyCount = 0;
try {
  $countStmt = $conn->prepare("SELECT COUNT(*) AS total FROM complaints WHERE student_id=? AND DATE(created_at)=CURDATE()");
  $countStmt->bind_param("s", $studentId);
  $countStmt->execute();
  $dailyCount = (int)($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
} catch (Throwable $t) {}
