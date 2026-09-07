<?php
/**
 * Diagnostic page to test logout functionality
 */
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';

requireLogin();

$logout_url = auth_logout_url();
$session_data = [
    'logout_token' => $_SESSION['logout_token'] ?? 'NOT SET',
    'session_token' => $_SESSION['session_token'] ?? 'NOT SET',
    'session_portal' => $_SESSION['session_portal'] ?? 'NOT SET',
    'admin_id' => $_SESSION['admin_id'] ?? 'NOT SET',
    'tenant_id' => $_SESSION['tenant_id'] ?? 'NOT SET',
    'super_admin_id' => $_SESSION['super_admin_id'] ?? 'NOT SET',
];

$test_token = $_GET['t'] ?? '';
$token_match = !empty($test_token) && !empty($_SESSION['logout_token']) 
    ? hash_equals($_SESSION['logout_token'], $test_token) 
    : 'N/A';

?>
<!DOCTYPE html>
<html>
<head>
    <title>Logout Test</title>
    <style>
        body { font-family: monospace; padding: 20px; background: #0f2438; color: #cbd5e1; }
        h1 { color: #c2f542; }
        .box { background: rgba(255,255,255,0.05); padding: 15px; margin: 15px 0; border-radius: 8px; border: 1px solid rgba(255,255,255,0.1); }
        .label { color: #94a3b8; font-weight: bold; display: inline-block; width: 200px; }
        .value { color: #e2e8f0; }
        .success { color: #10b981; }
        .error { color: #ef4444; }
        a { color: #c2f542; }
        pre { background: rgba(0,0,0,0.3); padding: 10px; overflow-x: auto; }
    </style>
</head>
<body>
    <h1>🔍 Logout Diagnostic Page</h1>
    
    <div class="box">
        <h2>Session Data</h2>
        <?php foreach ($session_data as $key => $value): ?>
            <div>
                <span class="label"><?php echo htmlspecialchars($key); ?>:</span>
                <span class="value"><?php echo htmlspecialchars($value); ?></span>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="box">
        <h2>Logout URL</h2>
        <div>
            <span class="label">Generated URL:</span>
            <span class="value"><?php echo htmlspecialchars($logout_url); ?></span>
        </div>
        <div style="margin-top: 10px;">
            <a href="<?php echo htmlspecialchars($logout_url); ?>">Click here to test logout</a>
        </div>
    </div>

    <?php if (!empty($test_token)): ?>
    <div class="box">
        <h2>Token Verification Test</h2>
        <div>
            <span class="label">Token from URL:</span>
            <span class="value"><?php echo htmlspecialchars(substr($test_token, 0, 20)) . '...'; ?></span>
        </div>
        <div>
            <span class="label">Token in session:</span>
            <span class="value"><?php echo htmlspecialchars(substr($_SESSION['logout_token'] ?? '', 0, 20)) . '...'; ?></span>
        </div>
        <div>
            <span class="label">Tokens match:</span>
            <span class="<?php echo $token_match === true ? 'success' : 'error'; ?>">
                <?php echo $token_match === true ? '✓ YES' : '✗ NO'; ?>
            </span>
        </div>
        <div style="margin-top: 10px;">
            <span class="label">auth_logout_request_ok():</span>
            <span class="<?php echo auth_logout_request_ok() ? 'success' : 'error'; ?>">
                <?php echo auth_logout_request_ok() ? '✓ TRUE' : '✗ FALSE'; ?>
            </span>
        </div>
    </div>
    <?php endif; ?>

    <div class="box">
        <h2>Full Session Contents</h2>
        <pre><?php print_r($_SESSION); ?></pre>
    </div>

    <div class="box">
        <h2>Instructions</h2>
        <ol>
            <li>Click the logout link above</li>
            <li>If it redirects to login → <span class="success">WORKING ✓</span></li>
            <li>If it shows error or stays logged in → <span class="error">NOT WORKING ✗</span></li>
            <li>Check the session data to see if logout_token exists</li>
        </ol>
    </div>

    <p><a href="index.php">← Back to Dashboard</a></p>
</body>
</html>
