<?php
require_once 'config.php';
requireLogin();

$pdo = getDB();
$user = currentUser();
$isManager = $user['role'] === 'manager';
$isSupervisor = $user['role'] === 'supervisor';

// Get sites the user can see
if ($isManager) {
    $sites = $pdo->query("SELECT id, name FROM sites ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
} else {
    $stmt = $pdo->prepare("SELECT id, name FROM sites WHERE supervisor_id = ? ORDER BY name");
    $stmt->execute([$user['id']]);
    $sites = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$jobCodes = getJobCodes();

$mySiteIds = getMySiteIds($user);
if ($isManager) {
    $workers = $pdo->query("SELECT id, first_name || ' ' || last_name as name, current_site_id FROM workers ORDER BY last_name")->fetchAll(PDO::FETCH_ASSOC);
} else {
    $stmt = $pdo->prepare("SELECT id, first_name || ' ' || last_name as name, current_site_id FROM workers WHERE current_site_id IN (" . siteIdsForSql($mySiteIds) . ") OR current_site_id IS NULL ORDER BY last_name");
    $stmt->execute();
    $workers = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    // Supervisor-only validation
    if ($isSupervisor) {
        $check = $pdo->prepare("SELECT id FROM sites WHERE id = ? AND supervisor_id = ?");
        $check->execute([$_POST['site_id'], $user['id']]);
        if (!$check->fetch()) { flash('You can only enter hours for your own sites'); header('Location: hours.php'); exit; }
    }
    $stmt = $pdo->prepare("INSERT INTO work_hours (worker_id, site_id, work_date, hours, overtime_hours, notes, entered_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$_POST['worker_id'], $_POST['site_id'], $_POST['work_date'], (float)$_POST['hours'], (float)($_POST['overtime_hours'] ?? 0), $_POST['notes'] ?? '', $user['id']]);
    flash('Hours recorded successfully');
    header('Location: hours.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit') {
    // Supervisor-only validation: can only edit hours for own sites
    if ($isSupervisor) {
        $check = $pdo->prepare("SELECT wh.id FROM work_hours wh JOIN sites s ON s.id = wh.site_id WHERE wh.id = ? AND s.supervisor_id = ?");
        $check->execute([$_POST['id'], $user['id']]);
        if (!$check->fetch()) { flash('You can only edit hours for your own sites'); header('Location: hours.php'); exit; }
    }
    $stmt = $pdo->prepare("UPDATE work_hours SET worker_id=?, site_id=?, work_date=?, hours=?, overtime_hours=?, notes=? WHERE id=?");
    $stmt->execute([
        $_POST['worker_id'], $_POST['site_id'], $_POST['work_date'],
        (float)$_POST['hours'], (float)($_POST['overtime_hours'] ?? 0),
        $_POST['notes'] ?? '',
        $_POST['id']
    ]);
    flash('Hours updated successfully');
    header('Location: hours.php');
    exit;
}

if (isset($_GET['delete'])) {
    // Supervisor-only validation: can only delete own site hours
    if ($isSupervisor) {
        $check = $pdo->prepare("SELECT wh.id FROM work_hours wh JOIN sites s ON s.id = wh.site_id WHERE wh.id = ? AND s.supervisor_id = ?");
        $check->execute([$_GET['delete'], $user['id']]);
        if (!$check->fetch()) { flash('You can only delete hours for your own sites'); header('Location: hours.php'); exit; }
    }
    $pdo->prepare("DELETE FROM work_hours WHERE id = ?")->execute([$_GET['delete']]);
    flash('Hours entry removed', 'warning');
    header('Location: hours.php');
    exit;
}

$editing = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM work_hours WHERE id = ?");
    $stmt->execute([$_GET['edit']]);
    $editing = $stmt->fetch(PDO::FETCH_ASSOC);
    // Supervisors can only edit their own site's hours
    if ($editing && $isSupervisor) {
        $check = $pdo->prepare("SELECT id FROM sites WHERE id = ? AND supervisor_id = ?");
        $check->execute([$editing['site_id'], $user['id']]);
        if (!$check->fetch()) {
            flash('You can only edit hours for your own sites');
            header('Location: hours.php');
            exit;
        }
    }
}

// Build query
$where = [];
$params = [];
if (isset($_GET['site_id']) && $_GET['site_id']) { $where[] = 'wh.site_id = ?'; $params[] = $_GET['site_id']; }
if (isset($_GET['worker_id']) && $_GET['worker_id']) { $where[] = 'wh.worker_id = ?'; $params[] = $_GET['worker_id']; }
if (isset($_GET['date_from']) && $_GET['date_from']) { $where[] = 'wh.work_date >= ?'; $params[] = $_GET['date_from']; }
if (isset($_GET['date_to']) && $_GET['date_to']) { $where[] = 'wh.work_date <= ?'; $params[] = $_GET['date_to']; }
if (isset($_GET['job_code']) && $_GET['job_code']) { $where[] = 's.job_code = ?'; $params[] = $_GET['job_code']; }
if ($isSupervisor) {
    $where[] = 'wh.site_id IN (' . siteIdsForSql($mySiteIds) . ')';
}
$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$sql = "
    SELECT wh.*, w.first_name || ' ' || w.last_name as worker_name,
        s.name as site_name, s.job_code, u.full_name as entered_by_name
    FROM work_hours wh
    JOIN workers w ON w.id = wh.worker_id
    JOIN sites s ON s.id = wh.site_id
    LEFT JOIN users u ON u.id = wh.entered_by
    $whereSQL
    ORDER BY s.name, wh.work_date DESC, w.last_name, w.first_name LIMIT 200
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$hours = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalFiltered = 0;
$totalOTFiltered = 0;
foreach ($hours as $h) {
    $totalFiltered += $h['hours'];
    $totalOTFiltered += $h['overtime_hours'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Work Hours - Construction Manager</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
<?php require_once 'sidebar.php'; ?>
<div class="main-content">
    <div class="topbar">
        <h1>Work Hours</h1>
        <div class="topbar-actions">
            <button onclick="document.getElementById('hoursForm').style.display='block'" class="btn btn-primary">+ Enter Hours</button>
        </div>
    </div>
    <div class="content-area">
        <?php if ($msg = flash()): ?><div class="alert <?= flashType() ?> auto-dismiss"><?= h($msg) ?></div><?php endif; ?>

        <div class="alert info" style="margin-bottom:20px;">
            <strong>⏱️ Work Hours Tracker:</strong>
            <?php if ($isManager): ?>
                Record and track work hours across all sites. Monitor regular hours and overtime.
            <?php else: ?>
                Enter hours for workers at your sites (<?= count($sites) ?> site<?= count($sites) != 1 ? 's' : '' ?>).
                You can only log hours for sites you manage.
            <?php endif; ?>
        </div>

        <div class="card" id="hoursForm" style="display:<?= ($editing || isset($_GET['new'])) ? 'block' : 'none' ?>">
            <div class="card-header">
                <h2><?= $editing ? 'Edit Hours Entry' : 'Enter Work Hours' ?></h2>
                <a href="hours.php" class="btn btn-sm btn-secondary">Close</a>
            </div>
            <div class="card-body">
                <?php if (!$sites): ?>
                    <div class="alert warning">You have no sites assigned. Please contact the manager.</div>
                <?php else: ?>
                <form method="POST">
                    <input type="hidden" name="action" value="<?= $editing ? 'edit' : 'create' ?>">
                    <?php if ($editing): ?><input type="hidden" name="id" value="<?= $editing['id'] ?>"><?php endif; ?>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Site *</label>
                            <select name="site_id" id="siteSelect" required>
                                <option value="">-- Select a site --</option>
                                <?php foreach ($sites as $s): ?>
                                <option value="<?= $s['id'] ?>" <?= ($editing['site_id'] ?? '') == $s['id'] ? 'selected' : '' ?>><?= h($s['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Worker *</label>
                            <select name="worker_id" id="workerSelect" required>
                                <option value="">-- Select a site first --</option>
                                <?php foreach ($workers as $w): ?>
                                <option value="<?= $w['id'] ?>" data-site-id="<?= h($w['current_site_id'] ?? '') ?>" <?= ($editing['worker_id'] ?? '') == $w['id'] ? 'selected' : '' ?>><?= h($w['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Date *</label>
                            <input type="date" name="work_date" required value="<?= h($editing['work_date'] ?? date('Y-m-d')) ?>">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Regular Hours *</label>
                            <input type="number" name="hours" step="0.5" min="0" max="24" required value="<?= h($editing['hours'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label>Overtime Hours</label>
                            <input type="number" name="overtime_hours" step="0.5" min="0" max="24" value="<?= h($editing['overtime_hours'] ?? 0) ?>">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Notes</label>
                        <textarea name="notes" rows="2"><?= h($editing['notes'] ?? '') ?></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary"><?= $editing ? 'Save Changes' : 'Record Hours' ?></button>
                    <?php if ($editing): ?>
                        <a href="hours.php" class="btn btn-secondary">Cancel</a>
                    <?php endif; ?>
                </form>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h2>Hours History</h2>
                <span style="color:var(--gray-600);font-size:13px">Total shown: <strong><?= number_format($totalFiltered, 1) ?>h</strong> &nbsp;|&nbsp; Overtime: <strong><?= number_format($totalOTFiltered, 1) ?>h</strong></span>
            </div>
            <div class="card-body">
                <form method="GET" class="filters" id="hoursFilterForm">
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
                    <select name="worker_id">
                        <option value="">All Workers</option>
                        <?php foreach ($workers as $w): ?>
                        <option value="<?= $w['id'] ?>" <?= ($_GET['worker_id'] ?? '') == $w['id'] ? 'selected' : '' ?>><?= h($w['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="date" name="date_from" value="<?= h($_GET['date_from'] ?? '') ?>" placeholder="From">
                    <span style="color:var(--gray-500);font-size:12px">to</span>
                    <input type="date" name="date_to" value="<?= h($_GET['date_to'] ?? '') ?>" placeholder="To">
                    <a href="hours.php" class="btn btn-sm btn-secondary">Clear</a>
                </form>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Worker</th>
                                <th>Site</th>
                                <th>Job Code</th>
                                <th>Hours</th>
                                <th>OT</th>
                                <th>Notes</th>
                                <th>By</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($hours as $h): ?>
                            <tr>
                                <td><?= h($h['work_date']) ?></td>
                                <td><?= h($h['worker_name']) ?></td>
                                <td><?= h($h['site_name']) ?></td>
                                <td><?= h($h['job_code']) ?: '—' ?></td>
                                <td><strong><?= h($h['hours']) ?>h</strong></td>
                                <td><?= h($h['overtime_hours']) ?>h</td>
                                <td><?= h($h['notes']) ?></td>
                                <td><small><?= h($h['entered_by_name']) ?></small></td>
                                <td class="actions">
                                    <a href="hours.php?edit=<?= $h['id'] ?>" class="btn btn-sm btn-edit">Edit</a>
                                    <form method="GET" style="display:inline" data-confirm="Remove this hours entry?">
                                        <input type="hidden" name="delete" value="<?= $h['id'] ?>">
                                        <button class="btn btn-sm btn-delete">×</button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; if (!$hours): ?>
                            <tr><td colspan="9" class="empty-state"><div class="icon">⏱️</div>No hours recorded yet.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<script src="js/app.js"></script>
<script>
document.querySelectorAll('#hoursFilterForm select, #hoursFilterForm input[type="date"]').forEach(function(el){
    el.addEventListener('change', function(){ document.getElementById('hoursFilterForm').submit(); });
});

// Enter Hours form: filter workers by selected site
(function(){
    var siteSel = document.getElementById('siteSelect');
    var workerSel = document.getElementById('workerSelect');
    if (!siteSel || !workerSel) return;
    var allOptions = Array.from(workerSel.options); // remember the full list

    function filterWorkers() {
        var siteId = siteSel.value;
        var current = workerSel.value;
        workerSel.innerHTML = '';
        // Always start with the placeholder
        var placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = '-- Select --';
        workerSel.appendChild(placeholder);
        allOptions.forEach(function(opt){
            if (!opt.value) return; // skip placeholder
            if (siteId === '' || opt.getAttribute('data-site-id') === siteId) {
                var clone = opt.cloneNode(true);
                if (clone.value === current) clone.selected = true;
                workerSel.appendChild(clone);
            }
        });
        // If the previously selected worker no longer matches, clear it
        if (workerSel.value !== current) {
            workerSel.value = '';
        }
    }

    siteSel.addEventListener('change', filterWorkers);
    // Run once on load (e.g. when editing an existing record)
    filterWorkers();
})();
</script>
</body>
</html>
