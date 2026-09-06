<?php
require_once 'config.php';
requireLogin();

$pdo = getDB();
$user = currentUser();
$isManager = $user['role'] === 'manager';
$isSupervisor = $user['role'] === 'supervisor';

if ($isManager) {
    $sites = $pdo->query("SELECT id, name FROM sites ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
} else {
    $stmt = $pdo->prepare("SELECT id, name FROM sites WHERE supervisor_id = ? ORDER BY name");
    $stmt->execute([$user['id']]);
    $sites = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    if ($isManager) { flash('Only supervisors can submit daily reports'); header('Location: reports.php'); exit; }
    if ($isSupervisor) {
        $check = $pdo->prepare("SELECT id FROM sites WHERE id = ? AND supervisor_id = ?");
        $check->execute([$_POST['site_id'], $user['id']]);
        if (!$check->fetch()) { flash('Not your site'); header('Location: reports.php'); exit; }
    }
    $stmt = $pdo->prepare("INSERT INTO daily_reports (site_id, supervisor_id, report_date, work_progress, notes, weather, issues) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$_POST['site_id'], $user['id'], $_POST['report_date'], $_POST['work_progress'] ?? '', $_POST['notes'] ?? '', $_POST['weather'] ?? '', $_POST['issues'] ?? '']);
    flash('Daily report submitted');
    header('Location: reports.php');
    exit;
}

if (isset($_GET['delete'])) {
    // Supervisors can only delete their own site's reports
    $mySiteIdsForDelete = getMySiteIds($user);
    $stmt = $pdo->prepare("SELECT dr.id FROM daily_reports dr JOIN sites s ON s.id = dr.site_id WHERE dr.id = ?");
    $stmt->execute([$_GET['delete']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) { flash('Report not found'); header('Location: reports.php'); exit; }
    $canDelete = $isManager || in_array($row['site_id'], $mySiteIdsForDelete);
    if (!$canDelete) { flash('Access denied'); header('Location: reports.php'); exit; }
    $pdo->prepare("DELETE FROM daily_reports WHERE id = ?")->execute([$_GET['delete']]);
    flash('Report deleted', 'warning');
    header('Location: reports.php');
    exit;
}

$where = []; $params = [];
if (isset($_GET['site_id']) && $_GET['site_id']) { $where[] = 'dr.site_id = ?'; $params[] = $_GET['site_id']; }
if ($isSupervisor) { $where[] = 's.id IN (' . siteIdsForSql($mySiteIds) . ')'; }
$wSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $pdo->prepare("
    SELECT dr.*, s.name as site_name, u.full_name as supervisor_name
    FROM daily_reports dr JOIN sites s ON s.id = dr.site_id JOIN users u ON u.id = dr.supervisor_id
    $wSQL ORDER BY dr.report_date DESC, dr.created_at DESC LIMIT 100
");
$stmt->execute($params);
$reports = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Daily Reports - Construction Manager</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
<?php require_once 'sidebar.php'; ?>
<div class="main-content">
    <div class="topbar">
        <h1>Daily Reports</h1>
        <div class="topbar-actions">
            <?php if ($isSupervisor): ?>
            <button onclick="document.getElementById('repForm').style.display='block'" class="btn btn-primary">+ New Report</button>
            <?php endif; ?>
        </div>
    </div>
    <div class="content-area">
        <?php if ($msg = flash()): ?><div class="alert <?= flashType() ?> auto-dismiss"><?= h($msg) ?></div><?php endif; ?>

        <div class="alert info" style="margin-bottom:20px;">
            <strong>📝 Daily Reports:</strong>
            <?php if ($isManager): ?>
                View all daily reports from all sites. Track progress, issues, and weather conditions.
                Only supervisors can submit new reports.
            <?php else: ?>
                Submit daily reports for your sites (<?= count($sites) ?> site<?= count($sites) != 1 ? 's' : '' ?>).
                Reports help the manager stay informed about site progress.
            <?php endif; ?>
        </div>

        <div class="card" id="repForm" style="display:<?= isset($_GET['new']) ? 'block' : 'none' ?>">
            <div class="card-header">
                <h2>Submit Daily Report</h2>
                <a href="reports.php" class="btn btn-sm btn-secondary">Close</a>
            </div>
            <div class="card-body">
                <?php if (!$sites): ?>
                    <div class="alert warning">You have no sites assigned. Please contact the manager.</div>
                <?php else: ?>
                <form method="POST">
                    <input type="hidden" name="action" value="create">
                    <div class="form-row">
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
                            <label>Date *</label>
                            <input type="date" name="report_date" required value="<?= date('Y-m-d') ?>">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Work Progress</label>
                        <textarea name="work_progress" rows="3" placeholder="What was accomplished today?"></textarea>
                    </div>
                    <div class="form-group">
                        <label>Issues / Blockers</label>
                        <textarea name="issues" rows="2" placeholder="Any problems, delays, or concerns?"></textarea>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Weather</label>
                            <input type="text" name="weather" placeholder="e.g. Sunny, 25°C">
                        </div>
                        <div class="form-group">
                            <label>Additional Notes</label>
                            <input type="text" name="notes" placeholder="Optional">
                        </div>
                    </div>
                    <button class="btn btn-primary">Submit Report</button>
                </form>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h2>Recent Reports (<?= count($reports) ?>)</h2>
                <?php if ($isManager): ?>
                <form method="GET" class="filters" id="repFilterForm" style="display:flex;gap:8px;">
                    <select name="site_id">
                        <option value="">All Sites</option>
                        <?php foreach ($sites as $s): ?>
                        <option value="<?= $s['id'] ?>" <?= ($_GET['site_id'] ?? '') == $s['id'] ? 'selected' : '' ?>><?= h($s['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <a href="reports.php" class="btn btn-sm btn-secondary">Clear</a>
                </form>
                <?php endif; ?>
            </div>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Date</th><th>Site</th><th>Supervisor</th><th>Progress</th><th>Issues</th><th>Weather</th><th></th></tr></thead>
                    <tbody>
                        <?php foreach ($reports as $r): ?>
                        <tr>
                            <td><?= h($r['report_date']) ?></td>
                            <td><?= h($r['site_name']) ?></td>
                            <td><?= h($r['supervisor_name']) ?></td>
                            <td title="<?= h($r['work_progress']) ?>"><?= h(mb_strimwidth($r['work_progress'] ?? '', 0, 60, '…')) ?: '—' ?></td>
                            <td title="<?= h($r['issues']) ?>"><?= h(mb_strimwidth($r['issues'] ?? '', 0, 60, '…')) ?: '—' ?></td>
                            <td><?= h($r['weather']) ?: '—' ?></td>
                            <td class="actions">
                                <a href="report_view.php?id=<?= $r['id'] ?>" class="btn btn-sm btn-view">View</a>
                                <form method="GET" style="display:inline" data-confirm="Delete this report?">
                                    <input type="hidden" name="delete" value="<?= $r['id'] ?>">
                                    <button class="btn btn-sm btn-delete">×</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; if (!$reports): ?>
                        <tr><td colspan="7" class="empty-state"><div class="icon">📝</div>No reports submitted yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<script src="js/app.js"></script>
<script>
document.querySelectorAll('#repFilterForm select').forEach(function(el){
    el.addEventListener('change', function(){ document.getElementById('repFilterForm').submit(); });
});
</script>
</body>
</html>
