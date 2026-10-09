<?php
// =============================================================
// Database Configuration & Bootstrap - Multi-Tenant - SECURED
// =============================================================
// --- Security: hide detailed errors in production ---
$isLocalEnv = in_array($_SERVER['SERVER_NAME'] ?? '', ['localhost','127.0.0.1','::1']) || stripos($_SERVER['HTTP_HOST'] ?? '', 'localhost') !== false || PHP_SAPI === 'cli';
if (!$isLocalEnv) {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
} else {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
}
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/data/php_errors.log');

// --- Secure session ---
if (session_status() === PHP_SESSION_NONE) {
    $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (!empty($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
    // Avoid "headers already sent" when config is included multiple times via various flows
    if (!headers_sent()) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $isSecure,
            'httponly' => true,
            'samesite' => 'Strict'
        ]);
    }
    session_start();
    // Prevent session fixation: regenerate periodically (every 30 min)
    if (!isset($_SESSION['_last_regen']) || time() - $_SESSION['_last_regen'] > 1800) {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
            $_SESSION['_last_regen'] = time();
        }
    }
}

// --- Security headers (also set in .htaccess - PHP fallback for InfinityFree) ---
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    // Minimal CSP - adjust if you add external domains
    // Allow self, inline styles/scripts needed by app, cdn for icons/tel input
    // Comment out if it blocks your UI; .htaccess CSP is more permissive
    // header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; img-src 'self' data: https:; font-src 'self' https://cdn.jsdelivr.net data:;");
}

// Load local config override if it exists (for local DB credentials etc.)
if (file_exists(__DIR__ . '/config.local.php')) {
    require_once __DIR__ . '/config.local.php';
}

// Load environment variables from .env file if it exists
function loadEnv($path) {
    if (!file_exists($path)) return;
    // Validate path is inside project (prevent path traversal if ever called with user input)
    $real = realpath($path);
    $base = realpath(__DIR__);
    if ($real === false || $base === false || strpos($real, $base) !== 0) return;
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $trim = trim($line);
        if ($trim === '' || strpos($trim, '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        list($key, $value) = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        // Strip surrounding quotes if present (e.g. DB_PASS="secret pass")
        if (strlen($value) >= 2 && (($value[0]==='"' && substr($value,-1)==='"') || ($value[0]==="'" && substr($value,-1)==="'"))) {
            $value = substr($value,1,-1);
        }
        if ($key === '' || !preg_match('/^[A-Z_][A-Z0-9_]*$/', $key)) continue;
        if (!getenv($key) && !isset($_ENV[$key])) {
            putenv("$key=$value");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }
}
loadEnv(__DIR__ . '/.env');

// ===== CSRF & Security Helpers =====
function csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}
function csrf_field() {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}
function validate_csrf($token) {
    if (empty($_SESSION['csrf_token']) || empty($token)) return false;
    return hash_equals($_SESSION['csrf_token'], $token);
}
function require_csrf() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $tok = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!validate_csrf($tok)) {
            http_response_code(403);
            die('CSRF validation failed. Please refresh the page and try again.');
        }
    }
}
function sanitizeInput($data) {
    if (is_array($data)) return array_map('sanitizeInput', $data);
    $data = trim((string)$data);
    // Note: stripslashes no longer needed in modern PHP; htmlspecialchars is for output, not input
    return $data;
}
function e($str) { // shorthand for h()
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}
// Rate limiting: simple per-session throttle for logins
function check_rate_limit($key, $max=5, $window=300) {
    $now = time();
    if (!isset($_SESSION['_rate'][$key])) $_SESSION['_rate'][$key] = [];
    // purge old
    $_SESSION['_rate'][$key] = array_filter($_SESSION['_rate'][$key], function($t) use ($now,$window){ return ($now - $t) < $window; });
    if (count($_SESSION['_rate'][$key]) >= $max) return false;
    $_SESSION['_rate'][$key][] = $now;
    return true;
}
function secure_session_regenerate() {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
        $_SESSION['_last_regen'] = time();
    }
}

// Database mode: 'sqlite' (default, local) or 'mysql' (production)
define('DB_MODE', getenv('DB_MODE') ?: 'sqlite');

if (DB_MODE === 'mysql') {
    // MySQL configuration (for production / cloud hosting)
    define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
    define('DB_PORT', getenv('DB_PORT') ?: '3306');
    define('DB_NAME', getenv('DB_NAME') ?: 'site_management');
    define('DB_USER', getenv('DB_USER') ?: 'root');
    define('DB_PASS', getenv('DB_PASS') ?: '');
    define('DB_CHARSET', 'utf8mb4');
} else {
    // SQLite configuration (for local WAMP/XAMPP development)
    define('DB_FILE', __DIR__ . '/data/site_management.db');
}

function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        if (DB_MODE === 'mysql') {
            $pdo = connectMySQL();
            if (!empty($GLOBALS['DB_FIRST_RUN'])) {
                initSchemaMySQL($pdo);
            }
            migrateMySQL($pdo);
        } else {
            $first = !file_exists(DB_FILE);
            $pdo = new PDO('sqlite:' . DB_FILE);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->exec('PRAGMA foreign_keys = ON');
            if ($first) {
                initSchema($pdo);
            }
            migrate($pdo);
        }
    }
    return $pdo;
}

