<?php
require_once 'config.php';
requireLogin();

$pdo = getDB();
$user = currentUser();
$companyId = getCurrentCompanyId();
$isManager = $user['role'] === 'manager';
$isSupervisor = $user['role'] === 'supervisor';

// Get sites the user can manage
$mySiteIds = getMySiteIds($user);
if ($isManager) {
    $sites = $pdo->query("SELECT id, name FROM sites WHERE company_id = $companyId ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
} else {
    $stmt = $pdo->prepare("SELECT id, name FROM sites WHERE company_id = ? AND id IN (" . siteIdsForSql($mySiteIds) . ") ORDER BY name");
    $stmt->execute([$companyId]);
    $sites = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// All workers with site name and status — filtered by company
$workers = $pdo->prepare("
    SELECT w.id, w.first_name || ' ' || w.last_name as name, w.current_site_id, w.status, s.name as site_name
    FROM workers w
    LEFT JOIN sites s ON s.id = w.current_site_id
    WHERE w.company_id = ?
    ORDER BY w.last_name
");
$workers->execute([$companyId]);
$workers = $workers->fetchAll(PDO::FETCH_ASSOC);

// --- CREATE ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    require_csrf();
    // Supervisor: validate site belongs to them (via site_supervisors or legacy supervisor_id)
    if ($isSupervisor) {
        $check = $pdo->prepare("SELECT id FROM sites WHERE id = ? AND company_id = $companyId AND id IN (" . siteIdsForSql($mySiteIds) . ")");
        $check->execute([$_POST['site_id']]);
        if (!$check->fetch()) { flash('You can only assign workers to your own sites'); header('Location: assignments.php'); exit; }
    }
    // Validate worker and site belong to company
    $chkW = $pdo->prepare("SELECT id FROM workers WHERE id = ? AND company_id = ?");
    $chkW->execute([$_POST['worker_id'], $companyId]);
    if (!$chkW->fetch()) { flash('Invalid worker for your company'); header('Location: assignments.php'); exit; }
    $chkS = $pdo->prepare("SELECT id FROM sites WHERE id = ? AND company_id = ?");
    $chkS->execute([$_POST['site_id'], $companyId]);
    if (!$chkS->fetch()) { flash('Invalid site for your company'); header('Location: assignments.php'); exit; }
    $stmt = $pdo->prepare("INSERT INTO assignments (worker_id, site_id, start_date, end_date, notes) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$_POST['worker_id'], $_POST['site_id'], $_POST['start_date'], $_POST['end_date'] ?: null, $_POST['notes'] ?? '']);
    // Update worker's current site
    $pdo->prepare("UPDATE workers SET current_site_id = ?, status = 'assigned' WHERE id = ?")->execute([$_POST['site_id'], $_POST['worker_id']]);
    flash('Assignment created');
    header('Location: assignments.php');
    exit;
}

// --- DELETE ---
if (isset($_GET['delete'])) {
    // Supervisor: validate assignment is for their site
    if ($isSupervisor) {
        $check = $pdo->prepare("SELECT a.id FROM assignments a JOIN sites s ON s.id = a.site_id WHERE a.id = ? AND s.supervisor_id = ?");
        $check->execute([$_GET['delete'], $user['id']]);
        if (!$check->fetch()) { flash('Not your site'); header('Location: assignments.php'); exit; }
    }
    $pdo->prepare("DELETE FROM assignments WHERE id = ?")->execute([$_GET['delete']]);
    flash('Assignment removed', 'warning');
    header('Location: assignments.php');
    exit;
}

// --- END ---
if (isset($_GET['end'])) {
    $endId = (int)$_GET['end'];
    if ($isSupervisor) {
        $check = $pdo->prepare("SELECT a.id FROM assignments a JOIN sites s ON s.id = a.site_id WHERE a.id = ? AND s.supervisor_id = ?");
        $check->execute([$endId, $user['id']]);
        if (!$check->fetch()) { flash('Not your site'); header('Location: assignments.php'); exit; }
    }
    // Get worker_id before updating
    $getWorker = $pdo->prepare("SELECT worker_id FROM assignments WHERE id = ?");
    $getWorker->execute([$endId]);
    $worker = $getWorker->fetch(PDO::FETCH_ASSOC);
    if ($worker) {
        $pdo->prepare("UPDATE workers SET current_site_id = NULL, status = 'available' WHERE id = ?")->execute([$worker['worker_id']]);
    }
    $stmt = $pdo->prepare("UPDATE assignments SET end_date = ? WHERE id = ?");
    $stmt->execute([date('Y-m-d'), $endId]);
    flash('Assignment ended', 'warning');
    header('Location: assignments.php');
    exit;
}

// --- LIST --- filtered by company
$where = ["s.company_id = $companyId", "w.company_id = $companyId"];
$params = [];
if ($isSupervisor) {
    $where[] = 's.id IN (' . siteIdsForSql($mySiteIds) . ')';
}
$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$assignments = $pdo->prepare("
    SELECT a.*, w.first_name || ' ' || w.last_name as worker_name, w.status as worker_status, s.name as site_name, s.job_code,
        CASE
            WHEN a.end_date IS NULL THEN 1
            WHEN a.end_date >= DATE('now') THEN 2
            ELSE 3
        END as status_order
    FROM assignments a
    JOIN workers w ON w.id = a.worker_id
    JOIN sites s ON s.id = a.site_id
    $whereSQL
    ORDER BY status_order ASC, a.start_date DESC
");
$assignments->execute($params);
$assignments = $assignments->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assignments - Construction Manager</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        body { overflow: auto; height: auto; min-height: 100vh; }
        .main-content { height: auto; overflow: visible; }
        .content-area { overflow: visible; }
        .table-wrap { max-height: none; overflow: visible; }
    </style>
</head>
<body>
<?php require_once 'sidebar.php'; ?>
<div class="main-content">
    <div class="topbar">
        <h1>Worker Assignments</h1>
        <div class="topbar-actions">
            <?php if ($isManager || ($isSupervisor && $sites)): ?>
            <button onclick="document.getElementById('asgForm').style.display='block'" class="btn btn-primary">+ New Assignment</button>
            <?php elseif ($isSupervisor): ?>
            <span style="color:#666;font-size:12px">No sites assigned to manage</span>
            <?php endif; ?>
        </div>
    </div>
    <div class="content-area">
        <?php if ($msg = flash()): ?><div class="alert <?= flashType() ?> auto-dismiss"><?= h($msg) ?></div><?php endif; ?>

        <div class="alert info" style="margin-bottom:20px;">
            <strong>📅 Worker Assignments:</strong>
            <?php if ($isManager): ?>
                View and manage all worker assignments across all sites. Ongoing assignments are shown first.
            <?php else: ?>
                Manage worker assignments for your sites (<?= count($sites) ?> site<?= count($sites) != 1 ? 's' : '' ?>).
                Assign workers to your sites and end active assignments when needed.
            <?php endif; ?>
        </div>

        <div class="card" id="asgForm" style="display:<?= isset($_GET['new']) ? 'block' : 'none' ?>">
            <div class="card-header">
                <h2>Assign Worker to Site</h2>
                <a href="assignments.php" class="btn btn-sm btn-secondary">Close</a>
            </div>
            <div class="card-body">
                <?php if (!$sites): ?>
                    <div class="alert warning">You have no sites assigned. Please contact the manager.</div>
                <?php else: ?>
                <form method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="create">
                    <div class="form-row-3">
                        <div class="form-group">
                            <label>Worker *</label>
                            <select name="worker_id" required>
                                <option value="">-- Select --</option>
                                <?php foreach ($workers as $w):
                                    $tag = $w['site_name'] ? ' @ ' . $w['site_name'] : ' (unassigned)';
                                    $tag .= ' [' . $w['status'] . ']';
                                ?>
                                <option value="<?= $w['id'] ?>"><?= h($w['name']) ?><?= h($tag) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Site *</label>
                            <select name="site_id" required>
                                <option value="">-- Select --</option>
                                <?php foreach ($sites as $s): ?>
                                <option value="<?= $s['id'] ?>"><?= h($s['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Start Date *</label>
                            <input type="date" name="start_date" required value="<?= date('Y-m-d') ?>">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>End Date (optional)</label>
                            <input type="date" name="end_date">
                        </div>
                        <div class="form-group">
                            <label>Notes</label>
                            <input type="text" name="notes" placeholder="Optional">
                        </div>
                    </div>
                    <button class="btn btn-primary">Create Assignment</button>
                </form>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h2>All Assignments (<?= count($assignments) ?>)</h2></div>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Worker</th><th>Site</th><th>Job Code</th><th>Start</th><th>End</th><th>Assignment Status</th><th>Notes</th><th>Actions</th></tr></thead>
                    <tbody>
                        <?php foreach ($assignments as $a): ?>
                        <?php
                            $isOngoing = !$a['end_date'];
                            $isActive = $isOngoing || $a['end_date'] > date('Y-m-d');
                            $isUpcoming = $a['start_date'] > date('Y-m-d');
                            $assignmentStatus = $isOngoing ? 'Ongoing' : ($isActive ? 'Active' : 'Ended');
                            $statusColor = $isOngoing ? 'success' : ($isActive ? 'info' : 'inactive');
                        ?>
                        <tr>
                            <td>
                                <strong><?= h($a['worker_name']) ?></strong>
                                <br><span class="badge badge-<?= h($a['worker_status']) ?>"><?= h($a['worker_status']) ?></span>
                            </td>
                            <td><?= h($a['site_name']) ?></td>
                            <td><?= h($a['job_code']) ?: '—' ?></td>
                            <td><?= h($a['start_date']) ?></td>
                            <td><?= $a['end_date'] ? h($a['end_date']) : '<em>—</em>' ?></td>
                            <td>
                                <span class="badge badge-<?= $statusColor ?>"><?= $assignmentStatus ?></span>
                                <?php if ($isUpcoming && !$isOngoing): ?>
                                    <span class="badge" style="background:#fbbf24;color:#000;margin-left:4px;">⏳ Upcoming</span>
                                <?php endif; ?>
                            </td>
                            <td><?= h($a['notes']) ?></td>
                            <td class="actions">
                                <?php if ($isActive && ($isManager || $isSupervisor)): ?>
                                <form method="POST" style="display:inline" data-confirm="End this assignment now?">
                                <?= csrf_field() ?>
                                <input type="hidden" name="end" value="<?= $a['id'] ?>">
                                <button class="btn btn-sm btn-end">End</button>
                            </form>
                                <?php endif; ?>
                                <?php if ($isManager || $isSupervisor): ?>
                                <form method="POST" style="display:inline" data-confirm="Delete this assignment?">
                                <?= csrf_field() ?>
                                <input type="hidden" name="delete" value="<?= $a['id'] ?>">
                                <button class="btn btn-sm btn-delete">Del</button>
                            </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; if (!$assignments): ?>
                        <tr><td colspan="8" class="empty-state"><div class="icon">📅</div>No assignments yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
</body>
</html>