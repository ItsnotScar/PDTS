
<?php
session_start();

// Guard
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'student') { header("Location: index.php"); exit(); }

// Session data
$studentName   = $_SESSION['name']        ?? 'Unknown';
$studentId     = $_SESSION['student_id']  ?? 'N/A';
$studentBlock  = $_SESSION['block']       ?? 'N/A';
$studentRoom   = $_SESSION['room_number'] ?? 'N/A';
$studentGender = $_SESSION['gender']      ?? 'N/A';
$studentEmail  = $_SESSION['email']       ?? 'N/A';

// Flash (optional)
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

require_once 'config.php';
function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Pull phone number from profile
$studentPhone = '';
try {
  $ps = $conn->prepare("SELECT phone FROM profile WHERE student_id = ? LIMIT 1");
  $ps->bind_param("s", $studentId);
  $ps->execute();
  $pr = $ps->get_result();
  if ($pr && $pr->num_rows > 0) {
    $rowp = $pr->fetch_assoc();
    $studentPhone = (string)($rowp['phone'] ?? '');
  }
} catch(Throwable $t) {}

// LIVE warnings + ban flag
$warnCount = 0;
$isBanned = false;

// warnings count
$ws = $conn->prepare("SELECT COUNT(*) c FROM student_warnings WHERE student_id=?");
$ws->bind_param("s", $studentId);
$ws->execute();
$warnCount = (int)($ws->get_result()->fetch_assoc()['c'] ?? 0);
$ws->close();

// ban flag (you already use this later; reuse here and remove the old duplicate query)
$br = $conn->prepare("SELECT is_banned FROM profile WHERE student_id=? LIMIT 1");
$br->bind_param("s", $studentId);
$br->execute();
$isBanned = (bool)($br->get_result()->fetch_assoc()['is_banned'] ?? 0);
$br->close();


