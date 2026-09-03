<?php
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';

requireLogin();

$tenant_id = getTenantId();
$is_tenant = isTenant();

$company_filter = isset($_GET['company_id']) ? (int)$_GET['company_id'] : 0;

// Fetch company list for filter
if ($is_tenant) {
    $comp_stmt = $conn->prepare("SELECT id, company_name FROM customers WHERE tenant_id = ? ORDER BY company_name ASC");
    $comp_stmt->bind_param("i", $tenant_id);
    $comp_stmt->execute();
    $companies_list = $comp_stmt->get_result();
} else {
    $companies_list = $conn->query("SELECT id, company_name FROM customers ORDER BY company_name ASC");
}

// Fetch ratings with scoping
if ($is_tenant) {
    $sql = "SELECT r.*, c.company_name 
            FROM ratings r 
            JOIN customers c ON r.company_id = c.id 
            WHERE c.tenant_id = ?";
    if ($company_filter > 0) {
        $sql .= " AND r.company_id = ?";
        $stmt = $conn->prepare($sql . " ORDER BY r.created_at DESC");
        $stmt->bind_param("ii", $tenant_id, $company_filter);
    } else {
        $stmt = $conn->prepare($sql . " ORDER BY r.created_at DESC");
        $stmt->bind_param("i", $tenant_id);
    }
    $stmt->execute();
    $ratings = $stmt->get_result();
} else {
    $sql = "SELECT r.*, c.company_name FROM ratings r JOIN customers c ON r.company_id = c.id";
    if ($company_filter > 0) {
        $stmt = $conn->prepare($sql . " WHERE r.company_id = ? ORDER BY r.created_at DESC");
        $stmt->bind_param("i", $company_filter);
        $stmt->execute();
        $ratings = $stmt->get_result();
    } else {
        $ratings = $conn->query($sql . " ORDER BY r.created_at DESC");
    }
}

$robots    = 'noindex, nofollow';

$BASE = '../';
$pageTitle = 'Ratings & Reviews';
$activeNav = 'ratings';
include __DIR__ . '/_shell.php';
?>
        <div class="page-header">
            <div>
                <h1>Customer Ratings &amp; Reviews</h1>
                <p>Browse and monitor real-time reviews submitted by your customers.</p>
            </div>

            <!-- Filter form -->
            <form method="GET" class="filter-form">
                <select name="company_id" onchange="this.form.submit()">
                    <option value="0">-- All Companies --</option>
                    <?php while ($c = $companies_list->fetch_assoc()): ?>
                        <option value="<?php echo $c['id']; ?>" <?php echo $company_filter === (int)$c['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($c['company_name']); ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </form>
        </div>

        <!-- Ratings Table -->
        <div class="data-table-card">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Target Company</th>
                        <th>Rating</th>
                        <th>Customer</th>
                        <th>Feedback / Comment</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($ratings && $ratings->num_rows > 0): ?>
                        <?php while ($r = $ratings->fetch_assoc()): ?>
                            <tr>
                                <td class="table-id"><?php echo $r['id']; ?></td>
                                <td class="table-title">
                                    <?php echo htmlspecialchars($r['company_name']); ?>
                                </td>
                                <td>
                                    <div class="table-rating">
                                        <?php for ($i=0; $i<$r['rating']; $i++) echo '★'; ?>
                                        <span><?php echo $r['rating']; ?>.0</span>
                                    </div>
                                </td>
                                <td>
                                    <div class="table-title"><?php echo htmlspecialchars($r['customer_name']); ?></div>
                                    <div class="table-meta"><?php echo htmlspecialchars($r['customer_email']); ?></div>
                                </td>
                                <td class="table-text">
                                    <?php echo htmlspecialchars($r['comment'] ?: 'No comment provided.'); ?>
                                </td>
                                <td class="table-meta">
                                    <?php echo date('M d, Y', strtotime($r['created_at'])); ?>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" class="table-empty">
                                No ratings found for the selected company.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </main>
<?php include __DIR__ . '/_shell_footer.php'; ?>
