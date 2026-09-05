<?php
require_once 'config.php';
requireLogin();

$pdo = getDB();
$user = currentUser();
$isManager = $user['role'] === 'manager';
$isSupervisor = $user['role'] === 'supervisor';
$mySiteIds = getMySiteIds($user);

// Filter by supervisor's sites if not manager
$siteFilter = '';
if ($isSupervisor) {
    $siteFilter = " AND s.id IN (" . siteIdsForSql($mySiteIds) . ")";
}

// Get my sites for the form
if ($isManager) {
    $sites = $pdo->query("SELECT id, name FROM sites ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
} else {
    $stmt = $pdo->prepare("SELECT id, name FROM sites WHERE id IN (" . siteIdsForSql($mySiteIds) . ") ORDER BY name");
    $stmt->execute();
    $sites = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Upcoming assignments
$upcoming = $pdo->query("
    SELECT a.*, w.first_name || ' ' || w.last_name as worker_name, s.name as site_name
    FROM assignments a
    JOIN workers w ON w.id = a.worker_id
    JOIN sites s ON s.id = a.site_id
    WHERE (a.end_date IS NULL OR DATE(a.end_date) >= DATE('now'))
    $siteFilter
    ORDER BY a.start_date ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Available workers (manager sees all, supervisor sees their site workers)
$availSQL = "
    SELECT * FROM workers
    WHERE status != 'inactive'
    AND (current_site_id IS NULL OR id NOT IN (SELECT worker_id FROM assignments WHERE end_date IS NULL))
";
if ($isSupervisor) {
    $availSQL .= " AND (current_site_id IN (" . siteIdsForSql($mySiteIds) . ") OR current_site_id IS NULL)";
}
$availableWorkers = $pdo->query($availSQL)->fetchAll(PDO::FETCH_ASSOC);

// Site capacity
$capSQL = "
    SELECT s.id, s.name,
        (SELECT COUNT(*) FROM assignments a WHERE a.site_id = s.id AND (a.end_date IS NULL OR DATE(a.end_date) >= DATE('now'))) as active_count,
        (SELECT COUNT(*) FROM assignments a WHERE a.site_id = s.id) as total_count
    FROM sites s
";
if ($isSupervisor) $capSQL .= " WHERE s.id IN (" . siteIdsForSql($mySiteIds) . ")";
$capSQL .= " ORDER BY s.name";
$cap = $pdo->query($capSQL)->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Future Planning - Construction Manager</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
<?php require_once 'sidebar.php'; ?>
<div class="main-content">
    <div class="topbar">
        <h1>Future Planning</h1>
        <div class="topbar-actions">
            <?php if ($isManager): ?>
            <a href="assignments.php?new=1" class="btn btn-primary">+ Plan Assignment</a>
            <?php endif; ?>
        </div>
    </div>
    <div class="content-area">
        <?php if ($msg = flash()): ?><div class="alert <?= flashType() ?> auto-dismiss"><?= h($msg) ?></div><?php endif; ?>

        <div class="alert info" style="margin-bottom:20px;">
            <strong>🗓️ Future Planning:</strong>
            <?php if ($isManager): ?>
                Plan and view all active and upcoming worker assignments across all sites.
            <?php else: ?>
                View active and upcoming assignments for your sites (<?= count($sites) ?> site<?= count($sites) != 1 ? 's' : '' ?>). Only the manager can plan new assignments.
            <?php endif; ?>
        </div>

        <div class="grid-2">
            <div class="card">
                <div class="card-header"><h2>Active & Upcoming Assignments</h2></div>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>Worker</th><th>Site</th><th>Start</th><th>End</th><th>Status</th></tr></thead>
                        <tbody>
                            <?php foreach ($upcoming as $a):
                                $isFuture = strtotime($a['start_date']) > strtotime(date('Y-m-d'));
                                $isActive = !$a['end_date'] || strtotime($a['end_date']) >= strtotime(date('Y-m-d'));
                            ?>
                            <tr>
                                <td><?= h($a['worker_name']) ?></td>
                                <td><?= h($a['site_name']) ?></td>
                                <td><?= h($a['start_date']) ?></td>
                                <td><?= $a['end_date'] ? h($a['end_date']) : '<em>—</em>' ?></td>
                                <td>
                                    <?php if ($isFuture): ?>
                                        <span class="badge badge-info">Upcoming</span>
                                    <?php elseif ($isActive): ?>
                                        <span class="badge badge-active">Active</span>
                                    <?php else: ?>
                                        <span class="badge badge-inactive">Ended</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; if (!$upcoming): ?>
                            <tr><td colspan="5" class="empty-state">No active or upcoming assignments.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="card">
                <div class="card-header"><h2>Available Workers (<?= count($availableWorkers) ?>)</h2></div>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>Name</th><th>Role</th><th>Status</th></tr></thead>
                        <tbody>
                            <?php foreach ($availableWorkers as $w): ?>
                            <tr>
                                <td><strong><?= h($w['first_name']) ?> <?= h($w['last_name']) ?></strong></td>
                                <td><?= h($w['role_title']) ?: '—' ?></td>
                                <td><span class="badge badge-<?= h($w['status']) ?>"><?= h($w['status']) ?></span></td>
                            </tr>
                            <?php endforeach; if (!$availableWorkers): ?>
                            <tr><td colspan="3" class="empty-state">No available workers — all are assigned.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h2>Site Capacity Overview</h2></div>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Site</th><th>Active Workers</th><th>Total Assignments</th></tr></thead>
                    <tbody>
                        <?php foreach ($cap as $c): ?>
                        <tr>
                            <td><a href="site_detail.php?id=<?= $c['id'] ?>"><?= h($c['name']) ?></a></td>
                            <td><strong><?= (int)$c['active_count'] ?></strong></td>
                            <td><?= (int)$c['total_count'] ?></td>
                        </tr>
                        <?php endforeach; if (!$cap): ?>
                        <tr><td colspan="3" class="empty-state">No sites found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<script src="js/app.js"></script>
</body>
</html>
