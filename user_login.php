<?php
require_once 'config.php';

// --- Shareable Company Link (for Supervisors - no company password needed) ---
// Supports: user_login.php?c=slug  (WAMP localhost friendly)
// Manager shares this link; supervisor lands directly on user login without entering company password
$inviteError = '';
if (isset($_GET['c']) || isset($_GET['company']) || isset($_GET['slug'])) {
    $raw = trim($_GET['c'] ?? $_GET['company'] ?? $_GET['slug'] ?? '');
    if ($raw !== '') {
        $pdoTmp = getDB();
        $slugTry = slugify($raw);
        $found = null;
        $stmt = $pdoTmp->prepare("SELECT * FROM companies WHERE LOWER(slug) = LOWER(?) LIMIT 1");
        $stmt->execute([$slugTry]);
        $found = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$found) {
            $stmt = $pdoTmp->prepare("SELECT * FROM companies WHERE LOWER(slug) = LOWER(?) OR LOWER(name) = LOWER(?) LIMIT 1");
            $stmt->execute([$raw, $raw]);
            $found = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        if ($found) {
            // Switch company if different; clear user session
            if (!isset($_SESSION['company_id']) || (int)$_SESSION['company_id'] !== (int)$found['id']) {
                unset($_SESSION['user_id'], $_SESSION['username'], $_SESSION['role'], $_SESSION['full_name']);
            }
            $_SESSION['company_id'] = (int)$found['id'];
            $_SESSION['company_name'] = $found['name'];
            $_SESSION['company_slug'] = $found['slug'];
            // If already fully logged in, stay on login page when invite link is used (show supervisor login, don't auto-redirect)
            // Previously redirected to dashboard, now shows login for supervisor even when manager is logged in
            // Continue to show user_login for this company (do not redirect)
        } else {
            $inviteError = 'Company link invalid: "' . h($raw) . '" not found. Ask your Manager for a new link.';
        }
    }
}

// Must be company-logged in to access user login (after invite handling)
if (!isCompanyLoggedIn()) {
    // If invite error, show it on fallback login instead of silent redirect
    if ($inviteError) {
        // will be shown after $company fetch attempt - store in session flash and keep on this page
        $error = $inviteError;
    } else {
        header('Location: login.php');
        exit;
    }
}
if (isLoggedIn() && !isset($_GET['preview']) && !isset($_GET['c']) && !isset($_GET['company']) && !isset($_GET['slug'])) {
    // Just log out current user to allow switching manager (keep company) — invite link (c) shows login even when logged in
    header('Location: logout.php');
    exit;
}

$company = currentCompany();
$companyId = getCurrentCompanyId();
// Detect supervisor invite link (no company password) vs normal manager company login
$isSupervisorInvite = isset($_GET['c']) || isset($_GET['company']) || isset($_GET['slug']);
$isPreview = isset($_GET['preview']);
// Preserve invite error if any; $error may already be set from shareable link handling
if (!isset($error) || $error === '') {
    $error = $inviteError ?? '';
}
$success = '';

