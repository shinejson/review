<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Checks if a tenant or admin is currently logged in
 */
function isLoggedIn() {
    return (isset($_SESSION['tenant_id']) && !empty($_SESSION['tenant_id'])) ||
           (isset($_SESSION['admin_id']) && !empty($_SESSION['admin_id']));
}

/**
 * Checks if current session belongs to a Tenant
 */
function isTenant() {
    return isset($_SESSION['tenant_id']) && !empty($_SESSION['tenant_id']);
}

/**
 * Checks if current session belongs to a Global Admin
 */
function isAdmin() {
    return isset($_SESSION['admin_id']) && !empty($_SESSION['admin_id']);
}

/**
 * Get active Tenant ID (or null if not a tenant)
 */
function getTenantId() {
    return $_SESSION['tenant_id'] ?? null;
}

/**
 * Get display name for logged-in user or company
 */
function getCurrentUserName() {
    if (isset($_SESSION['tenant_name'])) {
        return $_SESSION['tenant_name'];
    }
    if (isset($_SESSION['admin_username'])) {
        return $_SESSION['admin_username'];
    }
    return 'User';
}

/**
 * Enforce login for Admin / Tenant area
 */
function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: login.php');
        exit();
    }
    
    // If tenant, verify subscription is not cancelled or inactive
    if (isTenant() && isset($_SESSION['tenant_status'])) {
        if ($_SESSION['tenant_status'] === 'inactive' || $_SESSION['tenant_status'] === 'cancelled') {
            // Can allow read-only or redirect to payment notice
        }
    }
}

/**
 * Super Admin Auth Helpers
 */
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
