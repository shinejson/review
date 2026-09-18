<?php
/**
 * ============================================================
 * Login Attempt Tracking & Account Locking
 * ============================================================
 * Manages failed login attempts with automatic account locking
 * after 3 failed attempts. Only workspace owners (tenants) can unlock.
 */

/**
 * Check if an account is currently locked
 * @param object $conn Database connection
 * @param string $table Table name ('team_members' or 'tenants')
 * @param int $user_id User ID
 * @return bool True if account is locked
 */
function isAccountLocked($conn, $table, $user_id) {
    if (!in_array($table, ['team_members', 'tenants'])) {
        return false;
    }
    
    $stmt = $conn->prepare("SELECT account_locked_until FROM $table WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res->fetch_assoc();
    $stmt->close();
    
    if (!$row) {
        return false;
    }
    
    $locked_until = $row['account_locked_until'];
    if ($locked_until && strtotime($locked_until) > time()) {
        return true;
    }
    
    return false;
}

/**
 * Record a failed login attempt
 * @param object $conn Database connection
 * @param string $table Table name ('team_members' or 'tenants')
 * @param int $user_id User ID
 * @return array ['locked' => bool, 'attempts' => int, 'message' => string]
 */
function recordFailedLoginAttempt($conn, $table, $user_id) {
    if (!in_array($table, ['team_members', 'tenants'])) {
        return ['locked' => false, 'attempts' => 0, 'message' => 'Invalid table'];
    }
    
    // Get current attempt count
    $stmt = $conn->prepare("SELECT failed_login_attempts FROM $table WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res->fetch_assoc();
    $stmt->close();
    
    if (!$row) {
        return ['locked' => false, 'attempts' => 0, 'message' => 'User not found'];
    }
    
    $attempts = (int)$row['failed_login_attempts'] + 1;
    $is_locked = false;
    $lock_until = null;
    
    // Lock account after 3 failed attempts (lock for 30 minutes)
    if ($attempts >= 3) {
        $is_locked = true;
        $lock_until = date('Y-m-d H:i:s', time() + 1800); // 30 minutes
    }
    
    // Update the record
    if ($is_locked) {
        $upd = $conn->prepare("UPDATE $table SET failed_login_attempts = ?, last_failed_attempt_at = NOW(), account_locked_until = ? WHERE id = ?");
        $upd->bind_param("isi", $attempts, $lock_until, $user_id);
    } else {
        $upd = $conn->prepare("UPDATE $table SET failed_login_attempts = ?, last_failed_attempt_at = NOW() WHERE id = ?");
        $upd->bind_param("ii", $attempts, $user_id);
    }
    
    $upd->execute();
    $upd->close();
    
    return [
        'locked' => $is_locked,
        'attempts' => $attempts,
        'message' => $is_locked ? 'Account locked for 30 minutes. Contact your workspace owner to unlock.' : 'Invalid credentials. Attempt ' . $attempts . ' of 3.'
    ];
}

/**
 * Reset login attempts upon successful login
 * @param object $conn Database connection
 * @param string $table Table name ('team_members' or 'tenants')
 * @param int $user_id User ID
 */
function resetFailedLoginAttempts($conn, $table, $user_id) {
    if (!in_array($table, ['team_members', 'tenants'])) {
        return;
    }
    
    $rst = $conn->prepare("UPDATE $table SET failed_login_attempts = 0, last_failed_attempt_at = NULL, account_locked_until = NULL WHERE id = ?");
    $rst->bind_param("i", $user_id);
    $rst->execute();
    $rst->close();
}

/**
 * Unlock an account (admin/tenant action)
 * @param object $conn Database connection
 * @param string $table Table name ('team_members' or 'tenants')
 * @param int $user_id User ID
 * @return bool Success
 */
function unlockUserAccount($conn, $table, $user_id) {
    if (!in_array($table, ['team_members', 'tenants'])) {
        return false;
    }
    
    $unl = $conn->prepare("UPDATE $table SET failed_login_attempts = 0, last_failed_attempt_at = NULL, account_locked_until = NULL WHERE id = ?");
    $unl->bind_param("i", $user_id);
    $result = $unl->execute();
    $unl->close();
    
    return $result;
}

/**
 * Get lock status for a user
 * @param object $conn Database connection
 * @param string $table Table name ('team_members' or 'tenants')
 * @param int $user_id User ID
 * @return array Lock status information
 */
function getUserLockStatus($conn, $table, $user_id) {
    if (!in_array($table, ['team_members', 'tenants'])) {
        return ['is_locked' => false, 'attempts' => 0, 'locked_until' => null];
    }
    
    $stmt = $conn->prepare("SELECT failed_login_attempts, account_locked_until FROM $table WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res->fetch_assoc();
    $stmt->close();
    
    if (!$row) {
        return ['is_locked' => false, 'attempts' => 0, 'locked_until' => null];
    }
    
    $locked_until = $row['account_locked_until'];
    $is_locked = $locked_until && strtotime($locked_until) > time();
    
    return [
        'is_locked' => $is_locked,
        'attempts' => (int)$row['failed_login_attempts'],
        'locked_until' => $locked_until
    ];
}
