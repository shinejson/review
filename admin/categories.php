<?php
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';

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

$robots    = 'noindex, nofollow';

$BASE = '../';
$pageTitle = 'Categories';
$activeNav = 'categories';
include __DIR__ . '/_shell.php';
?>
        <div class="page-header">
            <div>
                <h1>Industry Categories</h1>
                <p>Organize your branches and businesses by industry sector.</p>
            </div>
        </div>

        <?php if ($success): ?>
            <div class="alert alert-success">
                ✓ <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>

        <!-- Add Category Form -->
        <div class="form-card">
            <h3>Add New Category</h3>
            <form method="POST" style="display:flex;gap:16px;align-items:center;max-width:500px;">
                <input type="text" name="name" placeholder="e.g. Legal & Compliance" required style="flex:1;padding:10px 14px;border:1px solid #cbd5e1;border-radius:8px;font-size:14px;">
                <button type="submit" class="btn btn-primary" style="white-space:nowrap;">
                    Add Category
                </button>
            </form>
        </div>

        <!-- Categories Table -->
        <div class="data-table-card" style="max-width:800px;">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Category Name</th>
                        <th>Active Companies</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($categories && $categories->num_rows > 0): ?>
                        <?php while ($cat = $categories->fetch_assoc()): ?>
                            <tr>
                                <td class="table-id"><?php echo $cat['id']; ?></td>
                                <td class="table-title">
                                    <?php echo htmlspecialchars($cat['name']); ?>
                                </td>
                                <td class="table-subtitle">
                                    <?php echo $cat['company_count']; ?> companies
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </main>
<?php include __DIR__ . '/_shell_footer.php'; ?>
