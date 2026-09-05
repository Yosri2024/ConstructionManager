<?php
require_once 'config.php';
requireLogin();
$user = currentUser();
$isManager = $user['role'] === 'manager';
$isSupervisor = $user['role'] === 'supervisor';

$pdo = getDB();
$mySiteIds = getMySiteIds($user);
$siteFilterSql = $isManager ? '' : 'WHERE id IN (' . siteIdsForSql($mySiteIds) . ')';

// Get stats (supervisor sees only their sites)
$stats['jobs'] = $isManager ? $pdo->query("SELECT COUNT(*) FROM jobs")->fetchColumn() : '-';
$stats['sites'] = $pdo->query("SELECT COUNT(*) FROM sites $siteFilterSql")->fetchColumn();
$stats['workers'] = $isManager
    ? $pdo->query("SELECT COUNT(*) FROM workers")->fetchColumn()
    : $pdo->query("SELECT COUNT(*) FROM workers WHERE current_site_id IN (" . siteIdsForSql($mySiteIds) . ")")->fetchColumn();
$hoursFilter = $isManager ? '' : 'WHERE site_id IN (' . siteIdsForSql($mySiteIds) . ')';
$stats['total_hours'] = $pdo->query("SELECT COALESCE(SUM(hours), 0) FROM work_hours $hoursFilter")->fetchColumn();
$repFilter = $isManager ? '' : 'WHERE site_id IN (' . siteIdsForSql($mySiteIds) . ')';
$stats['pending_reports'] = $pdo->query("SELECT COUNT(*) FROM daily_reports $repFilter" . ($repFilter ? ' AND' : 'WHERE') . " DATE(report_date) = DATE('now')")->fetchColumn();
$asgnFilter = $isManager ? '' : 'WHERE site_id IN (' . siteIdsForSql($mySiteIds) . ')';
$stats['active_assignments'] = $pdo->query("SELECT COUNT(*) FROM assignments $asgnFilter" . ($asgnFilter ? ' AND' : 'WHERE') . " (end_date IS NULL OR end_date >= DATE('now'))")->fetchColumn();

// Recent hours entries (filtered by site for supervisor)
$hoursSql = $isManager ? "
    SELECT wh.*, w.first_name || ' ' || w.last_name as worker_name, s.name as site_name
    FROM work_hours wh
    JOIN workers w ON w.id = wh.worker_id
    JOIN sites s ON s.id = wh.site_id
    ORDER BY wh.created_at DESC LIMIT 10
" : "
    SELECT wh.*, w.first_name || ' ' || w.last_name as worker_name, s.name as site_name
    FROM work_hours wh
    JOIN workers w ON w.id = wh.worker_id
    JOIN sites s ON s.id = wh.site_id
    WHERE s.id IN (" . siteIdsForSql($mySiteIds) . ")
    ORDER BY wh.created_at DESC LIMIT 10
";
$recentHours = $pdo->query($hoursSql)->fetchAll(PDO::FETCH_ASSOC);

// Today's reports
$repSql = $isManager ? "
    SELECT dr.*, s.name as site_name, u.full_name as supervisor_name
    FROM daily_reports dr
    JOIN sites s ON s.id = dr.site_id
    JOIN users u ON u.id = dr.supervisor_id
    WHERE DATE(dr.report_date) = DATE('now')
    ORDER BY dr.created_at DESC
" : "
    SELECT dr.*, s.name as site_name, u.full_name as supervisor_name
    FROM daily_reports dr
    JOIN sites s ON s.id = dr.site_id
    JOIN users u ON u.id = dr.supervisor_id
    WHERE s.id IN (" . siteIdsForSql($mySiteIds) . ") AND DATE(dr.report_date) = DATE('now')
    ORDER BY dr.created_at DESC
";
$todayReports = $pdo->query($repSql)->fetchAll(PDO::FETCH_ASSOC);

// Upcoming assignments (filtered)
$asgnSql = $isManager ? "
    SELECT a.*, w.first_name || ' ' || w.last_name as worker_name, s.name as site_name
    FROM assignments a
    JOIN workers w ON w.id = a.worker_id
    JOIN sites s ON s.id = a.site_id
    WHERE DATE(a.start_date) >= DATE('now', '-3 days') AND DATE(a.start_date) <= DATE('now', '+7 days')
    ORDER BY a.start_date ASC LIMIT 10
" : "
    SELECT a.*, w.first_name || ' ' || w.last_name as worker_name, s.name as site_name
    FROM assignments a
    JOIN workers w ON w.id = a.worker_id
    JOIN sites s ON s.id = a.site_id
    WHERE s.id IN (" . siteIdsForSql($mySiteIds) . ")
      AND DATE(a.start_date) >= DATE('now', '-3 days') AND DATE(a.start_date) <= DATE('now', '+7 days')
    ORDER BY a.start_date ASC LIMIT 10