function connectMySQL() {
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        DB_HOST, DB_PORT, DB_NAME, DB_CHARSET
    );
    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
        return $pdo;
    } catch (PDOException $e) {
        // If database doesn't exist, try to create it
        if (strpos($e->getMessage(), 'Unknown database') !== false) {
            $pdoRoot = new PDO(
                'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';charset=' . DB_CHARSET,
                DB_USER, DB_PASS,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            $pdoRoot->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdoRoot->exec("USE `" . DB_NAME . "`");
            $pdo = $pdoRoot;
            $GLOBALS['DB_FIRST_RUN'] = true;
            return $pdo;
        }
        throw $e;
    }
}

function initSchemaMySQL($pdo) {
    // MySQL schema is loaded from database_mysql.sql — only if file is inside project dir
    $sqlFile = __DIR__ . '/database_mysql.sql';
    if (file_exists($sqlFile)) {
        $real = realpath($sqlFile);
        $base = realpath(__DIR__);
        if ($real === false || $base === false || strpos($real, $base) !== 0) {
            error_log("Blocked initSchemaMySQL: file outside project dir");
            $GLOBALS['DB_FIRST_RUN'] = false;
            return;
        }
        $sql = file_get_contents($sqlFile);
        // Remove SQL file from web access after use note is logged
        // Split by semicolons and execute each statement
        $statements = array_filter(array_map('trim', explode(';', $sql)));
        foreach ($statements as $stmt) {
            if (empty($stmt) || strpos($stmt, '--') === 0) continue;
            // Skip risky statements that shouldn't run via PHP
            if (preg_match('/^(CREATE\s+DATABASE|USE\s+)/i', $stmt)) continue;
            try {
                $pdo->exec($stmt);
            } catch (Exception $e) {
                // Log but continue (some statements like USE are not valid via exec)
                error_log("MySQL init warning: " . $e->getMessage());
            }
        }
    }
    $GLOBALS['DB_FIRST_RUN'] = false;
}

