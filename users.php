<?php
require_once 'config.php';
requireRole('manager');

$pdo = getDB();
$user = currentUser();
$companyId = getCurrentCompanyId();
$company = currentCompany();
// Invite link for supervisors (WAMP localhost - no company password needed)
$inviteSlug = $company['slug'] ?? '';
$scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http');
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
$basePath = str_replace('\\', '/', $basePath);
$basePath = str_replace(' ', '%20', $basePath);
$inviteUrl = $scheme . '://' . $host . $basePath . '/user_login.php?c=' . rawurlencode($inviteSlug);
$inviteUrlPreview = $inviteUrl . '&preview=1';
$waText = rawurlencode("You are invited to ".($company['name'] ?? 'our workspace')." 👷\n\n━━━━━━━━━━━━━━━━━━━━\n🔗 SUPERVISOR LOGIN LINK:\n".$inviteUrl."\n━━━━━━━━━━━━━━━━━━━━\n\nLog in with your username & password (provided by Manager).\nNo company password needed.");
$mailSubject = rawurlencode("Supervisor invite - ".($company['name'] ?? 'ConstructionManager')." — Action Required");
$mailBody = rawurlencode("Hi,\n\nYou have been invited as Supervisor for ".($company['name'] ?? '').".\n\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n🔗 SUPERVISOR LOGIN LINK:\n".$inviteUrl."\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n👉 Log in with your username & password (provided by Manager).\n🔒 You do NOT need the company password.\n\nThanks!\n".($company['name'] ?? '')." Team");