";
$upcomingAssignments = $pdo->query($asgnSql)->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Construction Manager</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
<?php require_once 'sidebar.php'; ?>
<div class="main-content">
    <div class="topbar">
        <h1>Dashboard</h1>
        <div class="topbar-actions">
            <span>Welcome, <?= h($user['full_name']) ?></span>
            <span class="user-badge role-<?= h($user['role']) ?>"><?= ucfirst($user['role']) ?></span>
        </div>
    </div>
    <div class="content-area">

        <?php if ($msg = flash()): ?>
            <div class="alert <?= flashType() ?> auto-dismiss"><?= h($msg) ?></div>
        <?php endif; ?>

        <div class="alert info" style="margin-bottom:20px;">
            <strong>📊 Dashboard Overview:</strong>
            <?php if ($isManager): ?>
                View comprehensive statistics and recent activity across all sites and projects.
            <?php else: ?>
                Overview of your sites (<?= count($mySiteIds) ?> site<?= count($mySiteIds) != 1 ? 's' : '' ?>) and recent activity.
                All data shown is filtered to your assigned sites only.
            <?php endif; ?>
        </div>

        <?php if ($isManager): ?>
        <!-- Manager Stats -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon">📁</div>
                <div class="stat-content">
                    <div class="stat-value"><?= $stats['jobs'] ?></div>
                    <div class="stat-label">Jobs / Projects</div>
                </div>
            </div>
            <div class="stat-card success">
                <div class="stat-icon">📍</div>
                <div class="stat-content">
                    <div class="stat-value"><?= $stats['sites'] ?></div>
                    <div class="stat-label">Active Sites</div>
                </div>
            </div>
            <div class="stat-card warning">
                <div class="stat-icon">👷</div>
                <div class="stat-content">
                    <div class="stat-value"><?= $stats['workers'] ?></div>
                    <div class="stat-label">Workers</div>
                </div>
            </div>
            <div class="stat-card info">
                <div class="stat-icon">⏱️</div>
                <div class="stat-content">
                    <div class="stat-value"><?= number_format($stats['total_hours'], 1) ?></div>
                    <div class="stat-label">Total Hours Recorded</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">📝</div>
                <div class="stat-content">
                    <div class="stat-value"><?= $stats['pending_reports'] ?></div>
                    <div class="stat-label">Today's Reports</div>
                </div>
            </div>
            <div class="stat-card success">
                <div class="stat-icon">📅</div>
                <div class="stat-content">
                    <div class="stat-value"><?= $stats['active_assignments'] ?></div>
                    <div class="stat-label">Active Assignments</div>
                </div>
            </div>
        </div>
        <?php else: ?>
        <!-- Supervisor Stats -->
        <div class="stats-grid">
            <div class="stat-card warning">
                <div class="stat-icon">👷</div>
                <div class="stat-content">
                    <div class="stat-value"><?= $stats['workers'] ?></div>
                    <div class="stat-label">My Site Workers</div>
                </div>
            </div>
            <div class="stat-card info">
                <div class="stat-icon">⏱️</div>
                <div class="stat-content">
                    <div class="stat-value"><?= number_format($stats['total_hours'], 1) ?></div>
                    <div class="stat-label">My Site Hours</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">📝</div>
                <div class="stat-content">
                    <div class="stat-value"><?= $stats['pending_reports'] ?></div>
                    <div class="stat-label">Today's Reports</div>
                </div>
            </div>
            <div class="stat-card success">
                <div class="stat-icon">📍</div>
                <div class="stat-content">
                    <div class="stat-value"><?= $stats['sites'] ?></div>
                    <div class="stat-label">My Sites</div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="grid-2">
            <!-- Recent Hours -->
            <div class="card">
                <div class="card-header">
                    <h2>Recent Work Hours</h2>
                    <a href="hours.php" class="btn btn-sm btn-secondary">View All</a>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Worker</th>
                                <th>Site</th>
                                <th>Date</th>
                                <th>Hours</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($recentHours): foreach ($recentHours as $h): ?>
                            <tr>
                                <td><?= h($h['worker_name']) ?></td>
                                <td><?= h($h['site_name']) ?></td>
                                <td><?= h($h['work_date']) ?></td>
                                <td><?= h($h['hours']) ?>h</td>
                            </tr>
                            <?php endforeach; else: ?>
                            <tr><td colspan="4" class="empty-state">No hours recorded yet.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Upcoming Assignments -->
            <div class="card">
                <div class="card-header">
                    <h2>Upcoming Assignments</h2>
                    <a href="planning.php" class="btn btn-sm btn-secondary">View All</a>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Worker</th>
                                <th>Site</th>
                                <th>Start Date</th>
                                <th>End Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($upcomingAssignments): foreach ($upcomingAssignments as $a): ?>
                            <tr>
                                <td><?= h($a['worker_name']) ?></td>
                                <td><?= h($a['site_name']) ?></td>
                                <td><?= h($a['start_date']) ?></td>
                                <td><?= $a['end_date'] ? h($a['end_date']) : '<em>Ongoing</em>' ?></td>
                            </tr>
                            <?php endforeach; else: ?>
                            <tr><td colspan="4" class="empty-state">No upcoming assignments.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <?php if ($isManager): ?>
        <!-- Quick links -->
        <div class="card">
            <div class="card-header"><h2>Quick Actions</h2></div>
            <div class="card-body" style="display:flex;gap:12px;flex-wrap:wrap;">
                <a href="jobs.php" class="btn btn-primary">+ Add Job</a>
                <a href="sites.php" class="btn btn-primary">+ Add Site</a>
                <a href="workers.php" class="btn btn-primary">+ Add Worker</a>
                <a href="hours.php" class="btn btn-primary">+ Enter Hours</a>
                <a href="planning.php" class="btn btn-secondary">+ Plan Assignment</a>
            </div>
        </div>
        <?php endif; ?>

    </div>
</div>
<script src="js/app.js"></script>
</body>
</html>
