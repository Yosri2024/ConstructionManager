<?php
require_once 'config.php';
requireLogin();

$pdo = getDB();
$companyId = getCurrentCompanyId();
$user = currentUser();
$isManager = $user['role'] === 'manager';
$isSupervisor = $user['role'] === 'supervisor';
$mySiteIds = $isManager ? [] : getMySiteIds($user);

// Approve action — MANAGER ONLY
if (isset($_GET['approve'])) {
    if (!$isManager) { flash('Access denied'); header('Location: transfer_requests.php'); exit; }
    
    $stmt = $pdo->prepare("SELECT tr.* FROM transfer_requests tr JOIN users u ON u.id = tr.requested_by WHERE tr.id = ? AND tr.status = 'pending' AND u.company_id = ?");
    $stmt->execute([$reqId, $companyId]);
    $req = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($req) {
        $pdo->beginTransaction();
        try {
            $pdo->prepare("INSERT INTO assignments (worker_id, site_id, start_date, notes) VALUES (?, ?, ?, ?)")
                ->execute([$req['worker_id'], $req['to_site_id'], date('Y-m-d'), 'Approved transfer request #' . $reqId]);
            if ($req['from_site_id']) {
                $pdo->prepare("UPDATE assignments SET end_date = date('now') WHERE worker_id = ? AND site_id = ? AND (end_date IS NULL OR end_date > date('now'))")
                    ->execute([$req['worker_id'], $req['from_site_id']]);
            }
            $pdo->prepare("UPDATE workers SET current_site_id = ?, status = 'assigned' WHERE id = ?")
                ->execute([$req['to_site_id'], $req['worker_id']]);
            $pdo->prepare("UPDATE transfer_requests SET status = 'approved', responded_at = datetime('now'), responded_by = ? WHERE id = ?")
                ->execute([$user['id'], $reqId]);
            $pdo->commit();
            flash('Transfer approved — worker assigned');
        } catch (Exception $e) {
            $pdo->rollBack();
            flash('Error: ' . $e->getMessage());
        }
    } else {
        flash('Request not found or already processed');
    }
    header('Location: transfer_requests.php');
    exit;
}

// Reject action — MANAGER ONLY
if (isset($_GET['reject'])) {
    if (!$isManager) { flash('Access denied'); header('Location: transfer_requests.php'); exit; }
    
    $stmt = $pdo->prepare("SELECT tr.* FROM transfer_requests tr JOIN users u ON u.id = tr.requested_by WHERE tr.id = ? AND tr.status = 'pending' AND u.company_id = ?");
    $stmt->execute([$reqId, $companyId]);
    $req = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($req) {
        $pdo->prepare("UPDATE transfer_requests SET status = 'rejected', responded_at = datetime('now'), responded_by = ? WHERE id = ?")
            ->execute([$user['id'], $reqId]);
        flash('Transfer request rejected', 'warning');
    }
    header('Location: transfer_requests.php');
    exit;
}

// Filters - always restrict to current company via requested_by user
$filter = $_GET['filter'] ?? 'pending';
$where = [];
$params = [];
$where[] = "rb.company_id = $companyId";

if ($isSupervisor) {
    $where[] = 'tr.to_site_id IN (' . siteIdsForSql($mySiteIds) . ')';
} else {
    if (isset($_GET['site_id']) && $_GET['site_id']) {
        // Ensure site belongs to company
        $chk = $pdo->prepare("SELECT id FROM sites WHERE id = ? AND company_id = ?");
        $chk->execute([(int)$_GET['site_id'], $companyId]);
        if (!$chk->fetch()) { flash('Invalid site'); header('Location: transfer_requests.php'); exit; }
        $where[] = '(tr.to_site_id = ? OR tr.from_site_id = ?)';
        $params[] = (int)$_GET['site_id'];
        $params[] = (int)$_GET['site_id'];
    }
}

if ($filter !== 'all') {
    $where[] = 'tr.status = ?';
    $params[] = $filter;
}

$wSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $pdo->prepare("
    SELECT tr.*,
           w.first_name || ' ' || w.last_name as worker_name, w.status as worker_status, w.role_title,
           fs.name as from_site_name,
           ts.name as to_site_name, ts.status as to_site_status,
           rb.full_name as requested_by_name, rb.role as requested_by_role,
           resp.full_name as responded_by_name
    FROM transfer_requests tr
    JOIN workers w ON w.id = tr.worker_id
    LEFT JOIN sites fs ON fs.id = tr.from_site_id
    JOIN sites ts ON ts.id = tr.to_site_id
    JOIN users rb ON rb.id = tr.requested_by
    LEFT JOIN users resp ON resp.id = tr.responded_by
    $wSQL
    ORDER BY (tr.status = 'pending') DESC, tr.created_at DESC
    LIMIT 200
");
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Counts for tabs - filtered by company
$countSQL = "
    SELECT tr.status, COUNT(*) as cnt
    FROM transfer_requests tr
    JOIN users rb ON rb.id = tr.requested_by
    WHERE rb.company_id = $companyId " . ($isSupervisor ? "AND tr.to_site_id IN (" . siteIdsForSql($mySiteIds) . ")" : "") . "
    GROUP BY tr.status
";
$counts = ['pending' => 0, 'approved' => 0, 'rejected' => 0];
foreach ($pdo->query($countSQL) as $r) {
    $counts[$r['status']] = (int)$r['cnt'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transfer Requests - Construction Manager</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        body { overflow: auto; height: auto; min-height: 100vh; }
        .main-content { height: auto; overflow: visible; }
        .content-area { overflow: visible; }
        .actions-stack { display: flex; flex-direction: column; gap: 4px; }
        .tab-bar { display: flex; gap: 4px; border-bottom: 1px solid #e5e7eb; margin-bottom: 16px; }
        .tab-bar a { padding: 10px 16px; text-decoration: none; color: #6b7280; border-bottom: 2px solid transparent; }
        .tab-bar a.active { color: #2563eb; border-bottom-color: #2563eb; font-weight: 500; }
        .tab-bar .count { background: #e0e7ff; color: #3730a3; border-radius: 10px; padding: 1px 8px; font-size: 11px; margin-left: 4px; }
    </style>
</head>
<body>
<?php require_once 'sidebar.php'; ?>
<div class="main-content">
    <div class="topbar">
        <h1>Transfer Requests</h1>
        <div class="topbar-actions">
            <a href="available_workers.php" class="btn btn-primary">+ New Request</a>
        </div>
    </div>
    <div class="content-area">
        <?php if ($msg = flash()): ?>
            <div class="alert <?= flashType() ?> auto-dismiss"><?= h($msg) ?></div>
        <?php endif; ?>

        <?php if ($isSupervisor): ?>
        <div class="alert info" style="margin-bottom:16px">
            Showing transfer requests targeting <strong>your sites</strong>. Only the manager can approve or reject requests.
        </div>
        <?php endif; ?>

        <div class="tab-bar">
            <a href="?filter=pending" class="<?= $filter === 'pending' ? 'active' : '' ?>">Pending <span class="count"><?= $counts['pending'] ?></span></a>
            <a href="?filter=approved" class="<?= $filter === 'approved' ? 'active' : '' ?>">Approved <span class="count"><?= $counts['approved'] ?></span></a>
            <a href="?filter=rejected" class="<?= $filter === 'rejected' ? 'active' : '' ?>">Rejected <span class="count"><?= $counts['rejected'] ?></span></a>
            <a href="?filter=all" class="<?= $filter === 'all' ? 'active' : '' ?>">All</a>
        </div>

        <div class="card">
            <div class="card-header">
                <h2><?= ucfirst($filter) ?> Requests (<?= count($rows) ?>)</h2>
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Worker</th>
                            <th>From</th>
                            <th>To</th>
                            <th>Requested By</th>
                            <th>Notes</th>
                            <th>Created</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $r): ?>
                        <tr>
                            <td>
                                <strong><?= h($r['worker_name']) ?></strong>
                                <br><small style="color:#666"><?= h($r['role_title']) ?: '—' ?></small>
                            </td>
                            <td><small><?= h($r['from_site_name'] ?? '— Unassigned —') ?></small></td>
                            <td>
                                <strong><?= h($r['to_site_name']) ?></strong>
                                <?php if ($r['to_site_status'] !== 'active'): ?>
                                <br><span class="badge badge-paused"><?= h($r['to_site_status']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= h($r['requested_by_name']) ?>
                                <br><small style="color:#666"><?= h($r['requested_by_role']) ?></small>
                            </td>
                            <td><small><?= h($r['notes']) ?: '—' ?></small></td>
                            <td><small><?= h($r['created_at']) ?></small></td>
                            <td>
                                <?php if ($r['status'] === 'pending'): ?>
                                    <span class="badge badge-warning">Pending</span>
                                    <?php if (!$isManager): ?>
                                    <br><small style="color:#92400e;font-weight:500;">Awaiting manager</small>
                                    <?php endif; ?>
                                <?php elseif ($r['status'] === 'approved'): ?>
                                    <span class="badge badge-active">Approved</span>
                                    <br><small style="color:#666">by <?= h($r['responded_by_name'] ?? '?') ?></small>
                                <?php else: ?>
                                    <span class="badge badge-inactive">Rejected</span>
                                    <br><small style="color:#666">by <?= h($r['responded_by_name'] ?? '?') ?></small>
                                <?php endif; ?>
                            </td>
                            <td class="actions">
                                <?php if ($r['status'] === 'pending' && $isManager): ?>
                                    <div class="actions-stack">
                                        <form method="POST" style="display:inline">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="approve" value="<?= $r['id'] ?>">
                                            <button class="btn btn-sm btn-approve" onclick="return confirm('Approve transfer? The worker will be moved to this site.')">✓ Approve</button>
                                        </form>
                                        <form method="POST" style="display:inline">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="reject" value="<?= $r['id'] ?>">
                                            <button class="btn btn-sm btn-reject" onclick="return confirm('Reject this transfer request?')">✗ Reject</button>
                                        </form>
                                    </div>
                                <?php else: ?>
                                <span style="color:#9ca3af;font-size:11px">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; if (!$rows): ?>
                        <tr><td colspan="8" class="empty-state"><div class="icon">📋</div>No <?= h($filter) ?> requests.</td></tr>
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
