<?php
require_once 'config.php';
// Shareable company link support (WAMP localhost)
if (isset($_GET['c']) || isset($_GET['company']) || isset($_GET['slug'])) {
    $raw = trim($_GET['c'] ?? $_GET['company'] ?? $_GET['slug'] ?? '');
    if ($raw !== '') {
        $pdoTmp = getDB();
        $slugTry = slugify($raw);
        $stmt = $pdoTmp->prepare("SELECT * FROM companies WHERE LOWER(slug) = LOWER(?) LIMIT 1");
        $stmt->execute([$slugTry]);
        $found = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$found) {
            $stmt = $pdoTmp->prepare("SELECT * FROM companies WHERE LOWER(slug) = LOWER(?) OR LOWER(name) = LOWER(?) LIMIT 1");
            $stmt->execute([$raw, $raw]);
            $found = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        if ($found) {
            if (!isset($_SESSION['company_id']) || (int)$_SESSION['company_id'] !== (int)$found['id']) {
                unset($_SESSION['user_id'], $_SESSION['username'], $_SESSION['role'], $_SESSION['full_name']);
            }
            $_SESSION['company_id'] = (int)$found['id'];
            $_SESSION['company_name'] = $found['name'];
            $_SESSION['company_slug'] = $found['slug'];
            header('Location: user_login.php');
            exit;
        }
    }
}
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
