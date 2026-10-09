<?php
require_once 'config.php';

$error = '';
$success = '';

// --- Shareable Company Link (for Supervisors - no company password needed) ---
// Manager sends: http://localhost/ConstructionManager%20Network%20copy/user_login.php?c=slug
// or http://localhost/ConstructionManager%20Network%20copy/login.php?c=slug
// This bypasses company password and sets company context directly (WAMP localhost friendly)
if (isset($_GET['c']) || isset($_GET['company']) || isset($_GET['slug'])) {
    $raw = trim($_GET['c'] ?? $_GET['company'] ?? $_GET['slug'] ?? '');
    if ($raw !== '') {
        $pdoTmp = getDB();
        $slugTry = slugify($raw);
        $found = null;
        // Try by slug (slugified) case-insensitive
        $stmt = $pdoTmp->prepare("SELECT * FROM companies WHERE LOWER(slug) = LOWER(?) LIMIT 1");
        $stmt->execute([$slugTry]);
        $found = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$found) {
            $stmt = $pdoTmp->prepare("SELECT * FROM companies WHERE LOWER(slug) = LOWER(?) OR LOWER(name) = LOWER(?) LIMIT 1");
            $stmt->execute([$raw, $raw]);
            $found = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        if ($found) {
            // If switching to different company, clear any user session
            if (!isset($_SESSION['company_id']) || (int)$_SESSION['company_id'] !== (int)$found['id']) {
                unset($_SESSION['user_id'], $_SESSION['username'], $_SESSION['role'], $_SESSION['full_name']);
            }
            $_SESSION['company_id'] = (int)$found['id'];
            $_SESSION['company_name'] = $found['name'];
            $_SESSION['company_slug'] = $found['slug'];
            // If already fully logged in, still show supervisor login when invite link is used (don't auto-redirect to dashboard)
            // Preserve preview flag for supervisor preview
            $qs = urlencode($found['slug']);
            $previewQs = isset($_GET['preview']) ? '?c='.$qs.'&preview=1' : '?c='.$qs;
            header('Location: user_login.php' . $previewQs);
            exit;
        } else {
            $error = 'Company link invalid: "' . h($raw) . '" not found. Check the link from your Manager.';
        }
    }
}

