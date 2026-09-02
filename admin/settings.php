<?php
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';

requireLogin();

$pageTitle = 'Settings';
include dirname(__DIR__) . '/includes/header.php';
?>

<div class="admin-content">
    <h1>Settings</h1>
    <a href="index.php">Back to Dashboard</a>
    
    <div class="settings-section">
        <h2>General Settings</h2>
        <p>Configuration options will be added here.</p>
    </div>
</div>

</body>
</html>
