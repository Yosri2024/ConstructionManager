-- =============================================================
-- Construction Site Management - MySQL Version
-- Use this if you want to migrate from SQLite to MySQL
-- =============================================================

CREATE DATABASE IF NOT EXISTS site_management
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE site_management;

-- ----------------------------
-- Users
-- ----------------------------
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    role ENUM('manager','supervisor') NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ----------------------------
-- Jobs / Projects
-- ----------------------------
CREATE TABLE jobs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) UNIQUE NOT NULL,
    name VARCHAR(150) NOT NULL,
    description TEXT,
    client_name VARCHAR(150),
    budget DECIMAL(12,2),
    status ENUM('active','paused','done') DEFAULT 'active',
    pause_reason TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ----------------------------
-- Construction Sites (includes job characteristics)
-- ----------------------------
CREATE TABLE sites (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    address VARCHAR(255),
    job_id INT NULL,
    supervisor_id INT NULL,
    status ENUM('active','paused','done') DEFAULT 'active',
    client_name VARCHAR(150),
    budget DECIMAL(12,2),
    job_code VARCHAR(50),
    job_description TEXT,
    pause_reason TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE SET NULL,
    FOREIGN KEY (supervisor_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ----------------------------
-- Workers
-- ----------------------------
CREATE TABLE workers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    first_name VARCHAR(50) NOT NULL,
    last_name VARCHAR(50) NOT NULL,
    role_title VARCHAR(80),
    phone VARCHAR(30),
    current_site_id INT NULL,
    status ENUM('available','assigned','on_leave','inactive') DEFAULT 'available',
    employment_start DATE NULL,
    employment_end DATE NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (current_site_id) REFERENCES sites(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ----------------------------
-- Worker Assignments
-- ----------------------------
CREATE TABLE assignments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    worker_id INT NOT NULL,
    site_id INT NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (worker_id) REFERENCES workers(id) ON DELETE CASCADE,
    FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ----------------------------
-- Work Hours
-- ----------------------------
CREATE TABLE work_hours (
    id INT AUTO_INCREMENT PRIMARY KEY,
    worker_id INT NOT NULL,
    site_id INT NOT NULL,
    work_date DATE NOT NULL,
    hours DECIMAL(5,2) NOT NULL,
    overtime_hours DECIMAL(5,2) DEFAULT 0,
    notes TEXT,
    entered_by INT NULL,
    period_start DATE NULL,
    period_end DATE NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (worker_id) REFERENCES workers(id) ON DELETE CASCADE,
    FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE,
    FOREIGN KEY (entered_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ----------------------------
-- Daily Reports
-- ----------------------------
CREATE TABLE daily_reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    site_id INT NOT NULL,
    supervisor_id INT NOT NULL,
    report_date DATE NOT NULL,
    work_progress TEXT,
    notes TEXT,
    weather VARCHAR(100),
    issues TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE,
    FOREIGN KEY (supervisor_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ----------------------------
-- Attendance
-- ----------------------------
CREATE TABLE attendance (
    id INT AUTO_INCREMENT PRIMARY KEY,
    worker_id INT NOT NULL,
    site_id INT NOT NULL,
    attendance_date DATE NOT NULL,
    status ENUM('present','absent','late','sick') NOT NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (worker_id) REFERENCES workers(id) ON DELETE CASCADE,
    FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ----------------------------
-- Future Assignments / Planning
-- ----------------------------
CREATE TABLE future_assignments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    worker_id INT NOT NULL,
    site_id INT NOT NULL,
    planned_date DATE NOT NULL,
    end_date DATE NULL,
    status ENUM('planned','confirmed','cancelled') DEFAULT 'planned',
    notes TEXT,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (worker_id) REFERENCES workers(id) ON DELETE CASCADE,
    FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- =============================================================
-- Seed Data
-- =============================================================
-- Passwords: manager/admin123, supervisor/super123
-- The bcrypt placeholder hashes below match the running app.
-- If login fails, regenerate in PHP:
--   echo password_hash('admin123', PASSWORD_DEFAULT);

-- Reset all data for a clean seed
SET FOREIGN_KEY_CHECKS = 0;
TRUNCATE TABLE future_assignments;
TRUNCATE TABLE attendance;
TRUNCATE TABLE daily_reports;
TRUNCATE TABLE work_hours;
TRUNCATE TABLE assignments;
TRUNCATE TABLE workers;
TRUNCATE TABLE sites;
TRUNCATE TABLE jobs;
TRUNCATE TABLE users;
SET FOREIGN_KEY_CHECKS = 1;

-- ============== USERS ==============
INSERT INTO users (username, password, full_name, role) VALUES
('manager',     '$2y$10$9.XaCiyBtkN3gNVp3rL/bejlDfn7InbKwchmubGPADKOzeYVKaFzy', 'Company Manager', 'manager'),
('supervisor',  '$2y$10$YQiVqBJxqXvZ6M8K0kqJXOZ7mN8QYZ5yK0QZ5yK0QZ5yK0QZ5yK0Q', 'Site Supervisor', 'supervisor'),
('supervisor2', '$2y$10$YQiVqBJxqXvZ6M8K0kqJXOZ7mN8QYZ5yK0QZ5yK0QZ5yK0QZ5yK0Q', 'Sarah Johnson',   'supervisor'),
('supervisor3', '$2y$10$YQiVqBJxqXvZ6M8K0kqJXOZ7mN8QYZ5yK0QZ5yK0QZ5yK0QZ5yK0Q', 'Omar Khalil',     'supervisor');

-- ============== JOBS / PROJECTS ==============
INSERT INTO jobs (code, name, description, client_name, budget, status) VALUES
('JOB-001', 'Downtown Office Tower',     '12-story office building construction',         'Acme Corp',        4500000.00, 'active'),
('JOB-002', 'Highway Bridge Repair',     'Structural repair and resurfacing of KM 15',    'DOT',              1850000.00, 'active'),
('JOB-003', 'Riverside Apartments',      '40-unit residential complex with parking',      'GreenBuilt LLC',   3200000.00, 'active'),
('JOB-004', 'Shopping Mall Renovation',  'Interior fit-out and HVAC upgrade',             'Mall Holdings',    1100000.00, 'paused'),
('JOB-005', 'Industrial Warehouse',      'New 50,000 sqft warehouse and loading docks',  'LogiCo',           2750000.00, 'active'),
('JOB-006', 'School Extension',          'Two-floor classroom block and cafeteria',       'City Schools',      980000.00, 'done'),
('JOB-007', 'Hospital Wing',             'New 3-floor patient wing and emergency dept',  'Regional Health',  6200000.00, 'active'),
('JOB-008', 'Stadium Renovation',        'Seating, lighting and field replacement',       'City Sports',      3400000.00, 'paused');

-- ============== SITES ==============
INSERT INTO sites (name, address, job_id, supervisor_id, status, client_name, budget, job_code, job_description, pause_reason) VALUES
('Site A - Downtown Tower',  '123 Main Street, Downtown',       1, 2, 'active', 'Acme Corp',        4500000.00, 'JOB-001', '12-story office building construction', NULL),
('Site B - Highway Bridge',  'Highway 40, KM 15',               2, 2, 'active', 'DOT',              1850000.00, 'JOB-002', 'Structural repair and resurfacing',     NULL),
('Site C - Riverside',       '88 River Road, Northbank',        3, 3, 'active', 'GreenBuilt LLC',   3200000.00, 'JOB-003', '40-unit residential complex',            NULL),
('Site D - Mall',            '500 Commerce Blvd',               4, 2, 'paused', 'Mall Holdings',    1100000.00, 'JOB-004', 'Interior fit-out and HVAC upgrade',      'Project on hold pending client decision and revised scope'),
('Site E - Industrial Park', 'LogiCo Park, Lot 12',             5, 4, 'active', 'LogiCo',           2750000.00, 'JOB-005', 'New 50,000 sqft warehouse',              NULL),
('Site F - Westside School', '250 Education Drive',             6, 3, 'done',   'City Schools',      980000.00, 'JOB-006', 'Two-floor classroom block',              NULL),
('Site G - North Hospital',  '900 Health Way, Northside',       7, 4, 'active', 'Regional Health',  6200000.00, 'JOB-007', 'New 3-floor patient wing',               NULL),
('Site H - Stadium',         '1 Stadium Drive',                 8, 3, 'paused', 'City Sports',      3400000.00, 'JOB-008', 'Seating and field replacement',          'Awaiting City Sports budget approval');

-- ============== WORKERS ==============
INSERT INTO workers (first_name, last_name, role_title, phone, current_site_id, status, employment_start, employment_end) VALUES
-- Site A (Downtown Tower) - 5 workers
('John',    'Smith',      'Mason',        '555-0101', 1, 'assigned', '2024-03-15', NULL),
('Mike',    'Johnson',    'Electrician',  '555-0102', 1, 'assigned', '2024-06-01', NULL),
('Ahmed',   'Hassan',     'Plumber',      '555-0104', 1, 'assigned', '2025-01-10', NULL),
('Liam',    'OBrien',     'Foreman',      '555-0107', 1, 'assigned', '2023-08-20', NULL),
('Pedro',   'Martinez',   'Laborer',      '555-0110', 1, 'available', '2025-09-05', NULL),
-- Site B (Highway Bridge) - 4 workers
('Carlos',  'Garcia',     'Carpenter',    '555-0103', 2, 'assigned', '2024-04-22', NULL),
('David',   'Brown',      'Welder',       '555-0105', 2, 'on_leave',  '2024-02-10', '2026-09-15'),
('Hassan',  'Yousef',     'Operator',     '555-0109', 2, 'assigned', '2025-03-12', NULL),
('Sam',     'Patel',      'Mason',        '555-0113', 2, 'assigned', '2025-07-18', NULL),
-- Site C (Riverside Apartments) - 5 workers
('Maria',   'Lopez',      'Electrician',  '555-0106', 3, 'assigned', '2024-09-03', NULL),
('Kenji',   'Tanaka',     'Carpenter',    '555-0108', 3, 'assigned', '2024-11-20', NULL),
('Aisha',   'Mohamed',    'Plumber',      '555-0111', 3, 'on_leave', '2024-05-15', '2026-09-10'),
('Tomas',   'Novak',      'Foreman',      '555-0114', 3, 'assigned', '2023-11-05', NULL),
('Fatima',  'Al-Sayed',   'Welder',       '555-0117', 3, 'assigned', '2025-04-08', NULL),
-- Site D (Mall) - 2 workers (paused site)
('James',   'Wilson',     'Mason',        '555-0112', 4, 'available', '2025-06-22', NULL),
('Elena',   'Petrova',    'Operator',     '555-0115', 4, 'on_leave', '2024-12-01', '2026-10-01'),
-- Site E (Industrial Park) - 4 workers
('Robert',  'Anderson',   'Electrician',  '555-0116', 5, 'assigned', '2024-08-14', NULL),
('Wei',     'Zhang',      'Welder',       '555-0118', 5, 'assigned', '2025-02-19', NULL),
('Diego',   'Silva',      'Crane Op',     '555-0121', 5, 'assigned', '2024-10-07', NULL),
('Yuki',    'Sato',       'Foreman',      '555-0122', 5, 'assigned', '2023-04-30', NULL),
-- Site F (School) - 1 worker (done site)
('George',  'Miller',     'Carpenter',    '555-0123', 6, 'available', '2024-03-12', '2026-07-30'),
-- Site G (Hospital) - 3 workers
('Sofia',   'Rossi',      'Electrician',  '555-0124', 7, 'assigned', '2025-05-25', NULL),
('Ravi',    'Kumar',      'Mason',        '555-0125', 7, 'assigned', '2024-07-09', NULL),
('Hans',    'Mueller',    'Plumber',      '555-0126', 7, 'assigned', '2025-08-01', NULL),
-- Unassigned
('Olivia',  'Bennett',    'Carpenter',    '555-0119', NULL, 'available', '2026-01-15', NULL),
('Marcus',  'Williams',   'Laborer',      '555-0120', NULL, 'inactive',  '2024-06-01', '2026-04-30'),
('Priya',   'Singh',      'Electrician',  '555-0127', NULL, 'available', '2026-02-20', NULL),
('Lukas',   'Kowalski',   'Welder',       '555-0128', NULL, 'available', '2026-03-10', NULL);

-- ============== ASSIGNMENTS (history + current) ==============
INSERT INTO assignments (worker_id, site_id, start_date, end_date, notes) VALUES
-- Currently open assignments
(1,  1, '2026-07-01', NULL,          'Masonry block A - floors 1-4'),
(2,  1, '2026-07-01', NULL,          'Wiring for floors 1-3'),
(3,  1, '2026-08-15', NULL,          'Plumbing risers'),
(4,  1, '2026-06-15', NULL,          'Site A lead foreman'),
(6,  2, '2026-07-10', NULL,          'Formwork and shoring'),
(7,  2, '2026-07-10', '2026-09-15',  'Steel welding - on medical leave'),
(8,  2, '2026-08-01', NULL,          'Heavy equipment operator'),
(9,  2, '2026-08-15', NULL,          'Pier masonry'),
(10, 3, '2026-08-01', NULL,          'Apartment electrical rough-in'),
(11, 3, '2026-08-01', NULL,          'Cabinet installation'),
(12, 3, '2026-08-20', '2026-09-10',  'Plumbing - on personal leave'),
(13, 3, '2026-08-01', NULL,          'Site C lead foreman'),
(14, 3, '2026-08-15', NULL,          'Structural welding'),
(17, 5, '2026-08-25', NULL,          'Industrial electrical rough-in'),
(18, 5, '2026-08-25', NULL,          'Steel framework welding'),
(19, 5, '2026-08-28', NULL,          'Crane operator - steel lifts'),
(20, 5, '2026-08-25', NULL,          'Site E lead foreman'),
(22, 7, '2026-08-20', NULL,          'Hospital wing electrical'),
(23, 7, '2026-08-20', NULL,          'Masonry for patient wing'),
(24, 7, '2026-08-20', NULL,          'Plumbing rough-in'),
-- Completed/past assignments (for history)
(15, 4, '2026-06-01', '2026-08-15',  'Mall renovation - on hold'),
(16, 4, '2026-06-01', '2026-08-15',  'Mall renovation - on hold'),
(21, 6, '2026-04-01', '2026-07-30',  'School extension - completed');

-- ============== WORK HOURS ==============
INSERT INTO work_hours (worker_id, site_id, work_date, hours, overtime_hours, notes, entered_by) VALUES
-- ===== Today (2026-08-31) =====
(1,  1, '2026-08-31', 8.00, 0.00, 'Regular shift',                2),
(2,  1, '2026-08-31', 8.00, 0.00, 'Regular shift',                2),
(3,  1, '2026-08-31', 7.50, 0.00, 'Left early - appointment',     2),
(4,  1, '2026-08-31', 9.00, 0.00, 'Foreman duties',               2),
(6,  2, '2026-08-31', 8.00, 0.00, 'Formwork',                     2),
(8,  2, '2026-08-31', 8.00, 0.00, 'Regular shift',                2),
(9,  2, '2026-08-31', 8.00, 0.00, 'Regular shift',                2),
(10, 3, '2026-08-31', 8.00, 0.00, 'Regular shift',                3),
(11, 3, '2026-08-31', 8.00, 0.00, 'Cabinets',                     3),
(13, 3, '2026-08-31', 9.00, 0.00, 'Foreman',                      3),
(14, 3, '2026-08-31', 8.00, 0.00, 'Regular shift',                3),
(17, 5, '2026-08-31', 8.00, 0.00, 'Conduit run',                  4),
(18, 5, '2026-08-31', 8.00, 1.00, 'Late welding pass',            4),
(19, 5, '2026-08-31', 8.00, 0.00, 'Crane operation',              4),
(20, 5, '2026-08-31', 9.00, 0.00, 'Foreman',                      4),
(22, 7, '2026-08-31', 8.00, 0.00, 'Wiring hospital wing',         4),
(23, 7, '2026-08-31', 8.00, 0.00, 'Masonry patient wing',         4),
(24, 7, '2026-08-31', 8.00, 0.00, 'Plumbing rough-in',            4),
-- ===== Yesterday (2026-08-30) =====
(1,  1, '2026-08-30', 8.00, 1.50, 'Stayed late to finish pour',   2),
(2,  1, '2026-08-30', 8.00, 0.00, 'Regular shift',                2),
(3,  1, '2026-08-30', 8.00, 0.00, 'Regular shift',                2),
(4,  1, '2026-08-30', 9.00, 0.00, 'Foreman',                      2),
(6,  2, '2026-08-30', 8.00, 2.00, 'Concrete pour',                2),
(8,  2, '2026-08-30', 8.00, 0.00, 'Regular shift',                2),
(9,  2, '2026-08-30', 8.00, 0.00, 'Regular shift',                2),
(10, 3, '2026-08-30', 8.00, 0.00, 'Regular shift',                3),
(11, 3, '2026-08-30', 8.00, 0.00, 'Cabinets',                     3),
(13, 3, '2026-08-30', 9.00, 0.00, 'Foreman',                      3),
(14, 3, '2026-08-30', 8.00, 0.00, 'Welding',                      3),
(17, 5, '2026-08-30', 8.00, 0.00, 'Conduit',                      4),
(18, 5, '2026-08-30', 8.00, 0.00, 'Welding',                      4),
(20, 5, '2026-08-30', 9.00, 0.00, 'Foreman',                      4),
(22, 7, '2026-08-30', 8.00, 0.00, 'Wiring',                       4),
(24, 7, '2026-08-30', 8.00, 0.00, 'Plumbing',                     4),
-- ===== 2026-08-29 =====
(1,  1, '2026-08-29', 8.00, 0.00, 'Regular shift',                2),
(2,  1, '2026-08-29', 8.00, 0.00, 'Regular shift',                2),
(3,  1, '2026-08-29', 8.00, 0.00, 'Regular shift',                2),
(4,  1, '2026-08-29', 9.00, 0.00, 'Foreman',                      2),
(6,  2, '2026-08-29', 8.00, 0.00, 'Formwork',                     2),
(8,  2, '2026-08-29', 8.00, 0.00, 'Regular shift',                2),
(10, 3, '2026-08-29', 8.00, 0.00, 'Regular shift',                3),
(13, 3, '2026-08-29', 9.00, 0.00, 'Foreman',                      3),
(17, 5, '2026-08-29', 8.00, 0.00, 'Conduit',                      4),
(19, 5, '2026-08-29', 8.00, 1.00, 'Crane - heavy lift',           4),
(22, 7, '2026-08-29', 8.00, 0.00, 'Wiring',                       4),
(23, 7, '2026-08-29', 8.00, 0.00, 'Masonry',                      4),
-- ===== 2026-08-28 =====
(1,  1, '2026-08-28', 8.00, 0.00, 'Regular shift',                2),
(2,  1, '2026-08-28', 8.00, 0.00, 'Regular shift',                2),
(3,  1, '2026-08-28', 8.00, 0.00, 'Plumbing',                     2),
(4,  1, '2026-08-28', 9.00, 0.00, 'Foreman',                      2),
(6,  2, '2026-08-28', 8.00, 0.00, 'Regular shift',                2),
(8,  2, '2026-08-28', 8.00, 0.00, 'Regular shift',                2),
(9,  2, '2026-08-28', 8.00, 0.00, 'Regular shift',                2),
(10, 3, '2026-08-28', 8.00, 0.00, 'Regular shift',                3),
(11, 3, '2026-08-28', 8.00, 0.00, 'Cabinets',                     3),
(13, 3, '2026-08-28', 9.00, 0.00, 'Foreman',                      3),
(14, 3, '2026-08-28', 8.00, 0.00, 'Welding',                      3),
(17, 5, '2026-08-28', 8.00, 0.00, 'Conduit',                      4),
(18, 5, '2026-08-28', 8.00, 0.00, 'Welding',                      4),
(20, 5, '2026-08-28', 9.00, 0.00, 'Foreman',                      4),
(22, 7, '2026-08-28', 8.00, 0.00, 'Wiring',                       4),
(24, 7, '2026-08-28', 8.00, 0.00, 'Plumbing',                     4),
-- ===== 2026-08-27 =====
(1,  1, '2026-08-27', 8.00, 0.00, 'Regular shift',                2),
(2,  1, '2026-08-27', 8.00, 0.00, 'Regular shift',                2),
(4,  1, '2026-08-27', 9.00, 0.00, 'Foreman',                      2),
(6,  2, '2026-08-27', 8.00, 0.00, 'Regular shift',                2),
(8,  2, '2026-08-27', 8.00, 0.00, 'Regular shift',                2),
(10, 3, '2026-08-27', 8.00, 0.00, 'Regular shift',                3),
(13, 3, '2026-08-27', 9.00, 0.00, 'Foreman',                      3),
(17, 5, '2026-08-27', 8.00, 0.00, 'Conduit',                      4),
(22, 7, '2026-08-27', 8.00, 0.00, 'Wiring',                       4),
-- ===== 2026-08-26 =====
(1,  1, '2026-08-26', 8.00, 0.00, 'Regular shift',                2),
(2,  1, '2026-08-26', 7.00, 0.00, 'Bus delay - late',             2),
(3,  1, '2026-08-26', 8.00, 0.00, 'Regular shift',                2),
(4,  1, '2026-08-26', 9.00, 0.00, 'Foreman',                      2),
(6,  2, '2026-08-26', 8.00, 0.00, 'Regular shift',                2),
(9,  2, '2026-08-26', 8.00, 0.00, 'Regular shift',                2),
(10, 3, '2026-08-26', 8.00, 0.00, 'Regular shift',                3),
(13, 3, '2026-08-26', 9.00, 0.00, 'Foreman',                      3),
(17, 5, '2026-08-26', 8.00, 0.00, 'Conduit',                      4),
(18, 5, '2026-08-26', 8.00, 0.00, 'Welding',                      4),
(20, 5, '2026-08-26', 9.00, 0.00, 'Foreman',                      4),
-- ===== 2026-08-25 =====
(1,  1, '2026-08-25', 8.00, 0.00, 'Regular shift',                2),
(2,  1, '2026-08-25', 8.00, 0.00, 'Regular shift',                2),
(4,  1, '2026-08-25', 9.00, 0.00, 'Foreman',                      2),
(6,  2, '2026-08-25', 8.00, 0.00, 'Regular shift',                2),
(8,  2, '2026-08-25', 8.00, 0.00, 'Regular shift',                2),
(10, 3, '2026-08-25', 8.00, 0.00, 'Regular shift',                3),
(13, 3, '2026-08-25', 9.00, 0.00, 'Foreman',                      3),
(17, 5, '2026-08-25', 8.00, 0.00, 'Conduit',                      4),
(20, 5, '2026-08-25', 9.00, 0.00, 'Foreman',                      4),
-- ===== Previous week (2026-08-21) =====
(1,  1, '2026-08-21', 8.00, 0.00, 'Regular shift',                2),
(2,  1, '2026-08-21', 8.00, 0.00, 'Regular shift',                2),
(4,  1, '2026-08-21', 9.00, 0.00, 'Foreman',                      2),
(6,  2, '2026-08-21', 8.00, 2.50, 'Pour day',                     2),
(10, 3, '2026-08-21', 8.00, 0.00, 'Regular shift',                3),
(13, 3, '2026-08-21', 9.00, 0.00, 'Foreman',                      3),
(17, 5, '2026-08-21', 8.00, 0.00, 'Conduit',                      4),
(22, 7, '2026-08-21', 8.00, 0.00, 'Wiring',                       4),
-- ===== Two weeks ago (2026-08-14) =====
(1,  1, '2026-08-14', 8.00, 0.00, 'Regular shift',                2),
(2,  1, '2026-08-14', 8.00, 0.00, 'Regular shift',                2),
(4,  1, '2026-08-14', 9.00, 0.00, 'Foreman',                      2),
(6,  2, '2026-08-14', 8.00, 0.00, 'Regular shift',                2),
(10, 3, '2026-08-14', 8.00, 0.00, 'Regular shift',                3),
(13, 3, '2026-08-14', 9.00, 0.00, 'Foreman',                      3),
(17, 5, '2026-08-14', 8.00, 0.00, 'Conduit',                      4),
-- ===== Three weeks ago (2026-08-07) =====
(1,  1, '2026-08-07', 8.00, 0.00, 'Regular shift',                2),
(2,  1, '2026-08-07', 8.00, 0.00, 'Regular shift',                2),
(4,  1, '2026-08-07', 9.00, 0.00, 'Foreman',                      2),
(10, 3, '2026-08-07', 8.00, 0.00, 'Regular shift',                3),
(13, 3, '2026-08-07', 9.00, 0.00, 'Foreman',                      3);

-- ============== DAILY REPORTS ==============
INSERT INTO daily_reports (site_id, supervisor_id, report_date, work_progress, notes, weather, issues) VALUES
-- ===== Today =====
(1, 2, '2026-08-31', 'Completed masonry on floors 1-2, started floor 3 columns', 'Crew of 5 working well',        'Sunny, 28C', NULL),
(2, 2, '2026-08-31', 'Formwork for pier 4 complete, started pier 5',            'Worker David on medical leave',  'Sunny, 29C', 'Welder shortage'),
(3, 3, '2026-08-31', 'Cabinets installed in units 1-8',                         'Good progress',                  'Sunny, 27C', NULL),
(5, 4, '2026-08-31', 'Steel framework 60% complete',                            'Welding progressing well',       'Sunny, 26C', NULL),
(7, 4, '2026-08-31', 'Foundation poured for patient wing',                      'Inspection scheduled',           'Sunny, 25C', NULL),
(4, 2, '2026-08-31', 'Project on hold pending client decision',                  'Awaiting revised scope',         'N/A',         'Project paused - client review'),
(6, 3, '2026-08-30', 'Final inspection scheduled for next week',                 'Project substantially complete', 'Sunny, 25C', NULL),
-- ===== Yesterday =====
(1, 2, '2026-08-30', 'Poured concrete for floor 2 slab',                       'Pour completed by 16:00',       'Sunny, 30C', 'Crane delayed by 1 hour'),
(2, 2, '2026-08-30', 'Concrete pour on pier 4 - 18 cubic meters',               'Pour ran 2 hours over',          'Sunny, 31C', NULL),
(3, 3, '2026-08-30', 'Electrical rough-in units 1-12',                          'Ahead of schedule',              'Sunny, 28C', NULL),
(5, 4, '2026-08-30', 'Loading dock foundation poured',                          'Cement delivery on time',        'Sunny, 27C', NULL),
(7, 4, '2026-08-30', 'Masonry walls for emergency department',                   'On schedule',                    'Sunny, 26C', NULL),
-- ===== 2026-08-29 =====
(1, 2, '2026-08-29', 'Set rebar and formwork for floor 2',                     'On schedule',                    'Partly cloudy, 26C', NULL),
(2, 2, '2026-08-29', 'Continued pier 3 work',                                   'Awaiting welder return',         'Sunny, 27C', 'Welder on leave'),
(3, 3, '2026-08-29', 'Plumbing rough-in units 1-10',                            'Plumber Aisha on leave',         'Rain, 22C',  'Plumber shortage'),
(5, 4, '2026-08-29', 'Conduit run for main electrical room',                    'No issues',                      'Cloudy, 25C', NULL),
(7, 4, '2026-08-29', 'Excavation complete for east wing',                       'Found old utility lines',        'Sunny, 24C', 'Coordination with city required'),
-- ===== 2026-08-28 =====
(1, 2, '2026-08-28', 'Finished plumbing risers through floor 1',                'Inspector approved rough-in',    'Sunny, 27C', NULL),
(2, 2, '2026-08-28', 'Pier 3 formwork stripped, all on spec',                   'Inspection passed',              'Cloudy, 24C', NULL),
(3, 3, '2026-08-28', 'Framing complete on building B',                          'All inspections passed',         'Partly cloudy, 25C', NULL),
(5, 4, '2026-08-28', 'Steel columns erected - sections A and B',                'Crane work completed safely',    'Sunny, 26C', NULL),
(7, 4, '2026-08-28', 'Site survey and stakeout complete',                       'Ready for excavation',           'Sunny, 25C', NULL),
-- ===== 2026-08-27 =====
(1, 2, '2026-08-27', 'Concrete pour for foundation footing',                    'Pour finished early afternoon',  'Sunny, 29C', NULL),
(2, 2, '2026-08-27', 'Pier 2 reinforcement tied',                               'Inspection scheduled tomorrow',  'Partly cloudy, 27C', NULL),
(3, 3, '2026-08-27', 'Drywall started in units 1-4',                            'Plastering crew on standby',     'Sunny, 28C', NULL),
(5, 4, '2026-08-27', 'Site prep and leveling',                                   'Heavy equipment on site',        'Sunny, 27C', NULL),
(7, 4, '2026-08-27', 'Initial site clearing complete',                          'Tree removal coordinated',       'Sunny, 26C', NULL),
-- ===== 2026-08-26 =====
(1, 2, '2026-08-26', 'Excavation for elevator pit',                             'Coordinated with surveyor',      'Sunny, 28C', NULL),
(2, 2, '2026-08-26', 'Pier 1 formwork complete',                                'Pour scheduled for next week',   'Sunny, 30C', NULL),
(3, 3, '2026-08-26', 'Roof trusses delivered and staged',                       'Crane scheduled for installation','Sunny, 27C', NULL),
(5, 4, '2026-08-26', 'Foundation layout and marking',                            'Surveyor on site',               'Sunny, 26C', NULL),
-- ===== 2026-08-25 =====
(1, 2, '2026-08-25', 'Continued foundation prep',                                'Concrete delivery confirmed',    'Partly cloudy, 25C', NULL),
(2, 2, '2026-08-25', 'Pier excavation complete',                                'Reinforcement delivery tomorrow','Sunny, 28C', NULL),
(3, 3, '2026-08-25', 'Framing walls 80% complete',                              'On schedule for roof install',   'Sunny, 27C', NULL);

-- ============== ATTENDANCE (last 7 days) ==============
INSERT INTO attendance (worker_id, site_id, attendance_date, status, notes) VALUES
-- ===== Today (2026-08-31) =====
(1,  1, '2026-08-31', 'present', NULL),
(2,  1, '2026-08-31', 'present', NULL),
(3,  1, '2026-08-31', 'late',    'Traffic - arrived 09:30'),
(4,  1, '2026-08-31', 'present', NULL),
(6,  2, '2026-08-31', 'present', NULL),
(8,  2, '2026-08-31', 'present', NULL),
(9,  2, '2026-08-31', 'present', NULL),
(10, 3, '2026-08-31', 'present', NULL),
(11, 3, '2026-08-31', 'present', NULL),
(13, 3, '2026-08-31', 'present', NULL),
(14, 3, '2026-08-31', 'present', NULL),
(17, 5, '2026-08-31', 'present', NULL),
(18, 5, '2026-08-31', 'present', NULL),
(19, 5, '2026-08-31', 'present', NULL),
(20, 5, '2026-08-31', 'present', NULL),
(22, 7, '2026-08-31', 'present', NULL),
(23, 7, '2026-08-31', 'present', NULL),
(24, 7, '2026-08-31', 'present', NULL),
-- ===== Yesterday (2026-08-30) =====
(1,  1, '2026-08-30', 'present', NULL),
(2,  1, '2026-08-30', 'present', NULL),
(3,  1, '2026-08-30', 'present', NULL),
(4,  1, '2026-08-30', 'present', NULL),
(6,  2, '2026-08-30', 'present', NULL),
(7,  2, '2026-08-30', 'absent',  'Sick - medical leave'),
(8,  2, '2026-08-30', 'present', NULL),
(9,  2, '2026-08-30', 'present', NULL),
(10, 3, '2026-08-30', 'present', NULL),
(12, 3, '2026-08-30', 'absent',  'Personal leave'),
(13, 3, '2026-08-30', 'present', NULL),
(14, 3, '2026-08-30', 'present', NULL),
(17, 5, '2026-08-30', 'present', NULL),
(18, 5, '2026-08-30', 'late',    'Equipment delay'),
(19, 5, '2026-08-30', 'present', NULL),
(20, 5, '2026-08-30', 'present', NULL),
(22, 7, '2026-08-30', 'present', NULL),
(23, 7, '2026-08-30', 'present', NULL),
(24, 7, '2026-08-30', 'present', NULL),
-- ===== 2026-08-29 =====
(1,  1, '2026-08-29', 'present', NULL),
(2,  1, '2026-08-29', 'present', NULL),
(4,  1, '2026-08-29', 'present', NULL),
(6,  2, '2026-08-29', 'present', NULL),
(8,  2, '2026-08-29', 'present', NULL),
(10, 3, '2026-08-29', 'late',    'Stuck in rain traffic'),
(11, 3, '2026-08-29', 'present', NULL),
(13, 3, '2026-08-29', 'present', NULL),
(14, 3, '2026-08-29', 'sick',    'Flu - sent home'),
(17, 5, '2026-08-29', 'present', NULL),
(18, 5, '2026-08-29', 'present', NULL),
(20, 5, '2026-08-29', 'present', NULL),
(22, 7, '2026-08-29', 'present', NULL),
(23, 7, '2026-08-29', 'present', NULL),
(24, 7, '2026-08-29', 'present', NULL),
-- ===== 2026-08-28 =====
(1,  1, '2026-08-28', 'present', NULL),
(2,  1, '2026-08-28', 'present', NULL),
(3,  1, '2026-08-28', 'present', NULL),
(4,  1, '2026-08-28', 'present', NULL),
(6,  2, '2026-08-28', 'present', NULL),
(9,  2, '2026-08-28', 'present', NULL),
(10, 3, '2026-08-28', 'present', NULL),
(11, 3, '2026-08-28', 'present', NULL),
(13, 3, '2026-08-28', 'present', NULL),
(14, 3, '2026-08-28', 'present', NULL),
(17, 5, '2026-08-28', 'present', NULL),
(18, 5, '2026-08-28', 'present', NULL),
(20, 5, '2026-08-28', 'present', NULL),
(22, 7, '2026-08-28', 'present', NULL),
(24, 7, '2026-08-28', 'present', NULL),
-- ===== 2026-08-27 =====
(1,  1, '2026-08-27', 'present', NULL),
(2,  1, '2026-08-27', 'present', NULL),
(4,  1, '2026-08-27', 'present', NULL),
(6,  2, '2026-08-27', 'present', NULL),
(8,  2, '2026-08-27', 'present', NULL),
(10, 3, '2026-08-27', 'present', NULL),
(13, 3, '2026-08-27', 'present', NULL),
(17, 5, '2026-08-27', 'present', NULL),
(20, 5, '2026-08-27', 'present', NULL),
(22, 7, '2026-08-27', 'present', NULL),
-- ===== 2026-08-26 =====
(1,  1, '2026-08-26', 'present', NULL),
(2,  1, '2026-08-26', 'late',    'Bus delay'),
(3,  1, '2026-08-26', 'present', NULL),
(4,  1, '2026-08-26', 'present', NULL),
(6,  2, '2026-08-26', 'present', NULL),
(9,  2, '2026-08-26', 'present', NULL),
(10, 3, '2026-08-26', 'present', NULL),
(13, 3, '2026-08-26', 'present', NULL),
(17, 5, '2026-08-26', 'present', NULL),
(18, 5, '2026-08-26', 'present', NULL),
(20, 5, '2026-08-26', 'present', NULL),
-- ===== 2026-08-25 =====
(1,  1, '2026-08-25', 'present', NULL),
(2,  1, '2026-08-25', 'present', NULL),
(4,  1, '2026-08-25', 'present', NULL),
(6,  2, '2026-08-25', 'present', NULL),
(8,  2, '2026-08-25', 'present', NULL),
(10, 3, '2026-08-25', 'present', NULL),
(13, 3, '2026-08-25', 'present', NULL),
(17, 5, '2026-08-25', 'present', NULL),
(20, 5, '2026-08-25', 'present', NULL);

-- ============== FUTURE ASSIGNMENTS / PLANNING ==============
INSERT INTO future_assignments (worker_id, site_id, planned_date, end_date, status, notes, created_by) VALUES
-- Next 7 days
(5,  1, DATE_ADD('2026-09-01', INTERVAL 1 DAY),  NULL, 'planned',   'Pedro to assist John with masonry floor 3',         1),
(25, 1, DATE_ADD('2026-09-01', INTERVAL 1 DAY),  NULL, 'confirmed', 'Olivia carpenter assigned to Site A floors 4-5',   1),
(5,  1, DATE_ADD('2026-09-01', INTERVAL 2 DAY),  NULL, 'planned',   'Pedro starting on Site A foundation support',      1),
(15, 4, DATE_ADD('2026-09-01', INTERVAL 3 DAY),  NULL, 'planned',   'James to return if Mall project resumes',           1),
(26, 2, DATE_ADD('2026-09-01', INTERVAL 2 DAY),  NULL, 'confirmed', 'Priya electrician rotating to Site B for 2 weeks',  1),
(7,  2, DATE_ADD('2026-09-01', INTERVAL 15 DAY), NULL, 'planned',   'David welder scheduled return from medical leave', 1),
(27, 7, DATE_ADD('2026-09-01', INTERVAL 1 DAY),  NULL, 'confirmed', 'Lukas welder joining Hospital wing team',           1),
(8,  2, DATE_ADD('2026-09-01', INTERVAL 3 DAY),  NULL, 'planned',   'Hassan operating excavator for pier 5',             1),
-- Next 8-14 days
(5,  1, DATE_ADD('2026-09-01', INTERVAL 7 DAY),  NULL, 'planned',   'Pedro starting foreman training program',           1),
(25, 1, DATE_ADD('2026-09-01', INTERVAL 10 DAY), NULL, 'planned',   'Olivia continuing Site A - cabinet installation',   1),
(26, 2, DATE_ADD('2026-09-01', INTERVAL 14 DAY), NULL, 'planned',   'Priya to return to unassigned after rotation',      1),
(9,  2, DATE_ADD('2026-09-01', INTERVAL 12 DAY), NULL, 'planned',   'Sam assisting on bridge expansion',                 1),
(11, 3, DATE_ADD('2026-09-01', INTERVAL 8 DAY),  NULL, 'planned',   'Kenji training junior carpenter',                    1),
-- 15-30 days
(5,  1, DATE_ADD('2026-09-01', INTERVAL 21 DAY), NULL, 'planned',   'Pedro promotion to assistant foreman',              1),
(15, 1, DATE_ADD('2026-09-01', INTERVAL 18 DAY), NULL, 'planned',   'James to start on Site A as foreman assistant',     1),
(28, 5, DATE_ADD('2026-09-01', INTERVAL 20 DAY), NULL, 'planned',   'Marcus returning from inactive for Site E work',     1),
(25, 3, DATE_ADD('2026-09-01', INTERVAL 25 DAY), NULL, 'planned',   'Olivia rotation to Site C for cabinet finish',       1),
(26, 7, DATE_ADD('2026-09-01', INTERVAL 28 DAY), NULL, 'planned',   'Priya second rotation - Hospital electrical',        1),
-- Cancelled
(15, 5, DATE_ADD('2026-09-01', INTERVAL 5 DAY),  NULL, 'cancelled', 'James reassignment cancelled - kept at Mall',       1),
(11, 2, DATE_ADD('2026-09-01', INTERVAL 4 DAY),  NULL, 'cancelled', 'Kenji to Site B cancelled - kept on Site C',        1);
