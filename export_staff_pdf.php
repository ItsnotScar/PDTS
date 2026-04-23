<?php
session_start();
require_once 'config.php';

/* Allow admin OR boss_ups */
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin','boss_ups'], true)) { header("Location: index.php"); exit; }

// Helpers
function val($k){ return trim($_GET[$k] ?? ''); }
function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Filters (same keys as Staff tab / CSV)
$search   = val('search');
$f_block  = val('f_block');
$f_gender = val('f_gender');
$f_spec   = val('f_spec');

// Determine which column holds the assigned block
$techBlockCol = 'assigned_block';
$hasAssignedBlock = false;
if ($res = $conn->query("SHOW COLUMNS FROM profile LIKE 'assigned_block'")) { $hasAssignedBlock = ($res && $res->num_rows > 0); $res->close(); }
if (!$hasAssignedBlock) $techBlockCol = 'block';

// Build WHERE from filters (technicians only, not deleted)
$where = ["role='technician'","is_deleted=0"];
if ($search !== '')  { $s=$conn->real_escape_string($search); $where[]="(name LIKE '%$s%' OR email LIKE '%$s%')"; }
if ($f_block !== '') { $where[]="$techBlockCol='".$conn->real_escape_string($f_block)."'"; }
if (in_array($f_gender, ['male','female'], true)) { $where[]="gender='".$conn->real_escape_string($f_gender)."'"; }
if ($f_spec !== '')  { $where[]="specialty='".$conn->real_escape_string($f_spec)."'"; }
$whereSql = implode(' AND ', $where);

// Fetch rows
$sql = "SELECT id,name,email,role,gender,$techBlockCol AS assigned_block,specialty
        FROM profile WHERE $whereSql ORDER BY name";
$res = $conn->query($sql);

$rows=[]; $ids=[];
while($r=$res->fetch_assoc()){ $rows[]=$r; $ids[]=(int)$r['id']; }

// Workload stats for the fetched ids only
$open=[]; $done=[];
if ($ids){
  $csv = implode(',', array_map('intval',$ids));
  $rs = $conn->query("SELECT assigned_to, COUNT(*) c
                      FROM complaints
                      WHERE is_deleted=0 AND assigned_to IN ($csv)
                        AND status NOT IN ('Completed','Rejected')
                      GROUP BY assigned_to");
  while($r=$rs->fetch_assoc()) $open[(int)$r['assigned_to']] = (int)$r['c'];

  $rs = $conn->query("SELECT assigned_to, COUNT(*) c
                      FROM complaints
                      WHERE is_deleted=0 AND assigned_to IN ($csv)
                        AND status='Completed'
                      GROUP BY assigned_to");
  while($r=$rs->fetch_assoc()) $done[(int)$r['assigned_to']] = (int)$r['c'];
}

// Build "Back" link (preserve the same filters)
$qs = http_build_query([
  'section'  => 'staff',
  'search'   => $search,
  'f_block'  => $f_block,
  'f_gender' => $f_gender,
  'f_spec'   => $f_spec,
]);
$backHref = 'admin_page.php?'.$qs;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Technicians (Staff Export)</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    @media print { .no-print { display:none !important; } body { -webkit-print-color-adjust: exact; print-color-adjust: exact; } }
    body { font-family: Arial, Helvetica, sans-serif; margin: 24px; color:#111; }
    h1 { margin:0 0 6px; font-size: 20px; }
    .muted { color:#555; margin-bottom: 16px; }
    .actions { margin: 12px 0 20px; }
    .btn { display:inline-block; padding:8px 12px; background:#2563eb; color:#fff; text-decoration:none; border-radius:6px; }

    .pill { display:inline-block; padding:2px 8px; border-radius:999px; background:#eef2ff; font-size:12px; margin-right:6px; margin-bottom:6px; }

    table { width:100%; border-collapse: collapse; }
    th, td { border:1px solid #ddd; padding:8px; text-align:left; font-size: 12px; }
    thead th { background:#f3f4f6; text-transform: uppercase; letter-spacing: .03em; }
  </style>
</head>
<body>

  <div class="actions no-print">
    <a class="btn" href="<?= e($backHref) ?>">&larr; Back</a>
    <a class="btn" href="#" onclick="window.print();return false;">Print</a>
  </div>

  <h1>Technicians</h1>
  <div class="muted">Generated: <?= date('Y-m-d H:i:s') ?></div>

  <div style="margin:8px 0 16px;">
    <span class="pill">Search: <?= $search !== '' ? e($search) : '—' ?></span>
    <span class="pill">Block: <?= $f_block !== '' ? e($f_block) : 'All' ?></span>
    <span class="pill">Gender: <?= $f_gender !== '' ? e(ucfirst($f_gender)) : 'All' ?></span>
    <span class="pill">Specialty: <?= $f_spec !== '' ? e($f_spec) : 'All' ?></span>
  </div>

  <table>
    <thead>
      <tr>
        <th>#</th>
        <th>Name</th>
        <th>Email</th>
        <th>Role</th>
        <th>Block / Gender</th>
        <th>Specialty</th>
        <th>Open</th>
        <th>Completed</th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="8" style="text-align:center;">No staff match your filters.</td></tr>
      <?php else: $i=1; foreach($rows as $r): $sid=(int)$r['id']; ?>
        <tr>
          <td><?= $i++ ?></td>
          <td><?= e($r['name']) ?></td>
          <td><?= e($r['email']) ?></td>
          <td><?= e(ucfirst($r['role'])) ?></td>
          <td><?= 'Blok '.e($r['assigned_block'] ?: '—').' — '.e($r['gender'] ?: '—') ?></td>
          <td><?= e($r['specialty'] ?: '') ?></td>
          <td><?= (int)($open[$sid] ?? 0) ?></td>
          <td><?= (int)($done[$sid] ?? 0) ?></td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>

</body>
</html>
