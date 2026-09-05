<?php
require_once 'config.php';
requireLogin();
$user = currentUser();
$isManager = $user['role'] === 'manager';
$isSupervisor = $user['role'] === 'supervisor';
$pdo = getDB();

// Managers can create/edit/delete. Supervisors see all sites read-only.
if (!$isManager) {
    // Supervisors cannot access create/delete/edit — redirect to view-only
    if (isset($_GET['delete']) || isset($_GET['edit']) || isset($_GET['new'])) {
        flash('Access denied');
        header('Location: sites.php');
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['create', 'edit'])) {
        flash('Access denied');
        header('Location: sites.php');
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    $jobId = $_POST['job_id'] ?: null;
    if (!$jobId && !empty($_POST['job_code_standalone'])) {
        $stmt = $pdo->prepare("INSERT INTO jobs (code, name, description, client_name, budget, status) VALUES (?, ?, ?, ?, ?, 'active')");
        $stmt->execute([$_POST['job_code_standalone'], $_POST['name'] . ' Project', $_POST['job_description_standalone'] ?? '', $_POST['client_name'] ?? '', $_POST['budget'] ?: null]);
        $jobId = $pdo->lastInsertId();
    }
    $stmt = $pdo->prepare("INSERT INTO sites (name, address, job_id, supervisor_id, status, client_name, budget, job_code, job_description, pause_reason) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $_POST['name'], $_POST['address'] ?? '', $jobId, $_POST['supervisor_id'] ?: null, $_POST['status'] ?? 'active',
        $_POST['client_name'] ?? '', $_POST['budget'] ?: null, $_POST['job_code_standalone'] ?? '', $_POST['job_description_standalone'] ?? '', $_POST['pause_reason'] ?? ''
    ]);
    $newSiteId = $pdo->lastInsertId();
    // Auto-assign primary supervisor to site_supervisors
    if (!empty($_POST['supervisor_id'])) {
        $pdo->prepare("INSERT OR IGNORE INTO site_supervisors (site_id, user_id) VALUES (?, ?)")->execute([$newSiteId, $_POST['supervisor_id']]);
    }
    flash('Site created successfully');
    header('Location: sites.php');
    exit;
}