// --- CREATE ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    require_csrf();
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
    require_csrf();
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

        <div class="card" style="border-left:4px solid #10b981; margin-bottom:16px; border-radius:10px; overflow:hidden;">
            <div class="card-header" style="background:#f0fdf4; border-bottom:1px solid #dcfce7;">
                <h2 style="color:#065f46; display:flex; align-items:center; gap:8px; margin:0; font-size:15px;"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle; margin-right:6px;"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>Supervisor Invite Link <span style="font-size:10px; color:#047857; background:#dcfce7; padding:3px 8px; border-radius:20px; font-weight:700;">SUPERVISOR ONLY</span></h2>
                <span style="font-size:11px; color:#059669; background:#fff; border:1px solid #bbf7d0; padding:4px 10px; border-radius:20px; font-weight:600;"><svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#059669" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle; margin-right:4px;"><polyline points="20 6 9 17 4 12"/></svg>No company password</span>
            </div>
            <div class="card-body" style="padding:16px;">
                <div style="background:#f0fdf4; border:1px solid #bbf7d0; padding:10px 12px; border-radius:8px; margin-bottom:14px; display:flex; gap:10px; align-items:flex-start;">
                    <div style="display:flex; align-items:center; justify-content:center; width:28px; height:28px; background:#dcfce7; border-radius:50%; flex-shrink:0;"><svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#059669" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle;"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><path d="M22 4L12 14.01l-3-3"/></svg></div>
                    <div>
                        <div style="font-size:12px; color:#065f46; font-weight:700;">Ready to send to your supervisors</div>
                        <div style="font-size:11px; color:#047857; margin-top:2px;">They click → log in with <strong>their username & password only</strong>. Your company password stays private.</div>
                    </div>
                </div>
                <label style="font-size:10px; font-weight:700; color:#475569; letter-spacing:0.5px; display:block; margin-bottom:6px;">SUPERVISOR LOGIN LINK</label>
                <div style="display:flex; gap:0; border:1px solid #e5e7eb; border-radius:8px; overflow:hidden; background:#fff; box-shadow:0 1px 2px rgba(0,0,0,0.04);">
                    <div style="display:flex; align-items:center; padding:0 12px; background:#f8fafc; border-right:1px solid #e5e7eb; color:#10b981;"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle; margin-right:6px;"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg></div>
                    <input type="text" readonly id="inviteLinkUsers" value="<?= h($inviteUrl) ?>" style="flex:1; border:none; padding:12px; font-size:13px; background:#fff; outline:none; color:#0f172a; font-family:monospace;">
                    <button onclick="copyInviteUsers()" id="copyBtnUsers" class="btn btn-primary" style="border-radius:0; padding:0 18px; margin:0; white-space:nowrap; border:none; font-weight:700; background:#10b981; display:flex; align-items:center; gap:6px;"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle; margin-right:4px;"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v3"/></svg>Copy</button>
                </div>
                <div id="copyFeedbackUsers" style="display:none; font-size:12px; color:#059669; margin-top:8px; font-weight:600; background:#f0fdf4; padding:6px 10px; border-radius:6px; border:1px solid #bbf7d0;"><svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#059669" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle; margin-right:4px;"><polyline points="20 6 9 17 4 12"/></svg> Copied! Ready to paste.</div>
                <div style="display:flex; gap:8px; margin-top:12px; flex-wrap:wrap; align-items:center;">
                    <a href="https://wa.me/?text=<?= $waText ?>" target="_blank" class="btn btn-secondary" style="font-size:12px; padding:8px 14px; background:#25D366; color:#fff; border-color:#25D366; font-weight:600;"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle; margin-right:4px;"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>WhatsApp</a>
                    <a href="mailto:?subject=<?= $mailSubject ?>&body=<?= $mailBody ?>" class="btn btn-secondary" style="font-size:12px; padding:8px 14px; font-weight:600;"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle; margin-right:4px;"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>Email</a>
                    <a href="<?= h($inviteUrlPreview) ?>" target="_blank" class="btn btn-secondary" style="font-size:12px; padding:8px 14px; background:#f8fafc;" title="Preview supervisor view — if logged as Manager, use Incognito"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle; margin-right:4px;"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>Preview</a>
                    <span style="font-size:11px; color:#94a3b8; margin-left:auto; background:#f8fafc; padding:4px 8px; border-radius:6px; border:1px solid #f1f5f9;">Slug: <code style="color:#0f172a; font-weight:600;"><?= h($inviteSlug) ?></code></span>
                </div>
                <div style="font-size:11px; color:#64748b; margin-top:8px; display:flex; gap:6px; background:#f8fafc; padding:6px 10px; border-radius:6px;">
                    <span><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#64748b" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle; margin-right:4px;"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg></span> <span><strong>Why Preview may show Dashboard?</strong> You are logged as Manager. Test the link in <strong>Incognito</strong> to see true supervisor login.</span>
                </div>
                <details style="margin-top:10px;">
                    <summary style="font-size:11px; color:#64748b; cursor:pointer; font-weight:600;">Alternative & help</summary>
                    <div style="font-size:11px; color:#475569; margin-top:8px; background:#f8fafc; padding:10px; border-radius:6px; border:1px solid #e2e8f0;">
                        Alternative: <code style="background:#fff; padding:2px 6px; border-radius:4px; border:1px solid #e5e7eb;"><?= h($scheme) ?>://<?= h($host) ?><?= h($basePath) ?>/login.php?c=<?= h($inviteSlug) ?></code><br>
                        First create supervisor in <strong>+ New User</strong> (role = Supervisor), then send link + his username/password.
                    </div>
                </details>
            </div>
        </div>
        <script>
        function copyInviteUsers(){
            var i=document.getElementById('inviteLinkUsers');
            var b=document.getElementById('copyBtnUsers');
            var f=document.getElementById('copyFeedbackUsers');
            if(!i) return;
            i.select(); i.setSelectionRange(0,99999);
            var done=function(){
                if(b){ b.innerHTML='<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#059669" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle; margin-right:4px;"><polyline points="20 6 9 17 4 12"/></svg> Copied!'; b.style.background='#059669'; setTimeout(function(){ b.innerHTML='<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle; margin-right:4px;"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v3"/></svg>Copy'; b.style.background='#10b981'; }, 2000); }
                if(f){ f.style.display='block'; setTimeout(function(){ f.style.display='none'; }, 3000); }
            };
            if(navigator.clipboard && navigator.clipboard.writeText){ navigator.clipboard.writeText(i.value).then(done).catch(function(){ document.execCommand('copy'); done(); }); }
            else { try{ document.execCommand('copy'); done(); } catch(e){ alert('Copy: '+i.value); } }
        }
        </script>

        <div class="card" id="userForm" style="display:<?= $editing ? 'block' : 'none' ?>">
            <div class="card-header">
                <h2><?= $editing ? 'Edit User' : 'Add New User' ?></h2>
                <a href="users.php" class="btn btn-sm btn-secondary">Close</a>
            </div>
            <div class="card-body">
                <form method="POST">
                    <?= csrf_field() ?>
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
