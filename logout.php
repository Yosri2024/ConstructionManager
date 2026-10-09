<?php
require_once 'config.php';
// CSRF note: logout is GET for usability; state-changing but low risk as it only clears session.
// If token is supplied, validate it; POST logout will be validated strictly
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['logout']) || isset($_POST['company']))) {
    require_csrf();
}
// If ?company=1 then clear company session (switch company), else full logout
if (isset($_GET['company']) || isset($_POST['company'])) {
    if (isset($_GET['company']) && isset($_GET['csrf']) && !validate_csrf($_GET['csrf'])) { flash('Invalid token'); header('Location: dashboard.php'); exit; }
    unset($_SESSION['company_id'], $_SESSION['company_name'], $_SESSION['company_slug']);
    unset($_SESSION['user_id'], $_SESSION['username'], $_SESSION['role'], $_SESSION['full_name']);
    header('Location: login.php');
    exit;
}
if (isset($_SESSION['user_id'])) {
    // User logout but keep company logged in -> go to user_login
    $companyStay = isset($_SESSION['company_id']);
    unset($_SESSION['user_id'], $_SESSION['username'], $_SESSION['role'], $_SESSION['full_name']);
    if ($companyStay) {
        header('Location: user_login.php');
        exit;
    }
}
session_destroy();
header('Location: login.php');
exit;
