<?php
require_once 'config.php';
requireRole('manager');

$pdo = getDB();
$user = currentUser();
$companyId = getCurrentCompanyId();
$company = currentCompany();

// --- CREATE ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    $errors = [];
    if (empty(trim($_POST['username']))) $errors[] = 'Username is required';
    if (empty(trim($_POST['full_name']))) $errors[] = 'Full name is required';
    if (empty($_POST['password']) || strlen($_POST['password']) < 8) $errors[] = 'Password must be at least 8 characters';
    elseif (!preg_match('/\d/', $_POST['password'])) $errors[] = 'Password must contain at least one number';
    if ($_POST['password'] !== $_POST['password2']) $errors[] = 'Passwords do not match';
    if (empty($_POST['role'])) $errors[] = 'Role is required';

    // Check unique username, email, phone - already used
    $check = $pdo->prepare("SELECT id FROM users WHERE LOWER(username) = LOWER(?)");
    $check->execute([trim($_POST['username'])]);
    if ($check->fetch()) $errors[] = 'Already used this username';
    // Check email if provided
    if (!empty(trim($_POST['email']))) {
        $chkE = $pdo->prepare("SELECT id FROM users WHERE LOWER(email) = LOWER(?) AND company_id = ?");
        $chkE->execute([trim($_POST['email']), $companyId]);
        if ($chkE->fetch()) $errors[] = 'Already used this email';
        else {
            $chkEG = $pdo->prepare("SELECT id FROM users WHERE LOWER(email) = LOWER(?)");
            $chkEG->execute([trim($_POST['email'])]);
            if ($chkEG->fetch()) $errors[] = 'Already used this email';
        }
    }
    // Check phone if provided (normalized digits)
    if (!empty(trim($_POST['phone']))) {
        $normPhone = preg_replace('/[^0-9]/', '', trim($_POST['phone']));
        $allP = $pdo->prepare("SELECT phone FROM users WHERE company_id = ? AND phone IS NOT NULL");
        $allP->execute([$companyId]);
        foreach ($allP->fetchAll(PDO::FETCH_COLUMN) as $p) {
            if (preg_replace('/[^0-9]/', '', $p) === $normPhone) { $errors[] = 'Already used this phone'; break; }
        }
    }

    if ($errors) {
        foreach ($errors as $e) flash($e);
        header('Location: users.php');
        exit;
    }

    $stmt = $pdo->prepare("INSERT INTO users (company_id, username, password, full_name, role, phone, email) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $companyId,
        trim($_POST['username']),
        password_hash($_POST['password'], PASSWORD_DEFAULT),
        trim($_POST['full_name']),
        $_POST['role'],
        trim($_POST['phone'] ?? ''),
        trim($_POST['email'] ?? '')
    ]);
    flash('User created successfully for company: ' . h($company['name'] ?? ''));
    header('Location: users.php');
    exit;
}

// --- DELETE ---
if (isset($_GET['delete'])) {
    $delId = (int)$_GET['delete'];
    // Verify target belongs to same company
    $chk = $pdo->prepare("SELECT * FROM users WHERE id = ? AND company_id = ?");
    $chk->execute([$delId, $companyId]);
    $targetUser = $chk->fetch(PDO::FETCH_ASSOC);
    if (!$targetUser) { flash('User not found or access denied'); header('Location: users.php'); exit; }
    // Cannot delete yourself
    if ($delId === $user['id']) { flash('You cannot delete yourself'); header('Location: users.php'); exit; }
    // Cannot delete the last manager of this company
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE company_id = ? AND role = 'manager'");
    $stmt->execute([$companyId]);
    $remaining = $stmt->fetchColumn();
    $target = $targetUser['role'];
    if ($target === 'manager' && $remaining <= 1) { flash('Cannot delete the last manager of your company'); header('Location: users.php'); exit; }
    $pdo->prepare("DELETE FROM users WHERE id = ? AND company_id = ?")->execute([$delId, $companyId]);
    flash('User deleted', 'warning');
    header('Location: users.php');
    exit;
}

