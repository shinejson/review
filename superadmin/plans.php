<?php
require_once '../includes/auth.php';
require_once '../config/database.php';
require_once '../includes/functions.php';

requireSuperAdminLogin();

// Handle plan creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create') {
    $plan_name = sanitize($_POST['plan_name']);
    $price = (float)$_POST['price'];
    $max_ratings = (int)$_POST['max_ratings'];
    $max_customers = (int)$_POST['max_customers'];
    $features = sanitize($_POST['features']);
    
    $stmt = $conn->prepare("INSERT INTO subscription_plans (plan_name, price, max_ratings, max_customers, features) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("sdiis", $plan_name, $price, $max_ratings, $max_customers, $features);
    $stmt->execute();
    
    $success = "Plan created successfully!";
}

$pageTitle = 'Manage Plans';
include '../includes/header.php';

$plans = $conn->query("SELECT * FROM subscription_plans ORDER BY price ASC");
?>

<div class="admin-content">
    <h1>Manage Subscription Plans</h1>
    <a href="index.php">Back to Dashboard</a>
    
    <?php if (isset($success)): ?>
        <p class="success"><?php echo $success; ?></p>
    <?php endif; ?>
    
    <div class="add-form">
        <h2>Create New Plan</h2>
        <form method="POST">
            <input type="hidden" name="action" value="create">
            <input type="text" name="plan_name" placeholder="Plan Name" required>
            <input type="number" step="0.01" name="price" placeholder="Price per month" required>
            <input type="number" name="max_ratings" placeholder="Max Ratings per month" required>
            <input type="number" name="max_customers" placeholder="Max Customers" required>
            <textarea name="features" placeholder="Features (comma separated)" required></textarea>
            <button type="submit">Create Plan</button>
        </form>
    </div>
    
    <div class="plans-grid">
        <?php while ($plan = $plans->fetch_assoc()): ?>
        <div class="plan-card">
            <h3><?php echo htmlspecialchars($plan['plan_name']); ?></h3>
            <p class="price">$<?php echo number_format($plan['price'], 2); ?>/month</p>
            <ul>
                <li><?php echo $plan['max_ratings']; ?> ratings/month</li>
                <li><?php echo $plan['max_customers']; ?> customers max</li>
                <li>Status: <?php echo ucfirst($plan['status']); ?></li>
            </ul>
            <p><strong>Features:</strong><br><?php echo nl2br(htmlspecialchars($plan['features'])); ?></p>
        </div>
        <?php endwhile; ?>
    </div>
</div>

<style>
.plans-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 20px;
    margin-top: 20px;
}

.plan-card {
    background: white;
    padding: 20px;
    border-radius: 8px;
    box-shadow: 0 2px 5px rgba(0,0,0,0.1);
}

.plan-card h3 {
    color: #007bff;
    margin-bottom: 10px;
}

.plan-card .price {
    font-size: 28px;
    font-weight: bold;
    color: #28a745;
    margin: 15px 0;
}

.plan-card ul {
    list-style: none;
    padding: 0;
    margin: 15px 0;
}

.plan-card ul li {
    padding: 5px 0;
    border-bottom: 1px solid #eee;
}

.add-form textarea {
    width: 100%;
    padding: 10px;
    margin-bottom: 10px;
    border: 1px solid #ddd;
    border-radius: 4px;
    min-height: 100px;
}
</style>

</body>
</html>