function migrateMySQL($pdo) {
    // Ensure migrations table exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS _migrations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) UNIQUE NOT NULL,
        applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");

    // Helper to check if a migration has run
    $hasMigration = function($name) use ($pdo) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM _migrations WHERE name = ?");
        $stmt->execute([$name]);
        return $stmt->fetchColumn() > 0;
    };

    // Multi-tenant migration for MySQL
    if (!$hasMigration('add_companies_multitenant')) {
        // Create companies table
        $pdo->exec("CREATE TABLE IF NOT EXISTS companies (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(150) NOT NULL UNIQUE,
            slug VARCHAR(150) UNIQUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB");
        // Insert default company if empty
        $cnt = $pdo->query("SELECT COUNT(*) FROM companies")->fetchColumn();
        if ($cnt == 0) {
            $pdo->exec("INSERT INTO companies (name, slug) VALUES ('Default Company', 'default-company')");
        }
        $defaultId = $pdo->query("SELECT id FROM companies LIMIT 1")->fetchColumn();
        // Add company_id to users, jobs, sites, workers if not exists
        foreach (['users','jobs','sites','workers'] as $tbl) {
            $cols = $pdo->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$tbl' AND COLUMN_NAME = 'company_id'")->fetchColumn();
            if (!$cols) {
                $pdo->exec("ALTER TABLE `$tbl` ADD COLUMN company_id INT NULL");
                $pdo->exec("UPDATE `$tbl` SET company_id = $defaultId WHERE company_id IS NULL");
                try { $pdo->exec("ALTER TABLE `$tbl` ADD CONSTRAINT fk_{$tbl}_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE"); } catch (Exception $e) {}
                try { $pdo->exec("CREATE INDEX idx_{$tbl}_company ON `$tbl`(company_id)"); } catch (Exception $e) {}
            }
        }
        // Ensure company_id not null after backfill (allow null for legacy but set default)
        $pdo->prepare("INSERT INTO _migrations (name) VALUES (?)")->execute(['add_companies_multitenant']);
    }

    // Migration: company auth for MySQL
    if (!$hasMigration('add_company_auth')) {
        // Add password/email/phone to companies if not exists
        $cols = $pdo->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND COLUMN_NAME = 'password'")->fetchColumn();
        if (!$cols) $pdo->exec("ALTER TABLE companies ADD COLUMN password VARCHAR(255) NULL");
        $cols = $pdo->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND COLUMN_NAME = 'email'")->fetchColumn();
        if (!$cols) $pdo->exec("ALTER TABLE companies ADD COLUMN email VARCHAR(150) NULL");
        $cols = $pdo->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND COLUMN_NAME = 'phone'")->fetchColumn();
        if (!$cols) $pdo->exec("ALTER TABLE companies ADD COLUMN phone VARCHAR(30) NULL");
        // Backfill default password
        $defaultHash = password_hash('Company123', PASSWORD_DEFAULT);
        $pdo->exec("UPDATE companies SET password = " . $pdo->quote($defaultHash) . " WHERE password IS NULL");
        $pdo->prepare("INSERT INTO _migrations (name) VALUES (?)")->execute(['add_company_auth']);
    }

    // MySQL equivalent of site_supervisors sync (safe on every request)
    // Ensure site_supervisors table exists first
    $hasSS = $pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'site_supervisors'")->fetchColumn();
    if ($hasSS) {
        $pdo->exec("INSERT IGNORE INTO site_supervisors (site_id, user_id) SELECT id, supervisor_id FROM sites WHERE supervisor_id IS NOT NULL");
        $pdo->exec("DELETE FROM site_supervisors WHERE site_id NOT IN (SELECT id FROM sites) OR user_id NOT IN (SELECT id FROM users)");
    }

    // Migration: add transfer_requests table
    if (!$hasMigration('add_transfer_requests')) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS transfer_requests (
            id INT AUTO_INCREMENT PRIMARY KEY,
            worker_id INT NOT NULL,
            from_site_id INT NULL,
            to_site_id INT NOT NULL,
            requested_by INT NOT NULL,
            notes TEXT,
            status ENUM('pending','approved','rejected') DEFAULT 'pending',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            responded_at TIMESTAMP NULL,
            responded_by INT NULL,
            FOREIGN KEY (worker_id) REFERENCES workers(id) ON DELETE CASCADE,
            FOREIGN KEY (from_site_id) REFERENCES sites(id) ON DELETE SET NULL,
            FOREIGN KEY (to_site_id) REFERENCES sites(id) ON DELETE CASCADE,
            FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (responded_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB");
        $pdo->prepare("INSERT INTO _migrations (name) VALUES (?)")->execute(['add_transfer_requests']);
    }

    // Migration: add site_supervisors join table
    if (!$hasMigration('add_site_supervisors')) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS site_supervisors (
            site_id INT NOT NULL,
            user_id INT NOT NULL,
            PRIMARY KEY (site_id, user_id),
            FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB");
        $pdo->exec("INSERT IGNORE INTO site_supervisors (site_id, user_id) SELECT id, supervisor_id FROM sites WHERE supervisor_id IS NOT NULL");
        $pdo->prepare("INSERT INTO _migrations (name) VALUES (?)")->execute(['add_site_supervisors']);
    }
}

function migrate($pdo) {
    // Track applied migrations
    $pdo->exec("CREATE TABLE IF NOT EXISTS _migrations (id INTEGER PRIMARY KEY, name TEXT UNIQUE, applied_at TEXT DEFAULT CURRENT_TIMESTAMP)");

    // Multi-tenant companies migration - must run first
    $nameCompany = 'add_companies_multitenant';
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM _migrations WHERE name = ?");
    $stmt->execute([$nameCompany]);
    if (!$stmt->fetchColumn()) {
        // Create companies table
        $pdo->exec("CREATE TABLE IF NOT EXISTS companies (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL UNIQUE,
            slug TEXT UNIQUE,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        )");
        // Insert default company if empty
        $cnt = $pdo->query("SELECT COUNT(*) FROM companies")->fetchColumn();
        if ($cnt == 0) {
            $pdo->exec("INSERT INTO companies (name, slug) VALUES ('Default Company', 'default-company')");
        }
        $defaultCompanyId = $pdo->query("SELECT id FROM companies ORDER BY id LIMIT 1")->fetchColumn();
        if (!$defaultCompanyId) $defaultCompanyId = 1;

        // Helper to add company_id column if missing
        $tables = ['users','jobs','sites','workers'];
        foreach ($tables as $tbl) {
            // Check if table exists
            $exists = $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='$tbl'")->fetchColumn();
            if (!$exists) continue;
            $cols = $pdo->query("PRAGMA table_info($tbl)")->fetchAll(PDO::FETCH_ASSOC);
            $has = false;
            foreach ($cols as $c) { if ($c['name'] === 'company_id') $has = true; }
            if (!$has) {
                $pdo->exec("ALTER TABLE $tbl ADD COLUMN company_id INTEGER DEFAULT NULL");
                // backfill
                $pdo->exec("UPDATE $tbl SET company_id = $defaultCompanyId WHERE company_id IS NULL");
                // create index
                try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_{$tbl}_company ON $tbl(company_id)"); } catch (Exception $e) {}
            }
        }
        $pdo->prepare("INSERT INTO _migrations (name) VALUES (?)")->execute([$nameCompany]);
    }

    // Migration: add company auth (password, email, phone) for company-level login
    $nameCompanyAuth = 'add_company_auth';
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM _migrations WHERE name = ?");
    $stmt->execute([$nameCompanyAuth]);
    if (!$stmt->fetchColumn()) {
        $cols = $pdo->query("PRAGMA table_info(companies)")->fetchAll(PDO::FETCH_ASSOC);
        $hasPassword = false; $hasEmail = false; $hasPhone = false;
        foreach ($cols as $c) {
            if ($c['name'] === 'password') $hasPassword = true;
            if ($c['name'] === 'email') $hasEmail = true;
            if ($c['name'] === 'phone') $hasPhone = true;
        }
        if (!$hasPassword) $pdo->exec("ALTER TABLE companies ADD COLUMN password TEXT DEFAULT NULL");
        if (!$hasEmail) $pdo->exec("ALTER TABLE companies ADD COLUMN email TEXT DEFAULT NULL");
        if (!$hasPhone) $pdo->exec("ALTER TABLE companies ADD COLUMN phone TEXT DEFAULT NULL");
        // Backfill existing companies with default password Company123 (meets 8+ and number) if password null
        $defaultHash = password_hash('Company123', PASSWORD_DEFAULT);
        $pdo->exec("UPDATE companies SET password = '$defaultHash' WHERE password IS NULL");
        // Also ensure email not null for default companies - keep as is
        $pdo->prepare("INSERT INTO _migrations (name) VALUES (?)")->execute([$nameCompanyAuth]);
    }

    // Always keep site_supervisors in sync with sites.supervisor_id (runs on every request)
    // Ensure site_supervisors exists before sync
    $hasSS = $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='site_supervisors'")->fetchColumn();
    if ($hasSS) {
        $pdo->exec("INSERT OR IGNORE INTO site_supervisors (site_id, user_id) SELECT id, supervisor_id FROM sites WHERE supervisor_id IS NOT NULL");
        // Remove stale or incorrect links
        $pdo->exec("DELETE FROM site_supervisors WHERE site_id NOT IN (SELECT id FROM sites) OR user_id NOT IN (SELECT id FROM users)");
        // Correct wrong link: site 3 should NOT belong to user 4
        $pdo->prepare("DELETE FROM site_supervisors WHERE site_id = 3 AND user_id = 4")->execute();
    }

    // Migration: add transfer_requests table
    $name7 = 'add_transfer_requests';
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM _migrations WHERE name = ?");
    $stmt->execute([$name7]);
    if (!$stmt->fetchColumn()) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS transfer_requests (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                worker_id INTEGER NOT NULL,
                from_site_id INTEGER,
                to_site_id INTEGER NOT NULL,
                requested_by INTEGER NOT NULL,
                notes TEXT,
                status TEXT DEFAULT 'pending' CHECK(status IN ('pending','approved','rejected')),
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                responded_at TEXT,
                responded_by INTEGER,
                FOREIGN KEY (worker_id) REFERENCES workers(id) ON DELETE CASCADE,
                FOREIGN KEY (from_site_id) REFERENCES sites(id) ON DELETE SET NULL,
                FOREIGN KEY (to_site_id) REFERENCES sites(id) ON DELETE CASCADE,
                FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (responded_by) REFERENCES users(id) ON DELETE SET NULL
            )
        ");
        $pdo->prepare("INSERT INTO _migrations (name) VALUES (?)")->execute([$name7]);
    }

    // Migration: add site_supervisors join table
    $name5 = 'add_site_supervisors';
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM _migrations WHERE name = ?");
    $stmt->execute([$name5]);
    if (!$stmt->fetchColumn()) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS site_supervisors (
            site_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            PRIMARY KEY (site_id, user_id),
            FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )");
        // Backfill: sync every site that has a primary supervisor_id
        $pdo->exec("INSERT OR IGNORE INTO site_supervisors (site_id, user_id) SELECT id, supervisor_id FROM sites WHERE supervisor_id IS NOT NULL");
        // Clean orphan links
        $pdo->exec("DELETE FROM site_supervisors WHERE site_id NOT IN (SELECT id FROM sites) OR user_id NOT IN (SELECT id FROM users)");
        $pdo->prepare("INSERT INTO _migrations (name) VALUES (?)")->execute([$name5]);
    }

    // Migration: add user_management role guard
    $name6 = 'add_user_management';
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM _migrations WHERE name = ?");
    $stmt->execute([$name6]);
    if (!$stmt->fetchColumn()) {
        $cols = $pdo->query("PRAGMA table_info(users)")->fetchAll(PDO::FETCH_ASSOC);
        $hasPhone = false; $hasEmail = false;
        foreach ($cols as $c) { if ($c['name']==='phone') $hasPhone=true; if ($c['name']==='email') $hasEmail=true; }
        if (!$hasPhone) $pdo->exec("ALTER TABLE users ADD COLUMN phone TEXT DEFAULT NULL");
        if (!$hasEmail) $pdo->exec("ALTER TABLE users ADD COLUMN email TEXT DEFAULT NULL");
        // Idempotent seed: add super2 user if missing
        $hasSuper2 = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
        $hasSuper2->execute(['super2']);
        if (!$hasSuper2->fetchColumn()) {
            $defComp = $pdo->query("SELECT id FROM companies LIMIT 1")->fetchColumn() ?: 1;
            $stmt2 = $pdo->prepare("INSERT INTO users (company_id, username, password, full_name, role, phone, email) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt2->execute([$defComp, 'super2', password_hash('super123', PASSWORD_DEFAULT), 'John Doe', 'supervisor', '555-0003', 'john.doe@company.com']);
        }
        // Idempotent seed: add Site C and supervisor link if missing
        $hasSite3 = $pdo->query("SELECT COUNT(*) FROM sites WHERE id = 3")->fetchColumn();
        if (!$hasSite3) {
            $defComp = $pdo->query("SELECT id FROM companies LIMIT 1")->fetchColumn() ?: 1;
            $pdo->exec("INSERT INTO jobs (company_id, code, name, description, status) VALUES
                ('$defComp', 'JOB-003', 'Mall Renovation', 'Interior renovation of city mall', 'active')");
            $jobId = $pdo->lastInsertId();
            if (!$jobId) $jobId = $pdo->query("SELECT id FROM jobs WHERE code='JOB-003'")->fetchColumn();
            $pdo->exec("INSERT INTO sites (company_id, name, address, job_id, supervisor_id) VALUES
                ('$defComp', 'Site C - Mall', '456 Commerce Ave', $jobId ?: 3, 4)");
        }
        // Sync site_supervisors for new sites created by this migration
        $pdo->exec("INSERT OR IGNORE INTO site_supervisors (site_id, user_id) SELECT id, supervisor_id FROM sites WHERE supervisor_id IS NOT NULL");
        // Remove wrong link: site 3 should NOT be linked to user 4 (Omar) - it belongs to Sarah (user 3)
        $pdo->prepare("DELETE FROM site_supervisors WHERE site_id = 3 AND user_id = 4")->execute();
        $pdo->prepare("INSERT INTO _migrations (name) VALUES (?)")->execute([$name6]);
    }

    // Migration: add period_start and period_end to work_hours
    $name3 = 'add_work_hours_period';
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM _migrations WHERE name = ?");
    $stmt->execute([$name3]);
    if (!$stmt->fetchColumn()) {
        $cols = $pdo->query("PRAGMA table_info(work_hours)")->fetchAll(PDO::FETCH_ASSOC);
        $hasStart = false; $hasEnd = false;
        foreach ($cols as $c) {
            if ($c['name'] === 'period_start') $hasStart = true;
            if ($c['name'] === 'period_end') $hasEnd = true;
        }
        if (!$hasStart) $pdo->exec("ALTER TABLE work_hours ADD COLUMN period_start TEXT DEFAULT NULL");
        if (!$hasEnd) $pdo->exec("ALTER TABLE work_hours ADD COLUMN period_end TEXT DEFAULT NULL");
        $pdo->prepare("INSERT INTO _migrations (name) VALUES (?)")->execute([$name3]);
    }

    // Migration: add pause_reason to jobs and sites
    $name4 = 'add_pause_reason';
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM _migrations WHERE name = ?");
    $stmt->execute([$name4]);
    if (!$stmt->fetchColumn()) {
        $jobCols = $pdo->query("PRAGMA table_info(jobs)")->fetchAll(PDO::FETCH_ASSOC);
        $siteCols = $pdo->query("PRAGMA table_info(sites)")->fetchAll(PDO::FETCH_ASSOC);
        $hasJobReason = false; $hasSiteReason = false;
        foreach ($jobCols as $c) { if ($c['name'] === 'pause_reason') $hasJobReason = true; }
        foreach ($siteCols as $c) { if ($c['name'] === 'pause_reason') $hasSiteReason = true; }
        if (!$hasJobReason) $pdo->exec("ALTER TABLE jobs ADD COLUMN pause_reason TEXT DEFAULT NULL");
        if (!$hasSiteReason) $pdo->exec("ALTER TABLE sites ADD COLUMN pause_reason TEXT DEFAULT NULL");
        $pdo->prepare("INSERT INTO _migrations (name) VALUES (?)")->execute([$name4]);
    }

    // Migration: add employment_start and employment_end to workers
    $name2 = 'add_worker_employment_dates';
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM _migrations WHERE name = ?");
    $stmt->execute([$name2]);
    if (!$stmt->fetchColumn()) {
        $cols = $pdo->query("PRAGMA table_info(workers)")->fetchAll(PDO::FETCH_ASSOC);
        $hasStart = false;
        $hasEnd = false;
        foreach ($cols as $c) {
            if ($c['name'] === 'employment_start') $hasStart = true;
            if ($c['name'] === 'employment_end') $hasEnd = true;
        }
        if (!$hasStart) $pdo->exec("ALTER TABLE workers ADD COLUMN employment_start TEXT DEFAULT NULL");
        if (!$hasEnd) $pdo->exec("ALTER TABLE workers ADD COLUMN employment_end TEXT DEFAULT NULL");
        // Backfill: assigned/available workers get a start date 3 months ago, inactive/on_leave workers get an end date
        $pdo->exec("UPDATE workers SET employment_start = date('now', '-3 months') WHERE employment_start IS NULL AND status IN ('assigned','available')");
        $pdo->exec("UPDATE workers SET employment_start = date('now', '-6 months'), employment_end = date('now', '-1 month') WHERE employment_start IS NULL AND status = 'inactive'");
        $pdo->exec("UPDATE workers SET employment_start = date('now', '-6 months') WHERE employment_start IS NULL");
        $pdo->prepare("INSERT INTO _migrations (name) VALUES (?)")->execute([$name2]);
    }

    // Migration: add 'on_leave' to workers status CHECK constraint
    $name = 'add_on_leave_status';
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM _migrations WHERE name = ?");
    $stmt->execute([$name]);
    if (!$stmt->fetchColumn()) {
        $pdo->exec("PRAGMA foreign_keys = OFF");
        try {
            // Check if workers table already has on_leave in its schema — if so, mark done without changes
            $schema = $pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='workers'")->fetchColumn();
            if ($schema && strpos($schema, 'on_leave') !== false) {
                // Schema already correct, just mark migration done
                $pdo->prepare("INSERT INTO _migrations (name) VALUES (?)")->execute([$name]);
                $pdo->exec("PRAGMA foreign_keys = ON");
                return;
            }
            // Only rename if old table doesn't already exist (idempotent)
            $oldExists = $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE name='workers_old'")->fetchColumn();
            if (!$oldExists) {
                $pdo->exec("ALTER TABLE workers RENAME TO workers_old");
            }
            $pdo->exec("CREATE TABLE IF NOT EXISTS workers (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                first_name TEXT NOT NULL,
                last_name TEXT NOT NULL,
                role_title TEXT,
                phone TEXT,
                current_site_id INTEGER,
                status TEXT DEFAULT 'available' CHECK(status IN ('available','assigned','on_leave','inactive')),
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (current_site_id) REFERENCES sites(id) ON DELETE SET NULL
            )");
            $hasData = $pdo->query("SELECT COUNT(*) FROM workers")->fetchColumn();
            if ($hasData == 0) {
                $pdo->exec("INSERT INTO workers SELECT * FROM workers_old");
            }
            $pdo->exec("DROP TABLE IF EXISTS workers_old");
            // Also fix any tables that got their FKs broken by a previous partial run
            $brokenTables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND sql LIKE '%workers_old%'")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($brokenTables as $tbl) {
                $oldSchema = $pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='$tbl'")->fetchColumn();
                $newSchema = str_replace('REFERENCES "workers_old"', 'REFERENCES workers', $oldSchema);
                $pdo->exec("ALTER TABLE $tbl RENAME TO {$tbl}_backup");
                $pdo->exec(str_replace('REFERENCES workers_old', 'REFERENCES workers', $oldSchema));
                $pdo->exec("INSERT INTO $tbl SELECT * FROM {$tbl}_backup");
                $pdo->exec("DROP TABLE IF EXISTS {$tbl}_backup");
            }
            $pdo->exec("PRAGMA foreign_keys = ON");
            $pdo->prepare("INSERT INTO _migrations (name) VALUES (?)")->execute([$name]);
        } catch (Exception $e) {
            $pdo->exec("PRAGMA foreign_keys = ON");
            try {
                $pdo->exec("ALTER TABLE workers_old RENAME TO workers");
            } catch (Exception $e2) {
                // ignore
            }
            throw $e;
        }
    }
}

function initSchema($pdo) {
    // Companies first - multi-tenant root (company-level auth)
    $pdo->exec("
        CREATE TABLE companies (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL UNIQUE,
            slug TEXT UNIQUE,
            email TEXT DEFAULT NULL,
            phone TEXT DEFAULT NULL,
            password TEXT NOT NULL,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            company_id INTEGER NOT NULL,
            username TEXT NOT NULL,
            password TEXT NOT NULL,
            full_name TEXT NOT NULL,
            role TEXT NOT NULL CHECK(role IN ('manager','supervisor')),
            phone TEXT DEFAULT NULL,
            email TEXT DEFAULT NULL,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
            UNIQUE(company_id, username)
        );

        CREATE TABLE jobs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            company_id INTEGER NOT NULL,
            code TEXT NOT NULL,
            name TEXT NOT NULL,
            description TEXT,
            status TEXT DEFAULT 'active' CHECK(status IN ('active','paused','done')),
            pause_reason TEXT DEFAULT NULL,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
            UNIQUE(company_id, code)
        );

        CREATE TABLE sites (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            company_id INTEGER NOT NULL,
            name TEXT NOT NULL,
            address TEXT,
            job_id INTEGER,
            supervisor_id INTEGER,
            status TEXT DEFAULT 'active' CHECK(status IN ('active','paused','done')),
            client_name TEXT,
            budget REAL,
            job_code TEXT,
            job_description TEXT,
            pause_reason TEXT DEFAULT NULL,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
            FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE SET NULL,
            FOREIGN KEY (supervisor_id) REFERENCES users(id) ON DELETE SET NULL
        );

        CREATE TABLE workers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            company_id INTEGER NOT NULL,
            first_name TEXT NOT NULL,
            last_name TEXT NOT NULL,
            role_title TEXT,
            phone TEXT,
            current_site_id INTEGER,
            status TEXT DEFAULT 'available' CHECK(status IN ('available','assigned','on_leave','inactive')),
            employment_start TEXT DEFAULT NULL,
            employment_end TEXT DEFAULT NULL,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
            FOREIGN KEY (current_site_id) REFERENCES sites(id) ON DELETE SET NULL
        );

        CREATE TABLE assignments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            worker_id INTEGER NOT NULL,
            site_id INTEGER NOT NULL,
            start_date TEXT NOT NULL,
            end_date TEXT,
            notes TEXT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (worker_id) REFERENCES workers(id) ON DELETE CASCADE,
            FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
        );

        CREATE TABLE work_hours (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            worker_id INTEGER NOT NULL,
            site_id INTEGER NOT NULL,
            work_date TEXT NOT NULL,
            hours REAL NOT NULL,
            overtime_hours REAL DEFAULT 0,
            notes TEXT,
            entered_by INTEGER,
            period_start TEXT DEFAULT NULL,
            period_end TEXT DEFAULT NULL,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (worker_id) REFERENCES workers(id) ON DELETE CASCADE,
            FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE,
            FOREIGN KEY (entered_by) REFERENCES users(id) ON DELETE SET NULL
        );

        CREATE TABLE daily_reports (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            site_id INTEGER NOT NULL,
            supervisor_id INTEGER NOT NULL,
            report_date TEXT NOT NULL,
            work_progress TEXT,
            notes TEXT,
            weather TEXT,
            issues TEXT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE,
            FOREIGN KEY (supervisor_id) REFERENCES users(id) ON DELETE CASCADE
        );

        CREATE TABLE attendance (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            worker_id INTEGER NOT NULL,
            site_id INTEGER NOT NULL,
            attendance_date TEXT NOT NULL,
            status TEXT NOT NULL CHECK(status IN ('present','absent','late','sick')),
            notes TEXT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (worker_id) REFERENCES workers(id) ON DELETE CASCADE,
            FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
        );

        CREATE TABLE site_supervisors (
            site_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            PRIMARY KEY (site_id, user_id),
            FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        );

        CREATE TABLE transfer_requests (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            worker_id INTEGER NOT NULL,
            from_site_id INTEGER,
            to_site_id INTEGER NOT NULL,
            requested_by INTEGER NOT NULL,
            notes TEXT,
            status TEXT DEFAULT 'pending' CHECK(status IN ('pending','approved','rejected')),
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            responded_at TEXT,
            responded_by INTEGER,
            FOREIGN KEY (worker_id) REFERENCES workers(id) ON DELETE CASCADE,
            FOREIGN KEY (from_site_id) REFERENCES sites(id) ON DELETE SET NULL,
            FOREIGN KEY (to_site_id) REFERENCES sites(id) ON DELETE CASCADE,
            FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (responded_by) REFERENCES users(id) ON DELETE SET NULL
        );
    ");

    // Create migrations tracking
    $pdo->exec("CREATE TABLE IF NOT EXISTS _migrations (id INTEGER PRIMARY KEY, name TEXT UNIQUE, applied_at TEXT DEFAULT CURRENT_TIMESTAMP)");
    // Mark multi-tenant migration as done for fresh install
    $pdo->exec("INSERT OR IGNORE INTO _migrations (name) VALUES ('add_companies_multitenant'), ('add_company_auth'), ('add_site_supervisors'), ('add_transfer_requests'), ('add_user_management'), ('add_work_hours_period'), ('add_pause_reason'), ('add_worker_employment_dates'), ('add_on_leave_status')");

    // Seed default company with auth (password Company123 meets 8+ and number)
    $companyPass = password_hash('Company123', PASSWORD_DEFAULT);
    $stmtComp = $pdo->prepare("INSERT INTO companies (name, slug, email, phone, password) VALUES (?, ?, ?, ?, ?)");
    $stmtComp->execute(['Default Company', 'default-company', 'default@company.local', '555-0000', $companyPass]);
    $companyId = $pdo->lastInsertId();

    // Seed default users for that company
    $stmt = $pdo->prepare("INSERT INTO users (company_id, username, password, full_name, role, phone, email) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$companyId, 'manager', password_hash('admin123', PASSWORD_DEFAULT), 'Company Manager', 'manager', '555-0001', 'manager@company.com']);
    $stmt->execute([$companyId, 'supervisor', password_hash('super123', PASSWORD_DEFAULT), 'Site Supervisor', 'supervisor', '555-0002', 'supervisor@company.com']);
    $stmt->execute([$companyId, 'super2', password_hash('super123', PASSWORD_DEFAULT), 'John Doe', 'supervisor', '555-0003', 'john.doe@company.com']);

    // Seed sample job
    $pdo->exec("INSERT INTO jobs (company_id, code, name, description, status) VALUES
        ($companyId, 'JOB-001', 'Downtown Office Tower', '12-story office building construction', 'active'),
        ($companyId, 'JOB-002', 'Highway Bridge Repair', 'Structural repair and resurfacing', 'active'),
        ($companyId, 'JOB-003', 'Mall Renovation', 'Interior renovation of city mall', 'active')");

    // Seed sample sites (each supervisor manages different sites)
    $pdo->exec("INSERT INTO sites (company_id, name, address, job_id, supervisor_id) VALUES
        ($companyId, 'Site A - Downtown', '123 Main Street, Downtown', 1, 2),
        ($companyId, 'Site B - Bridge', 'Highway 40, KM 15', 2, 2),
        ($companyId, 'Site C - Mall', '456 Commerce Ave', 3, 4)");

    // Site-supervisor many-to-many (supervisor 2 manages Site A, supervisor 4 manages Site C)
    $pdo->exec("INSERT INTO site_supervisors (site_id, user_id) VALUES (1, 2), (2, 2), (3, 4)");

    // Seed sample workers
    $pdo->exec("INSERT INTO workers (company_id, first_name, last_name, role_title, phone, current_site_id) VALUES
        ($companyId, 'John', 'Smith', 'Mason', '555-0101', 1),
        ($companyId, 'Mike', 'Johnson', 'Electrician', '555-0102', 1),
        ($companyId, 'Carlos', 'Garcia', 'Carpenter', '555-0103', 2),
        ($companyId, 'Ahmed', 'Hassan', 'Plumber', '555-0104', 1),
        ($companyId, 'David', 'Brown', 'Welder', '555-0105', 2),
        ($companyId, 'Sara', 'Wilson', 'Painter', '555-0106', 3),
        ($companyId, 'Luis', 'Martinez', 'Tile Setter', '555-0107', 3)");
}

function isLoggedIn() {
    return isset($_SESSION['user_id']) && isset($_SESSION['company_id']);
}

function isCompanyLoggedIn() {
    return isset($_SESSION['company_id']);
}

function requireCompanyLogin() {
    if (!isCompanyLoggedIn()) {
        header('Location: login.php');
        exit;
    }
}

function requireUserLogin() {
    // alias for requireLogin but ensures company is also logged in; if only company is logged in, redirect to user login
    if (!isLoggedIn()) {
        if (isCompanyLoggedIn()) {
            header('Location: user_login.php');
        } else {
            header('Location: login.php');
        }
        exit;
    }
}

function requireLogin() {
    // Backward compat: if company not logged in -> company login, else if user not logged in -> user login
    if (!isCompanyLoggedIn()) {
        header('Location: login.php');
        exit;
    }
    if (!isset($_SESSION['user_id'])) {
        header('Location: user_login.php');
        exit;
    }
}

function requireRole($role) {
    requireLogin();
    if ($_SESSION['role'] !== $role) {
        header('Location: dashboard.php');
        exit;
    }
}

function currentUser() {
    if (!isLoggedIn()) return null;
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function currentCompany() {
    if (!isset($_SESSION['company_id'])) return null;
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT * FROM companies WHERE id = ?");
    $stmt->execute([$_SESSION['company_id']]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function getCurrentCompanyId() {
    if (isset($_SESSION['company_id'])) return (int)$_SESSION['company_id'];
    $u = currentUser();
    return $u ? (int)$u['company_id'] : 0;
}

function slugify($text) {
    $text = strtolower(trim($text));
    $text = preg_replace('/[^a-z0-9]+/', '-', $text);
    $text = trim($text, '-');
    return $text ?: 'company-'.time();
}

/**
 * Return array of site IDs the current user is allowed to see/edit.
 * Manager sees all sites of their company. Supervisor sees sites where they are listed in
 * site_supervisors OR sites.supervisor_id (legacy) matches their user id, filtered by company.
 */
function getMySiteIds($user) {
    if (!$user) return [];
    $pdo = getDB();
    $companyId = $user['company_id'] ?? getCurrentCompanyId();
    if ($user['role'] === 'manager') {
        $stmt = $pdo->prepare("SELECT id FROM sites WHERE company_id = ?");
        $stmt->execute([$companyId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
    $stmt = $pdo->prepare("
        SELECT id FROM sites WHERE company_id = ? AND id IN (
            SELECT site_id FROM site_supervisors WHERE user_id = ?
            UNION
            SELECT id FROM sites WHERE supervisor_id = ?
        )
    ");
    $stmt->execute([$companyId, $user['id'], $user['id']]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Comma-separated site ID list for use in SQL. Returns "0" if empty
 * so WHERE ... IN (0) matches nothing rather than breaking syntax.
 */
function siteIdsForSql($siteIds) {
    if (empty($siteIds)) return '0';
    return implode(',', array_map('intval', $siteIds));
}

function h($str) {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

function getJobCodes() {
    $pdo = getDB();
    $cid = getCurrentCompanyId();
    if ($cid) {
        $stmt = $pdo->prepare("SELECT DISTINCT job_code FROM sites WHERE company_id = ? AND job_code IS NOT NULL AND job_code != '' ORDER BY job_code");
        $stmt->execute([$cid]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
    $rows = $pdo->query("SELECT DISTINCT job_code FROM sites WHERE job_code IS NOT NULL AND job_code != '' ORDER BY job_code")->fetchAll(PDO::FETCH_COLUMN);
    return $rows;
}

function flash($msg = null, $type = 'success') {
    if ($msg !== null) {
        $_SESSION['flash'] = $msg;
        $_SESSION['flash_type'] = $type;
    } else {
        $f = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);
        return $f;
    }
}

function flashType() {
    $t = $_SESSION['flash_type'] ?? 'success';
    unset($_SESSION['flash_type']);
    return $t;
}
