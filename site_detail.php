<?php
require_once 'config.php';
requireLogin();

$pdo = getDB();
$user = currentUser();
$id = (int)($_GET['id'] ?? 0);
$isManager = $user['role'] === 'manager';
$isSupervisor = $user['role'] === 'supervisor';
$mySiteIds = getMySiteIds($user);

// For supervisors, restrict to their own sites
$site = $pdo->prepare("SELECT s.*, j.name as job_name, j.code as job_code, u.full_name as supervisor_name FROM sites s LEFT JOIN jobs j ON j.id = s.job_id LEFT JOIN users u ON u.id = s.supervisor_id WHERE s.id = ?");
$site->execute([$id]);
$site = $site->fetch(PDO::FETCH_ASSOC);

if (!$site) { flash('Site not found'); header('Location: sites.php'); exit; }

// Supervisors can only see their own sites
if ($isSupervisor && !in_array($id, $mySiteIds)) {
    flash('Access denied'); header('Location: dashboard.php'); exit;
}

// Workers at this site
$workers = $pdo->prepare("SELECT * FROM workers WHERE current_site_id = ? ORDER BY first_name");
$workers->execute([$id]);
$workers = $workers->fetchAll(PDO::FETCH_ASSOC);

// Recent hours at this site
$hours = $pdo->prepare("
    SELECT wh.*, w.first_name || ' ' || w.last_name as worker_name
    FROM work_hours wh JOIN workers w ON w.id = wh.worker_id
    WHERE wh.site_id = ? ORDER BY wh.work_date DESC LIMIT 20
");
$hours->execute([$id]);
$hours = $hours->fetchAll(PDO::FETCH_ASSOC);

// Reports for this site
$reports = $pdo->prepare("
    SELECT dr.*, u.full_name as supervisor_name
    FROM daily_reports dr JOIN users u ON u.id = dr.supervisor_id
    WHERE dr.site_id = ? ORDER BY dr.report_date DESC LIMIT 15
");
$reports->execute([$id]);
$reports = $reports->fetchAll(PDO::FETCH_ASSOC);

// Total hours
$totalHours = $pdo->prepare("SELECT COALESCE(SUM(hours), 0) FROM work_hours WHERE site_id = ?");
$totalHours->execute([$id]);
$totalHours = $totalHours->fetchColumn();

// Job characteristics (use site-level info if no job linked)
$jobInfo = null;
if ($site['job_id']) {
    $jstmt = $pdo->prepare("SELECT * FROM jobs WHERE id = ?");
    $jstmt->execute([$site['job_id']]);
    $jobInfo = $jstmt->fetch(PDO::FETCH_ASSOC);
}
$effectiveJobCode = $jobInfo['code'] ?? $site['job_code'] ?? '—';
$effectiveJobDesc = $jobInfo['description'] ?? $site['job_description'] ?? '';
$effectiveClient = $jobInfo['client_name'] ?? $site['client_name'] ?? '';
$effectiveBudget = $jobInfo['budget'] ?? $site['budget'] ?? null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($site['name']) ?> - Site Details</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
<?php require_once 'sidebar.php'; ?>
<div class="main-content">
    <div class="topbar">
        <h1><?= h($site['name']) ?></h1>
        <div class="topbar-actions">
            <a href="<?= $isManager ? 'sites.php' : 'dashboard.php' ?>" class="btn btn-sm btn-secondary">← Back</a>
        </div>
    </div>
    <div class="content-area">
        <?php if ($msg = flash()): ?><div class="alert <?= flashType() ?> auto-dismiss"><?= h($msg) ?></div><?php endif; ?>

        <div class="stats-grid" style="margin-bottom:20px">
            <div class="stat-card">
                <div class="stat-icon">📍</div>
                <div class="stat-content">
                    <div class="stat-value"><?= number_format($totalHours, 1) ?></div>
                    <div class="stat-label">Total Hours</div>
                </div>
            </div>
            <div class="stat-card warning">
                <div class="stat-icon">👷</div>
                <div class="stat-content">
                    <div class="stat-value"><?= count($workers) ?></div>
                    <div class="stat-label">Workers</div>
                </div>
            </div>
            <div class="stat-card info">
                <div class="stat-icon">📁</div>
                <div class="stat-content">
                    <div class="stat-value"><?= h($site['job_code'] ?? '—') ?></div>
                    <div class="stat-label">Linked Job</div>
                </div>
            </div>
            <div class="stat-card success">
                <div class="stat-icon">📝</div>
                <div class="stat-content">
                    <div class="stat-value"><?= count($reports) ?></div>
                    <div class="stat-label">Reports</div>
                </div>
            </div>
        </div>

        <div class="grid-2">
            <div class="card">
                <div class="card-header"><h2>Site Information</h2></div>
                <div class="card-body">
                    <ul class="detail-list">
                        <li><span class="label">Site Name</span><span class="value"><?= h($site['name']) ?></span></li>
                        <li><span class="label">Address</span><span class="value"><?= h($site['address']) ?: '—' ?></span></li>
                        <li><span class="label">Supervisor</span><span class="value"><?= h($site['supervisor_name']) ?: '—' ?></span></li>
                        <li><span class="label">Status</span><span class="value"><span class="badge badge-<?= h($site['status']) ?>"><?= h($site['status']) ?></span></span></li>
                    </ul>
                    <?php if ($site['status'] === 'paused' && !empty($site['pause_reason'])): ?>
                    <h3 style="margin-top:14px;font-size:13px;color:#92400e">⏸ Pause Reason</h3>
                    <div class="notes-box" style="border-left-color:#f59e0b"><?= nl2br(h($site['pause_reason'])) ?></div>
                    <?php endif; ?>
                    <?php if ($isManager): ?>
                    <div style="margin-top:16px">
                        <a href="sites.php?edit=<?= $id ?>" class="btn btn-sm btn-edit">Edit Site</a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card">
                <div class="card-header"><h2>Job / Project Characteristics</h2></div>
                <div class="card-body">
                    <ul class="detail-list">
                        <li><span class="label">Job Code</span><span class="value"><?= h($effectiveJobCode) ?></span></li>
                        <li><span class="label">Job Name</span><span class="value"><?= h($site['job_name'] ?? $site['name']) ?></span></li>
                        <li><span class="label">Client</span><span class="value"><?= h($effectiveClient) ?: '—' ?></span></li>
                        <li><span class="label">Budget</span><span class="value"><?= $effectiveBudget ? number_format($effectiveBudget, 2) : '—' ?></span></li>
                        <li><span class="label">Created</span><span class="value"><?= h($site['created_at']) ?></span></li>
                    </ul>
                    <?php if ($effectiveJobDesc): ?>
                    <h3 style="margin-top:14px;font-size:13px;color:#444">Description</h3>
                    <div class="notes-box"><?= nl2br(h($effectiveJobDesc)) ?></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h2>Workers on Site (<?= count($workers) ?>)</h2></div>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Name</th><th>Role</th><th>Phone</th></tr></thead>
                    <tbody>
                        <?php foreach ($workers as $w): ?>
                        <tr>
                            <td><?= h($w['first_name']) ?> <?= h($w['last_name']) ?></td>
                            <td><?= h($w['role_title']) ?: '—' ?></td>
                            <td><?= h($w['phone']) ?: '—' ?></td>
                        </tr>
                        <?php endforeach; if (!$workers): ?>
                        <tr><td colspan="3" class="empty-state">No workers assigned.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h2>Recent Work Hours</h2></div>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Date</th><th>Worker</th><th>Hours</th><th>Overtime</th><th>Notes</th></tr></thead>
                    <tbody>
                        <?php foreach ($hours as $h): ?>
                        <tr>
                            <td><?= h($h['work_date']) ?></td>
                            <td><?= h($h['worker_name']) ?></td>
                            <td><?= h($h['hours']) ?>h</td>
                            <td><?= h($h['overtime_hours']) ?>h</td>
                            <td><?= h($h['notes']) ?></td>
                        </tr>
                        <?php endforeach; if (!$hours): ?>
                        <tr><td colspan="5" class="empty-state">No hours recorded.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h2>Daily Reports</h2>
                <a href="reports.php" class="btn btn-sm btn-primary">+ New Report</a>
            </div>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Date</th><th>Supervisor</th><th>Progress</th><th>Issues</th></tr></thead>
                    <tbody>
                        <?php foreach ($reports as $r): ?>
                        <tr>
                            <td><?= h($r['report_date']) ?></td>
                            <td><?= h($r['supervisor_name']) ?></td>
                            <td><?= h($r['work_progress']) ?: '—' ?></td>
                            <td><?= h($r['issues']) ?: '—' ?></td>
                        </tr>
                        <?php endforeach; if (!$reports): ?>
                        <tr><td colspan="4" class="empty-state">No reports submitted.</td></tr>
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
