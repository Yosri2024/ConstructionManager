<?php
require_once 'config.php';
requireLogin();

$pdo = getDB();
$user = currentUser();
$isManager = $user['role'] === 'manager';
$isSupervisor = $user['role'] === 'supervisor';
$mySiteIds = getMySiteIds($user);
$id = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare("
    SELECT dr.*, s.name as site_name, u.full_name as supervisor_name, j.name as job_name
    FROM daily_reports dr
    JOIN sites s ON s.id = dr.site_id
    JOIN users u ON u.id = dr.supervisor_id
    LEFT JOIN jobs j ON j.id = s.job_id
    WHERE dr.id = ?
");
$stmt->execute([$id]);
$report = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$report) { flash('Report not found'); header('Location: reports.php'); exit; }

// Supervisor check — only their sites
if ($isSupervisor && !in_array($report['site_id'], $mySiteIds)) {
    flash('Access denied'); header('Location: dashboard.php'); exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Report <?= h($report['report_date']) ?> - Construction Manager</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
<?php require_once 'sidebar.php'; ?>
<div class="main-content">
    <div class="topbar">
        <h1>Daily Report — <?= h($report['report_date']) ?></h1>
        <div class="topbar-actions">
            <a href="reports.php" class="btn btn-sm btn-secondary">← Back</a>
        </div>
    </div>
    <div class="content-area">
        <div class="card">
            <div class="card-body">
                <div class="report-meta">
                    <span><strong>Site:</strong> <?= h($report['site_name']) ?></span>
                    <span><strong>Job:</strong> <?= h($report['job_name'] ?? '—') ?></span>
                    <span><strong>Supervisor:</strong> <?= h($report['supervisor_name']) ?></span>
                    <span><strong>Date:</strong> <?= h($report['report_date']) ?></span>
                    <span><strong>Weather:</strong> <?= h($report['weather']) ?: '—' ?></span>
                </div>
                <h3 style="margin-top:16px;font-size:14px;color:var(--gray-700)">Work Progress</h3>
                <div class="notes-box"><?= $report['work_progress'] ? nl2br(h($report['work_progress'])) : '<em style="color:var(--gray-400)">No progress notes</em>' ?></div>
                <h3 style="margin-top:16px;font-size:14px;color:var(--gray-700)">Issues / Blockers</h3>
                <div class="notes-box"><?= $report['issues'] ? nl2br(h($report['issues'])) : '<em style="color:var(--gray-400)">No issues reported</em>' ?></div>
                <h3 style="margin-top:16px;font-size:14px;color:var(--gray-700)">Additional Notes</h3>
                <div class="notes-box"><?= $report['notes'] ? nl2br(h($report['notes'])) : '<em style="color:var(--gray-400)">No additional notes</em>' ?></div>
                <p style="margin-top:16px;color:var(--gray-500);font-size:12px;">Submitted on <?= h($report['created_at']) ?></p>
            </div>
        </div>
    </div>
</div>
<script src="js/app.js"></script>
</body>
</html>
