<?php
require_once 'config.php';
header('Content-Type: application/json');

$id = (int)($_GET['id'] ?? 0);
$out = [
  'tech_name' => null,
  'tech_remark' => null,
  'completed_at' => null,
  'admin_remark' => null,
  'status' => null
];

if ($id > 0) {
  $sql = "
    SELECT 
      c.status,
      c.created_at,
      c.updated_at,
      c.proof_note AS tech_remark,
      u.name AS tech_name,
      c.remark_pending,
      c.remark_in_progress,
      c.remark_completed,
      c.remark_rejected
    FROM complaints c
    LEFT JOIN profile u ON u.id = c.assigned_to
    WHERE c.id = ? LIMIT 1
  ";
  $stmt = $conn->prepare($sql);
  $stmt->bind_param('i', $id);
  $stmt->execute();
  $res = $stmt->get_result();

  if ($row = $res->fetch_assoc()) {
    $status = $row['status'] ?? '—';
    $out['status'] = $status;
    $out['tech_name'] = $row['tech_name'] ?? '—';
    $out['tech_remark'] = $row['tech_remark'] ?? '—';

    // ✅ Admin remark logic (based on status)
    switch ($status) {
      case 'Pending':
        $out['admin_remark'] = $row['remark_pending'] ?: '—';
        break;
      case 'In Progress':
        $out['admin_remark'] = $row['remark_in_progress'] ?: '—';
        break;
      case 'Completed':
        $out['admin_remark'] = $row['remark_completed'] ?: '—';
        break;
      case 'Rejected':
        $out['admin_remark'] = $row['remark_rejected'] ?: '—';
        break;
      default:
        $out['admin_remark'] = '—';
    }

    // ✅ Completed At logic (dynamic per status)
    switch ($status) {
      case 'Pending':
        $out['completed_at'] = date('m/d/Y, g:i:s A', strtotime($row['created_at'] ?? 'now'));
        break;
      case 'In Progress':
      case 'Rejected':
        $out['completed_at'] = date('m/d/Y, g:i:s A', strtotime($row['updated_at'] ?? 'now'));
        break;
      case 'Completed':
        $out['completed_at'] = !empty($row['updated_at'])
          ? date('m/d/Y, g:i:s A', strtotime($row['updated_at']))
          : '—';
        break;
      default:
        $out['completed_at'] = '—';
    }
  }

  $stmt->close();
}

echo json_encode($out);
?>