if (isset($_GET['delete'])) {
    $pdo->prepare("DELETE FROM sites WHERE id = ?")->execute([$_GET['delete']]);
    flash('Site deleted', 'warning');
    header('Location: sites.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit') {
    $jobId = $_POST['job_id'] ?: null;
    $stmt = $pdo->prepare("UPDATE sites SET name = ?, address = ?, job_id = ?, supervisor_id = ?, status = ?, client_name = ?, budget = ?, job_code = ?, job_description = ?, pause_reason = ? WHERE id = ?");
    $stmt->execute([
        $_POST['name'], $_POST['address'] ?? '', $jobId, $_POST['supervisor_id'] ?: null, $_POST['status'] ?? 'active',
        $_POST['client_name'] ?? '', $_POST['budget'] ?: null, $_POST['job_code_standalone'] ?? '', $_POST['job_description_standalone'] ?? '', $_POST['pause_reason'] ?? '', $_POST['id']
    ]);
    // Sync site_supervisors — clear and re-add selected
    $siteId = (int)$_POST['id'];
    $pdo->prepare("DELETE FROM site_supervisors WHERE site_id = ?")->execute([$siteId]);
    foreach (($_POST['site_supervisors'] ?? []) as $sid) {
        $pdo->prepare("INSERT OR IGNORE INTO site_supervisors (site_id, user_id) VALUES (?, ?)")->execute([$siteId, (int)$sid]);
    }
    // Primary supervisor always in list
    if (!empty($_POST['supervisor_id'])) {
        $pdo->prepare("INSERT OR IGNORE INTO site_supervisors (site_id, user_id) VALUES (?, ?)")->execute([$siteId, $_POST['supervisor_id']]);
    }
    flash('Site updated');
    header('Location: sites.php');
    exit;
}

// Get all site IDs current user can see
$mySiteIds = getMySiteIds($user);

// Build SQL filter
$siteFilterSql = $isManager ? '' : 'WHERE s.id IN (' . siteIdsForSql($mySiteIds) . ')';

$sites = $pdo->query("
    SELECT s.*, j.name as job_name, j.code as job_ref_code, j.description as job_full_description,
        u.full_name as supervisor_name,
        (SELECT COUNT(*) FROM workers WHERE current_site_id = s.id) as worker_count,
        (SELECT COUNT(*) FROM work_hours WHERE site_id = s.id) as hours_count,
        (SELECT GROUP_CONCAT(u2.full_name, ', ')
         FROM site_supervisors ss2 JOIN users u2 ON u2.id = ss2.user_id
         WHERE ss2.site_id = s.id) as supervisor_names
    FROM sites s
    LEFT JOIN jobs j ON j.id = s.job_id
    LEFT JOIN users u ON u.id = s.supervisor_id
    $siteFilterSql
    ORDER BY s.name
")->fetchAll(PDO::FETCH_ASSOC);

// Apply filters in PHP (in-memory)
$f_site   = trim($_GET['f_site'] ?? '');
$f_job    = trim($_GET['f_job'] ?? '');
$f_status = trim($_GET['f_status'] ?? '');
$f_search = trim($_GET['f_search'] ?? '');
if ($f_site || $f_job || $f_status || $f_search) {
    $sites = array_values(array_filter($sites, function($s) use ($f_site, $f_job, $f_status, $f_search) {
        if ($f_site && stripos($s['name'], $f_site) === false) return false;
        if ($f_job && stripos(($s['job_ref_code'] ?? $s['job_code'] ?? ''), $f_job) === false) return false;
        if ($f_status && ($s['status'] ?? '') !== $f_status) return false;
        if ($f_search) {
            $hay = strtolower(($s['name'] ?? '') . ' ' . ($s['address'] ?? '') . ' ' . ($s['job_ref_code'] ?? '') . ' ' . ($s['client_name'] ?? ''));
            if (strpos($hay, strtolower($f_search)) === false) return false;
        }
        return true;
    }));
}

$jobs = $pdo->query("SELECT id, code, name FROM jobs ORDER BY code")->fetchAll(PDO::FETCH_ASSOC);
$supervisors = $pdo->query("SELECT id, full_name FROM users WHERE role = 'supervisor' ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);

$editing = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM sites WHERE id = ?");
    $stmt->execute([$_GET['edit']]);
    $editing = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($editing) {
        // Load assigned supervisors
        $editing['_supervisors'] = $pdo->prepare("SELECT user_id FROM site_supervisors WHERE site_id = ?")->execute([$editing['id']]);
        $editing['_supervisors'] = array_column($pdo->query("SELECT user_id FROM site_supervisors WHERE site_id = " . (int)$editing['id'])->fetchAll(), 'user_id');
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sites - Construction Manager</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
<?php require_once 'sidebar.php'; ?>
<div class="main-content">
    <div class="topbar">
        <h1>Construction Sites</h1>
        <div class="topbar-actions">
            <button onclick="document.getElementById('siteForm').style.display='block'" class="btn btn-primary">+ New Site</button>
        </div>
    </div>
    <div class="content-area">
        <?php if ($msg = flash()): ?><div class="alert <?= flashType() ?> auto-dismiss"><?= h($msg) ?></div><?php endif; ?>

        <div class="card" id="siteForm" style="display:<?= ($editing || isset($_GET['new'])) ? 'block' : 'none' ?>">
            <div class="card-header">
                <h2><?= $editing ? 'Edit Site' : 'Add New Site' ?></h2>
                <a href="sites.php" class="btn btn-sm btn-secondary">Close</a>
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="action" value="<?= $editing ? 'edit' : 'create' ?>">
                    <?php if ($editing): ?><input type="hidden" name="id" value="<?= $editing['id'] ?>"><?php endif; ?>
                    <div class="form-group">
                        <label>Site Name *</label>
                        <input type="text" name="name" required value="<?= h($editing['name'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label>Address</label>
                        <input type="text" name="address" value="<?= h($editing['address'] ?? '') ?>">
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Linked Job / Project</label>
                            <select name="job_id">
                                <option value="">-- None --</option>
                                <?php foreach ($jobs as $j): ?>
                                <option value="<?= $j['id'] ?>" <?= ($editing['job_id'] ?? '') == $j['id'] ? 'selected' : '' ?>><?= h($j['code']) ?> - <?= h($j['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Primary Supervisor</label>
                            <select name="supervisor_id">
                                <option value="">-- None --</option>
                                <?php foreach ($supervisors as $s): ?>
                                <option value="<?= $s['id'] ?>" <?= ($editing['supervisor_id'] ?? '') == $s['id'] ? 'selected' : '' ?>><?= h($s['full_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Additional Supervisors</label>
                        <div class="checkbox-grid">
                            <?php foreach ($supervisors as $s): ?>
                            <label class="checkbox-item">
                                <input type="checkbox" name="site_supervisors[]" value="<?= $s['id'] ?>"
                                    <?= in_array($s['id'], $editing['_supervisors'] ?? []) ? 'checked' : '' ?>>
                                <?= h($s['full_name']) ?>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Job Code (if no linked job)</label>
                        <input type="text" name="job_code_standalone" value="<?= h($editing['job_code'] ?? '') ?>" placeholder="e.g. JOB-003">
                    </div>
                    <div class="form-group">
                        <label>Job Description (if no linked job)</label>
                        <textarea name="job_description_standalone" rows="2"><?= h($editing['job_description'] ?? '') ?></textarea>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Client Name</label>
                            <input type="text" name="client_name" value="<?= h($editing['client_name'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label>Budget</label>
                            <input type="number" step="0.01" name="budget" value="<?= h($editing['budget'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Status</label>
                        <select name="status">
                            <option value="active" <?= ($editing['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Active</option>
                            <option value="paused" <?= ($editing['status'] ?? '') === 'paused' ? 'selected' : '' ?>>Paused</option>
                            <option value="done" <?= ($editing['status'] ?? '') === 'done' ? 'selected' : '' ?>>Done</option>
                        </select>
                    </div>
                    <div class="form-group" id="pauseReasonGroup" style="display:<?= ($editing['status'] ?? '') === 'paused' ? 'block' : 'none' ?>">
                        <label>Pause Reason</label>
                        <textarea name="pause_reason" rows="2" placeholder="Why is this site paused?"><?= h($editing['pause_reason'] ?? '') ?></textarea>
                    </div>
                    <script>document.querySelector('[name="status"]')?.addEventListener('change', function(e){document.getElementById('pauseReasonGroup').style.display=e.target.value==='paused'?'block':'none';});</script>
                    <button type="submit" class="btn btn-primary"><?= $editing ? 'Save Changes' : 'Create Site' ?></button>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h2><?= $isSupervisor ? 'My Sites' : 'All Sites' ?> (<?= count($sites) ?>)</h2>
            </div>
            <div class="card-body" style="padding-top:0">
                <form method="GET" class="filter-bar" id="siteFilterForm">
                    <label>Site</label>
                    <input type="text" name="f_site" value="<?= h($f_site) ?>" placeholder="Site name...">
                    <label>Job Code</label>
                    <select name="f_job">
                        <option value="">All Jobs</option>
                        <?php foreach ($jobs as $j): ?>
                        <option value="<?= h($j['code']) ?>" <?= ($f_job ?? '') === $j['code'] ? 'selected' : '' ?>><?= h($j['code']) ?> — <?= h($j['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <label>Status</label>
                    <select name="f_status">
                        <option value="">All</option>
                        <option value="active" <?= ($f_status ?? '') === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="paused" <?= ($f_status ?? '') === 'paused' ? 'selected' : '' ?>>Paused</option>
                        <option value="done" <?= ($f_status ?? '') === 'done' ? 'selected' : '' ?>>Done</option>
                    </select>
                    <a href="sites.php" class="btn btn-sm btn-secondary">Clear</a>
                </form>
            <div class="table-wrap">
                <table id="sitesTable">
                    <thead>
                        <tr>
                            <th>Site / Job Name</th>
                            <th>Address</th>
                            <th>Job Code</th>
                            <th>Client</th>
                            <th>Budget</th>
                            <th>Supervisors</th>
                            <th>Status</th>
                            <th>Pause Reason</th>
                            <th>Workers</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sites as $s): ?>
                        <tr>
                            <td>
                                <a href="site_detail.php?id=<?= $s['id'] ?>"><strong><?= h($s['name']) ?></strong></a>
                                <?php if ($s['job_name']): ?><br><small style="color:#666">📁 <?= h($s['job_name']) ?></small><?php endif; ?>
                            </td>
                            <td><?= h($s['address']) ?: '—' ?></td>
                            <td><?= h($s['job_ref_code'] ?? $s['job_code']) ?: '—' ?></td>
                            <td><?= h($s['client_name']) ?: '—' ?></td>
                            <td><?= $s['budget'] ? number_format($s['budget'], 2) : '—' ?></td>
                            <td><small><?= h($s['supervisor_names'] ?: $s['supervisor_name']) ?: '—' ?></small></td>
                            <td><span class="badge badge-<?= h($s['status']) ?>"><?= h($s['status']) ?></span></td>
                            <td><?= $s['status'] === 'paused' && $s['pause_reason'] ? '<span style="color:#92400e;font-size:12px">⏸ ' . h($s['pause_reason']) . '</span>' : '—' ?></td>
                            <td><?= (int)$s['worker_count'] ?></td>
                            <td class="actions">
                                <a href="site_detail.php?id=<?= $s['id'] ?>" class="btn btn-sm btn-view">View</a>
                                <?php if ($isManager): ?>
                                <a href="sites.php?edit=<?= $s['id'] ?>" class="btn btn-sm btn-edit">Edit</a>
                                <form method="GET" style="display:inline" data-confirm="Delete this site?">
                                    <input type="hidden" name="delete" value="<?= $s['id'] ?>">
                                    <button class="btn btn-sm btn-delete">Del</button>
                                </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; if (!$sites): ?>
                        <tr><td colspan="10" class="empty-state"><div class="icon">📍</div>No sites found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<script src="js/app.js"></script>
<script>
document.querySelectorAll('#siteFilterForm select, #siteFilterForm input').forEach(function(el){
    el.addEventListener('change', function(){ document.getElementById('siteFilterForm').submit(); });
});
document.querySelector('#siteFilterForm input[name="f_site"]').addEventListener('keypress', function(e){
    if(e.key==='Enter'){ e.preventDefault(); document.getElementById('siteFilterForm').submit(); }
});
</script>
</body>
</html>
