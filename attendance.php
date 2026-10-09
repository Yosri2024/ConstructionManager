<?php
require_once 'config.php';
requireLogin();

// Lightweight polling endpoint for auto-refresh
if (isset($_GET['__poll'])) {
    header('Content-Type: application/json');
    $pdo = getDB();
    // Need company id from session if available
    $cid = $_SESSION['company_id'] ?? 0;
    $today = date('Y-m-d');
    if ($cid) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM attendance a JOIN workers w ON w.id = a.worker_id WHERE w.company_id = ? AND a.attendance_date = ?");
        $stmt->execute([$cid, $today]);
        $count = (int)$stmt->fetchColumn();
    } else {
        $count = (int)$pdo->query("SELECT COUNT(*) FROM attendance WHERE attendance_date = '$today'")->fetchColumn();
    }
    echo json_encode(['count' => $count, 'time' => time()]);
    exit;
}

$pdo = getDB();
$user = currentUser();
$companyId = getCurrentCompanyId();
$isManager = $user['role'] === 'manager';
$isSupervisor = $user['role'] === 'supervisor';

$mySiteIds = $isManager ? [] : getMySiteIds($user);

if ($isManager) {
    $sites = $pdo->prepare("SELECT id, name FROM sites WHERE company_id = ? ORDER BY name");
    $sites->execute([$companyId]);
    $sites = $sites->fetchAll(PDO::FETCH_ASSOC);
    $workers = $pdo->prepare("SELECT id, first_name || ' ' || last_name as name, current_site_id FROM workers WHERE company_id = ? ORDER BY last_name");
    $workers->execute([$companyId]);
    $workers = $workers->fetchAll(PDO::FETCH_ASSOC);
} else {
    // Supervisor: only their own sites (via site_supervisors + legacy supervisor_id) filtered by company
    if (empty($mySiteIds)) {
        $sites = [];
        $workers = [];
    } else {
        $stmt = $pdo->prepare("SELECT id, name FROM sites WHERE company_id = ? AND id IN (" . siteIdsForSql($mySiteIds) . ") ORDER BY name");
        $stmt->execute([$companyId]);
        $sites = $stmt->fetchAll(PDO::FETCH_ASSOC);
        // Supervisor: only workers assigned to their own sites
        $stmt = $pdo->prepare("SELECT id, first_name || ' ' || last_name as name, current_site_id FROM workers WHERE company_id = ? AND current_site_id IN (" . siteIdsForSql($mySiteIds) . ") ORDER BY last_name");
        $stmt->execute([$companyId]);
        $workers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

$jobCodes = getJobCodes();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    require_csrf();
    if ($isManager) { flash('Only supervisors can mark attendance'); header('Location: attendance.php'); exit; }
    if ($isSupervisor) {
        $check = $pdo->prepare("SELECT id FROM sites WHERE id = ? AND id IN (" . siteIdsForSql($mySiteIds) . ")");
        $check->execute([$_POST['site_id']]);
        if (!$check->fetch()) { flash('Not your site'); header('Location: attendance.php'); exit; }
    }
    $stmt = $pdo->prepare("INSERT INTO attendance (worker_id, site_id, attendance_date, status, notes) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$_POST['worker_id'], $_POST['site_id'], $_POST['attendance_date'], $_POST['status'], $_POST['notes'] ?? '']);
    flash('Attendance updated');
    header('Location: attendance.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit') {
    require_csrf();
    if ($isManager) { flash('Only supervisors can edit attendance'); header('Location: attendance.php'); exit; }
    if ($isSupervisor) {
        $check = $pdo->prepare("SELECT a.id FROM attendance a JOIN sites s ON s.id = a.site_id WHERE a.id = ? AND s.id IN (" . siteIdsForSql($mySiteIds) . ")");
        $check->execute([$_POST['id']]);
        if (!$check->fetch()) { flash('Not your site'); header('Location: attendance.php'); exit; }
    }
    $stmt = $pdo->prepare("UPDATE attendance SET worker_id=?, site_id=?, attendance_date=?, status=?, notes=? WHERE id=?");
    $stmt->execute([$_POST['worker_id'], $_POST['site_id'], $_POST['attendance_date'], $_POST['status'], $_POST['notes'] ?? '', $_POST['id']]);
    flash('Attendance updated');
    header('Location: attendance.php');
    exit;
}

if (isset($_GET['delete'])) {
    if ($isManager) { flash('Only supervisors can delete attendance records'); header('Location: attendance.php'); exit; }
    if ($isSupervisor) {
        $check = $pdo->prepare("SELECT a.id FROM attendance a JOIN sites s ON s.id = a.site_id WHERE a.id = ? AND s.id IN (" . siteIdsForSql($mySiteIds) . ")");
        $check->execute([$__aid]);
        if (!$check->fetch()) { flash('Not your site'); header('Location: attendance.php'); exit; }
    }
    $pdo->prepare("DELETE FROM attendance WHERE id = ?")->execute([$__aid]);
    flash('Entry removed', 'warning');
    header('Location: attendance.php');
    exit;
}

$editing = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM attendance WHERE id = ?");
    $stmt->execute([$_GET['edit']]);
    $editing = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($editing && $isSupervisor) {
        $check = $pdo->prepare("SELECT id FROM sites WHERE id = ? AND id IN (" . siteIdsForSql($mySiteIds) . ")");
        $check->execute([$editing['site_id']]);
        if (!$check->fetch()) { flash('Not your site'); header('Location: attendance.php'); exit; }
    }
}

$where = []; $params = [];
// Always filter by company
$where[] = 's.company_id = ?'; $params[] = $companyId;
$where[] = 'w.company_id = ?'; $params[] = $companyId;
if (isset($_GET['site_id']) && $_GET['site_id']) { $where[] = 'a.site_id = ?'; $params[] = $_GET['site_id']; }
if (isset($_GET['date']) && $_GET['date']) { $where[] = 'a.attendance_date = ?'; $params[] = $_GET['date']; }
if (isset($_GET['job_code']) && $_GET['job_code']) { $where[] = 's.job_code = ?'; $params[] = $_GET['job_code']; }
if ($isSupervisor) { $where[] = 'a.site_id IN (' . siteIdsForSql($mySiteIds) . ')'; }
// Supervisor can only see attendance for workers CURRENTLY assigned to their sites
if ($isSupervisor) { $where[] = 'w.current_site_id IN (' . siteIdsForSql($mySiteIds) . ')'; }
$wSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Get today's date and current time for the top section
$today = date('Y-m-d');
$currentTime = date('H:i');
$currentDateTime = date('l, F d, Y - H:i');

// Query 1: Get today's attendance summary (all workers and their status for today) - filtered by company
// Sort by status: present -> late -> absent -> sick -> unmarked
$todaySQL = "SELECT w.id, w.first_name || ' ' || w.last_name as worker_name,
             s.name as site_name, s.id as site_id, s.job_code, a.status, a.notes, a.id as attendance_id,
             CASE
                 WHEN a.status = 'present' THEN 1
                 WHEN a.status = 'late' THEN 2
                 WHEN a.status = 'absent' THEN 3
                 WHEN a.status = 'sick' THEN 4
                 WHEN a.status = 'on_leave' THEN 5
                 ELSE 6
             END as status_order
             FROM workers w
             JOIN sites s ON s.id = w.current_site_id AND s.company_id = $companyId
             LEFT JOIN attendance a ON a.worker_id = w.id AND a.attendance_date = ? AND a.site_id = s.id
             WHERE w.company_id = $companyId";
if ($isSupervisor) {
    $todaySQL .= " AND w.current_site_id IN (" . siteIdsForSql($mySiteIds) . ")";
}
$todaySQL .= " ORDER BY status_order, s.name, w.last_name, w.first_name";
$stmt = $pdo->prepare($todaySQL);
$stmt->execute([$today]);
$todayAttendance = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Query 2: Get all attendance records grouped by date (HISTORICAL - excludes today)
// Sort by status within each date: present -> late -> absent -> sick -> on_leave
$stmt = $pdo->prepare("
    SELECT a.*, w.first_name || ' ' || w.last_name as worker_name, s.name as site_name, s.job_code,
           CASE
               WHEN a.status = 'present' THEN 1
               WHEN a.status = 'late' THEN 2
               WHEN a.status = 'absent' THEN 3
               WHEN a.status = 'sick' THEN 4
               WHEN a.status = 'on_leave' THEN 5
               ELSE 6
           END as status_order
    FROM attendance a JOIN workers w ON w.id = a.worker_id JOIN sites s ON s.id = a.site_id
    $wSQL AND a.attendance_date < ?
    ORDER BY a.attendance_date DESC, status_order, w.last_name LIMIT 200
");
$params[] = $today;
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Group by date for display
$groupedByDate = [];
foreach ($rows as $r) {
    $groupedByDate[$r['attendance_date']][] = $r;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance - Construction Manager</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
<?php require_once 'sidebar.php'; ?>
<div class="main-content">
    <div class="topbar">
        <h1>Attendance</h1>
        <div class="topbar-actions">
            <?php if ($isSupervisor): ?>
            <button onclick="document.getElementById('attForm').style.display='block'" class="btn btn-primary">+ Mark Attendance</button>
            <?php endif; ?>
        </div>
    </div>
    <div class="content-area">
        <?php if ($msg = flash()): ?><div class="alert <?= flashType() ?> auto-dismiss"><?= h($msg) ?></div><?php endif; ?>

        <div class="alert info" style="margin-bottom:20px;">
            <strong>📋 Attendance Tracker:</strong>
            <?php if ($isManager): ?>
                View attendance records across all sites. Only supervisors can mark attendance.
            <?php else: ?>
                Track attendance for your assigned sites (<?= count($sites) ?> site<?= count($sites) != 1 ? 's' : '' ?>).
                Showing workers currently assigned to your sites only.
            <?php endif; ?>
        </div>

        <?php if ($isSupervisor || $editing): ?>
        <div class="card" id="attForm" style="display:<?= ($editing || isset($_GET['new'])) ? 'block' : 'none' ?>">
            <div class="card-header">
                <h2><?= $editing ? 'Edit Attendance' : ($isManager ? 'Edit Attendance' : 'Record Attendance') ?></h2>
                <a href="attendance.php" class="btn btn-sm btn-secondary">Close</a>
            </div>
            <div class="card-body">
                <?php if (!$sites): ?>
                    <div class="alert warning">You have no sites assigned.</div>
                <?php else: ?>
                <form method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="<?= $editing ? 'edit' : 'create' ?>">
                    <?php if ($editing): ?><input type="hidden" name="id" value="<?= $editing['id'] ?>"><?php endif; ?>
                    <div class="form-row-3">
                        <div class="form-group">
                            <label>Worker *</label>
                            <select name="worker_id" required>
                                <option value="">-- Select --</option>
                                <?php
                                $preSelectedWorker = $editing['worker_id'] ?? ($_GET['worker_id'] ?? '');
                                $workerSiteFilter = ($editing && $editing['site_id'])
                                    ? ($editing['site_id'])
                                    : ($_GET['site_id'] ?? ($sites[0]['id'] ?? ''));
                                foreach ($workers as $w):
                                    $isAssignedToSelectedSite = $w['current_site_id'] == $workerSiteFilter;
                                    $isSelected = $preSelectedWorker == $w['id'];
                                ?>
                                <option value="<?= $w['id'] ?>" <?= ($isSelected || ($isAssignedToSelectedSite && !$editing && !$preSelectedWorker)) ? 'selected' : '' ?>><?= h($w['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Site *</label>
                            <select name="site_id" required>
                                <?php
                                $preSelectedSite = $editing['site_id'] ?? ($_GET['site_id'] ?? '');
                                foreach ($sites as $s):
                                    $isSiteSelected = $preSelectedSite == $s['id'];
                                    $isFirstSite = !$preSelectedSite && $s['id'] == ($sites[0]['id'] ?? '');
                                ?>
                                <option value="<?= $s['id'] ?>" <?= ($isSiteSelected || $isFirstSite) ? 'selected' : '' ?>><?= h($s['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Date *</label>
                            <input type="date" name="attendance_date" required value="<?= h($editing['attendance_date'] ?? ($_GET['date'] ?? date('Y-m-d'))) ?>">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Status *</label>
                            <select name="status" required>
                                <?php if ($editing): ?>
                                    <?php /* Editing: supervisor sees all, manager sees only Absent/Sick/On Leave */ ?>
                                    <?php if ($isSupervisor): ?>
                                    <option value="present" <?= ($editing['status'] ?? '') === 'present' ? 'selected' : '' ?>>Present</option>
                                    <option value="late" <?= ($editing['status'] ?? '') === 'late' ? 'selected' : '' ?>>Late</option>
                                    <option value="absent" <?= ($editing['status'] ?? '') === 'absent' ? 'selected' : '' ?>>Absent</option>
                                    <option value="sick" <?= ($editing['status'] ?? '') === 'sick' ? 'selected' : '' ?>>Sick</option>
                                    <option value="on_leave" <?= ($editing['status'] ?? '') === 'on_leave' ? 'selected' : '' ?>>On Leave</option>
                                    <?php else: ?>
                                    <option value="absent" <?= ($editing['status'] ?? '') === 'absent' ? 'selected' : '' ?>>Absent</option>
                                    <option value="sick" <?= ($editing['status'] ?? '') === 'sick' ? 'selected' : '' ?>>Sick</option>
                                    <option value="on_leave" <?= ($editing['status'] ?? '') === 'on_leave' ? 'selected' : '' ?>>On Leave</option>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <?php /* Creating (marking): supervisor only, no Sick */ ?>
                                    <option value="present">Present</option>
                                    <option value="late">Late</option>
                                    <option value="absent">Absent</option>
                                    <option value="on_leave">On Leave</option>
                                <?php endif; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Notes</label>
                            <input type="text" name="notes" value="<?= h($editing['notes'] ?? '') ?>">
                        </div>
                    </div>
                    <button class="btn btn-primary"><?= $editing ? 'Save Changes' : 'Save' ?></button>
                    <?php if ($editing): ?>
                        <a href="attendance.php" class="btn btn-secondary">Cancel</a>
                    <?php endif; ?>
                </form>
                <?php endif; ?>
            </div>
        </div>

        <div class="card" style="margin-bottom:20px;">
            <div class="card-header">
                <h2>📅 Today - <?= date('l, F d, Y') ?></h2>
                <span style="color:#6b7280;font-size:13px;">⏰ <?= $currentTime ?></span>
            </div>
            <div class="card-body">
                <?php if (empty($todayAttendance)): ?>
                    <div class="empty-state" style="padding:40px;">
                        <div class="icon">👷</div>
                        <p>No workers assigned to your sites yet.</p>
                    </div>
                <?php else: ?>
                    <div class="table-wrap">
                        <table>
                            <colgroup>
                                <col style="width:18%">
                                <col style="width:20%">
                                <col style="width:12%">
                                <col style="width:14%">
                                <col style="width:26%">
                                <col style="width:10%">
                            </colgroup>
                            <thead>
                                <tr>
                                    <th>Worker</th>
                                    <th>Site</th>
                                    <th>Job Code</th>
                                    <th>Status</th>
                                    <th>Notes</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($todayAttendance as $t): ?>
                                <tr>
                                    <td><strong><?= h($t['worker_name']) ?></strong></td>
                                    <td><?= h($t['site_name']) ?></td>
                                    <td><?= h($t['job_code']) ?: '—' ?></td>
                                    <td>
                                        <?php if ($t['status']): ?>
                                            <span class="badge badge-<?= h($t['status']) ?>"><?= h($t['status']) ?></span>
                                        <?php else: ?>
                                            <span class="badge" style="background:#e5e7eb;color:#6b7280;">Unmarked</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= h($t['notes']) ?: '—' ?></td>
                                    <td class="actions">
                                        <?php if ($t['attendance_id']): ?>
                                            <?php
                                            // Edit button: only for absent, sick, on_leave — not for present or late
                                            $canEdit = in_array($t['status'], ['absent', 'sick', 'on_leave']);
                                            ?>
                                            <?php if ($canEdit): ?>
                                            <a href="attendance.php?edit=<?= $t['attendance_id'] ?>" class="btn btn-sm btn-edit">✏️ Edit</a>
                                            <?php endif; ?>
                                            <?php if ($isSupervisor): ?>
                                            <form method="GET" style="display:inline;" data-confirm="Delete this attendance record?">
                                                <input type="hidden" name="delete" value="<?= $t['attendance_id'] ?>">
                                                <button class="btn btn-sm btn-delete">🗑️</button>
                                            </form>
                                            <?php endif; ?>
                                        <?php elseif ($isSupervisor): ?>
                                            <a href="attendance.php?new=1&worker_id=<?= $t['id'] ?>&site_id=<?= $t['site_id'] ?>&date=<?= $today ?>" class="btn btn-sm btn-approve">➕ Mark</a>
                                        <?php else: ?>
                                            <span style="color:#9ca3af;font-size:12px">—</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; // end supervisor-or-editing form ?>

        <div class="card">
            <div class="card-header"><h2>📋 Daily History</h2></div>
            <div class="card-body">
                <form method="GET" class="filters" id="attFilterForm">
                    <select name="site_id">
                        <option value="">All Sites</option>
                        <?php foreach ($sites as $s): ?>
                        <option value="<?= $s['id'] ?>" <?= ($_GET['site_id'] ?? '') == $s['id'] ? 'selected' : '' ?>><?= h($s['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="job_code">
                        <option value="">All Job Codes</option>
                        <?php foreach ($jobCodes as $jc): ?>
                        <option value="<?= h($jc) ?>" <?= ($_GET['job_code'] ?? '') === $jc ? 'selected' : '' ?>><?= h($jc) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="date" name="date" value="<?= h($_GET['date'] ?? '') ?>">
                    <a href="attendance.php" class="btn btn-sm btn-secondary">Clear</a>
                </form>
                <?php if (empty($rows)): ?>
                    <div class="empty-state" style="padding:40px;">
                        <div class="icon">📋</div>
                        <p>No records yet.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($groupedByDate as $date => $dateRows): ?>
                        <div style="margin-bottom:20px;">
                            <h3 style="background:#f3f4f6;padding:10px;border-radius:6px;color:#1f2937;margin-bottom:10px;">
                                📅 <?= date('l, F d, Y', strtotime($date)) ?>
                                <span style="float:right;font-size:13px;color:#6b7280;font-weight:normal;">
                                    <?= count($dateRows) ?> worker<?= count($dateRows) > 1 ? 's' : '' ?>
                                </span>
                            </h3>
                            <div class="table-wrap">
                                <table>
                                    <colgroup>
                                        <col style="width:18%">
                                        <col style="width:20%">
                                        <col style="width:12%">
                                        <col style="width:14%">
                                        <col style="width:26%">
                                        <col style="width:10%">
                                    </colgroup>
                                    <thead>
                                        <tr>
                                            <th>Worker</th><th>Site</th><th>Job Code</th><th>Status</th><th>Notes</th>
                                            <th></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($dateRows as $r): ?>
                                        <tr>
                                            <td><strong><?= h($r['worker_name']) ?></strong></td>
                                            <td><?= h($r['site_name']) ?></td>
                                            <td><?= h($r['job_code']) ?: '—' ?></td>
                                            <td><span class="badge badge-<?= h($r['status']) ?>"><?= h($r['status']) ?></span></td>
                                            <td><?= h($r['notes']) ?: '—' ?></td>
                                            <td class="actions">
                                                <?php
                                                // Edit button: only for absent, sick, on_leave
                                                $canEditR = in_array($r['status'], ['absent', 'sick', 'on_leave']);
                                                ?>
                                                <?php if ($canEditR): ?>
                                                <a href="attendance.php?edit=<?= $r['id'] ?>" class="btn btn-sm btn-edit">Edit</a>
                                                <?php endif; ?>
                                                <?php if ($isSupervisor): ?>
                                                <form method="GET" style="display:inline" data-confirm="Delete entry?">
                                                    <input type="hidden" name="delete" value="<?= $r['id'] ?>">
                                                    <button class="btn btn-sm btn-delete">×</button>
                                                </form>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<script src="js/app.js"></script>
<script>
document.querySelectorAll('#attFilterForm select, #attFilterForm input').forEach(function(el){
    el.addEventListener('change', function(){ document.getElementById('attFilterForm').submit(); });
});
document.querySelector('#attFilterForm input[name="date"]').addEventListener('keypress', function(e){
    if(e.key==='Enter'){ e.preventDefault(); document.getElementById('attFilterForm').submit(); }
});
// Auto-refresh for manager: poll every 30s, reload if today's record count changes
<?php if ($isManager): ?>
(function(){
    var lastCount = null;
    var lastTime = null;
    var poll = function() {
        fetch('attendance.php?__poll=1')
            .then(function(r){ return r.json(); })
            .then(function(data){
                if (lastCount !== null && (data.count !== lastCount || data.time !== lastTime)) {
                    location.reload();
                }
                lastCount = data.count;
                lastTime = data.time;
            })
            .catch(function(){});
    };
    // Initial snapshot
    poll();
    // Poll every 30 seconds
    setInterval(poll, 30000);
})();
<?php endif; ?>
</script>
</body>
</html>
