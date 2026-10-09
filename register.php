<?php
require_once 'config.php';

if (isCompanyLoggedIn() && isLoggedIn()) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $company_name = trim($_POST['company_name'] ?? '');
    $email        = trim($_POST['email'] ?? '');
    $phone        = trim($_POST['phone'] ?? '');
    $password     = $_POST['password'] ?? '';
    $password2    = $_POST['password2'] ?? '';

    // Validation
    if (!$company_name) $error = 'Company name is required';
    elseif (strlen($company_name) < 3) $error = 'Company name must be at least 3 characters';
    elseif (!$email) $error = 'Company email is required';
    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $error = 'Invalid email format';
    elseif (!$phone) $error = 'Phone number is required';
    elseif (!preg_match('/^[0-9+\-\s\(\)]{7,25}$/', $phone)) $error = 'Invalid phone number';
    elseif (!$password) $error = 'Password is required';
    elseif (strlen($password) < 8) $error = 'Password must be at least 8 characters';
    elseif (!preg_match('/\d/', $password)) $error = 'Password must contain at least one number';
    elseif ($password !== $password2) $error = 'Passwords do not match';
    else {
        $pdo = getDB();
        // Check company name, email, phone unique - already used
        $chk = $pdo->prepare("SELECT id FROM companies WHERE LOWER(name) = LOWER(?)");
        $chk->execute([$company_name]);
        if ($chk->fetch()) {
            $error = 'Already used this company name';
        } else {
            $chk2 = $pdo->prepare("SELECT id FROM companies WHERE LOWER(email) = LOWER(?)");
            $chk2->execute([$email]);
            if ($chk2->fetch()) {
                $error = 'Already used this email';
            } else {
                // Normalized phone check (digits only) to catch +212 6 12 34 56 78 vs +212612345678
            $normPhone = preg_replace('/[^0-9]/', '', $phone);
            $phoneExists = false;
            $allPhones = $pdo->query("SELECT phone FROM companies WHERE phone IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($allPhones as $p) {
                if (preg_replace('/[^0-9]/', '', $p) === $normPhone) { $phoneExists = true; break; }
            }
            if ($phoneExists) {
                $error = 'Already used this phone';
            } else {
                    try {
                    $pdo->beginTransaction();
                    $slug = slugify($company_name);
                    $baseSlug = $slug;
                    $i = 1;
                    while (true) {
                        $s = $pdo->prepare("SELECT id FROM companies WHERE slug = ?");
                        $s->execute([$slug]);
                        if (!$s->fetch()) break;
                        $slug = $baseSlug . '-' . $i++;
                    }
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $pdo->prepare("INSERT INTO companies (name, slug, email, phone, password) VALUES (?, ?, ?, ?, ?)")->execute([$company_name, $slug, $email, $phone, $hash]);
                    $pdo->commit();
                    // Do NOT auto-login company; redirect to company login
                    flash('Company created successfully! Please sign in with your company credentials.');
                    header('Location: login.php');
                    exit;
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    $error = 'Registration failed: ' . $e->getMessage();
                }
            }
        }
    }
}

}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register Company - Construction Manager</title>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/intl-tel-input@18.2.1/build/css/intlTelInput.css">
    <style>
        .iti { width:100%; display:block; }
        .iti input.form-control, .iti input[type="tel"] { padding-left: 90px !important; }
        .iti__flag { background-image: url("https://cdn.jsdelivr.net/npm/intl-tel-input@18.2.1/build/img/flags.png"); }
        @media (-webkit-min-device-pixel-ratio: 2), (min-resolution: 192dpi) {
            .iti__flag { background-image: url("https://cdn.jsdelivr.net/npm/intl-tel-input@18.2.1/build/img/flags@2x.png"); }
        }
        /* Ensure placeholder not hidden behind dial code */
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
            background: linear-gradient(135deg,#1e293b 0%,#334155 100%) !important;
        }
        .register-wrapper {
            display:flex !important;
            align-items:center !important;
            justify-content:center !important;
            width:100% !important;
            min-height:100vh !important;
            padding:20px !important;
            box-sizing:border-box !important;
            background: transparent !important;
        }
        .register-card {
            background:#fff; border-radius:16px; padding:32px; width:100%; max-width:520px;
            box-shadow:0 20px 60px rgba(0,0,0,0.3);
            margin:0 auto !important;
            box-sizing:border-box;
        }
        .register-card h1 { font-size:22px; margin:8px 0 4px; text-align:center;}
        .register-card .subtitle { text-align:center; color:#64748b; font-size:13px; margin-bottom:20px;}
        .login-footer { text-align:center; margin-top:18px; font-size:13px; color:#64748b;}
        .login-footer a { color:#2563eb; text-decoration:none; font-weight:600;}
        .login-footer a:hover { text-decoration:underline;}
        /* Password strength meter */
        .strength-meter { height:6px; background:#e5e7eb; border-radius:3px; margin-top:6px; overflow:hidden; }
        .strength-meter div { height:100%; width:0; transition:width 0.3s, background 0.3s; border-radius:3px; }
        .strength-text { font-size:11px; margin-top:4px; font-weight:600; }
        .strength-weak { background:#ef4444; color:#ef4444; }
        .strength-medium { background:#f59e0b; color:#f59e0b; }
        .strength-strong { background:#10b981; color:#10b981; }
        .strength-verystrong { background:#2563eb; color:#2563eb; }
        .req-list { font-size:11px; color:#64748b; margin-top:6px; }
        .req-list span { display:inline-block; margin-right:10px; }
        .req-list .ok { color:#10b981; font-weight:600; }
        .req-list .bad { color:#ef4444; }
        .password-wrapper { position: relative; }
        .password-wrapper input { padding-right: 40px !important; }
        .password-toggle { position: absolute; right: 10px; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; font-size: 16px; color: #64748b; line-height:1; padding: 4px; }
        .password-toggle:hover { color: #2563eb; }
        @media (max-width: 768px) {
            body.login-page {
                display:flex !important;
                align-items:center !important;
                justify-content:center !important;
                padding:16px 0 !important;
                min-height:100vh !important;
            }
            .register-wrapper {
                min-height:auto !important;
                height:auto !important;
                padding:12px !important;
                width:100% !important;
                display:flex !important;
                align-items:center !important;
                justify-content:center !important;
            }
            .register-card {
                width:calc(100% - 24px) !important;
                max-width:520px !important;
                margin:0 auto !important;
                padding:20px !important;
            }
            .form-row { flex-direction:column !important; gap:0 !important; }
        }
        @media (max-width: 480px) {
            .register-card { width:calc(100% - 16px) !important; padding:16px !important; }
        }
    </style>
</head>
<body class="login-page">
    <div class="register-wrapper">
        <div class="register-card">
            <div class="logo" style="text-align:center;font-size:32px;">🏗️</div>
            <h1>Create Company</h1>
            <p class="subtitle">Register your company only. You will sign in as company, then create a Manager account.</p>
            <form method="POST" class="login-form" id="registerForm">
                <?= csrf_field() ?>
                <?php if ($error): ?>
                    <div class="alert error"><?= h($error) ?></div>
                <?php endif; ?>
                <?php if ($msg = flash()): ?>
                    <div class="alert success"><?= h($msg) ?></div>
                <?php endif; ?>
                <div class="form-group">
                    <label>Company Name *</label>
                    <input type="text" name="company_name" required autofocus placeholder="e.g. Acme Construction LLC" value="<?= h($_POST['company_name'] ?? '') ?>">
                </div>
                <div class="form-row" style="display:flex;gap:12px;">
                    <div class="form-group" style="flex:1;">
                        <label>Company Email *</label>
                        <input type="email" name="email" required placeholder="company@acme.com" value="<?= h($_POST['email'] ?? '') ?>">
                    </div>
                    <div class="form-group" style="flex:1;">
                        <label>Phone Number *</label>
                        <input type="tel" id="companyPhone" name="phone" required placeholder="Enter phone" value="<?= h($_POST['phone'] ?? '') ?>">
                    </div>
                </div>
                <div class="form-group">
                    <label>Company Password *</label>
                    <div class="password-wrapper">
                        <input type="password" name="password" id="password" required placeholder="Min 8 chars, at least one number">
                        <button type="button" class="password-toggle" onclick="togglePwd('password', this)" aria-label="Show password"><svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg></button>
                    </div>
                    <div class="strength-meter"><div id="strengthBar"></div></div>
                    <div class="strength-text" id="strengthText"></div>
                    <div class="req-list" id="reqList">
                        <span id="reqUpper" class="bad">✗ Upper/lower</span>
                        <span id="reqLen" class="bad">✗ 8+ chars</span>
                        <span id="reqNum" class="bad">✗ Number</span>
                    </div>
                </div>
                <div class="form-group">
                    <label>Confirm Password *</label>
                    <input type="password" name="password2" id="password2" required placeholder="Repeat password">
                </div>
                <button type="submit" class="btn btn-primary" style="width:100%;padding:12px;font-size:15px;">Create Company</button>
            </form>
            <div class="login-footer">
                Already have a company account? <a href="login.php">Sign In</a><br>
                <span style="font-size:11px;color:#94a3b8;margin-top:6px;display:inline-block;">After creating company, you will sign in as company, then create your Manager account.</span>
            </div>
        </div>
    </div>
<script>
(function(){
    var pwd = document.getElementById('password');
    var bar = document.getElementById('strengthBar');
    var text = document.getElementById('strengthText');
    var reqLen = document.getElementById('reqLen');
    var reqNum = document.getElementById('reqNum');
    var reqUpper = document.getElementById('reqUpper');
    if(!pwd) return;
    pwd.addEventListener('input', function(){
        var v = pwd.value;
        var lenOk = v.length >= 8;
        var numOk = /\d/.test(v);
        var upperOk = /[A-Z]/.test(v) && /[a-z]/.test(v);
        var score = 0;
        if(lenOk) score++;
        if(numOk) score++;
        if(upperOk) score++;
        if(v.length >= 12) score++;
        if(/[ !@#$%^&*]/.test(v)) score++;
        // Update req list (order: Upper/lower, 8+ chars, Number) like manager login
        reqUpper.className = upperOk ? 'ok' : 'bad';
        reqUpper.textContent = (upperOk?'✓':'✗')+' Upper/lower';
        reqLen.className = lenOk ? 'ok' : 'bad';
        reqLen.textContent = (lenOk?'✓':'✗')+' 8+ chars';
        reqNum.className = numOk ? 'ok' : 'bad';
        reqNum.textContent = (numOk?'✓':'✗')+' Number';
        // Strength
        var width = '0', cls='', label='';
        if(v.length===0){ width='0'; label=''; }
        else if(score <=1){ width='25%'; cls='strength-weak'; label='Weak'; }
        else if(score===2){ width='50%'; cls='strength-medium'; label='Medium'; }
        else if(score===3){ width='75%'; cls='strength-strong'; label='Strong'; }
        else { width='100%'; cls='strength-verystrong'; label='Very Strong'; }
        bar.style.width = width;
        bar.className = cls;
        text.textContent = label;
        text.className = 'strength-text ' + cls;
    });
})();
function togglePwd(id, btn){
    var inp = document.getElementById(id);
    if(!inp) return;
    if(inp.type === 'password'){ inp.type='text'; btn.innerHTML='<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"></path><path d="M9.53 9.53a3 3 0 1 0 4.24 4.24"></path><path d="M1 1l22 22"></path></svg>'; btn.setAttribute('aria-label','Hide password'); } else { inp.type='password'; btn.innerHTML='<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>'; btn.setAttribute('aria-label','Show password'); }
}
</script>
<script src="https://cdn.jsdelivr.net/npm/intl-tel-input@18.2.1/build/js/intlTelInput.min.js"></script>
<script>
(function(){
    var input = document.querySelector("#companyPhone");
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
    var form = document.getElementById("registerForm");
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
