<?php
// Shared sidebar navigation — include at top of every main page
$currentPage = basename($_SERVER['PHP_SELF'], '.php');
$user = currentUser();
$isManager = ($user['role'] ?? '') === 'manager';

// Pending transfer request count (only relevant for manager)
$pendingTransferCount = 0;
if ($isManager) {
    try {
        $pendingTransferCount = (int)getDB()
            ->query("SELECT COUNT(*) FROM transfer_requests WHERE status = 'pending'")
            ->fetchColumn();
    } catch (Exception $e) {
        $pendingTransferCount = 0;
    }
}
?>
<button class="nav-toggle" onclick="document.body.classList.toggle('nav-open')">☰</button>
<div class="sidebar-backdrop" onclick="document.body.classList.remove('nav-open')"></div>
<div class="sidebar" id="mainSidebar">
    <div class="sidebar-header">
        <div class="logo">🏗️</div>
        <h2>Site Manager</h2>
        <div class="user-name">
            <?= h($user['full_name'] ?? '') ?><br>
            <span style="font-size:10px;color:#9ca3af;"><?= ucfirst($user['role'] ?? '') ?></span>
        </div>
    </div>
    <nav class="sidebar-nav">
        <div class="nav-section">Main</div>
        <a href="dashboard.php" class="nav-link <?= $currentPage === 'dashboard' ? 'active' : '' ?>">
            <span class="icon">📊</span> Dashboard
        </a>

        <?php if ($isManager): ?>
        <div class="nav-section">Management</div>
        <a href="users.php" class="nav-link <?= $currentPage === 'users' ? 'active' : '' ?>">
            <span class="icon">👤</span> User Accounts
        </a>
        <a href="sites.php" class="nav-link <?= $currentPage === 'sites' || $currentPage === 'site_detail' ? 'active' : '' ?>">
            <span class="icon">📍</span> Sites &amp; Jobs
        </a>
        <a href="workers.php" class="nav-link <?= $currentPage === 'workers' ? 'active' : '' ?>">
            <span class="icon">👷</span> Workers
        </a>
        <?php endif; ?>

        <div class="nav-section">Operations</div>
        <a href="hours.php" class="nav-link <?= $currentPage === 'hours' ? 'active' : '' ?>">
            <span class="icon">⏱️</span> Work Hours
        </a>
        <a href="attendance.php" class="nav-link <?= $currentPage === 'attendance' ? 'active' : '' ?>">
            <span class="icon">📋</span> Attendance
        </a>
        <a href="assignments.php" class="nav-link <?= $currentPage === 'assignments' ? 'active' : '' ?>">
            <span class="icon">📅</span> Assignments
        </a>
        <a href="available_workers.php" class="nav-link <?= $currentPage === 'available_workers' ? 'active' : '' ?>">
            <span class="icon">👷</span> Available Workers
        </a>
        <a href="transfer_requests.php" class="nav-link <?= $currentPage === 'transfer_requests' ? 'active' : '' ?>">
            <span class="icon">🔄</span> Transfer Requests<?php if ($isManager && $pendingTransferCount > 0): ?> (<?= $pendingTransferCount ?>)<?php endif; ?>
        </a>
        <a href="reports.php" class="nav-link <?= $currentPage === 'reports' ? 'active' : '' ?>">
            <span class="icon">📝</span> Daily Reports
        </a>
        <a href="planning.php" class="nav-link <?= $currentPage === 'planning' ? 'active' : '' ?>">
            <span class="icon">🗓️</span> Future Planning
        </a>

        <div class="nav-section">Account</div>
        <a href="logout.php" class="nav-link">
            <span class="icon">🚪</span> Logout
        </a>
    </nav>
</div>
