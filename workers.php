<?php
require_once 'config.php';
requireLogin();
$user = currentUser();
$isManager = $user['role'] === 'manager';
$isSupervisor = $user['role'] === 'supervisor';
$pdo = getDB();
$companyId = getCurrentCompanyId();
$mySiteIds = getMySiteIds($user);

// Supervisors cannot add/edit/delete — redirect to view-only
if (!$isManager) {
    if (isset($_GET['delete']) || isset($_GET['edit'])) {
        flash('Access denied');
        header('Location: workers.php');
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['create', 'edit'])) {
        flash('Access denied');
        header('Location: workers.php');
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    require_csrf();
    if (!$isManager) { flash('Access denied'); header('Location: workers.php'); exit; }
    // verify site belongs to company
    if (!empty($_POST['current_site_id'])) {
        $chk = $pdo->prepare("SELECT id FROM sites WHERE id = ? AND company_id = ?");
        $chk->execute([$_POST['current_site_id'], $companyId]);
        if (!$chk->fetch()) { flash('Invalid site'); header('Location: workers.php'); exit; }
    }
    $stmt = $pdo->prepare("INSERT INTO workers (company_id, first_name, last_name, role_title, phone, current_site_id, employment_start, employment_end) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $companyId, $_POST['first_name'], $_POST['last_name'], $_POST['role_title'] ?? '', $_POST['phone'] ?? '',
        $_POST['current_site_id'] ?: null,
        $_POST['employment_start'] ?: null,
        $_POST['employment_end'] ?: null
    ]);
    flash('Worker added');
    header('Location: workers.php');
    exit;
}

