<?php
require_once 'config.php';
requireLogin();

$pdo = getDB();
$user = currentUser();
$companyId = getCurrentCompanyId();
$isManager = $user['role'] === 'manager';
$isSupervisor = $user['role'] === 'supervisor';
$mySiteIds = $isManager ? [] : getMySiteIds($user);

// Available = status available
// Manager sees all. Supervisor sees all available workers too (their own sites + others + unassigned).
if ($isManager) {
    $workers = $pdo->query("
        SELECT w.id, w.first_name, w.last_name, w.role_title, w.phone, w.status, w.current_site_id,
               s.name AS site_name, s.id AS site_id, s.job_code,
               u.full_name AS supervisor_name
        FROM workers w
        LEFT JOIN sites s ON s.id = w.current_site_id
        LEFT JOIN users u ON u.id = s.supervisor_id
        WHERE w.status = 'available' AND w.company_id = $companyId
        ORDER BY w.last_name, w.first_name
    ")->fetchAll(PDO::FETCH_ASSOC);
    $myWorkers = [];
    $otherWorkers = $workers;
} else {
    // Supervisor: fetch all available workers
    $stmt = $pdo->prepare("
        SELECT w.id, w.first_name, w.last_name, w.role_title, w.phone, w.status, w.current_site_id,
               s.name AS site_name, s.id AS site_id, s.job_code,
               u.full_name AS supervisor_name
        FROM workers w
        LEFT JOIN sites s ON s.id = w.current_site_id
        LEFT JOIN users u ON u.id = s.supervisor_id
        WHERE w.status = 'available' AND w.company_id = $companyId
        ORDER BY w.last_name, w.first_name
    ");
    $stmt->execute();
    $workers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Split into supervisor's own workers and others
    $myWorkers = [];
    $otherWorkers = [];
    foreach ($workers as $w) {
        // Own worker = assigned to one of the supervisor's sites
        if (in_array((int)$w['current_site_id'], array_map('intval', $mySiteIds), true)) {
            $myWorkers[] = $w;
        } else {
            $otherWorkers[] = $w;
        }
    }
}

// All active sites for the assignment dropdown
$allSites = $pdo->query("SELECT id, name, status FROM sites WHERE company_id = $companyId ORDER BY status, name")->fetchAll(PDO::FETCH_ASSOC);
// Filter to only sites the current user can assign to
if ($isManager) {
    $sites = $allSites;
} else {
    $sites = array_values(array_filter($allSites, function($s) use ($mySiteIds) {
        return in_array((int)$s['id'], array_map('intval', $mySiteIds), true);
    }));
}

// Get pending transfer requests so we can show which workers already have one (filtered by company)
$pendingRows = $pdo->prepare("
    SELECT tr.worker_id, tr.to_site_id, s.name as to_site_name
    FROM transfer_requests tr
    JOIN sites s ON s.id = tr.to_site_id
    JOIN users u ON u.id = tr.requested_by
    WHERE tr.status = 'pending' AND u.company_id = ?
");
$pendingRows->execute([$companyId]);
$pendingRows = $pendingRows->fetchAll(PDO::FETCH_ASSOC);
$pendingByWorker = [];
foreach ($pendingRows as $pr) {
    $pendingByWorker[$pr['worker_id']] = $pr['to_site_name'];
}

// Submit transfer request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'assign') {
    require_csrf();
    $workerId = (int)$_POST['worker_id'];
    $toSiteId = (int)$_POST['site_id'];
    $notes = trim($_POST['notes'] ?? '');

    if (!$notes) { flash('Please explain why you need this worker'); header('Location: available_workers.php'); exit; }

    // Prevent duplicate pending requests for the same worker
    $dup = $pdo->prepare("SELECT id FROM transfer_requests WHERE worker_id = ? AND status = 'pending'");
    $dup->execute([$workerId]);
    if ($dup->fetch()) { flash('Transfer already pending for this worker'); header('Location: available_workers.php'); exit; }

    // Validate: supervisor can only request to their own sites
    if ($isSupervisor) {
        $check = $pdo->prepare("SELECT id FROM sites WHERE id = ? AND company_id = $companyId AND id IN (" . siteIdsForSql($mySiteIds) . ")");
        $check->execute([$toSiteId]);
        if (!$check->fetch()) { flash('Access denied'); header('Location: available_workers.php'); exit; }
    }

    // Get worker's current site
    $wStmt = $pdo->prepare("SELECT current_site_id FROM workers WHERE id = ? AND company_id = $companyId");
    $wStmt->execute([$workerId]);
    $w = $wStmt->fetch(PDO::FETCH_ASSOC);
    $fromSiteId = $w ? $w['current_site_id'] : null;

    // Create transfer request
    $stmt = $pdo->prepare("INSERT INTO transfer_requests (worker_id, from_site_id, to_site_id, requested_by, notes) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$workerId, $fromSiteId, $toSiteId, $user['id'], $notes]);

    flash('Transfer request submitted — pending approval');
    header('Location: available_workers.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Available Workers - Construction Manager</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        body { overflow: auto; height: auto; min-height: 100vh; }
        .main-content { height: auto; overflow: visible; }
        .content-area { overflow: visible; }
        .worker-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 16px; display: flex; align-items: center; gap: 16px; flex-wrap: wrap; }
        .worker-card + .worker-card { margin-top: 10px; }
        .worker-info { flex: 1; min-width: 200px; }
        .worker-info strong { font-size: 15px; }
        .worker-info small { color: #6b7280; display: block; margin-top: 2px; }
        .worker-meta { display: flex; gap: 12px; flex-wrap: wrap; margin-top: 6px; }
        .worker-meta span { font-size: 12px; background: #f3f4f6; color: #374151; padding: 2px 8px; border-radius: 4px; }
        .worker-actions { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        .form-row-inline { display: flex; gap: 8px; align-items: flex-end; }
        .transfer-select,
        .transfer-notes,
        .transfer-submit { height: 30px !important; line-height: 30px; font-size: 12px; }
        .transfer-select { min-width: 180px !important; padding: 0 8px; }
        .transfer-notes { min-width: 160px !important; padding: 0 8px; }
        .transfer-submit { padding: 0 10px !important; white-space: nowrap; }
    </style>
</head>
<body>
<?php require_once 'sidebar.php'; ?>
<div class="main-content">
    <div class="topbar">
        <h1><?= $isSupervisor ? 'Available Workers Pool' : 'All Available Workers' ?></h1>
    </div>
    <div class="content-area">
        <?php if ($msg = flash()): ?>
            <div class="alert <?= flashType() ?> auto-dismiss"><?= h($msg) ?></div>
        <?php endif; ?>

        <div class="alert info" style="margin-bottom:20px;">
            <strong>👷 Available Workers Pool:</strong>
            <?php if ($isManager): ?>
                View all available workers across the system. Transfer them to any site as needed.
            <?php else: ?>
                View available workers. <strong>Your Workers</strong> are assigned to your sites, <strong>Other Workers</strong> are from other supervisors.
                Submit a transfer request — the manager must approve before the worker is assigned.
            <?php endif; ?>
        </div>

        <?php if (empty($workers)): ?>
        <div class="card">
            <div class="empty-state" style="padding:40px">
                <div class="icon">👷</div>
                <p>No available workers in the pool.</p>
            </div>
        </div>
        <?php else: ?>
            <?php if ($isSupervisor): ?>
                <!-- Your Workers Section -->
                <?php if (!empty($myWorkers)): ?>
                <div class="card" style="margin-bottom:20px;">
                    <div class="card-header" style="background:#ecfdf5; border-bottom:2px solid #10b981;">
                        <h2 style="color:#065f46;">👤 Your Workers - <?= h($user['full_name']) ?> (<?= count($myWorkers) ?>)</h2>
                    </div>
                    <div class="card-body">
                        <?php foreach ($myWorkers as $w): ?>
                        <div class="worker-card" style="border-left:4px solid #10b981;">
                            <div class="worker-info">
                                <strong><?= h($w['first_name']) ?> <?= h($w['last_name']) ?></strong>
                                <small style="color:#2563eb; font-weight:500;">👤 Supervisor: <?= h($w['supervisor_name']) ?: 'No supervisor' ?></small>
                                <small><?= h($w['role_title']) ?: 'No trade specified' ?></small>
                                <div class="worker-meta">
                                    <?php if ($w['phone']): ?>
                                    <span>📞 <?= h($w['phone']) ?></span>
                                    <?php endif; ?>
                                    <?php if ($w['current_site_id']): ?>
                                    <span>📍 <?= h($w['site_name']) ?></span>
                                    <span>🔢 <?= h($w['job_code']) ?: '—' ?></span>
                                    <?php else: ?>
                                    <span>📍 Unassigned</span>
                                    <?php endif; ?>
                                    <span class="badge badge-available">Available - <?= h($w['supervisor_name']) ?: 'Unassigned' ?></span>
                                </div>
                            </div>
                            <?php if (!empty($sites)): ?>
                            <div class="worker-actions">
                                <?php if (isset($pendingByWorker[$w['id']])): ?>
                                    <span class="badge badge-warning" style="padding:6px 12px;">
                                        ⏳ Pending transfer to <strong><?= h($pendingByWorker[$w['id']]) ?></strong> — awaiting manager
                                    </span>
                                <?php else: ?>
                                <form method="POST" class="form-row-inline">
                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="assign">
                                    <input type="hidden" name="worker_id" value="<?= $w['id'] ?>">
                                    <select name="site_id" required class="transfer-select">
                                        <option value="">Why are you taking this worker?</option>
                                        <?php
                                        $currentGroup = null;
                                        foreach ($sites as $s):
                                            $group = ucfirst($s['status'] ?? 'active');
                                            if ($group !== $currentGroup):
                                                if ($currentGroup !== null) echo '</optgroup>';
                                                echo '<optgroup label="' . h($group) . '">';
                                                $currentGroup = $group;
                                            endif;
                                        ?>
                                        <option value="<?= $s['id'] ?>"><?= h($s['name']) ?></option>
                                        <?php endforeach; if ($currentGroup !== null) echo '</optgroup>'; ?>
                                    </select>
                                    <input type="text" name="notes" placeholder="Why do you need them? (required)" required class="transfer-notes">
                                    <button type="submit" class="btn btn-primary btn-sm transfer-submit">📨 Request Transfer</button>
                                </form>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Other Workers Section -->
                <?php if (!empty($otherWorkers)): ?>
                <div class="card" style="margin-bottom:20px;">
                    <div class="card-header" style="background:#eff6ff; border-bottom:2px solid #3b82f6;">
                        <h2 style="color:#1e3a8a;">👥 Other Workers (<?= count($otherWorkers) ?>)</h2>
                    </div>
                    <div class="card-body">
                        <?php foreach ($otherWorkers as $w): ?>
                        <div class="worker-card" style="border-left:4px solid #3b82f6;">
                            <div class="worker-info">
                                <strong><?= h($w['first_name']) ?> <?= h($w['last_name']) ?></strong>
                                <small style="color:#2563eb; font-weight:500;">👤 Supervisor: <?= h($w['supervisor_name']) ?: 'No supervisor' ?></small>
                                <small><?= h($w['role_title']) ?: 'No trade specified' ?></small>
                                <div class="worker-meta">
                                    <?php if ($w['phone']): ?>
                                    <span>📞 <?= h($w['phone']) ?></span>
                                    <?php endif; ?>
                                    <?php if ($w['current_site_id']): ?>
                                    <span>📍 <?= h($w['site_name']) ?></span>
                                    <span>🔢 <?= h($w['job_code']) ?: '—' ?></span>
                                    <?php else: ?>
                                    <span>📍 Unassigned</span>
                                    <?php endif; ?>
                                    <span class="badge badge-available">Available - <?= h($w['supervisor_name']) ?: 'Unassigned' ?></span>
                                </div>
                            </div>
                            <?php if (!empty($sites)): ?>
                            <div class="worker-actions">
                                <?php if (isset($pendingByWorker[$w['id']])): ?>
                                    <span class="badge badge-warning" style="padding:6px 12px;">
                                        ⏳ Pending transfer to <strong><?= h($pendingByWorker[$w['id']]) ?></strong> — awaiting manager
                                    </span>
                                <?php else: ?>
                                <form method="POST" class="form-row-inline">
                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="assign">
                                    <input type="hidden" name="worker_id" value="<?= $w['id'] ?>">
                                    <select name="site_id" required class="transfer-select">
                                        <option value="">Why are you taking this worker?</option>
                                        <?php
                                        $currentGroup = null;
                                        foreach ($sites as $s):
                                            $group = ucfirst($s['status'] ?? 'active');
                                            if ($group !== $currentGroup):
                                                if ($currentGroup !== null) echo '</optgroup>';
                                                echo '<optgroup label="' . h($group) . '">';
                                                $currentGroup = $group;
                                            endif;
                                        ?>
                                        <option value="<?= $s['id'] ?>"><?= h($s['name']) ?></option>
                                        <?php endforeach; if ($currentGroup !== null) echo '</optgroup>'; ?>
                                    </select>
                                    <input type="text" name="notes" placeholder="Why do you need them? (required)" required class="transfer-notes">
                                    <button type="submit" class="btn btn-primary btn-sm transfer-submit">📨 Request Transfer</button>
                                </form>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            <?php else: ?>
                <!-- Manager view: single list -->
                <div class="card">
                    <div class="card-header">
                        <h2>All Available Workers (<?= count($workers) ?>)</h2>
                    </div>
                    <div class="card-body">
                        <?php foreach ($workers as $w): ?>
                        <div class="worker-card">
                            <div class="worker-info">
                                <strong><?= h($w['first_name']) ?> <?= h($w['last_name']) ?></strong>
                                <small style="color:#2563eb; font-weight:500;">👤 Supervisor: <?= h($w['supervisor_name']) ?: 'No supervisor' ?></small>
                                <small><?= h($w['role_title']) ?: 'No trade specified' ?></small>
                                <div class="worker-meta">
                                    <?php if ($w['phone']): ?>
                                    <span>📞 <?= h($w['phone']) ?></span>
                                    <?php endif; ?>
                                    <?php if ($w['current_site_id']): ?>
                                    <span>📍 <?= h($w['site_name']) ?></span>
                                    <span>🔢 <?= h($w['job_code']) ?: '—' ?></span>
                                    <?php else: ?>
                                    <span>📍 Unassigned</span>
                                    <?php endif; ?>
                                    <span class="badge badge-available">Available - <?= h($w['supervisor_name']) ?: 'Unassigned' ?></span>
                                </div>
                            </div>
                            <?php if (!empty($sites)): ?>
                            <div class="worker-actions">
                                <?php if (isset($pendingByWorker[$w['id']])): ?>
                                    <span class="badge badge-warning" style="padding:6px 12px;">
                                        ⏳ Pending transfer to <strong><?= h($pendingByWorker[$w['id']]) ?></strong> — awaiting manager
                                    </span>
                                <?php else: ?>
                                <form method="POST" class="form-row-inline">
                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="assign">
                                    <input type="hidden" name="worker_id" value="<?= $w['id'] ?>">
                                    <select name="site_id" required class="transfer-select">
                                        <option value="">Why are you taking this worker?</option>
                                        <?php
                                        $currentGroup = null;
                                        foreach ($sites as $s):
                                            $group = ucfirst($s['status'] ?? 'active');
                                            if ($group !== $currentGroup):
                                                if ($currentGroup !== null) echo '</optgroup>';
                                                echo '<optgroup label="' . h($group) . '">';
                                                $currentGroup = $group;
                                            endif;
                                        ?>
                                        <option value="<?= $s['id'] ?>"><?= h($s['name']) ?></option>
                                        <?php endforeach; if ($currentGroup !== null) echo '</optgroup>'; ?>
                                    </select>
                                    <input type="text" name="notes" placeholder="Why do you need them? (required)" required class="transfer-notes">
                                    <button type="submit" class="btn btn-primary btn-sm transfer-submit">📨 Request Transfer</button>
                                </form>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>

    </div>
</div>
<script src="js/app.js"></script>
</body>
</html>
