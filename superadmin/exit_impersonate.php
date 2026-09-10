<?php
/**
 * ============================================================
 *  Super Admin — Exit Support Impersonation Mode
 * ============================================================
 *  Clears the tenant session context and returns the administrator
 *  safely back to the Super Admin control center.
 */

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/sa_helpers.php';

$super_admin_id = $_SESSION['impersonator_super_admin_id'] ?? ($_SESSION['super_admin_id'] ?? null);

// Clear tenant session keys
unset(
    $_SESSION['tenant_id'],
    $_SESSION['tenant_name'],
    $_SESSION['tenant_logo'],
    $_SESSION['tenant_username'],
    $_SESSION['tenant_email'],
    $_SESSION['tenant_plan_id'],
    $_SESSION['tenant_status'],
    $_SESSION['tenant_subscription_end'],
    $_SESSION['user_type'],
    $_SESSION['impersonator_super_admin_id'],
    $_SESSION['impersonator_super_admin_name'],
    $_SESSION['admin_id'],
    $_SESSION['admin_username']
);

if ($super_admin_id) {
    $_SESSION['super_admin_id'] = (int) $super_admin_id;
    sa_flash('info', 'Exited tenant support mode. Welcome back to Super Admin.');
    redirect('tenants.php');
} else {
    redirect('login.php');
}
