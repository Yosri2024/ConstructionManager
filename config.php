<?php
// =============================================================
// Database Configuration & Bootstrap
// =============================================================
session_start();

define('DB_FILE', __DIR__ . '/data/site_management.db');

function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        $first = !file_exists(DB_FILE);
        $pdo = new PDO('sqlite:' . DB_FILE);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA foreign_keys = ON');
        if ($first) {
            initSchema($pdo);
        }
        // Run migrations on every request
        migrate($pdo);
    }
    return $pdo;
}

function migrate($pdo) {
    // Track applied migrations
    $pdo->exec("CREATE TABLE IF NOT EXISTS _migrations (id INTEGER PRIMARY KEY, name TEXT UNIQUE, applied_at TEXT DEFAULT CURRENT_TIMESTAMP)");

    // Always keep site_supervisors in sync with sites.supervisor_id (runs on every request)
    $pdo->exec("INSERT OR IGNORE INTO site_supervisors (site_id, user_id) SELECT id, supervisor_id FROM sites WHERE supervisor_id IS NOT NULL");
    // Remove stale or incorrect links
    $pdo->exec("DELETE FROM site_supervisors WHERE site_id NOT IN (SELECT id FROM sites) OR user_id NOT IN (SELECT id FROM users)");
    // Correct wrong link: site 3 should NOT belong to user 4
    $pdo->prepare("DELETE FROM site_supervisors WHERE site_id = 3 AND user_id = 4")->execute();

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
        $pdo->exec("ALTER TABLE users ADD COLUMN phone TEXT DEFAULT NULL");
        $pdo->exec("ALTER TABLE users ADD COLUMN email TEXT DEFAULT NULL");
        // Idempotent seed: add super2 user if missing
        $hasSuper2 = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
        $hasSuper2->execute(['super2']);
        if (!$hasSuper2->fetchColumn()) {
            $stmt = $pdo->prepare("INSERT INTO users (username, password, full_name, role, phone, email) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute(['super2', password_hash('super123', PASSWORD_DEFAULT), 'John Doe', 'supervisor', '555-0003', 'john.doe@company.com']);
        }
        // Idempotent seed: add Site C and supervisor link if missing
        $hasSite3 = $pdo->query("SELECT COUNT(*) FROM sites WHERE id = 3")->fetchColumn();
        if (!$hasSite3) {
            $pdo->exec("INSERT INTO jobs (code, name, description, status) VALUES
                ('JOB-003', 'Mall Renovation', 'Interior renovation of city mall', 'active')");
            $pdo->exec("INSERT INTO sites (name, address, job_id, supervisor_id) VALUES
                ('Site C - Mall', '456 Commerce Ave', 3, 4)");
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
    $pdo->exec("
        CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE NOT NULL,
            password TEXT NOT NULL,
            full_name TEXT NOT NULL,
            role TEXT NOT NULL CHECK(role IN ('manager','supervisor')),
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE jobs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            code TEXT UNIQUE NOT NULL,
            name TEXT NOT NULL,
            description TEXT,
            status TEXT DEFAULT 'active' CHECK(status IN ('active','paused','done')),
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE sites (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            address TEXT,
            job_id INTEGER,
            supervisor_id INTEGER,
            status TEXT DEFAULT 'active' CHECK(status IN ('active','paused','done')),
            client_name TEXT,
            budget REAL,
            job_code TEXT,
            job_description TEXT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE SET NULL,
            FOREIGN KEY (supervisor_id) REFERENCES users(id) ON DELETE SET NULL
        );

        CREATE TABLE workers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            first_name TEXT NOT NULL,
            last_name TEXT NOT NULL,
            role_title TEXT,
            phone TEXT,
            current_site_id INTEGER,
            status TEXT DEFAULT 'available' CHECK(status IN ('available','assigned','on_leave','inactive')),
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
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
    ");

    // Seed default users
    $stmt = $pdo->prepare("INSERT INTO users (username, password, full_name, role, phone, email) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute(['manager', password_hash('admin123', PASSWORD_DEFAULT), 'Company Manager', 'manager', '555-0001', 'manager@company.com']);
    $stmt->execute(['supervisor', password_hash('super123', PASSWORD_DEFAULT), 'Site Supervisor', 'supervisor', '555-0002', 'supervisor@company.com']);
    $stmt->execute(['super2', password_hash('super123', PASSWORD_DEFAULT), 'John Doe', 'supervisor', '555-0003', 'john.doe@company.com']);

    // Seed sample job
    $pdo->exec("INSERT INTO jobs (code, name, description, status) VALUES
        ('JOB-001', 'Downtown Office Tower', '12-story office building construction', 'active'),
        ('JOB-002', 'Highway Bridge Repair', 'Structural repair and resurfacing', 'active'),
        ('JOB-003', 'Mall Renovation', 'Interior renovation of city mall', 'active')");

    // Seed sample sites (each supervisor manages different sites)
    $pdo->exec("INSERT INTO sites (name, address, job_id, supervisor_id) VALUES
        ('Site A - Downtown', '123 Main Street, Downtown', 1, 2),
        ('Site B - Bridge', 'Highway 40, KM 15', 2, 2),
        ('Site C - Mall', '456 Commerce Ave', 3, 4)");

    // Site-supervisor many-to-many (supervisor 2 manages Site A, supervisor 4 manages Site C)
    $pdo->exec("INSERT INTO site_supervisors (site_id, user_id) VALUES (1, 2), (2, 2), (3, 4)");

    // Seed sample workers
    $pdo->exec("INSERT INTO workers (first_name, last_name, role_title, phone, current_site_id) VALUES
        ('John', 'Smith', 'Mason', '555-0101', 1),
        ('Mike', 'Johnson', 'Electrician', '555-0102', 1),
        ('Carlos', 'Garcia', 'Carpenter', '555-0103', 2),
        ('Ahmed', 'Hassan', 'Plumber', '555-0104', 1),
        ('David', 'Brown', 'Welder', '555-0105', 2),
        ('Sara', 'Wilson', 'Painter', '555-0106', 3),
        ('Luis', 'Martinez', 'Tile Setter', '555-0107', 3)");
}

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: login.php');
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

/**
 * Return array of site IDs the current user is allowed to see/edit.
 * Manager sees all. Supervisor sees sites where they are listed in
 * site_supervisors OR sites.supervisor_id (legacy) matches their user id.
 */
function getMySiteIds($user) {
    if (!$user) return [];
    $pdo = getDB();
    if ($user['role'] === 'manager') {
        $all = $pdo->query("SELECT id FROM sites")->fetchAll(PDO::FETCH_COLUMN);
        return $all;
    }
    $stmt = $pdo->prepare("
        SELECT id FROM sites WHERE id IN (
            SELECT site_id FROM site_supervisors WHERE user_id = ?
            UNION
            SELECT id FROM sites WHERE supervisor_id = ?
        )
    ");
    $stmt->execute([$user['id'], $user['id']]);
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
