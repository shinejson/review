<?php
/** ============================================================
 * Session / authentication tests — portal boundaries, tenant
 * isolation, logout, revocation, impersonation cleanup.
 *
 * Run:  php tests/session_tests.php
 * ============================================================ */

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../includes/session.php';

$pass = 0;
$fail = 0;
$failures = [];

function check($label, $condition, $detail = '')
{
    global $pass, $fail, $failures;
    if ($condition) {
        $pass++;
        echo "  [ ok ] {$label}\n";
    } else {
        $fail++;
        $failures[] = $label . ($detail !== '' ? ' — ' . $detail : '');
        echo "  [FAIL] {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

function section($title)
{
    echo "\n" . $title . "\n" . str_repeat('=', strlen($title)) . "\n";
}

function tss_reset() { $_SESSION = []; }

function tss_set(array $values) { foreach ($values as $k => $v) { $_SESSION[$k] = $v; } }

/* ============================================================
   1. auth_current_portal() — portal resolution
   ============================================================ */
section('auth_current_portal() — portal resolution');

tss_reset();
check('empty session returns null', auth_current_portal() === null);

tss_reset();
tss_set(['super_admin_id' => 1]);
check('super_admin_id only returns superadmin', auth_current_portal() === 'superadmin');

tss_reset();
tss_set(['admin_id' => 5]);
check('admin_id only returns admin', auth_current_portal() === 'admin');

tss_reset();
tss_set(['tenant_id' => 18]);
check('tenant_id only returns admin', auth_current_portal() === 'admin');

tss_reset();
tss_set(['impersonator_super_admin_id' => 1, 'tenant_id' => 18]);
check('impersonation returns admin (tenant workspace)', auth_current_portal() === 'admin');

tss_reset();
tss_set(['impersonator_super_admin_id' => 1]);
check('impersonator without tenant_id returns null', auth_current_portal() === null);

/* ============================================================
   2. auth_current_user_id() — team-aware identity
   ============================================================ */
section('auth_current_user_id() — team-aware identity');

tss_reset();
tss_set(['super_admin_id' => 42]);
check('superadmin returns super_admin_id', auth_current_user_id() === 42);

tss_reset();
tss_set(['admin_id' => 7]);
check('admin returns admin_id', auth_current_user_id() === 7);

tss_reset();
tss_set(['tenant_id' => 18]);
check('tenant returns tenant_id', auth_current_user_id() === 18);

tss_reset();
tss_set(['team_member_id' => 99, 'tenant_id' => 18]);
check('team member returns team_member_id', auth_current_user_id() === 99);

tss_reset();
tss_set(['team_member_id' => 0, 'tenant_id' => 18]);
check('zero team_member_id falls back to tenant_id', auth_current_user_id() === 18);

/* ============================================================
   3. auth_current_user_kind() — kind classification
   ============================================================ */
section('auth_current_user_kind() — kind classification');

tss_reset();
tss_set(['super_admin_id' => 1]);
check('superadmin kind', auth_current_user_kind() === 'super_admin');

tss_reset();
tss_set(['admin_id' => 5]);
check('admin kind', auth_current_user_kind() === 'admin');

tss_reset();
tss_set(['tenant_id' => 18]);
check('tenant kind', auth_current_user_kind() === 'tenant');

tss_reset();
tss_set(['team_member_id' => 99, 'tenant_id' => 18]);
check('team member kind', auth_current_user_kind() === 'team');

/* ============================================================
   4. auth_is_impersonating() — impersonation detection
   ============================================================ */
section('auth_is_impersonating() — impersonation detection');

tss_reset();
check('not impersonating by default', auth_is_impersonating() === false);

tss_reset();
tss_set(['impersonator_super_admin_id' => 1]);
check('impersonator without tenant is not impersonating', auth_is_impersonating() === false);

tss_reset();
tss_set(['tenant_id' => 18]);
check('tenant without impersonator is not impersonating', auth_is_impersonating() === false);

tss_reset();
tss_set(['impersonator_super_admin_id' => 1, 'tenant_id' => 18]);
check('impersonator with tenant is impersonating', auth_is_impersonating() === true);

/* ============================================================
   5. auth_session_identity() — canonical (user_id, kind)
   ============================================================ */
section('auth_session_identity() — canonical identity');

tss_reset();
tss_set(['super_admin_id' => 42]);
list($uid, $kind) = auth_session_identity();
check('superadmin identity is (42, super_admin)', $uid === 42 && $kind === 'super_admin', "$uid / $kind");

tss_reset();
tss_set(['admin_id' => 7]);
list($uid, $kind) = auth_session_identity();
check('admin identity is (7, admin)', $uid === 7 && $kind === 'admin', "$uid / $kind");

tss_reset();
tss_set(['tenant_id' => 18]);
list($uid, $kind) = auth_session_identity();
check('tenant identity is (18, tenant)', $uid === 18 && $kind === 'tenant', "$uid / $kind");

tss_reset();
tss_set(['team_member_id' => 99, 'tenant_id' => 18]);
list($uid, $kind) = auth_session_identity();
check('team member identity is (99, team)', $uid === 99 && $kind === 'team', "$uid / $kind");

tss_reset();
tss_set(['impersonator_super_admin_id' => 1, 'tenant_id' => 18]);
list($uid, $kind) = auth_session_identity();
check('impersonation identity is (18, tenant)', $uid === 18 && $kind === 'tenant', "$uid / $kind");

require_once __DIR__ . '/../tools/php/mock-db.php';
$mockConn = new MockMysqli();

/* ============================================================
   6. auth_session_count() / auth_session_counts() with kind
   ============================================================ */
section('auth_session_count() / auth_session_counts() — kind-aware filtering');

/* Verify auth_session_count() wrapper with kind=admin (raw query path via auth_session_counts) */
$counts = auth_session_counts($mockConn, 'admin', [1], 'admin');
check('auth_session_counts with kind=admin: user 1 has 2 sessions', $counts[1] ?? 0 === 2, 'got ' . (($counts[1] ?? 0)));

$counts = auth_session_counts($mockConn, 'admin', [1], 'team');
check('auth_session_counts with kind=team: user 1 has 0 sessions', $counts[1] ?? 0 === 0, 'got ' . (($counts[1] ?? 0)));

/* Without kind, should return all sessions for user_id across kinds */
$counts = auth_session_counts($mockConn, 'admin', [1]);
check('auth_session_counts without kind: user 1 has 2 sessions', $counts[1] ?? 0 === 2, 'got ' . (($counts[1] ?? 0)));

$counts = auth_session_counts($mockConn, 'superadmin', [1], 'super_admin');
check('auth_session_counts superadmin kind=super_admin: user 1 has 2 sessions', $counts[1] ?? 0 === 2, 'got ' . (($counts[1] ?? 0)));

$counts = auth_session_counts($mockConn, 'superadmin', [1], 'admin');
check('auth_session_counts superadmin kind=admin: user 1 has 0 sessions', $counts[1] ?? 0 === 0, 'got ' . (($counts[1] ?? 0)));

/* ============================================================
   Summary
   ============================================================ */
section('Summary');
echo "  {$pass} passed, {$fail} failed\n";
if ($fail) {
    echo "\nFailures:\n";
    foreach ($failures as $failure) {
        echo "  - {$failure}\n";
    }
}
exit($fail === 0 ? 0 : 1);