// Helper: fetch attachment list (id, path, size) for a complaint id
function get_attachments_full(mysqli $conn, $complaintId){
  $out = [];
  $stmt = $conn->prepare("SELECT id, file_path, file_size FROM complaint_attachments WHERE complaint_id = ? ORDER BY id ASC");
  $stmt->bind_param("i", $complaintId);
  $stmt->execute();
  $res = $stmt->get_result();
  while ($row = $res->fetch_assoc()) {
    $out[] = [
      'id'   => (int)$row['id'],
      'path' => (string)$row['file_path'],
      'size' => (int)$row['file_size'],
    ];
  }
  return $out;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
  <title>Student Dashboard</title>
  <link rel="icon" type="image/png" sizes="32x32" href="assets/favicon.png">
  <link rel="icon" type="image/png" sizes="16x16" href="assets/favicon.png">
  <link rel="apple-touch-icon" href="assets/logo.png">
  <link rel="stylesheet" href="student.css">
  <style>
    /* Focus/highlight for a jumped-to ticket row */
    .focus-highlight {
      position: relative;
      animation: rowFlash 2.2s ease-out 1;
      outline: 2px solid #60a5fa;
      outline-offset: -2px;
      background: #eff6ff !important;
    }
    @keyframes rowFlash {
      0%   { box-shadow: 0 0 0 rgba(59,130,246,0.0); }
      25%  { box-shadow: 0 0 0 6px rgba(59,130,246,0.25); }
      100% { box-shadow: 0 0 0 rgba(59,130,246,0.0); }
    }
    /* strike links 
     Make "Recent Strikes" items look like links (blue + underline on hover) */
    .strike-link {
      color: #2563eb;           /* blue */
      text-decoration: none;    /* clean by default */
      font-weight: 600;
    }
    .strike-link:hover,
    .strike-link:focus {
      text-decoration: underline;
      color: #1d4ed8;           /* slightly darker on hover/focus */
    }
    .strike-link:active {
      color: #1e40af;           /* pressed */
    }
    /* keep same blue in dark mode */
    body.dark .strike-link {
      color: #60a5fa;           /* lighter blue for dark bg */
    }
    body.dark .strike-link:hover,
    body.dark .strike-link:focus {
      color: #3b82f6;
      text-decoration: underline;
    }


    html, body { height: 100%; }
    body { margin: 0; position: relative; background: #e5eefc; min-height: 100vh; min-height: 100svh; z-index: 0; }
    body::before { content: ""; position: fixed; inset: 0; background: url('assets/dormitory.jpg') center/cover no-repeat; filter: blur(8px) brightness(.92) saturate(90%); transform: scale(1.06); z-index: -2; pointer-events: none; }
    body::after  { content: ""; position: fixed; inset: 0; background: rgba(0,0,0,.40); z-index: -1; pointer-events: none; }

    .muted-note, .form-hint, .card-subnote { font-size: 12.5px; color: #475569; margin-top: 6px; }
    .muted-note strong, .form-hint strong, .card-subnote strong { color:#0f172a; }
    .card-subnote { margin-left: auto; margin-right: 8px; }
    @media (max-width: 560px){ .card-subnote { width: 100%; margin: 6px 0 0 0; } }

    select:disabled { background: #f1f5f9; color:#64748b; cursor:not-allowed; }
    .btn-disabled { opacity: .55; cursor: not-allowed; }
    .tiny { font-size: 12px; color:#64748b; }
    .has-tooltip { position: relative; }
    .has-tooltip .tooltip { position: absolute; bottom: 125%; left: 50%; transform: translateX(-50%); background:#0f172a; color:#fff; padding:6px 8px; border-radius:6px; font-size:12px; opacity:0; pointer-events:none; white-space:nowrap; transition:opacity .15s ease; }
    .has-tooltip:hover .tooltip { opacity: .95; }
    .flash { background:#fff7ed; border:1px solid #fed7aa; color:#7c2d12; padding:10px 12px; border-radius:8px; margin-bottom:12px; }
    .flash.ok { background:#ecfdf5; border-color:#a7f3d0; color:#065f46; }
    .att-list { list-style: disc; margin: 6px 0 0 18px; padding: 0; }

    /* Clean attachments UI */
    .att-box { background:#f8fafc; border:1px dashed #cbd5e1; padding:12px; border-radius:10px; }
    .att-header { display:flex; align-items:center; justify-content:space-between; }
    .att-title { font-weight:600; color:#111827; }
    .att-row { display:flex; gap:8px; align-items:flex-start; margin-top:10px; }
    .att-file { flex:1; display:flex; gap:8px; align-items:center; }
    .att-file input[type=file] { flex:1; padding:8px; border:1px solid #cbd5e1; border-radius:8px; background:#fff; }
    .att-remove { border:none; background:#fee2e2; color:#991b1b; padding:8px 10px; border-radius:8px; cursor:pointer; font-weight:600; }
    .att-remove:hover { background:#fecaca; }
    .att-actions { display:flex; gap:8px; margin-top:10px; }
    .att-add { border:1px solid #94a3b8; background:#fff; color:#0f172a; padding:8px 12px; border-radius:8px; cursor:pointer; font-weight:600; }
    .att-add:hover { background:#f1f5f9; }
    .att-files-list { font-size:12px; color:#334155; margin-top:6px; line-height:1.35; }
    .att-meter { font-size:12px; color:#334155; margin-top:8px; }
    .att-meter.over { color:#b91c1c; font-weight:700; }
    .att-box .small-btn, .att-add, .att-remove { width:auto !important; }

    /* Existing attachments — cleaner layout */
    .existing-list{
      list-style:none; margin:8px 0 0; padding:0;
    }
    .existing-item{
      display:grid;
      grid-template-columns: 1fr 36px;  /* text | action */
      align-items:center;
      gap:10px;
      padding:8px 10px;
      border:1px solid #e2e8f0;
      border-radius:10px;
      background:#fff;
      margin-top:8px;
    }
    .existing-meta{
      min-width:0;
      display:flex;
      align-items:flex-start;
      gap:6px;
    }
    .existing-name{
      font-size:14px; color:#0f172a; font-weight:600;
      max-width:100%;
      overflow:hidden; text-overflow:ellipsis; white-space:nowrap;
    }
    .existing-size{
      font-size:12px; color:#64748b; white-space:nowrap;
    }
    .trash-btn{
      width:32px; height:32px;
      display:grid; place-items:center;
      border:1px solid #e5e7eb;
      background:#fff;
      color:#ef4444;
      border-radius:8px;
      cursor:pointer;
      padding:0;
    }
    .existing-item:hover .trash-btn{ background:#fef2f2; border-color:#fecaca; }
    .trash-btn:focus{ outline:3px solid #bfdbfe; outline-offset:2px; }
    .existing-item.removed{ background:#fff7f7; border-color:#fecaca; }
    .existing-item.removed .existing-name{ text-decoration:line-through; color:#9ca3af; }

/* 🌙 Full Dark Mode Fix — readable and consistent */
body.dark {
  color: #f1f5f9 !important;
}

/* Modal container (dark background + light text) */
body.dark .modal-content.card {
  background: #0f172a !important;
  color: #f1f5f9 !important;
  border: 1px solid #334155;
}

/* Inner technician box */
body.dark #modalTechBox {
  background: #1e293b !important;
  border-color: #475569 !important;
}

/* Headings, labels, bold text */
body.dark h2,
body.dark h3,
body.dark h4,
body.dark strong {
  color: #f8fafc !important;
}

/* Paragraphs and minor text */
body.dark p,
body.dark span {
  color: #e2e8f0 !important;
}

/* Attachments / proof text */
body.dark #modalAttachments span,
body.dark #modalProof span {
  color: #cbd5e1 !important;
}



/* Adjust badge readability */
body.dark .badge {
  font-weight: 600;
  color: #0f172a !important;
}

/* Modal overlay darker for clarity */
body.dark .modal {
  background: rgba(0,0,0,0.75) !important;
}

/* 🌙 Keep Edit Modal consistent with Details Modal */
body.dark .modal-content.edit-card {
  background: #0f172a !important;
  color: #f1f5f9 !important;
  border: 1px solid #334155;
}

/* Form inputs, selects, textareas */
body.dark .modal-content.edit-card input,
body.dark .modal-content.edit-card select,
body.dark .modal-content.edit-card textarea {
  background: #1e293b !important;
  color: #f1f5f9 !important;
  border: 1px solid #475569 !important;
}

/* Placeholder text visibility */
body.dark .modal-content.edit-card input::placeholder,
body.dark .modal-content.edit-card textarea::placeholder {
  color: #94a3b8 !important;
}

/* Labels and headings */
body.dark .modal-content.edit-card label,
body.dark .modal-content.edit-card strong {
  color: #f8fafc !important;
}

/* File / attachment section */
body.dark .modal-content.edit-card .file-area {
  background: #1e293b !important;
  border: 1px dashed #475569 !important;
  color: #cbd5e1 !important;
}

/* Buttons stay bright for contrast */
body.dark .modal-content.edit-card button {
  background: #2563eb !important;
  color: #fff !important;
}


/* 🌙 Improve consistency for attachment boxes in Edit modal */
body.dark .modal-content.edit-card .att-box {
  background: #1e293b !important;  /* match technician box */
  border-color: #475569 !important;
}

body.dark .modal-content.edit-card .existing-item {
  background: #1e293b !important;
  border-color: #475569 !important;
}

body.dark .modal-content.edit-card .existing-name {
  color: #f8fafc !important;
}

body.dark .modal-content.edit-card .existing-size {
  color: #94a3b8 !important;
}

body.dark .modal-content.edit-card .trash-btn {
  background: #0f172a !important;
  border-color: #475569 !important;
  color: #fca5a5 !important;
}

body.dark .modal-content.edit-card .trash-btn:hover {
  background: #1e293b !important;
  border-color: #ef4444 !important;
}

/* 🌙 Refine file input + add button tone in Edit modal */
body.dark .modal-content.edit-card input[type="file"] {
  background: #1e293b !important;
  border: 1px solid #475569 !important;
  color: #f1f5f9 !important;
}

body.dark .modal-content.edit-card input[type="file"]::file-selector-button {
  background: #334155 !important;
  color: #f1f5f9 !important;
  border: none !important;
  padding: 6px 10px !important;
  border-radius: 6px !important;
  cursor: pointer !important;
}

body.dark .modal-content.edit-card input[type="file"]::file-selector-button:hover {
  background: #475569 !important;
}

/* "Add more files" button */
body.dark .modal-content.edit-card .att-add {
  background: #2563eb !important;
  color: #fff !important;
  border: none !important;
}

body.dark .modal-content.edit-card .att-add:hover {
  background: #1d4ed8 !important;
}

  /* 🌙 Subtle polish for Save button + Remove (✕) buttons */
body.dark .modal-content.edit-card button[type="submit"] {
  background: #2563eb !important;
  color: #fff !important;
  border: none !important;
  font-weight: 600;
  transition: background 0.2s ease;
}

body.dark .modal-content.edit-card button[type="submit"]:hover {
  background: #1d4ed8 !important;
}




/* 🌙 Fix section headers and hints inside Edit modal */
body.dark .modal-content.edit-card .att-title,
body.dark .modal-content.edit-card .att-header .tiny,
body.dark .modal-content.edit-card label,
body.dark .modal-content.edit-card h3,
body.dark .modal-content.edit-card h4 {
  color: #f1f5f9 !important;
}

/* Make hint/subtext slightly dimmer for readability balance */
body.dark .modal-content.edit-card .tiny,
body.dark .modal-content.edit-card .att-meter {
  color: #94a3b8 !important;
}


/* 🌙 Headings, labels, and attachment titles for both modals */
body.dark .modal-content.card h3,
body.dark .modal-content.card h4,
body.dark .modal-content.card label,
body.dark .modal-content.card .att-title {
  color: #f1f5f9 !important;
}

/* Softer tone for subtext, hints, and tiny info lines */
body.dark .modal-content.card .tiny,
body.dark .modal-content.card .form-hint,
body.dark .modal-content.card .att-header .tiny,
body.dark .modal-content.card .att-meter {
  color: #94a3b8 !important;
}

/* Inputs, selects, and textareas same as edit modal */
body.dark .modal-content.card input,
body.dark .modal-content.card select,
body.dark .modal-content.card textarea {
  background: #1e293b !important;
  color: #f1f5f9 !important;
  border: 1px solid #475569 !important;
}

/* File area in submit modal */
body.dark .modal-content.card .att-box {
  background: #1e293b !important;
  border: 1px dashed #475569 !important;
  color: #cbd5e1 !important;
}

/* Buttons (Submit + Add More) */
body.dark .modal-content.card button {
  background: #2563eb !important;
  color: #fff !important;
  border: none !important;
}

body.dark .modal-content.card button:hover {
  background: #1d4ed8 !important;
}


/* 🌙 Fix unreadable tip + complaint text in dark mode */
body.dark .card-subnote,
body.dark .card-subnote strong,
body.dark .tiny,
body.dark .tiny strong {
  color: #f1f5f9 !important;  /* soft white */
  font-weight: 500 !important;
}

body.dark .card-subnote strong,
body.dark .tiny strong {
  color: #ffffff !important;  /* pure white for emphasis */
}

body.dark .card-subnote {
  opacity: 0.95 !important;
}

body.dark .tiny {
  opacity: 0.9 !important;
}




  </style>
</head>
<body>

  <!-- HEADER -->
<div class="header">
  <h1>Politeknik Dormitory Complaint System</h1>

  <div class="header-right">
    <!-- Dark/Light Mode Button -->
    <div class="theme-toggle-wrap">
      <button id="themeToggle" class="theme-toggle" aria-label="Toggle Dark Mode">🌙</button>
      <span id="themeTooltip" class="theme-tooltip">Switch to dark mode</span>
    </div>

    <!-- Profile Section -->
    <div class="profile" onclick="toggleProfileDropdown()">
      <span class="profile-logo">👤</span>
      <span><?= e($studentName) ?></span>
      <span class="profile-arrow">▼</span>
      <div id="profileDropdown" class="profile-dropdown">
        <p><strong>Student ID:</strong> <?= e($studentId) ?></p>
        <p><strong>Gender:</strong> <?= e(ucfirst($studentGender)) ?></p>
        <p><strong>Block:</strong> <?= e($studentBlock) ?></p>
        <p><strong>Room:</strong> <?= e($studentRoom) ?></p>
        <p><strong>Email:</strong> <?= e($studentEmail) ?></p>
        <p><strong>Phone:</strong> <?= e($studentPhone ?: '—') ?></p>
        <hr>
        <form action="logout.php" method="post" class="logout-form">
          <button type="submit"><span class="logout-icon">⏻</span> Logout</button>
        </form>
      </div>
    </div>
  </div>
</div>




  <!-- DASHBOARD -->
  <div class="dashboard-container">
    <?php if ($flash): ?>
      <div class="flash <?= !empty($flash['ok']) ? 'ok' : '' ?>"><?= e($flash['msg'] ?? '') ?></div>
    <?php endif; ?>

    <div class="card welcome-card">
      <h2 class="highlight">Welcome, <?= e($studentName) ?></h2>
      <p class="subtitle">Submit and track your dormitory maintenance complaints</p>
    </div>

    <?php
    // Build strike message + styling
    $barClass = 'flash';
    $msg = '';

    if ($isBanned) {
      $barClass .= ''; // keep default red-ish style or add a custom class
      $msg = "Your account is <strong>banned</strong> due to repeated fake tickets. "
          . "Please contact the admin to appeal. (Strikes: <strong>{$warnCount}/3</strong>)";
    } else {
      if ($warnCount <= 0) {
        $barClass .= ' ok'; // green
        $msg = "You have 0 strikes. Please submit accurate complaints.";
      } elseif ($warnCount === 1) {
        $msg = "⚠️ This is your <strong>1st strike</strong>. Two more will result in a ban. "
            . "Avoid fake or duplicate tickets.";
      } elseif ($warnCount === 2) {
        $msg = "⚠️ <strong>2nd strike</strong>. One more strike will result in a ban.";
      } else { // 3 or more, but not banned flag yet (edge case)
        $msg = "⚠️ <strong>3rd strike reached</strong>. Your account may be banned soon. "
            . "Contact admin if you believe this is a mistake.";
      }
    }
    ?>
    <div class="<?= e($barClass) ?>" style="margin-top:10px;">
      <?= $msg ?>
    </div>


    <!-- Complaints table -->
    <?php
    $recent = [];
    $rw = $conn->prepare("SELECT complaint_id, reason, created_at
                          FROM student_warnings
                          WHERE student_id=?
                          ORDER BY created_at DESC
                          LIMIT 5");
    $rw->bind_param("s", $studentId);
    $rw->execute();
    $res = $rw->get_result();
    while($row = $res->fetch_assoc()) {
      $recent[] = $row;
    }
    $rw->close();
    ?>

    <?php if ($recent): ?>
      <div class="card" style="margin-top:12px;">
        <h3 style="margin:0 0 8px">Your Recent Strikes</h3>
        <ul class="att-list">
          <?php foreach($recent as $w):
            $cid  = isset($w['complaint_id']) ? (int)$w['complaint_id'] : 0;
            $href = $cid ? "?status=Rejected&focus={$cid}#c{$cid}" : "?status=Rejected";
          ?>
            <li>
              <a href="<?= e($href) ?>" class="strike-link">
                <strong><?= e($w['created_at']) ?></strong> — <?= e($w['reason'] ?: 'No reason provided') ?>
              </a>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>


    <div class="card">
      <div class="card-header" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
        <h3>Your Complaints</h3>
        <div class="card-subnote">Tip: Click <strong>“+ New Complaint”</strong> to submit a new report.</div>
        <div class="tiny" style="color:#475569;margin-top:4px;">
          Complaints today: <strong><?= $dailyCount ?>/5</strong>
        </div>
        <?php
        $isBannedRow = $conn->query("SELECT is_banned FROM profile WHERE student_id='".$conn->real_escape_string($studentId)."'")->fetch_assoc();
        $isBanned = !empty($isBannedRow['is_banned']);
        ?>
        <button class="small-btn" style="margin-left:auto"
                onclick="<?= $isBanned ? "alert('Your account is banned due to repeated fake tickets. Please contact admin.')" : "openModal('complaintModal')" ?>"
                <?= $isBanned ? 'disabled' : '' ?>>
          + New Complaint
        </button>
      </div>

<?php
// ─── Ticket filter tabs ───────────────────────────────────────────────
$statusFilter = $_GET['status'] ?? '';
$tabs = [
  '' => 'All',
  'Pending' => 'Pending',
  'In Progress' => 'In Progress',
  'Completed' => 'Completed',
  'Rejected' => 'Rejected'
];
?>
<div class="tab-bar">
  <?php foreach ($tabs as $key => $label): ?>
    <a href="?status=<?= urlencode($key) ?>"
       class="tab <?= $statusFilter === $key ? 'active' : '' ?>">
       <?= htmlspecialchars($label) ?>
    </a>
  <?php endforeach; ?>
</div>


      <?php
      $query = "SELECT * FROM complaints WHERE student_id = ?";
$params = [$studentId];
$types = "s";

if ($statusFilter) {
  $query .= " AND status = ?";
  $params[] = $statusFilter;
  $types .= "s";
}
$query .= " ORDER BY id DESC";

$stmt = $conn->prepare($query);
$stmt->bind_param($types, ...$params);

      $stmt->execute();
      $result = $stmt->get_result();
      ?>

      <?php if ($result->num_rows > 0): ?>
        <div class="table-shell">
          <table class="complaint-table">
            <thead>
              <tr>
                <th class="col-num">#</th>
                <th class="col-title">Title</th>
                <th class="col-cat">Category</th>
                <th class="col-cat">Sub-Category</th>
                <th class="col-sta">Status</th>
                <th class="col-act">Action</th>
              </tr>
            </thead>
            <tbody>
              <?php $i=1; while ($row = $result->fetch_assoc()):
                $cid = (int)$row['id']; // ← add this
                $isPending = strtolower((string)$row['status']) === 'pending';
                $atts = get_attachments_full($conn, $cid);
                $attsJson = e(json_encode($atts));
              ?>
                <tr id="c<?= $cid ?>"> <!-- ← add an id per row -->

                  <td class="col-num"><?= $i ?></td>
                  <td class="col-title"><?= e($row['title']) ?></td>
                  <td class="col-cat"><?= e($row['category']) ?></td>
                  <td class="col-cat"><?= isset($row['subcategory']) ? e($row['subcategory']) : '—' ?></td>
                  <td class="col-sta">
                    <span class="badge status-<?= strtolower(str_replace(' ', '-', $row['status'])) ?>">
                      <?= e($row['status']) ?>
                    </span>
                  </td>
                  <td class="col-act" style="display:flex; gap:6px; flex-wrap:wrap;">
                    <button class="details-btn"
                      data-id="<?= e($row['id']) ?>"
                      data-title="<?= e($row['title']) ?>"
                      data-category="<?= e($row['category']) ?>"
                      data-subcategory="<?= isset($row['subcategory']) ? e($row['subcategory']) : '' ?>"
                      data-status="<?= e($row['status']) ?>"
                      data-submitted="<?= e($row['created_at']) ?>"
                      data-description="<?= e($row['complaint']) ?>"
                      data-attachments='<?= $attsJson ?>'
                      onclick="openDetails(this)">View</button>

                    <?php if ($isPending): ?>
                      <button class="secondary-btn"
                        data-id="<?= e($row['id']) ?>"
                        data-title="<?= e($row['title']) ?>"
                        data-category="<?= e($row['category']) ?>"
                        data-subcategory='<?= isset($row['subcategory']) ? e($row['subcategory']) : '' ?>'
                        data-description="<?= e($row['complaint']) ?>"
                        data-attachments='<?= $attsJson ?>'
                        onclick="openEdit(this)">Edit</button>
                    <?php else: ?>
                      <span class="has-tooltip">
                        <button class="secondary-btn btn-disabled" disabled>Edit</button>
                        <span class="tooltip">Editing is disabled once status is not <strong>pending</strong>.</span>
                      </span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php $i++; endwhile; ?>
            </tbody>
          </table>
        </div>
      <?php else: ?>
        <p class="no-tickets">No complaints submitted yet.</p>
      <?php endif; ?>
    </div>
  </div>

  <!-- SUBMIT COMPLAINT MODAL -->
  <div id="complaintModal" class="modal" style="display:none;">
    <div class="modal-content card">
      <span class="close" onclick="closeModal('complaintModal')">&times;</span>
      <h3>Submit Complaint</h3>

      <form id="submitForm" action="student_submit_complaint.php" method="post" enctype="multipart/form-data">
        <label>Title</label>
        <input type="text" name="title" placeholder="Complaint Title" required>

        <!-- Main Category -->
        <label for="category">Category</label>
        <select id="category" name="category" required aria-describedby="catHint">
          <option value="">-- Select Category --</option>
          <option value="KEJURUTERAAN AWAM">KEJURUTERAAN AWAM</option>
          <option value="KEJURUTERAAN ELEKTRIK">KEJURUTERAAN ELEKTRIK</option>
          <option value="KEJURUTERAAN MEKANIKAL">KEJURUTERAAN MEKANIKAL</option>
        </select>
        <p id="catHint" class="form-hint">Select the main category. The related sub-categories will appear after you choose a category.</p>

        <!-- Sub-Category -->
        <label for="subcategory">Sub-Category</label>
        <select id="subcategory" name="subcategory" required disabled aria-describedby="subcatHint">
          <option value="">-- Select Sub-Category --</option>
        </select>
        <p id="subcatHint" class="form-hint">Choose the sub-category that best matches your issue.</p>

        <label>Description</label>
        <textarea name="complaint" placeholder="Describe your issue..." required></textarea>

        <!-- Attachments -->
        <div class="att-box">
          <div class="att-header">
            <div class="att-title">Attachments</div>
            <div class="tiny">Combined limit: 15 MB</div>
          </div>

          <!-- Row 1 -->
          <div class="att-row">
            <div class="att-file">
              <input type="file" name="attachments[]" accept="image/*,video/*" multiple>
            </div>
            <button type="button" class="att-remove" onclick="removeAttRow(this)" title="Remove row">✕</button>
          </div>
          <div class="att-files-list"></div>

          <div id="attRows"></div>

          <div class="att-actions">
            <button type="button" class="att-add" onclick="addAttRow()">+ Add more files</button>
          </div>

          <div id="attMeter" class="att-meter">Total selected: 0.00 MB / 15.00 MB</div>
        </div>

        <button type="submit">Submit Complaint</button>
      </form>
    </div>
  </div>

<!-- CLEANED + CONSISTENT DETAILS MODAL -->
<div id="detailsModal" class="modal" style="display:none;">
  <div class="modal-content card ticket-details" style="max-width:750px;">
    <span class="close" onclick="closeDetails()">&times;</span>
    <h2 id="modalTitle" style="margin-bottom:8px;">Complaint Details</h2>

    <div id="modalHeaderInfo" style="color:#334155; font-size:14px; margin-bottom:10px;">
      <span id="modalDate"><i class="fa-regular fa-clock"></i></span>
    </div>

    <div style="display:grid; grid-template-columns:1fr 1fr; gap:6px 20px; font-size:15px; color:#0f172a;">
      <p><strong>Sub Category:</strong> <span id="modalSubcategory">—</span></p>
      <p><strong>Student:</strong> <?= e($studentName) ?></p>
      <p><strong>Phone:</strong> <span id="modalPhone"><?= e($studentPhone ?: '—') ?></span></p>
      <p><strong>Status:</strong> <span id="modalStatus">—</span></p>
      <p><strong>Block/Room:</strong> <?= e($studentBlock) ?> / <?= e($studentRoom) ?></p>
      <p><strong>Gender:</strong> <?= e($studentGender) ?></p>
    </div>

    <p style="margin-top:8px;"><strong>Description:</strong> <span id="modalDescription">—</span></p>

    <h4 style="margin-top:16px;">Student Attachment:</h4>
    <div id="modalAttachments" class="files-grid" style="display:flex; flex-wrap:wrap; gap:10px; margin-top:6px;"></div>

    <!-- Technician + Admin Section -->
    <div id="modalTechBox" style="background:#f9fafb; border:1px solid #e5e7eb; border-radius:12px; padding:14px; margin-top:20px;">
     <span id="mStatusBadge" class="badge" style="background:#f1f5f9; color:#334155;">—</span>
      <p style="margin:8px 0 4px;"><strong>Technician:</strong> <span id="modalTechName" style="color:inherit; font-weight:normal;">—</span></p>
      <p style="margin:4px 0;"><strong>Technician Remark:</strong> <span id="modalTechRemark">—</span></p>

      <div id="modalProofWrap" style="margin-top:10px;">
        <strong>Proof:</strong>
        <div id="modalProof" style="margin-top:4px;"></div>
      </div>

      <p id="modalCompletedAt" style="margin:10px 0 4px;"><strong>Completed At:</strong> <span>—</span></p>
      <p id="modalAdminRemark" style="margin-top:4px;"><strong>Admin Remark:</strong> <span>—</span></p>
    

    </div>
  </div>
</div>


  <!-- EDIT COMPLAINT MODAL (with tiny trash buttons) -->
  <div id="editModal" class="modal" style="display:none;">
    <div class="modal-content card edit-card">

      <span class="close" onclick="closeModal('editModal')">&times;</span>
      <h3>Edit Complaint</h3>

      <form id="editForm" action="student_update_complaint.php" method="post" enctype="multipart/form-data">
        <input type="hidden" name="complaint_id" id="editComplaintId">

        <label>Phone (Profile)</label>
        <input
          type="text"
          name="phone"
          id="editPhone"
          inputmode="tel"
          autocomplete="tel"
          placeholder="e.g., 012-3456789 or +60 12-3456789"
          pattern="^(0[0-9\- ]{8,13}|(\+?60)[0-9\- ]{8,12})$"
          title="Start with 0 (local) or +60/60 (international).">

        <label>Title</label>
        <input type="text" name="title" id="editTitle" required>

        <label>Main Category</label>
        <select id="editCategory" name="category" required>
          <option value="">-- Select Category --</option>
          <option value="KEJURUTERAAN AWAM">KEJURUTERAAN AWAM</option>
          <option value="KEJURUTERAAN ELEKTRIK">KEJURUTERAAN ELEKTRIK</option>
          <option value="KEJURUTERAAN MEKANIKAL">KEJURUTERAAN MEKANIKAL</option>
        </select>

        <label>Sub-Category</label>
        <select id="editSubcategory" name="subcategory" required disabled>
          <option value="">-- Select Sub-Category --</option>
        </select>

        <label>Description</label>
        <textarea name="complaint" id="editDescription" required></textarea>

        <!-- Existing attachments with trash buttons -->
        <div class="att-box" style="margin-top:10px;">
          <div class="att-header">
            <div class="att-title">Existing attachments</div>
            <div class="tiny">Click the trash icon to mark for removal</div>
          </div>
          <ul id="editExistingList" class="existing-list"></ul>
        </div>

        <!-- Add more files (new) -->
        <div class="att-box" style="margin-top:10px;">
          <div class="att-header">
            <div class="att-title">Add more files</div>
            <div class="tiny">Combined limit (existing + new): 15 MB</div>
          </div>

          <div class="att-row">
            <div class="att-file">
              <input type="file" name="attachments[]" accept="image/*,video/*" multiple>
            </div>
            <button type="button" class="att-remove" onclick="removeAttRow(this)" title="Remove row">✕</button>
          </div>
          <div class="att-files-list"></div>

          <div id="editAttRows"></div>

          <div class="att-actions">
            <button type="button" class="att-add" onclick="addEditAttRow()">+ Add more files</button>
          </div>

          <div id="editAttMeter" class="att-meter">New selections: 0.00 MB</div>
          <div id="editAttInfo" class="tiny" style="margin-top:4px;"></div>
        </div>

        <button type="submit">Save Changes</button>
      </form>
      <p class="tiny" style="margin-top:8px;">Edits are only allowed while the complaint is “pending”.</p>
    </div>
  </div>

  <script>
    /* Profile dropdown */
    function toggleProfileDropdown(){
      const dd = document.getElementById('profileDropdown');
      const arrow = document.querySelector('.profile-arrow');
      const shown = dd.classList.toggle('show');
      arrow.classList.toggle('open', shown);
    }
    document.addEventListener('click', (e)=>{
      const wrap = document.querySelector('.profile');
      if (!wrap.contains(e.target)) {
        document.getElementById('profileDropdown')?.classList.remove('show');
        document.querySelector('.profile-arrow')?.classList.remove('open');
      }
    });

    function openModal(id){ document.getElementById(id).style.display = 'block'; }
    function closeModal(id){ document.getElementById(id).style.display = 'none'; }

    /* ----- Dependent Sub-Category logic (Malay values retained) ----- */
    const SUBCATS = {
      "KEJURUTERAAN AWAM": [
        "Bumbung","Siling","Lantai","Dinding","Tangga","Pintu/Jejenang Pintu",
        "Tingkap/Jejenang Tingkap/Window Handle","Pagar","Gutter",
        "RWDP (Rain Water Down Pipe","Saluran Paip","Pili Paip","Sinki",
        "Bidet","Tandas","Sistem Bekalan Air","Kebocoran",
        "Katil Pelajar","Almari Pelajar","Perabot (Kerusi/Meja/Kabinet)",
        "Tombol Pintu","Pokok/Landskap"
      ],
      "KEJURUTERAAN ELEKTRIK": [
        "Kipas","Lampu","Pendawaian/Wiring","Plug Socket","Suis",
        "Bekalan Elektrik Terputus/Power Trip","Perangkap Kilat/Lightning Arrestor",
        "Lampu Jalan/Lampu Foyer","MSB/SSB/DB"
      ],
      "KEJURUTERAAN MEKANIKAL": [
        "Alat Pemadam Api","Fire Alarm Panel","Heat Detector",
        "Alarm Bell","Break Glass Fire Alarm","Hose Reel"
      ]
    };

    function resetSub(el){
      el.innerHTML = '<option value="">-- Select Sub-Category --</option>';
      el.disabled = true;
    }
    function populateSub(cat, el){
      resetSub(el);
      if (!cat || !SUBCATS[cat]) return;
      SUBCATS[cat].forEach(label => {
        const opt = document.createElement('option');
        opt.value = label; opt.textContent = label;
        el.appendChild(opt);
      });
      el.disabled = false;
    }

    const catEl = document.getElementById('category');
    const subEl = document.getElementById('subcategory');
    if (catEl && subEl) {
      resetSub(subEl);
      catEl.addEventListener('change', function(){ populateSub(this.value, subEl); });
    }

    /* Details modal */
    const studentPhone = <?= json_encode($studentPhone) ?>;
  function openDetails(btn){
  const title = btn.dataset.title || 'Complaint Details';
  const category = btn.dataset.category || '—';
  const subcategory = btn.dataset.subcategory || '—';
  const status = btn.dataset.status || '—';
  const submitted = btn.dataset.submitted || '—';
  const description = btn.dataset.description || '—';

  document.getElementById('modalTitle').textContent = title;
  document.getElementById('modalDate').textContent = submitted;
  document.getElementById('modalSubcategory').textContent = subcategory;
  document.getElementById('modalStatus').textContent = status;
  document.getElementById('modalDescription').textContent = description;
  document.getElementById('modalPhone').textContent = studentPhone || '—';

 // Separate Student vs Technician files
const attachWrap = document.getElementById('modalAttachments');
attachWrap.innerHTML = '';
let attachments = [];
try { attachments = JSON.parse(btn.dataset.attachments || '[]'); } catch(e) {}

const studentFiles = attachments.filter(a => !a.path.includes('/proofs/'));
const techFiles = attachments.filter(a => a.path.includes('/proofs/'));

if (!studentFiles.length) {
  attachWrap.innerHTML = '<span style="color:#64748b;">No student attachments.</span>';
} else {
  studentFiles.forEach(a => {
    const ext = (a.path || '').split('.').pop().toLowerCase();
    const div = document.createElement('div');
    div.style.textAlign = 'center';
    div.style.fontSize = '12px';
    if (['jpg','jpeg','png','gif','webp'].includes(ext)) {
      const img = document.createElement('img');
      img.src = a.path;
      img.alt = 'Attachment';
      img.style.width = '90px';
      img.style.height = '70px';
      img.style.objectFit = 'cover';
      img.style.borderRadius = '8px';
      img.style.cursor = 'pointer';
      img.onclick = () => window.open(a.path, '_blank');
      div.appendChild(img);
    } else {
      const link = document.createElement('a');
      link.href = a.path;
      link.target = '_blank';
      link.rel = 'noopener';
      link.textContent = (a.path || '').split('/').pop();
      link.style.color = '#2563eb';
      div.appendChild(link);
    }
    attachWrap.appendChild(div);
  });
}

// Technician proof (if any)
const modalProof = document.querySelector('#modalProof');
modalProof.innerHTML = '';
if (techFiles.length > 0) {
  techFiles.forEach(a => {
    const img = document.createElement('img');
    img.src = a.path;
    img.alt = 'Proof';
    img.style.width = '90px';
    img.style.height = '70px';
    img.style.objectFit = 'cover';
    img.style.borderRadius = '8px';
    img.style.cursor = 'pointer';
    img.onclick = () => window.open(a.path, '_blank');
    modalProof.appendChild(img);

    const link = document.createElement('a');
    link.href = a.path;
    link.target = '_blank';
    link.rel = 'noopener';
    link.textContent = (a.path || '').split('/').pop();
    link.style.display = 'block';
    link.style.marginTop = '4px';
    link.style.color = '#2563eb';
    modalProof.appendChild(link);
  });
} else {
  modalProof.innerHTML = '<span style="color:#64748b;">—</span>';
}


// Fetch technician + admin remark details
fetch(`student_fetch_ticket_details.php?id=${btn.dataset.id}`)
  .then(r => r.json())
  .then(data => {
    document.getElementById('modalTechName').textContent = data.tech_name || '—';
    document.getElementById('modalTechRemark').textContent = data.tech_remark || '—';
    document.querySelector('#modalCompletedAt span').textContent = data.completed_at || '—';
    document.querySelector('#modalAdminRemark span').textContent = data.admin_remark || '—';
   
  // Completed At (conditionally shown)
  const completedLine = document.getElementById('modalCompletedAt');
  const completedValue = completedLine.querySelector('span');

  if (data.status === 'Completed' && data.completed_at && data.completed_at !== '—') {
    completedLine.style.display = 'block';
    completedValue.textContent = data.completed_at;
  } else {
    completedLine.style.display = 'none'; // hide if not completed
  }



    // ✅ NEW: Status badge color logic
    const badge = document.getElementById('mStatusBadge');
    badge.textContent = data.status || '—';

    switch (data.status) {
      case 'Completed':
        badge.style.background = '#dcfce7';
        badge.style.color = '#166534';
        break;
      case 'Pending':
        badge.style.background = '#fef9c3';
        badge.style.color = '#854d0e';
        break;
      case 'In Progress':
        badge.style.background = '#dbeafe';
        badge.style.color = '#1e3a8a';
        break;
      case 'Rejected':
        badge.style.background = '#fee2e2';
        badge.style.color = '#991b1b';
        break;
      default:
        badge.style.background = '#f1f5f9';
        badge.style.color = '#334155';
    }
  })
  .catch(() => {
    document.getElementById('modalTechName').textContent = '—';
    document.getElementById('modalTechRemark').textContent = '—';
    document.querySelector('#modalCompletedAt span').textContent = '—';
    document.querySelector('#modalAdminRemark span').textContent = '—';
  });

openModal('detailsModal');
}


    function closeDetails(){ closeModal('detailsModal'); }

    /* Edit modal subcategory sync */
    const editCategoryEl = document.getElementById('editCategory');
    const editSubcategoryEl = document.getElementById('editSubcategory');
    function syncEditSub(){ populateSub(editCategoryEl.value, editSubcategoryEl); }
    editCategoryEl?.addEventListener('change', syncEditSub);

    /* ----- New complaint attachments widget (15 MB) ----- */
    const MAX = 15 * 1024 * 1024;
    const attRowsWrap = document.getElementById('attRows');
    const meter       = document.getElementById('attMeter');
    const fmtMB = b => (b/1024/1024).toFixed(2);
    function summarizeFiles(input){ if (!input.files?.length) return ''; let out=[]; for (let f of input.files) out.push(`${f.name} (${fmtMB(f.size)} MB)`); return out.join(' • '); }
    function allNewInputs(){ return document.querySelectorAll('#complaintModal input[type=file][name="attachments[]"]'); }
    function totalSelectedBytes(){ let t=0; allNewInputs().forEach(inp=>{ if (inp.files) for (let f of inp.files) t+=f.size||0; }); return t; }
    function refreshMeter(){ if(!meter) return; const t=totalSelectedBytes(); meter.textContent=`Total selected: ${fmtMB(t)} MB / 15.00 MB`; meter.classList.toggle('over', t>MAX); }
    function bindRow(row){ const input=row.querySelector('input[type=file]'); const list=row.nextElementSibling; input.addEventListener('change', ()=>{ list.textContent=summarizeFiles(input)||''; refreshMeter(); }); }
    function addAttRow(){ const row=document.createElement('div'); row.className='att-row'; row.innerHTML=`<div class="att-file"><input type="file" name="attachments[]" accept="image/*,video/*" multiple></div><button type="button" class="att-remove" onclick="removeAttRow(this)" title="Remove row">✕</button>`; const preview=document.createElement('div'); preview.className='att-files-list'; attRowsWrap.appendChild(row); attRowsWrap.appendChild(preview); bindRow(row); refreshMeter(); }
    function removeAttRow(btn){ const row=btn.closest('.att-row'); if(!row) return; const input=row.querySelector('input[type=file]'); if(input){ try{input.value='';}catch(e){} } const preview=row.nextElementSibling; if(preview?.classList.contains('att-files-list')) preview.remove(); row.remove(); refreshMeter(); }
    (function(){ const firstRow=document.querySelector('#complaintModal .att-row'); const firstPrev=document.querySelector('#complaintModal .att-files-list'); if(firstRow){ const input=firstRow.querySelector('input[type=file]'); input.addEventListener('change', ()=>{ firstPrev.textContent=summarizeFiles(input)||''; refreshMeter(); }); }})();
    document.getElementById('submitForm')?.addEventListener('submit', (e)=>{ if(totalSelectedBytes()>MAX){ e.preventDefault(); alert('Total attachments exceed 15 MB. Please remove some files.'); } });

    /* ----- Edit modal: existing with tiny trash + add-more widget (15 MB new-only check) ----- */
    let removedSet = new Set(); // ids marked for removal

    function renderExistingList(arr){
      removedSet = new Set();
      const ul = document.getElementById('editExistingList');
      const info = document.getElementById('editAttInfo');
      document.querySelectorAll('#editForm input[name="existing_remove[]"]').forEach(n=>n.remove());

      ul.innerHTML = '';
      let total = 0;

      if (!arr || !arr.length){
        const li = document.createElement('li');
        li.className = 'existing-item';
        li.innerHTML = '<div class="existing-meta"><span class="existing-name">No existing attachments</span></div><div></div>';
        ul.appendChild(li);
        info.textContent = 'Existing total: 0.00 MB • New + remaining must be ≤ 15 MB.';
        return;
      }

      arr.forEach(o=>{
        const size = Number(o.size||0);
        total += size;

        const li = document.createElement('li');
        li.className = 'existing-item';
        li.dataset.id = o.id;

        const left = document.createElement('div');
        left.className = 'existing-meta';

        const a = document.createElement('a');
        a.href = o.path; a.target = '_blank'; a.rel = 'noopener';
        a.className = 'existing-name';
        a.textContent = (o.path||'').split('/').pop();

        const sizeSpan = document.createElement('span');
        sizeSpan.className = 'existing-size';
        sizeSpan.textContent = `(${(size/1024/1024).toFixed(2)} MB)`;

        left.appendChild(a);
        left.appendChild(sizeSpan);

        const action = document.createElement('button');
        action.type = 'button';
        action.className = 'trash-btn';
        action.title = 'Remove';
        action.setAttribute('aria-label','Remove');
        action.innerHTML = `
          <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
            <path d="M9 3h6a1 1 0 0 1 1 1v1h4v2h-1v12a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V7H3V5h4V4a1 1 0 0 1 1-1Zm1 2v0h4V5h-4Zm-2 4h2v10H8V9Zm4 0h2v10h-2V9Zm4 0h2v10h-2V9Z"/>
          </svg>
        `;
        action.addEventListener('click', ()=> toggleRemoveExisting(o.id, li));

        li.appendChild(left);
        li.appendChild(action);
        ul.appendChild(li);
      });

      info.textContent = `Existing total: ${(total/1024/1024).toFixed(2)} MB • New + remaining must be ≤ 15 MB.`;
    }

    function toggleRemoveExisting(id, li){
      const form = document.getElementById('editForm');
      const inputName = 'existing_remove[]';

      if (removedSet.has(id)){
        removedSet.delete(id);
        li.classList.remove('removed');
        form.querySelectorAll(`input[name="${inputName}"][value="${id}"]`).forEach(n=>n.remove());
      } else {
        removedSet.add(id);
        li.classList.add('removed');
        const hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = inputName;
        hidden.value = id;
        form.appendChild(hidden);
      }
    }

    const editMAX = 15 * 1024 * 1024;
    const editRowsWrap = document.getElementById('editAttRows');
    const editMeter    = document.getElementById('editAttMeter');
    const fmt = b => (b/1024/1024).toFixed(2);
    function summarize(input){ if(!input.files?.length) return ''; let out=[]; for (let f of input.files) out.push(`${f.name} (${fmt(f.size)} MB)`); return out.join(' • '); }
    function editInputs(){ return document.querySelectorAll('#editModal input[type=file][name="attachments[]"]'); }
    function totalNewBytes(){ let t=0; editInputs().forEach(inp=>{ if (inp.files) for (let f of inp.files) t+=f.size||0; }); return t; }
    function refreshEditMeter(){ editMeter.textContent = `New selections: ${fmt(totalNewBytes())} MB`; }
    function bindEditRow(row){ const input=row.querySelector('input[type=file]'); const preview=row.nextElementSibling; input.addEventListener('change', ()=>{ preview.textContent=summarize(input)||''; refreshEditMeter(); }); }
    function addEditAttRow(){ const row=document.createElement('div'); row.className='att-row'; row.innerHTML=`<div class="att-file"><input type="file" name="attachments[]" accept="image/*,video/*" multiple></div><button type="button" class="att-remove" title="Remove row" onclick="removeAttRow(this)">✕</button>`; const preview=document.createElement('div'); preview.className='att-files-list'; editRowsWrap.appendChild(row); editRowsWrap.appendChild(preview); bindEditRow(row); refreshEditMeter(); }
    (function(){ const firstRow=document.querySelector('#editModal .att-row'); const firstPrev=document.querySelector('#editModal .att-files-list'); if(firstRow){ const input=firstRow.querySelector('input[type=file]'); input.addEventListener('change', ()=>{ firstPrev.textContent=summarize(input)||''; refreshEditMeter(); }); }})();
    document.getElementById('editForm')?.addEventListener('submit', (e)=>{ if (totalNewBytes() > editMAX) { e.preventDefault(); alert('New attachments exceed 15 MB. Please remove some files or remove existing files.'); } });

    // Open Edit with attachments populated
    function openEdit(btn){
      document.getElementById('editComplaintId').value = btn.dataset.id;
      document.getElementById('editTitle').value       = btn.dataset.title || '';
      document.getElementById('editDescription').value = btn.dataset.description || '';
      document.getElementById('editPhone').value       = studentPhone || '';
      editCategoryEl.value = btn.dataset.category || '';
      syncEditSub();
      const subVal = btn.dataset.subcategory || '';
      if (subVal && !editSubcategoryEl.disabled) editSubcategoryEl.value = subVal;

      let arr=[]; try{ arr = JSON.parse(btn.dataset.attachments || '[]'); }catch(e){}
      renderExistingList(arr);

      openModal('editModal');
    }

    // ESC and click-outside to close
    document.addEventListener('keydown', e => { if (e.key === 'Escape') { ['detailsModal','editModal','complaintModal'].forEach(closeModal); }});
    ['detailsModal','complaintModal','editModal'].forEach(id=>{
      document.getElementById(id)?.addEventListener('click', e => { if (e.target.id === id) closeModal(id); });
    });



  </script>

  <script>
  /* ─── Unified Notification Toast (slide-in from right, with Close hover glow) ───────────────────────────── */
const COOLDOWN_MS = 60 * 1000; // 1 minute cooldown
const dailyCount = <?= json_encode($dailyCount) ?>;

/* ─── Create Toast ───────────────────────────── */
function createUnifiedNotice(message, cooldownSecs, dailyCount, limitReached, type = 'success') {
  document.querySelector('.unified-toast')?.remove();

  const box = document.createElement('div');
  box.className = 'unified-toast';

  // Color palette
  let bg, border, color;
  if (type === 'warning') {
    bg = '#fefce8'; border = '#fde68a'; color = '#92400e'; // yellow
  } else if (limitReached) {
    bg = '#fef2f2'; border = '#fecaca'; color = '#991b1b'; // red
  } else {
    bg = '#ecfdf5'; border = '#a7f3d0'; color = '#065f46'; // green
  }

  Object.assign(box.style, {
    position: 'fixed',
    bottom: '30px',
    right: '30px',
    zIndex: '9999',
    minWidth: '270px',
    maxWidth: '340px',
    background: bg,
    border: `1px solid ${border}`,
    color: color,
    padding: '12px 14px 10px',
    borderRadius: '10px',
    fontSize: '13px',
    lineHeight: '1.6',
    boxShadow: '0 8px 22px rgba(0,0,0,0.18)',
    opacity: '0',
    transform: 'translateX(100%)',
    transition: 'opacity 0.4s ease, transform 0.4s ease'
  });

  // Header with message + close
  const header = document.createElement('div');
  header.style.display = 'flex';
  header.style.justifyContent = 'space-between';
  header.style.alignItems = 'center';

  const main = document.createElement('div');
  main.innerHTML = message;
  main.style.fontWeight = '600';
  main.style.marginRight = '8px';

  const closeBtn = document.createElement('button');
  closeBtn.textContent = '×';
  Object.assign(closeBtn.style, {
    background: 'transparent',
    border: 'none',
    color,
    fontSize: '18px',
    fontWeight: 'bold',
    cursor: 'pointer',
    lineHeight: '1',
    borderRadius: '6px',
    padding: '2px 6px',
    transition: 'all 0.2s ease'
  });

  closeBtn.addEventListener('mouseenter', () => {
    closeBtn.style.background = type === 'warning' ? '#fef9c3' : limitReached ? '#fee2e2' : '#d1fae5';
    closeBtn.style.boxShadow = type === 'warning'
      ? '0 0 8px rgba(250,204,21,0.4)'
      : limitReached
        ? '0 0 8px rgba(239,68,68,0.4)'
        : '0 0 8px rgba(16,185,129,0.4)';
  });
  closeBtn.addEventListener('mouseleave', () => {
    closeBtn.style.background = 'transparent';
    closeBtn.style.boxShadow = 'none';
  });
  closeBtn.addEventListener('click', () => hideToast(box));

  header.appendChild(main);
  header.appendChild(closeBtn);
  box.appendChild(header);

  // Divider
  const line = document.createElement('hr');
  Object.assign(line.style, {
    border: 'none',
    borderTop: '1px solid rgba(0,0,0,0.1)',
    margin: '6px 0'
  });
  box.appendChild(line);

  // Countdown + Daily info
  const cooldown = document.createElement('div');
  cooldown.textContent = cooldownSecs ? `⏳ You can submit again in ${cooldownSecs}s` : '';
  box.appendChild(cooldown);

  const daily = document.createElement('div');
  daily.innerHTML = limitReached
    ? `📊 <strong style="color:#b91c1c;">Complaints today: ${dailyCount} / 5 (limit reached)</strong>`
    : `📊 Complaints today: <strong>${dailyCount} / 5</strong>`;
  box.appendChild(daily);

  document.body.appendChild(box);

  // Slide-in animation
  requestAnimationFrame(() => {
    box.style.opacity = '1';
    box.style.transform = 'translateX(0)';
  });

  // Auto-hide after delay
  const fadeDelay = cooldownSecs > 0 ? (COOLDOWN_MS + 4000) : 5000;
  setTimeout(() => hideToast(box), fadeDelay);
}

function hideToast(box) {
  box.style.opacity = '0';
  box.style.transform = 'translateX(100%)';
  setTimeout(() => box.remove(), 600);
}

/* ─── Flash + cooldown persistence ───────────────────────────── */
function showNotice() {
  const flashMsg = document.querySelector('.flash.ok');
  const storedEnd = localStorage.getItem('pdtsCooldownEnd');
  const remaining = storedEnd ? Math.ceil((parseInt(storedEnd) - Date.now()) / 1000) : 0;
  const limitReached = dailyCount >= 5;

  if (flashMsg) flashMsg.remove();
  createUnifiedNotice('✅ Complaint submitted successfully.', remaining > 0 ? remaining : 0, dailyCount, limitReached, 'success');

  if (remaining > 0) {
    const interval = setInterval(() => {
      const notice = document.querySelector('.unified-toast');
      const nowRemain = Math.ceil((parseInt(storedEnd) - Date.now()) / 1000);
      if (!notice) return clearInterval(interval);
      const cooldownLine = notice.querySelector('div:nth-child(3)');
      if (nowRemain <= 0) {
        cooldownLine.textContent = '';
        clearInterval(interval);
      } else {
        cooldownLine.textContent = `⏳ You can submit again in ${nowRemain}s`;
      }
    }, 1000);
  }
}

/* ─── Submit handler ───────────────────────────── */
const form = document.getElementById('submitForm');
let lastSubmitTime = 0;
form?.addEventListener('submit', (e) => {
  const now = Date.now();

  if (now - lastSubmitTime < 10000) {
    e.preventDefault();
    createUnifiedNotice("⚠️ Please wait a few seconds before submitting another complaint.", 0, dailyCount, false, 'warning');
    return;
  }
  lastSubmitTime = now;

  const storedEnd = parseInt(localStorage.getItem('pdtsCooldownEnd') || '0', 10);
  if (storedEnd > now) {
    e.preventDefault();
    const remaining = Math.ceil((storedEnd - now) / 1000);
    createUnifiedNotice(`⚠️ Please wait — you can submit again in ${remaining}s.`, remaining, dailyCount, false, 'warning');
    return;
  }

  const end = now + COOLDOWN_MS;
  localStorage.setItem('pdtsCooldownEnd', end);
  showNotice();

  sessionStorage.setItem('pdtsJustSubmitted', '1');

});

/* ─── Restore active cooldown ───────────────────────────── */
window.addEventListener('DOMContentLoaded', () => {
  const justSubmitted = sessionStorage.getItem('pdtsJustSubmitted');
  if (justSubmitted === '1') {
    showNotice(); // show the toast only once
    sessionStorage.removeItem('pdtsJustSubmitted');
  }
});

/* ─── Disable “+ New Complaint” button if limit reached ───────────────────────────── */
const newBtn = document.querySelector('.small-btn[onclick*="openModal"]');
if (dailyCount >= 5 && newBtn) {
  newBtn.disabled = true;
  newBtn.classList.add('btn-disabled');
  newBtn.title = "Daily limit reached. You can only submit up to 5 complaints per day.";
  newBtn.style.cursor = 'not-allowed';
  newBtn.style.opacity = '.6';

  const tooltip = document.createElement('span');
  tooltip.textContent = "Daily limit reached (5/5)";
  Object.assign(tooltip.style, {
    position: 'absolute',
    background: 'rgba(17, 24, 39, 0.9)',
    color: '#fff',
    padding: '6px 8px',
    borderRadius: '6px',
    fontSize: '12px',
    opacity: '0',
    transition: 'opacity .2s ease',
    whiteSpace: 'nowrap',
    zIndex: '999',
    transform: 'translateY(-30px)',
    pointerEvents: 'none'
  });
  newBtn.style.position = 'relative';
  newBtn.parentNode.style.position = 'relative';
  newBtn.parentNode.appendChild(tooltip);
  newBtn.addEventListener('mouseenter', () => (tooltip.style.opacity = '1'));
  newBtn.addEventListener('mouseleave', () => (tooltip.style.opacity = '0'));
}

</script>


<script>
/* ─── Dark Mode Toggle Logic ─────────────────────────── */
const themeToggle = document.getElementById('themeToggle');
const themeTooltip = document.getElementById('themeTooltip');

// Load theme preference
const savedTheme = localStorage.getItem('pdtsTheme');
if (savedTheme === 'dark') {
  document.body.classList.add('dark');
  themeToggle.textContent = '☀️';
  themeTooltip.textContent = 'Switch to light mode ☀️';
} else {
  themeToggle.textContent = '🌙';
  themeTooltip.textContent = 'Switch to dark mode 🌙';
}

// On click toggle
themeToggle.addEventListener('click', () => {
  document.body.classList.add('fade-transition');
  const isDark = document.body.classList.toggle('dark');

  localStorage.setItem('pdtsTheme', isDark ? 'dark' : 'light');
  themeToggle.textContent = isDark ? '☀️' : '🌙';
  themeTooltip.textContent = isDark ? 'Switch to light mode ☀️' : 'Switch to dark mode 🌙';

  setTimeout(() => document.body.classList.remove('fade-transition'), 400);
});


</script>

<script>
// If ?focus=ID is present, scroll to that row and briefly highlight it
window.addEventListener('DOMContentLoaded', () => {
  const params = new URLSearchParams(window.location.search);
  const focusId = params.get('focus');
  if (!focusId) return;

  const row = document.getElementById('c' + focusId);
  if (!row) return;

  row.scrollIntoView({ behavior: 'smooth', block: 'center' });
  setTimeout(() => {
    row.classList.add('focus-highlight');
    setTimeout(() => row.classList.remove('focus-highlight'), 3500);
  }, 250);
});
</script>


</body>
</html>
