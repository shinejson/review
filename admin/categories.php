<?php
require_once '../includes/auth.php';
require_once '../config/database.php';
require_once '../includes/functions.php';

requireLogin();

$tenant_id = getTenantId();
$is_tenant = isTenant();

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['name'])) {
    $name = sanitize($_POST['name']);
    if (!empty($name)) {
        $stmt = $conn->prepare("INSERT INTO categories (name) VALUES (?)");
        $stmt->bind_param("s", $name);
        if ($stmt->execute()) {
            $success = "Category '{$name}' added successfully.";
        } else {
            $error = "Failed to add category.";
        }
    }
}

$categories = $conn->query("SELECT c.*, (SELECT COUNT(*) FROM customers WHERE category_id = c.id) as company_count FROM categories c ORDER BY c.name ASC");

$pageTitle = 'Manage Categories - Optibiz';
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
            <a href="customers.php" style="color:#cbd5e1;text-decoration:none;font-size:14px;font-weight:500;">Companies / Branches</a>
            <a href="ratings.php" style="color:#cbd5e1;text-decoration:none;font-size:14px;font-weight:500;">Ratings &amp; Reviews</a>
            <a href="categories.php" style="color:#c2f542;text-decoration:none;font-size:14px;font-weight:600;">Categories</a>
            <a href="settings.php" style="color:#cbd5e1;text-decoration:none;font-size:14px;font-weight:500;">Settings</a>
            <a href="logout.php" style="background:rgba(239,68,68,0.2);color:#f87171;padding:6px 14px;border-radius:20px;text-decoration:none;font-size:13px;font-weight:600;">Logout</a>
        </nav>
    </header>

    <main style="max-width:1240px;margin:30px auto;padding:0 20px;">
        <div style="margin-bottom:24px;">
            <h1 style="font-size:26px;font-weight:800;color:#0f172a;">Industry Categories</h1>
            <p style="color:#64748b;font-size:14px;">Organize your branches and businesses by industry sector.</p>
        </div>

        <?php if ($success): ?>
            <div style="background:#ecfdf5;color:#065f46;padding:12px 16px;border-radius:8px;margin-bottom:20px;font-size:14px;font-weight:600;border:1px solid #a7f3d0;">
                ✓ <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>

        <!-- Add Category Form -->
        <div style="background:white;padding:24px;border-radius:16px;box-shadow:0 4px 15px rgba(0,0,0,0.03);border:1px solid #e2e8f0;margin-bottom:30px;">
            <h3 style="font-size:18px;font-weight:700;color:#0f172a;margin-bottom:16px;">Add New Category</h3>
            <form method="POST" style="display:flex;gap:16px;align-items:center;max-width:500px;">
                <input type="text" name="name" placeholder="e.g. Legal & Compliance" required style="flex:1;padding:10px 14px;border:1px solid #cbd5e1;border-radius:8px;font-size:14px;">
                <button type="submit" style="background:#0a1926;color:#c2f542;padding:11px 20px;border:none;border-radius:8px;font-weight:700;font-size:14px;cursor:pointer;white-space:nowrap;">
                    Add Category
                </button>
            </form>
        </div>

        <!-- Categories Table -->
        <div style="background:white;border-radius:16px;box-shadow:0 4px 15px rgba(0,0,0,0.03);border:1px solid #e2e8f0;overflow:hidden;max-width:800px;">
            <table style="width:100%;border-collapse:collapse;text-align:left;font-size:14px;">
                <thead>
                    <tr style="background:#f1f5f9;color:#475569;border-bottom:1px solid #e2e8f0;">
                        <th style="padding:14px 20px;font-weight:700;">ID</th>
                        <th style="padding:14px 20px;font-weight:700;">Category Name</th>
                        <th style="padding:14px 20px;font-weight:700;">Active Companies</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($categories && $categories->num_rows > 0): ?>
                        <?php while ($cat = $categories->fetch_assoc()): ?>
                            <tr style="border-bottom:1px solid #f1f5f9;">
                                <td style="padding:14px 20px;color:#94a3b8;"><?php echo $cat['id']; ?></td>
                                <td style="padding:14px 20px;font-weight:700;color:#0f172a;">
                                    <?php echo htmlspecialchars($cat['name']); ?>
                                </td>
                                <td style="padding:14px 20px;color:#64748b;">
                                    <?php echo $cat['company_count']; ?> companies
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </main>
</div>

</body>
</html>