// --- EDIT ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit') {
    $editId = (int)$_POST['id'];
    // Verify target belongs to same company
    $chk = $pdo->prepare("SELECT * FROM users WHERE id = ? AND company_id = ?");
    $chk->execute([$editId, $companyId]);
    if (!$chk->fetch()) { flash('Access denied'); header('Location: users.php'); exit; }
    $errors = [];
    if (empty(trim($_POST['full_name']))) $errors[] = 'Full name is required';
    if (!empty($_POST['password']) && strlen($_POST['password']) < 8) $errors[] = 'Password must be at least 8 characters';
    if (!empty($_POST['password']) && !preg_match('/\d/', $_POST['password'])) $errors[] = 'Password must contain at least one number';
    if (!empty($_POST['password']) && $_POST['password'] !== $_POST['password2']) $errors[] = 'Passwords do not match';

    // Check username, email, phone unique (excluding self) - already used
    $check = $pdo->prepare("SELECT id FROM users WHERE LOWER(username) = LOWER(?) AND id != ?");
    $check->execute([trim($_POST['username']), $editId]);
    if ($check->fetch()) $errors[] = 'Already used this username';
    if (!empty(trim($_POST['email']))) {
        $chkE = $pdo->prepare("SELECT id FROM users WHERE LOWER(email) = LOWER(?) AND id != ? AND company_id = ?");
        $chkE->execute([trim($_POST['email']), $editId, $companyId]);
        if ($chkE->fetch()) $errors[] = 'Already used this email';
        else {
            $chkEG = $pdo->prepare("SELECT id FROM users WHERE LOWER(email) = LOWER(?) AND id != ?");
            $chkEG->execute([trim($_POST['email']), $editId]);
            if ($chkEG->fetch()) $errors[] = 'Already used this email';
        }
    }
    if (!empty(trim($_POST['phone']))) {
        $normPhone = preg_replace('/[^0-9]/', '', trim($_POST['phone']));
        $allP = $pdo->prepare("SELECT phone FROM users WHERE id != ? AND company_id = ? AND phone IS NOT NULL");
        $allP->execute([$editId, $companyId]);
        foreach ($allP->fetchAll(PDO::FETCH_COLUMN) as $p) {
            if (preg_replace('/[^0-9]/', '', $p) === $normPhone) { $errors[] = 'Already used this phone'; break; }
        }
    }

    if ($errors) {
        foreach ($errors as $e) flash($e);
        header('Location: users.php?edit=' . $editId);
        exit;
    }

    if (!empty($_POST['password'])) {
        $stmt = $pdo->prepare("UPDATE users SET username=?, password=?, full_name=?, role=?, phone=?, email=? WHERE id=? AND company_id=?");
        $stmt->execute([
            trim($_POST['username']), password_hash($_POST['password'], PASSWORD_DEFAULT),
            trim($_POST['full_name']), $_POST['role'],
            trim($_POST['phone'] ?? ''), trim($_POST['email'] ?? ''), $editId, $companyId
        ]);
    } else {
        $stmt = $pdo->prepare("UPDATE users SET username=?, full_name=?, role=?, phone=?, email=? WHERE id=? AND company_id=?");
        $stmt->execute([
            trim($_POST['username']), trim($_POST['full_name']), $_POST['role'],
            trim($_POST['phone'] ?? ''), trim($_POST['email'] ?? ''), $editId, $companyId
        ]);
    }
    flash('User updated');
    header('Location: users.php');
    exit;
}

$editing = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND company_id = ?");
    $stmt->execute([$_GET['edit'], $companyId]);
    $editing = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$editing) { flash('User not found'); header('Location: users.php'); exit; }
}

