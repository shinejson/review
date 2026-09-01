<?php
require_once '../includes/auth.php';
require_once '../config/database.php';
require_once '../includes/functions.php';

requireSuperAdminLogin();

// Handle tenant creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create') {
    $company_name = sanitize($_POST['company_name']);
    $email = sanitize($_POST['email']);
    $phone = sanitize($_POST['phone']);
    $plan_id = (int)$_POST['plan_id'];
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $username = strtolower(str_replace(' ', '_', $company_name));
    
    // Get plan details
    $plan = $conn->query("SELECT * FROM subscription_plans WHERE id = $plan_id")->fetch_assoc();
    
    $stmt = $conn->prepare("INSERT INTO tenants (company_name, email, phone, username, password, plan_id, subscription_price, subscription_status) VALUES (?, ?, ?, ?, ?, ?, ?, 'trial')");
    $stmt->bind_param("ssssid", $company_name, $email, $phone, $username, $password, $plan_id, $plan['price']);
    $stmt->execute();
    
    $success = "Tenant created successfully!";
}

// Handle status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_status') {
    $tenant_id = (int)$_POST['tenant_id'];
    $status = sanitize($_POST['status']);
    
    $stmt = $conn->prepare("UPDATE tenants SET subscription_status = ? WHERE id = ?");
    $stmt->bind_param("si", $status, $tenant_id);
    $stmt->execute();
    
    $success = "Status updated successfully!";
}

$pageTitle = 'Manage Tenants';
include '../includes/header.php';

$tenants = $conn->query("SELECT t.*, p.plan_name FROM tenants t LEFT JOIN subscription_plans p ON t.plan_id = p.id ORDER BY t.created_at DESC");
$plans = $conn->query("SELECT * FROM subscription_plans WHERE status = 'active'");
?>

<div class="admin-content">
    <h1>Manage Tenants</h1>
    <a href="index.php">Back to Dashboard</a>
    
    <?php if (isset($success)): ?>
        <p class="success"><?php echo $success; ?></p>
    <?php endif; ?>
    
    <div class="add-form">
        <h2>Add New Tenant</h2>
        <form method="POST">
            <input type="hidden" name="action" value="create">
            <input type="text" name="company_name" placeholder="Company Name" required>
            <input type="email" name="email" placeholder="Email" required>
            <input type="text" name="phone" placeholder="Phone">
            <input type="password" name="password" placeholder="Password" required>
            <select name="plan_id" required>
                <option value="">Select Plan</option>
                <?php
                $plans_copy = $conn->query("SELECT * FROM subscription_plans WHERE status = 'active'");
                while ($plan = $plans_copy->fetch_assoc()):
                ?>
                <option value="<?php echo $plan['id']; ?>"><?php echo $plan['plan_name']; ?> - $<?php echo $plan['price']; ?>/month</option>
                <?php endwhile; ?>
            </select>
            <button type="submit">Create Tenant</button>
        </form>
    </div>
    
    <table class="data-table">
        <thead>
            <tr>
                <th>ID</th>
                <th>Company Name</th>
                <th>Email</th>
                <th>Phone</th>
                <th>Plan</th>
                <th>Price</th>
                <th>Status</th>
                <th>Created</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php while ($tenant = $tenants->fetch_assoc()): ?>
            <tr>
                <td><?php echo $tenant['id']; ?></td>
                <td><?php echo htmlspecialchars($tenant['company_name']); ?></td>
                <td><?php echo htmlspecialchars($tenant['email']); ?></td>
                <td><?php echo htmlspecialchars($tenant['phone']); ?></td>
                <td><?php echo htmlspecialchars($tenant['plan_name'] ?? 'N/A'); ?></td>
                <td>$<?php echo number_format($tenant['subscription_price'], 2); ?></td>
                <td>
                    <form method="POST" style="display:inline;">
                        <input type="hidden" name="action" value="update_status">
                        <input type="hidden" name="tenant_id" value="<?php echo $tenant['id']; ?>">
                        <select name="status" onchange="this.form.submit()">
                            <option value="trial" <?php echo $tenant['subscription_status'] === 'trial' ? 'selected' : ''; ?>>Trial</option>
                            <option value="active" <?php echo $tenant['subscription_status'] === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo $tenant['subscription_status'] === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                    </form>
                </td>
                <td><?php echo date('M d, Y', strtotime($tenant['created_at'])); ?></td>
                <td>
                    <a href="tenant_details.php?id=<?php echo $tenant['id']; ?>">View</a>
                </td>
            </tr>
            <?php endwhile; ?>
        </tbody>
    </table>
</div>

<style>
.add-form { margin: 20px 0; padding: 20px; background: white; border-radius: 8px; }
.add-form h2 { margin-bottom: 15px; }
.add-form input, .add-form select { padding: 10px; margin-right: 10px; margin-bottom: 10px; border: 1px solid #ddd; border-radius: 4px; }
.add-form button { padding: 10px 20px; background: #28a745; color: white; border: none; border-radius: 4px; cursor: pointer; }
</style>

</body>
</html>
