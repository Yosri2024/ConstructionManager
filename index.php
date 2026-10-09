<?php
require_once 'config.php';
if (isLoggedIn()) {
    header('Location: dashboard.php');
    exit;
}
if (isCompanyLoggedIn()) {
    header('Location: user_login.php');
    exit;
}
header('Location: login.php');
exit;
