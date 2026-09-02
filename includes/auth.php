<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function isLoggedIn() {
    return isset($_SESSION['admin_id']) && !empty($_SESSION['admin_id']);
}

function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: login.php');
        exit();
    }
}

function isSuperAdminLoggedIn() {
    return isset($_SESSION['super_admin_id']) && !empty($_SESSION['super_admin_id']);
}

function requireSuperAdminLogin() {
    if (!isSuperAdminLoggedIn()) {
        header('Location: login.php');
        exit();
    }
}
?>