// Handle Manager creation (only manager can be created from this page) - BLOCKED on supervisor invite
if ($isSupervisorInvite && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_manager') {
    require_csrf();
    $error = 'Manager creation not allowed via supervisor invite link. Use company login as Manager.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_manager') {
    require_csrf();
    $full_name = trim($_POST['full_name'] ?? '');
    $username  = trim($_POST['username'] ?? '');
    $email     = trim($_POST['email'] ?? '');
    $phone     = trim($_POST['phone'] ?? '');
    $password  = $_POST['password'] ?? '';
    $password2 = $_POST['password2'] ?? '';

    if (!$full_name) $error = 'Full name is required';
    elseif (!$username) $error = 'Username is required';
    elseif (strlen($username) < 3) $error = 'Username at least 3 chars';
    elseif (!preg_match('/^[a-zA-Z0-9_\-]+$/', $username)) $error = 'Username may only contain letters, numbers, _ and -';
    elseif (!$password) $error = 'Password is required';
    elseif (strlen($password) < 8) $error = 'Password must be at least 8 characters';
    elseif (!preg_match('/\d/', $password)) $error = 'Password must contain at least one number';
    elseif ($password !== $password2) $error = 'Passwords do not match';
    else {
        $pdo = getDB();
        $chk = $pdo->prepare("SELECT id FROM users WHERE LOWER(username) = LOWER(?)");
        $chk->execute([$username]);
        if ($chk->fetch()) {
            $error = 'Already used this username';
        } else {
            $emailExists = false;
            $phoneExists = false;
            if ($email) {
                $chkE = $pdo->prepare("SELECT id FROM users WHERE LOWER(email) = LOWER(?) AND company_id = ?");
                $chkE->execute([$email, $companyId]);
                if ($chkE->fetch()) $emailExists = true;
                else {
                    $chkEG = $pdo->prepare("SELECT id FROM users WHERE LOWER(email) = LOWER(?)");
                    $chkEG->execute([$email]);
                    if ($chkEG->fetch()) $emailExists = true;
                }
            }
            if ($phone) {
                $normPhone = preg_replace('/[^0-9]/', '', $phone);
                $allPhones = $pdo->prepare("SELECT phone FROM users WHERE company_id = ? AND phone IS NOT NULL");
                $allPhones->execute([$companyId]);
                foreach ($allPhones->fetchAll(PDO::FETCH_COLUMN) as $p) {
                    if (preg_replace('/[^0-9]/', '', $p) === $normPhone) { $phoneExists = true; break; }
                }
            }
            if ($emailExists) {
                $error = 'Already used this email';
            } elseif ($phoneExists) {
                $error = 'Already used this phone';
            } else {
                try {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $pdo->prepare("INSERT INTO users (company_id, username, password, full_name, role, email, phone) VALUES (?, ?, ?, ?, 'manager', ?, ?)")->execute([$companyId, $username, $hash, $full_name, $email ?: null, $phone ?: null]);
                    $success = 'Manager account created! You can now sign in.';
                } catch (Exception $e) {
                    $error = 'Failed: ' . $e->getMessage();
                }
            }
        }
    }
}

// Handle User Sign In (manager or supervisor)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
    require_csrf();
    if (!check_rate_limit('user_login_'.($companyId ?? 0), 8, 300)) {
        $error = 'Too many attempts. Try again in 5 minutes.';
    } else {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    if (!$username || !$password) {
        $error = 'Username and password required';
    } else {
        $pdo = getDB();
        $stmt = $pdo->prepare("SELECT u.*, c.name as company_name FROM users u LEFT JOIN companies c ON c.id = u.company_id WHERE u.username = ? AND u.company_id = ?");
        $stmt->execute([$username, $companyId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user && password_verify($password, $user['password'])) {
            secure_session_regenerate();
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['full_name'] = $user['full_name'];
            header('Location: dashboard.php');
            exit;
        } else {
            usleep(150000);
            $error = 'Invalid username or password for this company';
        }
    }
    } // rate limit
}

