<?php
require_once 'config.php';
requireRole('manager');

$pdo = getDB();
$companyId = getCurrentCompanyId();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    require_csrf();
    // Check code unique per company
    $chk = $pdo->prepare("SELECT id FROM jobs WHERE company_id = ? AND code = ?");
    $chk->execute([$companyId, trim($_POST['code'])]);
    if ($chk->fetch()) { flash('Job code already exists in your company'); header('Location: jobs.php'); exit; }
    $stmt = $pdo->prepare("INSERT INTO jobs (company_id, code, name, description, status, pause_reason) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$companyId, $_POST['code'], $_POST['name'], $_POST['description'] ?? '', $_POST['status'] ?? 'active', $_POST['pause_reason'] ?? '']);
    flash('Job created successfully');
    header('Location: jobs.php');
    exit;
}

if (isset($_POST['delete']) || isset($_GET['delete'])) {
    if ($_SERVER['REQUEST_METHOD']==='POST') { require_csrf(); } else { if (!validate_csrf($_GET['csrf'] ?? '')) { flash('Invalid token'); header('Location: jobs.php'); exit; } }
    $__jid = (int)($_POST['delete'] ?? $_GET['delete']);
    // Ensure job belongs to company
    $chk = $pdo->prepare("SELECT id FROM jobs WHERE id = ? AND company_id = ?");
    $chk->execute([$__jid, $companyId]);
    if ($chk->fetch()) {
        $stmt = $pdo->prepare("DELETE FROM jobs WHERE id = ? AND company_id = ?");
        $stmt->execute([$__jid, $companyId]);
        flash('Job deleted', 'warning');
    } else {
        flash('Access denied or job not found');
    }
    header('Location: jobs.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit') {
    require_csrf();
    // Verify ownership
    $chk = $pdo->prepare("SELECT id FROM jobs WHERE id = ? AND company_id = ?");
    $chk->execute([$_POST['id'], $companyId]);
    if (!$chk->fetch()) { flash('Access denied'); header('Location: jobs.php'); exit; }
    // Check code unique per company excluding self
    $chk2 = $pdo->prepare("SELECT id FROM jobs WHERE company_id = ? AND code = ? AND id != ?");
    $chk2->execute([$companyId, trim($_POST['code']), $_POST['id']]);
    if ($chk2->fetch()) { flash('Job code already exists in your company'); header('Location: jobs.php?edit=' . (int)$_POST['id']); exit; }
    $stmt = $pdo->prepare("UPDATE jobs SET code = ?, name = ?, description = ?, status = ?, pause_reason = ? WHERE id = ? AND company_id = ?");
    $stmt->execute([$_POST['code'], $_POST['name'], $_POST['description'] ?? '', $_POST['status'] ?? 'active', $_POST['pause_reason'] ?? '', $_POST['id'], $companyId]);
    flash('Job updated');
    header('Location: jobs.php');
    exit;
}

$jobs = $pdo->prepare("
    SELECT j.*,
        (SELECT COUNT(*) FROM sites WHERE job_id = j.id AND company_id = ?) AS site_count,
        (SELECT COUNT(DISTINCT worker_id) FROM assignments a JOIN sites s ON s.id = a.site_id WHERE s.job_id = j.id AND s.company_id = ?) AS worker_count
    FROM jobs j
    WHERE j.company_id = ?
    ORDER BY j.created_at DESC
");
$jobs->execute([$companyId, $companyId, $companyId]);
$jobs = $jobs->fetchAll(PDO::FETCH_ASSOC);

// Apply filters in PHP (in-memory)
$f_status_job = trim($_GET['f_status'] ?? '');
$f_search_job = trim($_GET['f_search'] ?? '');
if ($f_status_job || $f_search_job) {
    $jobs = array_values(array_filter($jobs, function($j) use ($f_status_job, $f_search_job) {
        if ($f_status_job && ($j['status'] ?? '') !== $f_status_job) return false;
        if ($f_search_job) {
            $hay = strtolower(($j['code'] ?? '') . ' ' . ($j['name'] ?? '') . ' ' . ($j['description'] ?? '') . ' ' . ($j['client_name'] ?? ''));
            if (strpos($hay, strtolower($f_search_job)) === false) return false;
        }
        return true;
    }));
}

$editing = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM jobs WHERE id = ? AND company_id = ?");
    $stmt->execute([$_GET['edit'], $companyId]);
    $editing = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$editing) { flash('Job not found'); header('Location: jobs.php'); exit; }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Jobs / Projects - Construction Manager</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
<?php require_once 'sidebar.php'; ?>
<div class="main-content">
    <div class="topbar">
        <h1>Jobs / Projects</h1>
        <div class="topbar-actions">
            <button onclick="document.getElementById('jobForm').style.display='block'" class="btn btn-primary">+ New Job</button>
        </div>
    </div>
    <div class="content-area">
        <?php if ($msg = flash()): ?>
            <div class="alert <?= flashType() ?> auto-dismiss"><?= h($msg) ?></div>
        <?php endif; ?>

        <!-- Add/Edit Form -->
        <div class="card" id="jobForm" style="display:<?= ($editing || isset($_GET['new'])) ? 'block' : 'none' ?>">
            <div class="card-header">
                <h2><?= $editing ? 'Edit Job' : 'Add New Job' ?></h2>
                <a href="jobs.php" class="btn btn-sm btn-secondary">Close</a>
            </div>
            <div class="card-body">
                <form method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="<?= $editing ? 'edit' : 'create' ?>">
                    <?php if ($editing): ?><input type="hidden" name="id" value="<?= $editing['id'] ?>"><?php endif; ?>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Code *</label>
                            <input type="text" name="code" required value="<?= h($editing['code'] ?? '') ?>" placeholder="JOB-003">
                        </div>
                        <div class="form-group">
                            <label>Status</label>
                            <select name="status">
                                <option value="active" <?= ($editing['status'] ?? '') === 'active' ? 'selected' : '' ?>>Active</option>
                                <option value="paused" <?= ($editing['status'] ?? '') === 'paused' ? 'selected' : '' ?>>Paused</option>
                                <option value="done" <?= ($editing['status'] ?? '') === 'done' ? 'selected' : '' ?>>Done</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Name *</label>
                        <input type="text" name="name" required value="<?= h($editing['name'] ?? '') ?>" placeholder="Project name">
                    </div>
                    <div class="form-group">
                        <label>Description</label>
                        <textarea name="description" rows="3"><?= h($editing['description'] ?? '') ?></textarea>
                    </div>
                    <div class="form-group" id="pauseReasonGroup" style="display:<?= ($editing['status'] ?? '') === 'paused' ? 'block' : 'none' ?>">
                        <label>Pause Reason</label>
                        <textarea name="pause_reason" rows="2" placeholder="Why is this project paused?"><?= h($editing['pause_reason'] ?? '') ?></textarea>
                    </div>
                    <script>document.querySelector('[name="status"]')?.addEventListener('change', function(e){document.getElementById('pauseReasonGroup').style.display=e.target.value==='paused'?'block':'none';});</script>
                    <button type="submit" class="btn btn-primary"><?= $editing ? 'Save Changes' : 'Create Job' ?></button>
                </form>
            </div>
        </div>

        <!-- List -->
        <div class="card">
            <div class="card-header">
                <h2>All Jobs (<?= count($jobs) ?>)</h2>
            </div>
            <div class="card-body" style="padding-top:0">
                <form method="GET" class="filter-bar" id="jobFilterForm">
                    <label>🔍 Search</label>
                    <input type="text" name="f_search" value="<?= h($f_search_job) ?>" placeholder="Code, name, client..." class="search-input" style="min-width:220px">
                    <label>Status</label>
                    <select name="f_status">
                        <option value="">All</option>
                        <option value="active" <?= ($f_status_job ?? '') === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="paused" <?= ($f_status_job ?? '') === 'paused' ? 'selected' : '' ?>>Paused</option>
                        <option value="done" <?= ($f_status_job ?? '') === 'done' ? 'selected' : '' ?>>Done</option>
                    </select>
                    <a href="jobs.php" class="btn btn-sm btn-secondary">Clear</a>
                </form>
            <div class="table-wrap">
                <table id="jobsTable">
                    <thead>
                        <tr>
                            <th>Code</th>
                            <th>Name</th>
                            <th>Status</th>
                            <th>Pause Reason</th>
                            <th>Sites</th>
                            <th>Workers</th>
                            <th>Created</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($jobs as $j): ?>
                        <tr>
                            <td><strong><?= h($j['code']) ?></strong></td>
                            <td><a href="job_detail.php?id=<?= $j['id'] ?>"><?= h($j['name']) ?></a></td>
                            <td><span class="badge badge-<?= h($j['status']) ?>"><?= h($j['status']) ?></span></td>
                            <td><?= $j['status'] === 'paused' && $j['pause_reason'] ? '<span style="color:#92400e;font-size:12px">⏸ ' . h($j['pause_reason']) . '</span>' : '—' ?></td>
                            <td><?= (int)$j['site_count'] ?></td>
                            <td><?= (int)$j['worker_count'] ?></td>
                            <td><?= h(substr($j['created_at'], 0, 10)) ?></td>
                            <td class="actions">
                                <a href="jobs.php?edit=<?= $j['id'] ?>" class="btn btn-sm btn-edit">Edit</a>
                                <a href="job_detail.php?id=<?= $j['id'] ?>" class="btn btn-sm btn-view">View</a>
                                <form method="POST" style="display:inline" data-confirm="Delete this job?">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="delete" value="<?= $j['id'] ?>">
                                    <button class="btn btn-sm btn-delete">Delete</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; if (!$jobs): ?>
                        <tr><td colspan="8" class="empty-state"><div class="icon">📁</div>No jobs yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<script src="js/app.js"></script>
<script>
document.querySelectorAll('#jobFilterForm select, #jobFilterForm input').forEach(function(el){
    el.addEventListener('change', function(){ document.getElementById('jobFilterForm').submit(); });
});
document.querySelector('#jobFilterForm input[name="f_search"]').addEventListener('keypress', function(e){
    if(e.key==='Enter'){ e.preventDefault(); document.getElementById('jobFilterForm').submit(); }
});
</script>
</body>
</html>
