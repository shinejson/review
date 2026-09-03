<?php
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';

requireLogin();

$tenant_id = getTenantId();
$is_tenant = isTenant();

$success = '';
$error = '';

// Fetch tenant plan limits
$max_customers = 9999;
if ($is_tenant && $tenant_id) {
    $plan_q = $conn->query("SELECT p.max_customers FROM tenants t JOIN subscription_plans p ON t.plan_id = p.id WHERE t.id = $tenant_id");
    if ($plan_q && $p_row = $plan_q->fetch_assoc()) {
        $max_customers = (int)$p_row['max_customers'];
    }
}

// Handle Add Customer
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_customer') {
    $company_name = sanitize($_POST['company_name'] ?? '');
    $category_id = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
    $email = sanitize($_POST['email'] ?? '');
    $phone = sanitize($_POST['phone'] ?? '');
    $website = sanitize($_POST['website'] ?? '');

    // Check count against limit
    $count_q = $is_tenant ? $conn->query("SELECT COUNT(*) as count FROM customers WHERE tenant_id = $tenant_id") : null;
    $curr_count = $count_q ? $count_q->fetch_assoc()['count'] : 0;

    if ($curr_count >= $max_customers) {
        $error = "You have reached your plan limit of {$max_customers} companies. Please upgrade your plan in settings.";
    } elseif (empty($company_name)) {
        $error = "Company name is required.";
    } else {
        $target_tenant_id = $is_tenant ? $tenant_id : (int)($_POST['tenant_id'] ?? 1);
        $stmt = $conn->prepare("INSERT INTO customers (tenant_id, company_name, category_id, email, phone, website) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("isisss", $target_tenant_id, $company_name, $category_id, $email, $phone, $website);
        if ($stmt->execute()) {
            $success = "Company '{$company_name}' added successfully!";
        } else {
            $error = "Failed to add company: " . $conn->error;
        }
    }
}

// Handle Delete Customer (Strictly Tenant Scoped)
if (isset($_GET['delete'])) {
    $del_id = (int)$_GET['delete'];
    if ($is_tenant) {
        $stmt = $conn->prepare("DELETE FROM customers WHERE id = ? AND tenant_id = ?");
        $stmt->bind_param("ii", $del_id, $tenant_id);
    } else {
        $stmt = $conn->prepare("DELETE FROM customers WHERE id = ?");
        $stmt->bind_param("i", $del_id);
    }
    if ($stmt->execute()) {
        $success = "Company deleted successfully.";
    } else {
        $error = "Failed to delete company.";
    }
}

// Fetch categories for dropdown
$categories = $conn->query("SELECT * FROM categories ORDER BY name ASC");

// Fetch customers list (Scoped)
if ($is_tenant) {
    $stmt = $conn->prepare("SELECT c.*, cat.name as category_name 
                            FROM customers c 
                            LEFT JOIN categories cat ON c.category_id = cat.id 
                            WHERE c.tenant_id = ? 
                            ORDER BY c.company_name ASC");
    $stmt->bind_param("i", $tenant_id);
    $stmt->execute();
    $customers = $stmt->get_result();
} else {
    $customers = $conn->query("SELECT c.*, cat.name as category_name, t.company_name as tenant_company 
                               FROM customers c 
                               LEFT JOIN categories cat ON c.category_id = cat.id 
                               LEFT JOIN tenants t ON c.tenant_id = t.id 
                               ORDER BY c.created_at DESC");
}

$robots    = 'noindex, nofollow';

$BASE = '../';
$pageTitle = 'Companies';
$activeNav = 'customers';
include __DIR__ . '/_shell.php';
?>
        <div class="page-header">
            <div>
                <h1>Companies &amp; Branches</h1>
                <p>Manage all business branches and view their rating links.</p>
            </div>
            <a href="#addCompanyForm" class="btn btn-primary">+ New Company</a>
        </div>

        <?php if ($success): ?>
            <div class="alert alert-success">
                ✓ <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-error">
                ⚠ <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <!-- Add Company Box -->
        <div id="addCompanyForm" class="form-card">
            <h3>Add New Company / Branch</h3>
            <form method="POST" class="form-grid">
                <input type="hidden" name="action" value="add_customer">
                
                <div class="form-group">
                    <label>Company / Branch Name *</label>
                    <input type="text" name="company_name" placeholder="e.g. Apex Health Clinic" required>
                </div>

                <div class="form-group">
                    <label>Category</label>
                    <select name="category_id">
                        <option value="">-- Select Category --</option>
                        <?php while ($cat = $categories->fetch_assoc()): ?>
                            <option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
                        <?php endwhile; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Email</label>
                    <input type="email" name="email" placeholder="branch@example.com">
                </div>

                <div class="form-group">
                    <label>Phone</label>
                    <input type="text" name="phone" placeholder="+1 (555) 000-0000">
                </div>

                <div class="form-group">
                    <label>Website</label>
                    <input type="text" name="website" placeholder="www.example.com">
                </div>

                <div class="form-group">
                    <button type="submit" class="btn btn-primary" style="width:100%">
                        Save Company
                    </button>
                </div>
            </form>
        </div>

        <!-- Companies Table -->
        <div class="data-table-card">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Company Name</th>
                        <th>Category</th>
                        <th>Average Rating</th>
                        <th>Reviews Count</th>
                        <th>Public Rating Link</th>
                        <th style="text-align:right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($customers && $customers->num_rows > 0): ?>
                        <?php while ($row = $customers->fetch_assoc()): 
                            $avg = getAverageRating($row['id'], $conn);
                            $cnt = getRatingCount($row['id'], $conn);
                            $rateUrl = $assetBase . "/rate/index.php?company=" . $row['id'];
                        ?>
                            <tr>
                                <td class="table-id"><?php echo $row['id']; ?></td>
                                <td class="table-title">
                                    <?php echo htmlspecialchars($row['company_name']); ?>
                                </td>
                                <td class="table-subtitle">
                                    <?php echo htmlspecialchars($row['category_name'] ?? 'General'); ?>
                                </td>
                                <td class="table-rating">
                                    <span style="color:#f59e0b;">★</span> <?php echo $avg; ?>
                                </td>
                                <td class="table-subtitle"><?php echo $cnt; ?></td>
                                <td>
                                    <a href="<?php echo $rateUrl; ?>" target="_blank" class="btn-link">
                                        Open Rating Form &rarr;
                                    </a>
                                </td>
                                <td style="text-align:right;">
                                    <a href="customers.php?delete=<?php echo $row['id']; ?>" onclick="return confirm('Are you sure you want to delete this company and all its ratings?');" class="btn-danger">Delete</a>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" class="table-empty">
                                No companies found. Use the form above to add your first company!
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </main>
<?php include __DIR__ . '/_shell_footer.php'; ?>
