<?php
require_once '../includes/auth.php';
require_once '../config/database.php';
require_once '../includes/functions.php';

requireLogin();

$pageTitle = 'Admin Dashboard';
include '../includes/header.php';
?>

<div class="dashboard">
    <h1>Admin Dashboard</h1>
    <nav class="admin-nav">
        <a href="index.php">Dashboard</a>
        <a href="ratings.php">Ratings</a>
        <a href="categories.php">Categories</a>
        <a href="customers.php">Customers</a>
        <a href="settings.php">Settings</a>
        <a href="logout.php">Logout</a>
    </nav>
    
    <div class="stats">
        <?php
        $total_ratings = $conn->query("SELECT COUNT(*) as count FROM ratings")->fetch_assoc()['count'];
        $total_customers = $conn->query("SELECT COUNT(*) as count FROM customers")->fetch_assoc()['count'];
        ?>
        <div class="stat-card">
            <h3>Total Ratings</h3>
            <p><?php echo $total_ratings; ?></p>
        </div>
        <div class="stat-card">
            <h3>Total Customers</h3>
            <p><?php echo $total_customers; ?></p>
        </div>
    </div>
</div>

</body>
</html>