if (isset($_POST['delete']) || isset($_GET['delete'])) {
    if ($_SERVER['REQUEST_METHOD']==='POST') { require_csrf(); } else { if (!validate_csrf($_GET['csrf'] ?? '')) { flash('Invalid token'); header('Location: workers.php'); exit; } }
    $__del = (int)($_POST['delete'] ?? $_GET['delete']);
    if (!$isManager) { flash('Access denied'); header('Location: workers.php'); exit; }
    // Ensure worker belongs to company
    $chk = $pdo->prepare("SELECT id FROM workers WHERE id = ? AND company_id = ?");
    $chk->execute([$__del, $companyId]);
    if (!$chk->fetch()) { flash('Access denied'); header('Location: workers.php'); exit; }
    $pdo->prepare("DELETE FROM workers WHERE id = ? AND company_id = ?")->execute([$__del, $companyId]);
    flash('Worker removed', 'warning');
    header('Location: workers.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit') {
    require_csrf();
    if (!$isManager) { flash('Access denied'); header('Location: workers.php'); exit; }
    // Ensure worker belongs to company
    $chk = $pdo->prepare("SELECT id FROM workers WHERE id = ? AND company_id = ?");
    $chk->execute([$_POST['id'], $companyId]);
    if (!$chk->fetch()) { flash('Access denied'); header('Location: workers.php'); exit; }
    if (!empty($_POST['current_site_id'])) {
        $chkS = $pdo->prepare("SELECT id FROM sites WHERE id = ? AND company_id = ?");
        $chkS->execute([$_POST['current_site_id'], $companyId]);
        if (!$chkS->fetch()) { flash('Invalid site'); header('Location: workers.php'); exit; }
    }
    $stmt = $pdo->prepare("UPDATE workers SET first_name=?, last_name=?, role_title=?, phone=?, current_site_id=?, status=?, employment_start=?, employment_end=? WHERE id=? AND company_id=?");
    $stmt->execute([
        $_POST['first_name'], $_POST['last_name'], $_POST['role_title'] ?? '', $_POST['phone'] ?? '',
        $_POST['current_site_id'] ?: null,
        $_POST['status'] ?? 'available',
        $_POST['employment_start'] ?: null,
        $_POST['employment_end'] ?: null,
        $_POST['id'], $companyId
    ]);
    flash('Worker updated');
    header('Location: workers.php');
    exit;
}

// Build query — supervisor sees only their site workers, always filtered by company
if ($isManager) {
    $workers = $pdo->prepare("
        SELECT w.*, s.name as site_name
        FROM workers w
        LEFT JOIN sites s ON s.id = w.current_site_id
        WHERE w.company_id = ?
        ORDER BY s.name, w.last_name, w.first_name
    ");
    $workers->execute([$companyId]);
    $workers = $workers->fetchAll(PDO::FETCH_ASSOC);
    $sites = $pdo->prepare("SELECT id, name FROM sites WHERE company_id = ? ORDER BY name");
    $sites->execute([$companyId]);
    $sites = $sites->fetchAll(PDO::FETCH_ASSOC);
} else {
    $stmt = $pdo->prepare("
        SELECT w.*, s.name as site_name
        FROM workers w
        LEFT JOIN sites s ON s.id = w.current_site_id
        WHERE w.company_id = ? AND w.current_site_id IN (" . siteIdsForSql($mySiteIds) . ")
        ORDER BY s.name, w.last_name, w.first_name
    ");
    $stmt->execute([$companyId]);
    $workers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $s = $pdo->prepare("SELECT id, name FROM sites WHERE company_id = ? AND id IN (" . siteIdsForSql($mySiteIds) . ") ORDER BY name");
    $s->execute([$companyId]);
    $sites = $s->fetchAll(PDO::FETCH_ASSOC);
}

// Apply filters in PHP
$f_role  = trim($_GET['f_role'] ?? '');
$f_site_w = trim($_GET['f_site_w'] ?? '');
$f_status_w = trim($_GET['f_status_w'] ?? '');
$f_start_from = trim($_GET['f_start_from'] ?? '');
$f_start_to   = trim($_GET['f_start_to'] ?? '');

if ($f_role || $f_site_w || $f_status_w || $f_start_from || $f_start_to) {
    $workers = array_values(array_filter($workers, function($w) use ($f_role, $f_site_w, $f_status_w, $f_start_from, $f_start_to) {
        if ($f_role && stripos(($w['role_title'] ?? ''), $f_role) === false) return false;
        if ($f_site_w === 'unassigned') {
            if (!empty($w['current_site_id'])) return false;
        } elseif ($f_site_w && (int)($w['current_site_id'] ?? 0) !== (int)$f_site_w) {
            return false;
        }
        if ($f_status_w && ($w['status'] ?? '') !== $f_status_w) return false;
        if ($f_start_from && ($w['employment_start'] ?? '') < $f_start_from) return false;
        if ($f_start_to && ($w['employment_start'] ?? '') > $f_start_to) return false;
        return true;
    }));
}

// Get unique roles for filter dropdown (per company)
$roles = $pdo->prepare("SELECT DISTINCT role_title FROM workers WHERE company_id = ? AND role_title IS NOT NULL AND role_title != '' ORDER BY role_title");
$roles->execute([$companyId]);
$roles = $roles->fetchAll(PDO::FETCH_COLUMN);

$editing = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM workers WHERE id = ? AND company_id = ?");
    $stmt->execute([$_GET['edit'], $companyId]);
    $editing = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$editing) { flash('Worker not found'); header('Location: workers.php'); exit; }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Workers - Construction Manager</title>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/intl-tel-input@18.2.1/build/css/intlTelInput.css">
    <style>
        .iti { width:100%; display:block; }
        .iti input.form-control, .iti input[type="tel"] { padding-left: 90px !important; }
        .iti__flag { background-image: url("https://cdn.jsdelivr.net/npm/intl-tel-input@18.2.1/build/img/flags.png"); }
        @media (-webkit-min-device-pixel-ratio: 2), (min-resolution: 192dpi) {
            .iti__flag { background-image: url("https://cdn.jsdelivr.net/npm/intl-tel-input@18.2.1/build/img/flags@2x.png"); }
        }
        .iti--separate-dial-code .iti__selected-flag { background-color: #f9fafb; border-right: 1px solid #e5e7eb; }
        .iti input::placeholder { color: #9ca3af; opacity:1; }
    </style>
</head>
<body>
<?php require_once 'sidebar.php'; ?>
<div class="main-content">
    <div class="topbar">
        <h1><?= $isSupervisor ? 'My Site Workers' : 'Workers' ?></h1>
        <div class="topbar-actions">
            <?php if ($isManager): ?>
            <button onclick="document.getElementById('workerForm').style.display='block'" class="btn btn-primary">+ Add Worker</button>
            <?php endif; ?>
        </div>
    </div>
    <div class="content-area">
        <?php if ($msg = flash()): ?><div class="alert <?= flashType() ?> auto-dismiss"><?= h($msg) ?></div><?php endif; ?>

        <?php if ($isSupervisor): ?>
        <div class="alert info" style="margin-bottom:12px">
            <strong>Read-only view:</strong> You are seeing workers assigned to your sites. Contact the manager to add or edit workers.
        </div>
        <?php endif; ?>

        <div class="card" id="workerForm" style="display:<?= $editing ? 'block' : 'none' ?>">
            <div class="card-header">
                <h2><?= $editing ? 'Edit Worker' : 'Add New Worker' ?></h2>
                <a href="workers.php" class="btn btn-sm btn-secondary">Close</a>
            </div>
            <div class="card-body">
                <form method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="<?= $editing ? 'edit' : 'create' ?>">
                    <?php if ($editing): ?><input type="hidden" name="id" value="<?= $editing['id'] ?>"><?php endif; ?>
                    <div class="form-row">
                        <div class="form-group">
                            <label>First Name *</label>
                            <input type="text" name="first_name" required value="<?= h($editing['first_name'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label>Last Name *</label>
                            <input type="text" name="last_name" required value="<?= h($editing['last_name'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Role / Trade</label>
                            <input type="text" name="role_title" value="<?= h($editing['role_title'] ?? '') ?>" placeholder="e.g. Mason, Electrician">
                        </div>
                        <div class="form-group">
                            <label>Phone</label>
                            <input type="tel" id="workerPhone" name="phone" value="<?= h($editing['phone'] ?? '') ?>" placeholder="Enter phone">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Current Site</label>
                            <select name="current_site_id">
                                <option value="">-- Unassigned --</option>
                                <?php foreach ($sites as $s): ?>
                                <option value="<?= $s['id'] ?>" <?= ($editing['current_site_id'] ?? '') == $s['id'] ? 'selected' : '' ?>><?= h($s['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php if ($editing): ?>
                        <div class="form-group">
                            <label>Status</label>
                            <select name="status">
                                <option value="available" <?= ($editing['status'] ?? '') === 'available' ? 'selected' : '' ?>>Available</option>
                                <option value="assigned" <?= ($editing['status'] ?? '') === 'assigned' ? 'selected' : '' ?>>Assigned</option>
                                <option value="on_leave" <?= ($editing['status'] ?? '') === 'on_leave' ? 'selected' : '' ?>>On leave</option>
                                <option value="inactive" <?= ($editing['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                            </select>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Employment Start</label>
                            <input type="date" name="employment_start" value="<?= h($editing['employment_start'] ?? date('Y-m-d')) ?>">
                        </div>
                        <div class="form-group">
                            <label>Employment End</label>
                            <input type="date" name="employment_end" value="<?= h($editing['employment_end'] ?? '') ?>" placeholder="Leave blank if still employed">
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary"><?= $editing ? 'Save Changes' : 'Add Worker' ?></button>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h2>All Workers (<?= count($workers) ?>)</h2>
            </div>
            <div class="card-body" style="padding-top:0">
                <form method="GET" class="filter-bar" id="workerFilterForm">
                    <label>Role</label>
                    <select name="f_role">
                        <option value="">All Roles</option>
                        <?php foreach ($roles as $r): ?>
                        <option value="<?= h($r) ?>" <?= ($f_role ?? '') === $r ? 'selected' : '' ?>><?= h($r) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <label>Site</label>
                    <select name="f_site_w">
                        <option value="">All Sites</option>
                        <option value="unassigned" <?= ($f_site_w ?? '') === 'unassigned' ? 'selected' : '' ?>>Unassigned</option>
                        <?php foreach ($sites as $s): ?>
                        <option value="<?= $s['id'] ?>" <?= ($f_site_w ?? '') == $s['id'] ? 'selected' : '' ?>><?= h($s['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <label>Status</label>
                    <select name="f_status_w">
                        <option value="">All</option>
                        <option value="available" <?= ($f_status_w ?? '') === 'available' ? 'selected' : '' ?>>Available</option>
                        <option value="assigned" <?= ($f_status_w ?? '') === 'assigned' ? 'selected' : '' ?>>Assigned</option>
                        <option value="on_leave" <?= ($f_status_w ?? '') === 'on_leave' ? 'selected' : '' ?>>On Leave</option>
                        <option value="inactive" <?= ($f_status_w ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                    </select>
                    <label>From</label>
                    <input type="date" name="f_start_from" value="<?= h($f_start_from) ?>">
                    <label>To</label>
                    <input type="date" name="f_start_to" value="<?= h($f_start_to) ?>">
                    <a href="workers.php" class="btn btn-sm btn-secondary">Clear</a>
                </form>
            <div class="table-wrap">
                <table id="workersTable">
                    <thead>
                        <tr><th>Name</th><th>Role</th><th>Phone</th><th>Current Site</th><th>Start</th><th>End</th><th>Status</th><th>Actions</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($workers as $w): ?>
                        <tr>
                            <td><strong><?= h($w['first_name']) ?> <?= h($w['last_name']) ?></strong></td>
                            <td><?= h($w['role_title']) ?: '—' ?></td>
                            <td><?= h($w['phone']) ?: '—' ?></td>
                            <td><?= h($w['site_name']) ?: '<em style="color:var(--gray-400)">Unassigned</em>' ?></td>
                            <td><small><?= h($w['employment_start'] ?: '—') ?></small></td>
                            <td><small><?= h($w['employment_end'] ?: '—') ?></small></td>
                            <td><span class="badge badge-<?= h($w['status']) ?>"><?= h($w['status']) ?></span></td>
                            <td class="actions">
                                <?php if ($isManager): ?>
                                <a href="workers.php?edit=<?= $w['id'] ?>" class="btn btn-sm btn-edit">Edit</a>
                                <form method="POST" style="display:inline" data-confirm="Remove this worker?">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="delete" value="<?= $w['id'] ?>">
                                    <button class="btn btn-sm btn-delete">Del</button>
                                </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; if (!$workers): ?>
                        <tr><td colspan="8" class="empty-state"><div class="icon">👷</div>No workers yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<script src="js/app.js"></script>
<script>
document.querySelectorAll('#workerFilterForm select, #workerFilterForm input').forEach(function(el){
    el.addEventListener('change', function(){ document.getElementById('workerFilterForm').submit(); });
});
</script>
<script src="https://cdn.jsdelivr.net/npm/intl-tel-input@18.2.1/build/js/intlTelInput.min.js"></script>
<script>
(function(){
    var input = document.querySelector("#workerPhone");
    if(!input) return;
    var iti = window.intlTelInput(input, {
        initialCountry: "auto",
        geoIpLookup: function(success, failure){
            fetch("https://ipapi.co/json/").then(function(res){ return res.json(); }).then(function(data){ success(data.country_code); }).catch(function(){ success("us"); });
        },
        utilsScript: "https://cdn.jsdelivr.net/npm/intl-tel-input@18.2.1/build/js/utils.js",
        separateDialCode: true,
        preferredCountries: ["us","gb","ma","fr","de","dz","eg","sa","ae","tr","in","pk","tn"],
        autoPlaceholder: "polite"
    });
    var form = input.closest("form");
    if(form){
        form.addEventListener("submit", function(){
            if(iti.isValidNumber()){
                input.value = iti.getNumber();
            }
        });
    }
})();
</script>
</body>
</html>
