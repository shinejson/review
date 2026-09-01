<?php
require_once '../includes/auth.php';
require_once '../config/database.php';
require_once '../includes/functions.php';

requireSuperAdminLogin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($_POST as $key => $value) {
        if ($key !== 'submit') {
            $stmt = $conn->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = ?");
            $stmt->bind_param("ss", $value, $key);
            $stmt->execute();
        }
    }
    $success = "Settings updated successfully!";
}

$pageTitle = 'Super Admin Settings';
include '../includes/header.php';

$settings = $conn->query("SELECT * FROM settings");
$settings_array = [];
while ($row = $settings->fetch_assoc()) {
    $settings_array[$row['setting_key']] = $row['setting_value'];
}
?>

<div class="admin-content">
    <h1>Super Admin Settings</h1>
    <a href="index.php">Back to Dashboard</a>
    
    <?php if (isset($success)): ?>
        <p class="success"><?php echo $success; ?></p>
    <?php endif; ?>
    
    <form method="POST" class="settings-form">
        <div class="form-group">
            <label>Platform Name:</label>
            <input type="text" name="site_name" value="<?php echo $settings_array['site_name'] ?? ''; ?>">
        </div>
        
        <div class="form-group">
            <label>Admin Email:</label>
            <input type="email" name="admin_email" value="<?php echo $settings_array['admin_email'] ?? ''; ?>">
        </div>
        
        <div class="form-group">
            <label>Ratings Per Page:</label>
            <input type="number" name="ratings_per_page" value="<?php echo $settings_array['ratings_per_page'] ?? '10'; ?>">
        </div>
        
        <button type="submit" name="submit">Save Settings</button>
    </form>
</div>

<style>
.settings-form {
    background: white;
    padding: 30px;
    border-radius: 8px;
    max-width: 600px;
    margin-top: 20px;
}

.settings-form .form-group {
    margin-bottom: 20px;
}

.settings-form label {
    display: block;
    margin-bottom: 5px;
    font-weight: bold;
}

.settings-form input {
    width: 100%;
    padding: 10px;
    border: 1px solid #ddd;
    border-radius: 4px;
}

.settings-form button {
    padding: 12px 30px;
    background: #007bff;
    color: white;
    border: none;
    border-radius: 4px;
    cursor: pointer;
}

.settings-form button:hover {
    background: #0056b3;
}
</style>

</body>
</html>
