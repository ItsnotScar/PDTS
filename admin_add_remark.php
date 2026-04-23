<?php
// admin_add_remark.php (Option A - sends email with technician note + admin name)
@ini_set('display_errors', 0);
@session_start();
require_once 'config.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require __DIR__ . '/PHPMailer-master/src/Exception.php';
require __DIR__ . '/PHPMailer-master/src/PHPMailer.php';
require __DIR__ . '/PHPMailer-master/src/SMTP.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
  $_SESSION['error_message'] = 'Forbidden';
  header('Location: admin_page.php?section=tickets'); exit;
}

$ticketId  = (int)($_POST['ticket_id'] ?? 0);
$statusNow = trim((string)($_POST['current_status'] ?? ''));
$remark    = trim((string)($_POST['admin_remark'] ?? ''));
$countFake = isset($_POST['count_as_fake']) && $_POST['count_as_fake'] == '1';
$redirect  = $_POST['redirect'] ?? 'section=tickets';

if ($ticketId <= 0 || $remark === '') {
  $_SESSION['error_message'] = 'Missing ticket or remark.';
  header("Location: admin_page.php?$redirect"); exit;
}

/* ensure per-status remark columns exist */
$needed = ['remark_pending','remark_in_progress','remark_completed','remark_rejected'];
$res = $conn->query("SHOW COLUMNS FROM complaints");
$existingCols = [];
if ($res) { while ($r = $res->fetch_assoc()) { $existingCols[] = $r['Field']; } $res->close(); }
foreach ($needed as $col) {
  if (!in_array($col, $existingCols)) {
    $conn->query("ALTER TABLE complaints ADD COLUMN `$col` TEXT NULL");
  }
}

$col = 'remark_pending';
$low = strtolower($statusNow);
if ($low==='in progress') $col = 'remark_in_progress';
elseif ($low==='completed') $col = 'remark_completed';
elseif ($low==='rejected')  $col = 'remark_rejected';

/* update remark */
$stmt = $conn->prepare("UPDATE complaints SET $col = ?, updated_at = NOW() WHERE id=? AND is_deleted=0");
$stmt->bind_param('si', $remark, $ticketId);
$stmt->execute();
$stmt->close();

/* warning table if rejected + fake checkbox */
if ($low==='rejected' && $countFake) {
  $conn->query("
    CREATE TABLE IF NOT EXISTS student_warnings (
      id INT AUTO_INCREMENT PRIMARY KEY,
      student_id VARCHAR(64) NOT NULL,
      complaint_id INT NOT NULL,
      reason TEXT,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uniq_warning_ticket (complaint_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");
  $sid = '';
  $res = $conn->query("SELECT student_id FROM complaints WHERE id=$ticketId LIMIT 1");
  if ($res && $row = $res->fetch_assoc()) { $sid = (string)$row['student_id']; }
  if ($res) $res->close();

  if ($sid !== '') {
    $stmt = $conn->prepare("INSERT IGNORE INTO student_warnings (student_id, complaint_id, reason) VALUES (?,?,?)");
    $reason = 'Fake/Rejected ticket (admin remark)';
    $stmt->bind_param('sis', $sid, $ticketId, $reason);
    $stmt->execute(); $stmt->close();
  }
}

/* ───────────────────────────────
   Send email only if Completed/Rejected
   ─────────────────────────────── */
if (in_array($low, ['completed','rejected'], true)) {
  $q = $conn->prepare("
    SELECT c.title, c.category, c.subcategory, c.status,
           c.remark_completed, c.remark_rejected, c.proof_note, c.updated_at,
           p.name AS student_name, p.email AS student_email
    FROM complaints c
    JOIN profile p ON c.student_id = p.student_id
    WHERE c.id=? LIMIT 1
  ");
  $q->bind_param('i', $ticketId);
  $q->execute();
  $info = $q->get_result()->fetch_assoc();
  $q->close();

  if ($info) {
    $studentEmail = $info['student_email'];
    $studentName  = htmlspecialchars($info['student_name']);
    $title        = htmlspecialchars($info['title']);
    $category     = htmlspecialchars($info['category']);
    $subcategory  = htmlspecialchars($info['subcategory']);
    $proofNote    = trim($info['proof_note'] ?? '');
    $adminName    = htmlspecialchars($_SESSION['name'] ?? 'Admin');
    $statusBadge  = ($low==='completed')
      ? "<span style='color:#16a34a;font-weight:700;'>Completed</span>"
      : "<span style='color:#dc2626;font-weight:700;'>Rejected</span>";
    $remarkValue  = nl2br(htmlspecialchars($remark));
    $updated_at   = date('d M Y, h:i A', strtotime($info['updated_at']));

    // Technician note (optional)
    $proofSection = $proofNote
      ? "<tr><td style='padding:8px;border:1px solid #e5e7eb;'><strong>Technician Note</strong></td>
          <td style='padding:8px;border:1px solid #e5e7eb;'>".nl2br(htmlspecialchars($proofNote))."</td></tr>"
      : '';

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
      $mail->addAddress($studentEmail, $studentName);
      $mail->isHTML(true);
      $mail->Subject = "Ticket Update: {$title} ({$statusNow})";
      $mail->Body = "
        <div style='font-family:Arial,sans-serif;background:#f9fafb;padding:20px;border-radius:8px;'>
          <h2 style='color:#1e3a8a;margin-bottom:10px;'>Ticket Status Update</h2>
          <p>Dear <strong>{$studentName}</strong>,</p>
          <p>Your complaint ticket has been updated by <strong>{$adminName}</strong>.</p>
          <table style='width:100%;border-collapse:collapse;margin:15px 0;'>
            <tr><td style='padding:8px;border:1px solid #e5e7eb;width:30%;'><strong>Title</strong></td>
                <td style='padding:8px;border:1px solid #e5e7eb;'>{$title}</td></tr>
            <tr><td style='padding:8px;border:1px solid #e5e7eb;'><strong>Category</strong></td>
                <td style='padding:8px;border:1px solid #e5e7eb;'>{$category}</td></tr>
            <tr><td style='padding:8px;border:1px solid #e5e7eb;'><strong>Subcategory</strong></td>
                <td style='padding:8px;border:1px solid #e5e7eb;'>{$subcategory}</td></tr>
            <tr><td style='padding:8px;border:1px solid #e5e7eb;'><strong>Status</strong></td>
                <td style='padding:8px;border:1px solid #e5e7eb;'>{$statusBadge}</td></tr>
            {$proofSection}
            <tr><td style='padding:8px;border:1px solid #e5e7eb;'><strong>Remark</strong></td>
                <td style='padding:8px;border:1px solid #e5e7eb;'>{$remarkValue}</td></tr>
            <tr><td style='padding:8px;border:1px solid #e5e7eb;'><strong>Date Updated</strong></td>
                <td style='padding:8px;border:1px solid #e5e7eb;'>{$updated_at}</td></tr>
          </table>
          <p style='margin-top:20px;'>You can log in to your student dashboard to view full details.</p>
          <p style='color:#6b7280;font-size:12px;margin-top:20px;'>This is an automated message from Dormitory Ticketing System (PDTS).</p>
        </div>";
      $mail->AltBody = "Your ticket '{$title}' has been updated to {$statusNow}. Remark: {$remark}";

      $mail->send();
      $_SESSION['success_message'] = 'Remark saved and email sent.';
    } catch (Exception $e) {
      $_SESSION['success_message'] = 'Remark saved (email failed to send).';
    }
  }
}

header("Location: admin_page.php?$redirect");
exit;
?>