$pdo = getDB();
$stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE company_id = ? AND role='manager'");
$stmt->execute([$companyId]);
$hasManager = $stmt->fetchColumn() > 0;

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $isSupervisorInvite ? 'Supervisor Sign In' : 'Sign In' ?> - <?= h($company['name'] ?? 'Company') ?></title>
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
        html, body { height:100%; }
        body.login-page {
            display:flex !important;
            align-items:center !important;
            justify-content:center !important;
            min-height:100vh !important;
            width:100% !important;
            margin:0 !important;
            background: #2563eb !important;
        }
        .user-wrapper, .login-wrapper {
            display:flex !important;
            align-items:center !important;
            justify-content:center !important;
            width:100% !important;
            min-height:100vh !important;
            padding:20px !important;
            box-sizing:border-box !important;
        }
        .user-card, .login-card {
            background:white; width:380px; padding:32px; border-radius:10px;
            box-shadow:0 10px 30px rgba(0,0,0,0.15);
            margin:0 auto !important;
            box-sizing:border-box;
        }
        /* Keep wider card for manager creation form when needed */
        .user-card.wide, .login-card.wide { width:100%; max-width:520px; }
        .tabs { display:flex; gap:8px; margin-bottom:16px; border-bottom:1px solid #e5e7eb; }
        .tab { flex:1; padding:10px; text-align:center; cursor:pointer; font-weight:600; font-size:13px; color:#64748b; border-bottom:2px solid transparent; }
        .tab.active { color:#2563eb; border-bottom-color:#2563eb; background:#f8fafc; }
        .tab-content { display:none; }
        .tab-content.active { display:block; }
        .strength-meter { height:6px; background:#e5e7eb; border-radius:3px; margin-top:6px; overflow:hidden; }
        .strength-meter div { height:100%; width:0; transition:width 0.3s, background 0.3s; border-radius:3px; }
        .strength-text { font-size:11px; margin-top:4px; font-weight:600; }
        .strength-weak { background:#ef4444; color:#ef4444; }
        .strength-medium { background:#f59e0b; color:#f59e0b; }
        .strength-strong { background:#10b981; color:#10b981; }
        .strength-verystrong { background:#2563eb; color:#2563eb; }
        .password-wrapper { position: relative; }
        .password-wrapper input { padding-right: 40px !important; }
        .password-toggle { position: absolute; right: 10px; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; font-size: 16px; color: #64748b; line-height:1; padding: 4px; }
        .password-toggle:hover { color: #2563eb; }
        @media (max-width: 768px) {
            body.login-page { padding:16px 0 !important; }
            .user-wrapper { padding:12px !important; }
            .user-card, .login-card { width:calc(100% - 24px) !important; max-width:380px !important; padding:20px !important; }
            .form-row { flex-direction:column !important; gap:0 !important; }
        }
    </style>
</head>
<body class="login-page">
    <div class="login-wrapper">
        <div class="login-card<?= !$isSupervisorInvite ? ' wide' : '' ?>">
            <div style="text-align:center;">
                <div class="logo" style="text-align:center; font-size:48px; line-height:1;"><?= $isSupervisorInvite ? '👷' : '🏢' ?></div>
                <h1 style="font-size:22px; margin:8px 0 4px; text-align:center;"><?= $isSupervisorInvite ? 'Supervisor Sign In' : h($company['name'] ?? 'Company') ?></h1>
                <p class="subtitle" style="color:#64748b; font-size:13px; margin-bottom:16px; text-align:center;">
                    <?= $isSupervisorInvite ? 'Welcome to <strong>'.h($company['name'] ?? '').'</strong><br><span style="font-size:11px; color:#64748b;">Supervisor workspace • '.h($company['slug'] ?? '').'</span>' : 'Company workspace • '.h($company['slug'] ?? '') ?>
                </p>
                <?php if ($isSupervisorInvite): ?>
                <div style="background:#f0fdf4; border:1px solid #bbf7d0; padding:8px 12px; border-radius:8px; font-size:12px; color:#166534; margin-bottom:14px; display:flex; gap:8px; align-items:flex-start;">
                    <span style="margin-top:2px; color:#059669;"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg></span><div>You were invited to <strong><?= h($company['name'] ?? '') ?></strong><br><span style="font-size:11px; color:#15803d;">Log in with your Supervisor account only — no company password needed</span></div>
                </div>
                <?php else: ?>
                <div style="background:#f1f5f9;padding:8px 12px;border-radius:8px;font-size:12px;color:#475569;margin-bottom:14px;">
                    Signed in to company <strong><?= h($company['name'] ?? '') ?></strong><br>
                    <a href="logout.php?company=1" style="color:#ef4444;font-size:11px;">Log out</a>
                    &nbsp;|&nbsp; <a href="login.php" style="color:#64748b;font-size:11px;">Company Login</a>
                </div>
                <?php endif; ?>
            </div>

            <?php if ($isSupervisorInvite): ?>
            <!-- Supervisor invite: no tabs, only Sign In -->

            <?php if ($isPreview && isset($_SESSION['user_id'])): ?>
            <div style="background:#fef3c7; border:1px solid #fcd34d; padding:8px 10px; border-radius:8px; font-size:11px; color:#92400e; margin-bottom:12px; text-align:center;">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#92400e" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle; margin-right:4px;"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg> <strong>Preview mode</strong> — you are still logged as <strong><?= h($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Manager') ?></strong> (<?= h($_SESSION['role'] ?? '') ?>). This is what your supervisor will see. <a href="dashboard.php" style="color:#b45309; font-weight:700; text-decoration:underline;">Back to Dashboard</a> | <a href="logout.php" style="color:#b45309; font-weight:600;">Log out to test</a>
            </div>
            <?php endif; ?>
            <?php else: ?>
            <div class="tabs">
                <div class="tab active" onclick="showTab('login')">Sign In</div>
                <div class="tab" onclick="showTab('create')" id="tabCreate">Create Manager Account</div>
            </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert error"><?= h($error) ?></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="alert success"><?= h($success) ?></div>
            <?php endif; ?>
            <?php if (!$hasManager): ?>
                <div class="alert info" style="font-size:12px;">No Manager yet for this company. Please create the first Manager account.</div>
            <?php endif; ?>

            <!-- LOGIN TAB -->
            <div id="tab-login" class="tab-content active">
                <form method="POST" class="login-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="login">
                    <div class="form-group">
                        <label><?= $isSupervisorInvite ? 'Username' : 'Username' ?></label>
                        <input type="text" name="username" required autofocus placeholder="<?= $isSupervisorInvite ? 'your username' : 'Manager or Supervisor username' ?>">
                    </div>
                    <div class="form-group">
                        <label>Password</label>
                        <div class="password-wrapper">
                            <input type="password" id="loginPwd" name="password" required placeholder="<?= $isSupervisorInvite ? 'your password' : 'Your account password' ?>">
                            <button type="button" class="password-toggle" onclick="togglePwd('loginPwd', this)" aria-label="Show password"><svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg></button>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary" style="width:100%;padding:10px;"><?= $isSupervisorInvite ? 'Sign In as Supervisor' : 'Sign In as Manager / Supervisor' ?></button>
                </form>
                <?php if ($isSupervisorInvite): ?>
                <p style="font-size:11px;color:#64748b;margin-top:12px;text-align:center; background:#f0fdf4; padding:8px; border-radius:6px; border:1px solid #dcfce7;">🔒 Supervisor-only login for <strong><?= h($company['name'] ?? '') ?></strong><br>Need help? Contact your Manager.</p>
                <?php else: ?>
                <p style="font-size:11px;color:#94a3b8;margin-top:12px;text-align:center;">Supervisor accounts are created by your Manager inside the app (Users page).</p>
                <?php endif; ?>
            </div>

            <?php if (!$isSupervisorInvite): ?>
            <!-- CREATE MANAGER TAB -->
            <div id="tab-create" class="tab-content">
                <form method="POST" class="login-form" id="createForm">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="create_manager">
                    <div class="alert info" style="font-size:12px;">Create account <strong>only for Manager</strong>. Supervisors cannot self-register; they are invited by Manager.</div>
                    <div class="form-group">
                        <label>Full Name *</label>
                        <input type="text" name="full_name" required placeholder="e.g. Ahmed Khalil" value="<?= h($_POST['full_name'] ?? '') ?>">
                    </div>
                    <div class="form-row" style="display:flex;gap:12px;">
                        <div class="form-group" style="flex:1;">
                            <label>Username *</label>
                            <input type="text" name="username" required placeholder="e.g. ahmed_manager">
                        </div>
                        <div class="form-group" style="flex:1;">
                            <label>Phone Number *</label>
                            <input type="tel" id="managerPhone" name="phone" required placeholder="Enter phone">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Email</label>
                        <input type="email" name="email" placeholder="manager@company.com">
                    </div>
                    <div class="form-group">
                        <label>Password * <span style="font-weight:400;color:#64748b;">(min 8, at least one number)</span></label>
                        <div class="password-wrapper">
                            <input type="password" name="password" id="pwdCreate" required placeholder="Min 8 chars, number required">
                            <button type="button" class="password-toggle" onclick="togglePwd('pwdCreate', this)" aria-label="Show password"><svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg></button>
                        </div>
                        <div class="strength-meter"><div id="barCreate"></div></div>
                        <div class="strength-text" id="textCreate"></div>
                        <div style="font-size:11px;color:#64748b;margin-top:4px;" id="reqCreate">
                            <span id="cUpper" style="color:#ef4444;">✗ Upper/lower</span> &nbsp;
                            <span id="cLen" style="color:#ef4444;">✗ 8+ chars</span> &nbsp;
                            <span id="cNum" style="color:#ef4444;">✗ Number</span>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Confirm Password *</label>
                        <input type="password" name="password2" id="pwdCreate2" required placeholder="Repeat password">
                    </div>
                    <button type="submit" class="btn btn-primary" style="width:100%;padding:10px;">Create Manager Account</button>
                </form>
            </div>
            <?php endif; ?>

            <div style="text-align:center;margin-top:14px;font-size:11px;color:#94a3b8;">
                Company: <strong><?= h($company['name'] ?? '') ?></strong> &bull; <a href="logout.php?company=1">Log out</a>
            </div>
        </div>
    </div>
<script>
function showTab(name){
    document.querySelectorAll('.tab').forEach(function(t){ t.classList.remove('active'); });
    document.querySelectorAll('.tab-content').forEach(function(c){ c.classList.remove('active'); });
    document.getElementById('tab-'+name).classList.add('active');
    if(name==='create') document.getElementById('tabCreate').classList.add('active');
    if(name==='login') document.querySelector('.tab').classList.add('active');
}
<?php if (!$isSupervisorInvite && (!$hasManager || (isset($_POST['action']) && $_POST['action']==='create_manager'))): ?>
showTab('create');
<?php endif; ?>

(function(){
    var pwd=document.getElementById('pwdCreate');
    var bar=document.getElementById('barCreate');
    var text=document.getElementById('textCreate');
    var cUpper=document.getElementById('cUpper');
    var cLen=document.getElementById('cLen');
    var cNum=document.getElementById('cNum');
    if(!pwd) return;
    pwd.addEventListener('input', function(){
        var v=pwd.value;
        var upperOk=/[A-Z]/.test(v) && /[a-z]/.test(v);
        var lenOk=v.length>=8;
        var numOk=/\d/.test(v);
        cUpper.style.color=upperOk?'#10b981':'#ef4444';
        cUpper.textContent=(upperOk?'✓':'✗')+' Upper/lower';
        cLen.style.color=lenOk?'#10b981':'#ef4444';
        cLen.textContent=(lenOk?'✓':'✗')+' 8+ chars';
        cNum.style.color=numOk?'#10b981':'#ef4444';
        cNum.textContent=(numOk?'✓':'✗')+' Number';
        var score=0;
        if(lenOk) score++;
        if(numOk) score++;
        if(/[A-Z]/.test(v) && /[a-z]/.test(v)) score++;
        if(v.length>=12) score++;
        if(/[!@#$%^&*]/.test(v)) score++;
        var w='0', cls='', label='';
        if(v.length===0){ w='0'; label='';}
        else if(score<=1){ w='25%'; cls='strength-weak'; label='Weak';}
        else if(score===2){ w='50%'; cls='strength-medium'; label='Medium';}
        else if(score===3){ w='75%'; cls='strength-strong'; label='Strong';}
        else { w='100%'; cls='strength-verystrong'; label='Very Strong';}
        bar.style.width=w;
        bar.className=cls;
        text.textContent=label;
        text.className='strength-text '+cls;
    });
})();
function togglePwd(id, btn){
    var inp = document.getElementById(id);
    if(!inp) return;
    if(inp.type === 'password'){ inp.type='text'; btn.innerHTML='<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"></path><path d="M9.53 9.53a3 3 0 1 0 4.24 4.24"></path><path d="M1 1l22 22"></path></svg>'; btn.setAttribute('aria-label','Hide password'); }
    else { inp.type='password'; btn.innerHTML='<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>'; btn.setAttribute('aria-label','Show password'); }
}
</script>
<script src="https://cdn.jsdelivr.net/npm/intl-tel-input@18.2.1/build/js/intlTelInput.min.js"></script>
<script>
(function(){
    var input = document.querySelector("#managerPhone");
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
    var form = document.getElementById("createForm");
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
