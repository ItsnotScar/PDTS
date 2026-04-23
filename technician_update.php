
<?php
session_start();
require_once 'config.php';

/* Load PHPMailer */
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require __DIR__ . '/PHPMailer-master/src/Exception.php';
require __DIR__ . '/PHPMailer-master/src/PHPMailer.php';
require __DIR__ . '/PHPMailer-master/src/SMTP.php';

/* Guard */
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'technician') {
  header("Location: ./index.php");
  exit();
}

$techId = (int)$_SESSION['user_id'];
$techName = htmlspecialchars($_SESSION['name'] ?? 'Technician');
$complaintId = (int)($_POST['id'] ?? 0);
$newStatus   = trim($_POST['status'] ?? '');
$proofNote   = trim($_POST['proof_note'] ?? '');
$uploadedPath = '';
$uploadedSize = 0;
$uploadedMime = '';

if ($complaintId <= 0 || !in_array($newStatus, ['In Progress', 'Completed'], true)) {
  header("Location: technician_mytickets.php?err=invalid_input");
  exit();
}

/* Optional file upload (Completed only) */
if ($newStatus === 'Completed' && !empty($_FILES['proof_attachment']['name']) && is_uploaded_file($_FILES['proof_attachment']['tmp_name'])) {
  $f = $_FILES['proof_attachment'];
  if ($f['error'] === UPLOAD_ERR_OK) {
    $dir = __DIR__ . '/uploads/proofs';
    if (!is_dir($dir)) mkdir($dir, 0775, true);

    $ext = pathinfo($f['name'], PATHINFO_EXTENSION);
    $fname = 'proof_' . $complaintId . '_' . time() . ($ext ? '.' . preg_replace('/[^a-zA-Z0-9]/', '', $ext) : '');
    $targetAbs = $dir . '/' . $fname;
    $targetRel = 'uploads/proofs/' . $fname;

    if (move_uploaded_file($f['tmp_name'], $targetAbs)) {
      $uploadedPath = $targetRel;
      $uploadedSize = (int)$f['size'];
      $uploadedMime = (string)($f['type'] ?? '');
    }
  }
}

/* Begin transaction */
$conn->begin_transaction();

try {
  if ($newStatus === 'In Progress') {
    // simple update when technician starts work
    $stmt = $conn->prepare("
      UPDATE complaints
      SET status='In Progress', updated_at=NOW()
      WHERE id=? AND assigned_to=? AND is_deleted=0
    ");
    $stmt->bind_param("ii", $complaintId, $techId);
    $stmt->execute();
    $stmt->close();
  } else {
    // completed with optional proof
    $stmt = $conn->prepare("
      UPDATE complaints
      SET status='Completed', proof_note=?, updated_at=NOW()
      WHERE id=? AND assigned_to=? AND is_deleted=0
    ");
    $stmt->bind_param("sii", $proofNote, $complaintId, $techId);
    $stmt->execute();
    $stmt->close();

    // if uploaded, insert record into attachments table
    if ($uploadedPath) {
      $ins = $conn->prepare("
        INSERT INTO complaint_attachments (complaint_id, file_path, file_size, mime_type)
        VALUES (?,?,?,?)
      ");
      $ins->bind_param("isis", $complaintId, $uploadedPath, $uploadedSize, $uploadedMime);
      $ins->execute();
      $ins->close();
    }

    // ───────────────────────────────
    // Email Notification to Student (Completed)
    // ───────────────────────────────
    $q = $conn->prepare("
      SELECT c.title, c.category, c.subcategory, c.updated_at,
             p.name AS student_name, p.email AS student_email
      FROM complaints c
      JOIN profile p ON c.student_id = p.student_id
      WHERE c.id=? LIMIT 1
    ");
    $q->bind_param("i", $complaintId);
    $q->execute();
    $info = $q->get_result()->fetch_assoc();
    $q->close();

    if ($info) {
      $studentEmail = $info['student_email'];
      $studentName  = htmlspecialchars($info['student_name']);
      $title        = htmlspecialchars($info['title']);
      $category     = htmlspecialchars($info['category']);
      $subcategory  = htmlspecialchars($info['subcategory']);
      $statusBadge  = "<span style='color:#16a34a;font-weight:700;'>Completed</span>";
      $proofNoteHtml = $proofNote ? nl2br(htmlspecialchars($proofNote)) : '—';
      $updated_at   = date('d M Y, h:i A', strtotime($info['updated_at']));
      $proofLink    = $uploadedPath ? "<a href='http://localhost/PDTS/{$uploadedPath}' target='_blank'>View Proof Attachment</a>" : '—';

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
        $mail->Subject = "Ticket Update: {$title} (Completed)";
        $mail->Body = "
          <div style='font-family:Arial,sans-serif;background:#f9fafb;padding:20px;border-radius:8px;'>
            <h2 style='color:#1e3a8a;margin-bottom:10px;'>Ticket Status Update</h2>
            <p>Dear <strong>{$studentName}</strong>,</p>
            <p>Your complaint ticket has been updated by <strong>Technician {$techName}</strong>.</p>
            <table style='width:100%;border-collapse:collapse;margin:15px 0;'>
              <tr><td style='padding:8px;border:1px solid #e5e7eb;width:30%;'><strong>Title</strong></td>
                  <td style='padding:8px;border:1px solid #e5e7eb;'>{$title}</td></tr>
              <tr><td style='padding:8px;border:1px solid #e5e7eb;'><strong>Category</strong></td>
                  <td style='padding:8px;border:1px solid #e5e7eb;'>{$category}</td></tr>
              <tr><td style='padding:8px;border:1px solid #e5e7eb;'><strong>Subcategory</strong></td>
                  <td style='padding:8px;border:1px solid #e5e7eb;'>{$subcategory}</td></tr>
              <tr><td style='padding:8px;border:1px solid #e5e7eb;'><strong>Status</strong></td>
                  <td style='padding:8px;border:1px solid #e5e7eb;'>{$statusBadge}</td></tr>
              <tr><td style='padding:8px;border:1px solid #e5e7eb;'><strong>Technician Note</strong></td>
                  <td style='padding:8px;border:1px solid #e5e7eb;'>{$proofNoteHtml}</td></tr>
              <tr><td style='padding:8px;border:1px solid #e5e7eb;'><strong>Proof Attachment</strong></td>
                  <td style='padding:8px;border:1px solid #e5e7eb;'>{$proofLink}</td></tr>
              <tr><td style='padding:8px;border:1px solid #e5e7eb;'><strong>Date Updated</strong></td>
                  <td style='padding:8px;border:1px solid #e5e7eb;'>{$updated_at}</td></tr>
            </table>
            <p style='margin-top:20px;'>You can log in to your student dashboard to view full details.</p>
            <p style='color:#6b7280;font-size:12px;margin-top:20px;'>This is an automated message from Dormitory Ticketing System (PDTS).</p>
          </div>
        ";
        $mail->AltBody = "Your ticket '{$title}' has been marked as Completed by Technician {$techName}. Note: {$proofNote}";

        $mail->send();
      } catch (Exception $e) {
        // Do not crash; just log internally if needed
      }
    }
  }

  $conn->commit();
  $_SESSION['success_message'] = "Ticket #$complaintId updated to $newStatus.";
} catch (Throwable $e) {
  $conn->rollback();
  $_SESSION['error_message'] = "Update failed: " . $e->getMessage();
}

header("Location: technician_mytickets.php");
exit();
?>
