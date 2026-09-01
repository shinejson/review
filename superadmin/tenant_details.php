<?php
require_once '../includes/auth.php';
require_once '../config/database.php';
require_once '../includes/functions.php';

requireSuperAdminLogin();

$tenant_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($tenant_id === 0) {
    redirect('tenants.php');
}

$tenant = $conn->query("SELECT t.*, p.plan_name FROM tenants t LEFT JOIN subscription_plans p ON t.plan_id = p.id WHERE t.id = $tenant_id")->fetch_assoc();

if (!$tenant) {
    redirect('tenants.php');
}

// Get tenant statistics
$customer_count = $conn->query("SELECT COUNT(*) as count FROM customers WHERE tenant_id = $tenant_id")->fetch_assoc()['count'];
$rating_count = $conn->query("SELECT COUNT(*) as count FROM ratings r JOIN customers c ON r.company_id = c.id WHERE c.tenant_id = $tenant_id")->fetch_assoc()['count'];

$pageTitle = 'Tenant Details - ' . $tenant['company_name'];
include '../includes/header.php';
?>

<div class="admin-content">
    <h1>Tenant Details: <?php echo htmlspecialchars($tenant['company_name']); ?></h1>
    <a href="tenants.php">Back to Tenants</a>
    
    <div class="tenant-details">
        <div class="detail-section">
            <h2>Company Information</h2>
            <p><strong>Email:</strong> <?php echo htmlspecialchars($tenant['email']); ?></p>
            <p><strong>Phone:</strong> <?php echo htmlspecialchars($tenant['phone']); ?></p>
            <p><strong>Username:</strong> <?php echo htmlspecialchars($tenant['username']); ?></p>
            <p><strong>Created:</strong> <?php echo date('M d, Y', strtotime($tenant['created_at'])); ?></p>
        </div>
        
        <div class="detail-section">
            <h2>Subscription Details</h2>
            <p><strong>Plan:</strong> <?php echo htmlspecialchars($tenant['plan_name'] ?? 'N/A'); ?></p>
            <p><strong>Price:</strong> $<?php echo number_format($tenant['subscription_price'], 2); ?>/month</p>
            <p><strong>Status:</strong> <span class="status-<?php echo $tenant['subscription_status']; ?>"><?php echo ucfirst($tenant['subscription_status']); ?></span></p>
            <p><strong>Auto Renew:</strong> <?php echo $tenant['auto_renew'] ? 'Yes' : 'No'; ?></p>
            <?php if ($tenant['subscription_start_date']): ?>
            <p><strong>Start Date:</strong> <?php echo date('M d, Y', strtotime($tenant['subscription_start_date'])); ?></p>
            <?php endif; ?>
            <?php if ($tenant['subscription_end_date']): ?>
            <p><strong>End Date:</strong> <?php echo date('M d, Y', strtotime($tenant['subscription_end_date'])); ?></p>
            <?php endif; ?>
        </div>
        
        <div class="detail-section">
            <h2>Usage Statistics</h2>
            <p><strong>Total Customers:</strong> <?php echo $customer_count; ?></p>
            <p><strong>Total Ratings:</strong> <?php echo $rating_count; ?></p>
        </div>
    </div>
</div>

<style>
.tenant-details {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 20px;
    margin-top: 20px;
}

.detail-section {
    background: white;
    padding: 20px;
    border-radius: 8px;
    box-shadow: 0 2px 5px rgba(0,0,0,0.1);
}

.detail-section h2 {
    margin-bottom: 15px;
    color: #007bff;
}

.detail-section p {
    margin: 10px 0;
}

.status-active { color: #28a745; font-weight: bold; }
.status-inactive { color: #dc3545; font-weight: bold; }
.status-trial { color: #ffc107; font-weight: bold; }
</style>

</body>
</html>
