<?php
require_once '../includes/auth.php';
require_once '../config/database.php';
require_once '../includes/functions.php';

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

$pageTitle = 'Manage Companies / Branches - Optibiz';
$extraCss = ['/assets/css/auth.css'];
include '../includes/header.php';
?>

<div style="background:#f8fafc;min-height:100vh;font-family:'Plus Jakarta Sans',sans-serif;">
    <!-- Top Nav -->
    <header style="background:#0a1926;color:white;padding:16px 5%;display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid rgba(255,255,255,0.1);">
        <div style="display:flex;align-items:center;gap:12px;">
            <a href="index.php" style="color:white;text-decoration:none;font-size:22px;font-weight:800;letter-spacing:-0.5px;display:flex;align-items:center;gap:8px;">
                <span style="width:28px;height:28px;background:#c2f542;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;color:#0a1926;font-size:14px;font-weight:900;">★</span>
                Optibiz
            </a>
            <span style="background:rgba(255,255,255,0.12);padding:4px 12px;border-radius:20px;font-size:12px;color:#c2f542;font-weight:600;">
                <?php echo $is_tenant ? 'Tenant Portal' : 'Global Admin'; ?>
            </span>
        </div>

        <nav style="display:flex;align-items:center;gap:20px;">
            <a href="index.php" style="color:#cbd5e1;text-decoration:none;font-size:14px;font-weight:500;">Dashboard</a>
            <a href="customers.php" style="color:#c2f542;text-decoration:none;font-size:14px;font-weight:600;">Companies / Branches</a>
            <a href="ratings.php" style="color:#cbd5e1;text-decoration:none;font-size:14px;font-weight:500;">Ratings &amp; Reviews</a>
            <a href="categories.php" style="color:#cbd5e1;text-decoration:none;font-size:14px;font-weight:500;">Categories</a>
            <a href="settings.php" style="color:#cbd5e1;text-decoration:none;font-size:14px;font-weight:500;">Settings</a>
            <a href="logout.php" style="background:rgba(239,68,68,0.2);color:#f87171;padding:6px 14px;border-radius:20px;text-decoration:none;font-size:13px;font-weight:600;">Logout</a>
        </nav>
    </header>

    <main style="max-width:1240px;margin:30px auto;padding:0 20px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;">
            <div>
                <h1 style="font-size:26px;font-weight:800;color:#0f172a;">Companies &amp; Branches</h1>
                <p style="color:#64748b;font-size:14px;">Manage all business branches and view their rating links.</p>
            </div>
            <a href="#addCompanyForm" style="background:#0a1926;color:white;padding:10px 18px;border-radius:8px;text-decoration:none;font-size:14px;font-weight:700;">+ New Company</a>
        </div>

        <?php if ($success): ?>
            <div style="background:#ecfdf5;color:#065f46;padding:12px 16px;border-radius:8px;margin-bottom:20px;font-size:14px;font-weight:600;border:1px solid #a7f3d0;">
                ✓ <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div style="background:#fef2f2;color:#991b1b;padding:12px 16px;border-radius:8px;margin-bottom:20px;font-size:14px;font-weight:600;border:1px solid #fecaca;">
                ⚠ <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <!-- Add Company Box -->
        <div id="addCompanyForm" style="background:white;padding:24px;border-radius:16px;box-shadow:0 4px 15px rgba(0,0,0,0.03);border:1px solid #e2e8f0;margin-bottom:30px;">
            <h3 style="font-size:18px;font-weight:700;color:#0f172a;margin-bottom:16px;">Add New Company / Branch</h3>
            <form method="POST" style="display:grid;grid-template-columns:repeat(auto-fit, minmax(200px, 1fr));gap:16px;align-items:flex-end;">
                <input type="hidden" name="action" value="add_customer">
                
                <div>
                    <label style="display:block;font-size:13px;font-weight:600;color:#334155;margin-bottom:6px;">Company / Branch Name *</label>
                    <input type="text" name="company_name" placeholder="e.g. Apex Health Clinic" required style="width:100%;padding:10px 14px;border:1px solid #cbd5e1;border-radius:8px;font-size:14px;">
                </div>

                <div>
                    <label style="display:block;font-size:13px;font-weight:600;color:#334155;margin-bottom:6px;">Category</label>
                    <select name="category_id" style="width:100%;padding:10px 14px;border:1px solid #cbd5e1;border-radius:8px;font-size:14px;">
                        <option value="">-- Select Category --</option>
                        <?php while ($cat = $categories->fetch_assoc()): ?>
                            <option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
                        <?php endwhile; ?>
                    </select>
                </div>

                <div>
                    <label style="display:block;font-size:13px;font-weight:600;color:#334155;margin-bottom:6px;">Email</label>
                    <input type="email" name="email" placeholder="branch@example.com" style="width:100%;padding:10px 14px;border:1px solid #cbd5e1;border-radius:8px;font-size:14px;">
                </div>

                <div>
                    <label style="display:block;font-size:13px;font-weight:600;color:#334155;margin-bottom:6px;">Phone</label>
                    <input type="text" name="phone" placeholder="+1 (555) 000-0000" style="width:100%;padding:10px 14px;border:1px solid #cbd5e1;border-radius:8px;font-size:14px;">
                </div>

                <div>
                    <label style="display:block;font-size:13px;font-weight:600;color:#334155;margin-bottom:6px;">Website</label>
                    <input type="text" name="website" placeholder="www.example.com" style="width:100%;padding:10px 14px;border:1px solid #cbd5e1;border-radius:8px;font-size:14px;">
                </div>

                <div>
                    <button type="submit" style="width:100%;background:#0a1926;color:#c2f542;padding:11px 20px;border:none;border-radius:8px;font-weight:700;font-size:14px;cursor:pointer;">
                        Save Company
                    </button>
                </div>
            </form>
        </div>

        <!-- Companies Table -->
        <div style="background:white;border-radius:16px;box-shadow:0 4px 15px rgba(0,0,0,0.03);border:1px solid #e2e8f0;overflow:hidden;">
            <table style="width:100%;border-collapse:collapse;text-align:left;font-size:14px;">
                <thead>
                    <tr style="background:#f1f5f9;color:#475569;border-bottom:1px solid #e2e8f0;">
                        <th style="padding:14px 20px;font-weight:700;">ID</th>
                        <th style="padding:14px 20px;font-weight:700;">Company Name</th>
                        <th style="padding:14px 20px;font-weight:700;">Category</th>
                        <th style="padding:14px 20px;font-weight:700;">Average Rating</th>
                        <th style="padding:14px 20px;font-weight:700;">Reviews Count</th>
                        <th style="padding:14px 20px;font-weight:700;">Public Rating Link</th>
                        <th style="padding:14px 20px;font-weight:700;text-align:right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($customers && $customers->num_rows > 0): ?>
                        <?php while ($row = $customers->fetch_assoc()): 
                            $avg = getAverageRating($row['id'], $conn);
                            $cnt = getRatingCount($row['id'], $conn);
                            $rateUrl = $assetBase . "/rate/index.php?company=" . $row['id'];
                        ?>
                            <tr style="border-bottom:1px solid #f1f5f9;">
                                <td style="padding:14px 20px;color:#94a3b8;"><?php echo $row['id']; ?></td>
                                <td style="padding:14px 20px;font-weight:700;color:#0f172a;">
                                    <?php echo htmlspecialchars($row['company_name']); ?>
                                </td>
                                <td style="padding:14px 20px;color:#64748b;">
                                    <?php echo htmlspecialchars($row['category_name'] ?? 'General'); ?>
                                </td>
                                <td style="padding:14px 20px;color:#0f172a;font-weight:700;">
                                    <span style="color:#f59e0b;">★</span> <?php echo $avg; ?>
                                </td>
                                <td style="padding:14px 20px;color:#64748b;"><?php echo $cnt; ?></td>
                                <td style="padding:14px 20px;">
                                    <a href="<?php echo $rateUrl; ?>" target="_blank" style="color:#0284c7;font-weight:600;text-decoration:none;font-size:13px;">
                                        Open Rating Form &rarr;
                                    </a>
                                </td>
                                <td style="padding:14px 20px;text-align:right;">
                                    <a href="customers.php?delete=<?php echo $row['id']; ?>" onclick="return confirm('Are you sure you want to delete this company and all its ratings?');" style="color:#ef4444;text-decoration:none;font-weight:600;font-size:13px;">Delete</a>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" style="padding:30px;text-align:center;color:#94a3b8;">
                                No companies found. Use the form above to add your first company!
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </main>
</div>

</body>
</html>
