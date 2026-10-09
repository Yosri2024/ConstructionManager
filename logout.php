<?php
require_once 'config.php';
// If ?company=1 then clear company session (switch company), else full logout
if (isset($_GET['company'])) {
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
