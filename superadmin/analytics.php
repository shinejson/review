<?php
require_once '../includes/auth.php';
require_once '../config/database.php';
require_once '../includes/functions.php';

requireSuperAdminLogin();

$pageTitle = 'Analytics';
include '../includes/header.php';

// Get analytics data
$revenue_by_month = $conn->query("
    SELECT DATE_FORMAT(created_at, '%Y-%m') as month, 
           COUNT(*) as tenants,
           SUM(subscription_price) as revenue
    FROM tenants 
    WHERE subscription_status = 'active'
    GROUP BY month 
    ORDER BY month DESC 
    LIMIT 12
");

$ratings_by_tenant = $conn->query("
    SELECT c.company_name, COUNT(r.id) as rating_count, AVG(r.rating) as avg_rating
    FROM customers c
    LEFT JOIN ratings r ON c.id = r.company_id
    GROUP BY c.id
    ORDER BY rating_count DESC
    LIMIT 10
");

$plan_distribution = $conn->query("
    SELECT p.plan_name, COUNT(t.id) as tenant_count
    FROM subscription_plans p
    LEFT JOIN tenants t ON p.id = t.plan_id
    WHERE t.subscription_status IN ('active', 'trial')
    GROUP BY p.id
");
?>

<div class="admin-content">
    <h1>Analytics Dashboard</h1>
    <a href="index.php">Back to Dashboard</a>
    
    <div class="analytics-section">
        <h2>Revenue by Month</h2>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Month</th>
                    <th>Active Tenants</th>
                    <th>Revenue</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($row = $revenue_by_month->fetch_assoc()): ?>
                <tr>
                    <td><?php echo $row['month']; ?></td>
                    <td><?php echo $row['tenants']; ?></td>
                    <td>$<?php echo number_format($row['revenue'], 2); ?></td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
    
    <div class="analytics-section">
        <h2>Top 10 Most Rated Companies</h2>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Company</th>
                    <th>Total Ratings</th>
                    <th>Average Rating</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($row = $ratings_by_tenant->fetch_assoc()): ?>
                <tr>
                    <td><?php echo htmlspecialchars($row['company_name']); ?></td>
                    <td><?php echo $row['rating_count']; ?></td>
                    <td><?php echo number_format($row['avg_rating'], 2); ?> ★</td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
    
    <div class="analytics-section">
        <h2>Plan Distribution</h2>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Plan</th>
                    <th>Active Tenants</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($row = $plan_distribution->fetch_assoc()): ?>
                <tr>
                    <td><?php echo htmlspecialchars($row['plan_name']); ?></td>
                    <td><?php echo $row['tenant_count']; ?></td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</div>

<style>
.analytics-section {
    background: white;
    padding: 20px;
    border-radius: 8px;
    margin: 20px 0;
}

.analytics-section h2 {
    margin-bottom: 15px;
    color: #333;
}
</style>

</body>
</html>
