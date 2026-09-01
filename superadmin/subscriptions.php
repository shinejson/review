<?php
require_once '../includes/auth.php';
require_once '../config/database.php';
require_once '../includes/functions.php';

requireSuperAdminLogin();

$pageTitle = 'Manage Subscriptions';
include '../includes/header.php';

$subscriptions = $conn->query("
    SELECT t.*, p.plan_name, p.price 
    FROM tenants t 
    LEFT JOIN subscription_plans p ON t.plan_id = p.id 
    ORDER BY t.subscription_end_date DESC
");
?>

<div class="admin-content">
    <h1>Manage Subscriptions</h1>
    <a href="index.php">Back to Dashboard</a>
    
    <table class="data-table">
        <thead>
            <tr>
                <th>Tenant</th>
                <th>Plan</th>
                <th>Price</th>
                <th>Status</th>
                <th>Start Date</th>
                <th>End Date</th>
                <th>Auto Renew</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php while ($sub = $subscriptions->fetch_assoc()): ?>
            <tr>
                <td><?php echo htmlspecialchars($sub['company_name']); ?></td>
                <td><?php echo htmlspecialchars($sub['plan_name'] ?? 'N/A'); ?></td>
                <td>$<?php echo number_format($sub['price'] ?? 0, 2); ?></td>
                <td><span class="status-<?php echo $sub['subscription_status']; ?>"><?php echo ucfirst($sub['subscription_status']); ?></span></td>
                <td><?php echo $sub['subscription_start_date'] ? date('M d, Y', strtotime($sub['subscription_start_date'])) : 'N/A'; ?></td>
                <td><?php echo $sub['subscription_end_date'] ? date('M d, Y', strtotime($sub['subscription_end_date'])) : 'N/A'; ?></td>
                <td><?php echo $sub['auto_renew'] ? 'Yes' : 'No'; ?></td>
                <td>
                    <a href="tenant_details.php?id=<?php echo $sub['id']; ?>">Manage</a>
                </td>
            </tr>
            <?php endwhile; ?>
        </tbody>
    </table>
</div>

<style>
.status-active { color: #28a745; font-weight: bold; }
.status-inactive { color: #dc3545; font-weight: bold; }
.status-trial { color: #ffc107; font-weight: bold; }
</style>

</body>
</html>
