<?php
require_once 'config.php';
if (isLoggedIn()) {
    header('Location: dashboard.php');
    exit;
}
header('Location: login.php');
exit;