// Handle Delete Company (must be before redirects so it works even when logged in)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_company') {
    require_csrf();
    $identifier = trim($_POST['identifier'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm = isset($_POST['confirm_delete']);
    if (!$identifier || !$password) {
        $error = 'Company and password required to delete';
    } elseif (!$confirm) {
        $error = 'Please confirm deletion by checking the box';
    } else {
        $pdo = getDB();
        $stmt = $pdo->prepare("SELECT * FROM companies WHERE LOWER(name) = LOWER(?) OR LOWER(email) = LOWER(?) OR LOWER(slug) = LOWER(?) LIMIT 1");
        $stmt->execute([$identifier, $identifier, slugify($identifier)]);
        $company = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$company) {
            $stmt = $pdo->prepare("SELECT * FROM companies WHERE LOWER(slug) = LOWER(?)");
            $stmt->execute([slugify($identifier)]);
            $company = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        if (!$company || !isset($company['password']) || !password_verify($password, $company['password'])) {
            $error = 'Invalid company credentials - cannot delete';
        } else {
            try {
                $pdo->beginTransaction();
                $cid = (int)$company['id'];
                // Delete will cascade via FKs, but ensure manual cleanup for sqlite where cascade may not cover all
                $pdo->exec("PRAGMA foreign_keys = OFF");
                // Delete dependent data explicitly for safety (company_id direct)
                $pdo->prepare("DELETE FROM transfer_requests WHERE worker_id IN (SELECT id FROM workers WHERE company_id=?) OR requested_by IN (SELECT id FROM users WHERE company_id=?)")->execute([$cid,$cid]);
                $pdo->prepare("DELETE FROM attendance WHERE site_id IN (SELECT id FROM sites WHERE company_id=?) OR worker_id IN (SELECT id FROM workers WHERE company_id=?)")->execute([$cid,$cid]);
                $pdo->prepare("DELETE FROM daily_reports WHERE site_id IN (SELECT id FROM sites WHERE company_id=?)")->execute([$cid]);
                $pdo->prepare("DELETE FROM work_hours WHERE site_id IN (SELECT id FROM sites WHERE company_id=?)")->execute([$cid]);
                $pdo->prepare("DELETE FROM assignments WHERE site_id IN (SELECT id FROM sites WHERE company_id=?) OR worker_id IN (SELECT id FROM workers WHERE company_id=?)")->execute([$cid,$cid]);
                $pdo->prepare("DELETE FROM site_supervisors WHERE site_id IN (SELECT id FROM sites WHERE company_id=?) OR user_id IN (SELECT id FROM users WHERE company_id=?)")->execute([$cid,$cid]);
                $pdo->prepare("DELETE FROM workers WHERE company_id=?")->execute([$cid]);
                $pdo->prepare("DELETE FROM sites WHERE company_id=?")->execute([$cid]);
                $pdo->prepare("DELETE FROM jobs WHERE company_id=?")->execute([$cid]);
                $pdo->prepare("DELETE FROM users WHERE company_id=?")->execute([$cid]);
                $pdo->prepare("DELETE FROM companies WHERE id=?")->execute([$cid]);
                $pdo->exec("PRAGMA foreign_keys = ON");
                $pdo->commit();
                // Clear session if deleted company was the logged in one
                if (isset($_SESSION['company_id']) && (int)$_SESSION['company_id'] === $cid) {
                    unset($_SESSION['company_id'], $_SESSION['company_name'], $_SESSION['company_slug'], $_SESSION['user_id'], $_SESSION['username'], $_SESSION['role'], $_SESSION['full_name']);
                }
                $success = 'Company "'.h($company['name']).'" and all its data deleted successfully.';
                // Do not redirect to user_login, stay on login page to show success
            } catch (Exception $e) {
                $pdo->exec("PRAGMA foreign_keys = ON");
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error = 'Delete failed: '.$e->getMessage();
            }
        }
    }
}

// If company and user already logged in -> dashboard
if (isLoggedIn()) {
    header('Location: dashboard.php');
    exit;
}
// If company already logged in but user not -> go to user login
if (isCompanyLoggedIn() && !isset($_SESSION['user_id'])) {
    header('Location: user_login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') !== 'delete_company') {
    require_csrf();
    if (!check_rate_limit('company_login', 8, 300)) {
        $error = 'Too many attempts. Please wait 5 minutes.';
    } else {
    $identifier = trim($_POST['identifier'] ?? ''); // company name or email
    $password = $_POST['password'] ?? '';

    if (!$identifier || !$password) {
        $error = 'Company and password are required';
    } else {
        $pdo = getDB();
        // Case-insensitive exact match for name/email/slug
        $stmt = $pdo->prepare("SELECT * FROM companies WHERE LOWER(name) = LOWER(?) OR LOWER(email) = LOWER(?) OR LOWER(slug) = LOWER(?) LIMIT 1");
        $stmt->execute([$identifier, $identifier, slugify($identifier)]);
        $company = $stmt->fetch(PDO::FETCH_ASSOC);
        // Also try slugify fallback case-insensitive
        if (!$company) {
            $stmt = $pdo->prepare("SELECT * FROM companies WHERE LOWER(slug) = LOWER(?)");
            $stmt->execute([slugify($identifier)]);
            $company = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        if ($company && isset($company['password']) && password_verify($password, $company['password'])) {
            secure_session_regenerate();
            $_SESSION['company_id'] = (int)$company['id'];
            $_SESSION['company_name'] = $company['name'];
            $_SESSION['company_slug'] = $company['slug'];
            // Do not set user yet; go to user login
            header('Location: user_login.php');
            exit;
        } else {
            // Constant-time delay to mitigate brute force
            usleep(200000);
            $error = 'Invalid company credentials';
        }
    }
    } // rate limit else
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Company Sign In - Construction Manager</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
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
        .login-wrapper {
            display:flex !important;
            align-items:center !important;
            justify-content:center !important;
            width:100% !important;
            min-height:100vh !important;
            padding:20px !important;
            box-sizing:border-box !important;
        }
        .login-card {
            background:white; width:380px; padding:32px; border-radius:10px;
            box-shadow:0 10px 30px rgba(0,0,0,0.15);
            margin:0 auto !important;
            box-sizing:border-box;
        }
        .password-wrapper { position: relative; }
        .password-wrapper input { padding-right: 40px !important; }
        .password-toggle { position: absolute; right: 10px; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; font-size: 16px; color: #64748b; line-height:1; padding: 4px; }
        .password-toggle:hover { color: #2563eb; }
        @media (max-width: 768px) {
            body.login-page {
                display:flex !important;
                align-items:center !important;
                justify-content:center !important;
                min-height:100vh !important;
                padding:16px 0 !important;
            }
            .login-wrapper {
                padding:12px !important;
                align-items:center !important;
                justify-content:center !important;
            }
            .login-card { width:calc(100% - 24px) !important; max-width:380px !important; margin:0 auto !important; padding:20px !important; }
        }
    </style>
</head>
<body class="login-page">
    <div class="login-wrapper">
        <div class="login-card">
            <div class="logo" style="text-align:center;font-size:48px;">🏢</div>
            <h1>Company Sign In</h1>
            <p class="subtitle">Sign in to your company workspace first.<br>Then you will sign in as Manager or Supervisor.</p>
            <form method="POST" class="login-form">
                <?= csrf_field() ?>
                <?php if ($msg = flash()): ?>
                    <div class="alert success"><?= h($msg) ?></div>
                <?php endif; ?>
                <?php if ($error): ?>
                    <div class="alert error"><?= h($error) ?></div>
                <?php endif; ?>
                <div class="form-group">
                    <label>Company Name or Email *</label>
                    <input type="text" name="identifier" required autofocus placeholder="Company name or email" value="<?= h($_POST['identifier'] ?? '') ?>">
                    <small style="color:#64748b; font-size:11px;">Type full company name (case-insensitive).</small>
                </div>
                <div class="form-group">
                    <label>Company Password</label>
                    <div class="password-wrapper">
                        <input type="password" id="companyPwd" name="password" required placeholder="Company password">
                        <button type="button" class="password-toggle" onclick="togglePwd('companyPwd', this)" aria-label="Show password"><svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg></button>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary" style="width:100%;">Sign In to Company</button>
            </form>
            <div style="text-align:center;margin-top:14px;font-size:13px;">
                No company yet? <a href="register.php" style="color:#2563eb;font-weight:600;text-decoration:none;">Create Company</a>
            </div>
            <div style="text-align:center; margin-top:16px; border-top:1px solid #e5e7eb; padding-top:12px;">
                <a href="#" onclick="document.getElementById('deleteForm').style.display=document.getElementById('deleteForm').style.display==='none'?'block':'none'; return false;" style="color:#ef4444; font-size:12px; text-decoration:none; font-weight:600;">🗑️ Delete Company</a>
                <div id="deleteForm" style="display:none; margin-top:12px; text-align:left; background:#fef2f2; padding:12px; border-radius:8px; border:1px solid #fecaca;">
                    <h4 style="color:#991b1b; font-size:13px; margin-bottom:8px;">Delete Company Permanently</h4>
                    <p style="font-size:11px; color:#7f1d1d; margin-bottom:8px;">This will delete the company and <strong>all</strong> its data (managers, supervisors, sites, workers, hours, etc.) This cannot be undone.</p>
                    <form method="POST">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete_company">
                        <div class="form-group">
                            <label>Company Name or Email *</label>
                            <input type="text" name="identifier" required placeholder="Company name or email">
                        </div>
                        <div class="form-group">
                            <label>Company Password *</label>
                            <div class="password-wrapper">
                                <input type="password" id="deletePwd" name="password" required placeholder="Company password">
                                <button type="button" class="password-toggle" onclick="togglePwd('deletePwd', this)" aria-label="Show password"><svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg></button>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="confirmDeleteCheck" style="display:flex; align-items:center; gap:10px; background:#fff; border:2px solid #fecaca; border-radius:8px; padding:10px 12px; cursor:pointer; transition:all 0.2s;">
                                <input type="checkbox" id="confirmDeleteCheck" name="confirm_delete" required style="width:18px; height:18px; accent-color:#ef4444; cursor:pointer; flex-shrink:0;">
                                <div style="flex:1;">
                                    <div style="font-weight:700; color:#991b1b; font-size:13px; display:flex; align-items:center; gap:6px;">
                                        <span style="font-size:16px;">⚠️</span> I confirm deletion
                                    </div>
                                    <div style="font-size:11px; color:#7f1d1d; margin-top:2px; line-height:1.2;">I understand this will permanently delete the company and all data</div>
                                </div>
                            </label>
                        </div>
                        <button type="submit" class="btn btn-danger" style="width:100%; background:#fee2e2; color:#991b1b; border-color:#fca5a5;" onclick="return confirm('Are you sure? This will permanently delete the company and ALL its data!')">Delete Company Permanently</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
<script>
function togglePwd(id, btn){
    var inp = document.getElementById(id);
    if(!inp) return;
    if(inp.type === 'password'){ inp.type='text'; btn.innerHTML='<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"></path><path d="M9.53 9.53a3 3 0 1 0 4.24 4.24"></path><path d="M1 1l22 22"></path></svg>'; btn.setAttribute('aria-label','Hide password'); } else { inp.type='password'; btn.innerHTML='<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>'; btn.setAttribute('aria-label','Show password'); }
}
</script>
</body>
</html>