$users = $pdo->prepare("SELECT u.*,
    (SELECT COUNT(*) FROM sites WHERE supervisor_id = u.id AND company_id = ?) as site_count
    FROM users u WHERE u.company_id = ? ORDER BY (u.role='manager') DESC, u.full_name");
$users->execute([$companyId, $companyId]);
$users = $users->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management - Construction Manager</title>
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
        <h1>User Management</h1>
        <div class="topbar-actions">
            <button onclick="document.getElementById('userForm').style.display='block'" class="btn btn-primary">+ New User</button>
        </div>
    </div>
    <div class="content-area">
        <?php if ($msg = flash()): ?><div class="alert <?= flashType() ?> auto-dismiss"><?= h($msg) ?></div><?php endif; ?>

        <div class="card" id="userForm" style="display:<?= $editing ? 'block' : 'none' ?>">
            <div class="card-header">
                <h2><?= $editing ? 'Edit User' : 'Add New User' ?></h2>
                <a href="users.php" class="btn btn-sm btn-secondary">Close</a>
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="action" value="<?= $editing ? 'edit' : 'create' ?>">
                    <?php if ($editing): ?><input type="hidden" name="id" value="<?= $editing['id'] ?>"><?php endif; ?>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Username *</label>
                            <input type="text" name="username" required value="<?= h($editing['username'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label>Full Name *</label>
                            <input type="text" name="full_name" required value="<?= h($editing['full_name'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Role *</label>
                            <select name="role" required>
                                <option value="supervisor" <?= ($editing['role'] ?? '') === 'supervisor' ? 'selected' : '' ?>>Supervisor</option>
                                <option value="manager" <?= ($editing['role'] ?? '') === 'manager' ? 'selected' : '' ?>>Manager</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Phone</label>
                            <input type="tel" id="userPhone" name="phone" value="<?= h($editing['phone'] ?? '') ?>" placeholder="Enter phone">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Email</label>
                            <input type="email" name="email" value="<?= h($editing['email'] ?? '') ?>" placeholder="user@company.com">
                        </div>
                        <div class="form-group">
                            <label><?= $editing ? 'New Password' : 'Password' ?> <?= $editing ? '(leave blank to keep)' : '*' ?> <span style="font-weight:400;color:#64748b;font-size:11px;">(min 8 + number)</span></label>
                            <div class="password-wrapper">
                                <input type="password" id="userPwd" name="password" <?= $editing ? '' : 'required' ?> placeholder="Min 8 chars, number">
                                <button type="button" class="password-toggle" onclick="togglePwd('userPwd', this)" aria-label="Show password"><svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg></button>
                            </div>
                        </div>
                    </div>
                    <div class="form-group" style="max-width:300px">
                        <label>Confirm Password <?= $editing ? '(if changing)' : '*' ?></label>
                        <input type="password" id="userPwd2" name="password2" <?= $editing ? '' : 'required' ?> placeholder="Repeat password">
                    </div>
                    <button type="submit" class="btn btn-primary"><?= $editing ? 'Save Changes' : 'Create User' ?></button>
                    <?php if ($editing): ?>
                        <a href="users.php" class="btn btn-secondary">Cancel</a>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h2>All Users (<?= count($users) ?>)</h2></div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr><th>Name</th><th>Username</th><th>Role</th><th>Phone</th><th>Email</th><th>Sites</th><th>Actions</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $u): ?>
                        <tr>
                            <td><strong><?= h($u['full_name']) ?></strong></td>
                            <td><?= h($u['username']) ?></td>
                            <td><span class="badge badge-<?= h($u['role']) ?>"><?= h($u['role']) ?></span></td>
                            <td><?= h($u['phone']) ?: '—' ?></td>
                            <td><?= h($u['email']) ?: '—' ?></td>
                            <td><?= (int)$u['site_count'] ?></td>
                            <td class="actions">
                                <a href="users.php?edit=<?= $u['id'] ?>" class="btn btn-sm btn-edit">Edit</a>
                                <?php if ($u['id'] !== $user['id']): ?>
                                <form method="GET" style="display:inline" data-confirm="Delete user '<?= h($u['full_name']) ?>?">
                                    <input type="hidden" name="delete" value="<?= $u['id'] ?>">
                                    <button class="btn btn-sm btn-delete">Del</button>
                                </form>
                                <?php else: ?>
                                <span style="color:#999;font-size:11px;padding:4px 8px">(you)</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<script src="js/app.js"></script>
<script src="https://cdn.jsdelivr.net/npm/intl-tel-input@18.2.1/build/js/intlTelInput.min.js"></script>
<script>
(function(){
    var input = document.querySelector("#userPhone");
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
function togglePwd(id, btn){
    var inp = document.getElementById(id);
    if(!inp) return;
    if(inp.type === 'password'){ inp.type='text'; btn.innerHTML='<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"></path><path d="M9.53 9.53a3 3 0 1 0 4.24 4.24"></path><path d="M1 1l22 22"></path></svg>'; btn.setAttribute('aria-label','Hide password'); } else { inp.type='password'; btn.innerHTML='<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>'; btn.setAttribute('aria-label','Show password'); }
}
</script>
</body>
</html>
