<?php
require_once '../includes/auth.php';
require_once '../config/database.php';
require_once '../includes/functions.php';

requireSuperAdminLogin();

$pageTitle = 'Super Admin Dashboard';
include '../includes/header.php';

// Get statistics
$total_tenants = $conn->query("SELECT COUNT(*) as count FROM tenants")->fetch_assoc()['count'];
$active_subscriptions = $conn->query("SELECT COUNT(*) as count FROM tenants WHERE subscription_status = 'active'")->fetch_assoc()['count'];
$total_revenue = $conn->query("SELECT SUM(subscription_price) as total FROM tenants WHERE subscription_status = 'active'")->fetch_assoc()['total'];
$total_ratings = $conn->query("SELECT COUNT(*) as count FROM ratings")->fetch_assoc()['count'];
?>

<div class="dashboard">
    <h1>Super Admin Dashboard</h1>
    <nav class="admin-nav">
        <a href="index.php">Dashboard</a>
        <a href="tenants.php">Tenants</a>
        <a href="subscriptions.php">Subscriptions</a>
        <a href="plans.php">Plans</a>
        <a href="analytics.php">Analytics</a>
        <a href="settings.php">Settings</a>
        <a href="logout.php">Logout</a>
    </nav>
    
    <div class="stats">
        <div class="stat-card">
            <h3>Total Tenants</h3>
            <p><?php echo $total_tenants; ?></p>
        </div>
        <div class="stat-card">
            <h3>Active Subscriptions</h3>
            <p><?php echo $active_subscriptions; ?></p>
        </div>
        <div class="stat-card">
            <h3>Monthly Revenue</h3>
            <p>$<?php echo number_format($total_revenue ?? 0, 2); ?></p>
        </div>
        <div class="stat-card">
            <h3>Total Ratings</h3>
            <p><?php echo $total_ratings; ?></p>
        </div>
    </div>
    
    <div class="recent-activity">
        <h2>Recent Tenants</h2>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Company Name</th>
                    <th>Email</th>
                    <th>Plan</th>
                    <th>Status</th>
                    <th>Created</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $recent_tenants = $conn->query("SELECT t.*, p.plan_name FROM tenants t LEFT JOIN subscription_plans p ON t.plan_id = p.id ORDER BY t.created_at DESC LIMIT 10");
                while ($tenant = $recent_tenants->fetch_assoc()):
                ?>
                <tr>
                    <td><?php echo htmlspecialchars($tenant['company_name']); ?></td>
                    <td><?php echo htmlspecialchars($tenant['email']); ?></td>
                    <td><?php echo htmlspecialchars($tenant['plan_name'] ?? 'N/A'); ?></td>
                    <td><span class="status-<?php echo $tenant['subscription_status']; ?>"><?php echo ucfirst($tenant['subscription_status']); ?></span></td>
                    <td><?php echo date('M d, Y', strtotime($tenant['created_at'])); ?></td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</div>

<style>
.status-active { color: #28a745; font-weight: bold; }
.status-inactive { color: #dc3545; font-weight: bold; }
.status-trial { color: #ffc107; font-weight: bold; }
.recent-activity { margin-top: 30px; background: white; padding: 20px; border-radius: 8px; }
</style>

</body>
</html>
