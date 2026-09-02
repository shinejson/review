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

$pageTitle = 'Ratings & Reviews - Optibiz';
$extraCss = ['/assets/css/auth.css'];
include dirname(__DIR__) . '/includes/header.php';
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
            <a href="ratings.php" style="color:#c2f542;text-decoration:none;font-size:14px;font-weight:600;">Ratings &amp; Reviews</a>
            <a href="categories.php" style="color:#cbd5e1;text-decoration:none;font-size:14px;font-weight:500;">Categories</a>
            <a href="settings.php" style="color:#cbd5e1;text-decoration:none;font-size:14px;font-weight:500;">Settings</a>
            <a href="logout.php" style="background:rgba(239,68,68,0.2);color:#f87171;padding:6px 14px;border-radius:20px;text-decoration:none;font-size:13px;font-weight:600;">Logout</a>
        </nav>
    </header>

    <main style="max-width:1240px;margin:30px auto;padding:0 20px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;">
            <div>
                <h1 style="font-size:26px;font-weight:800;color:#0f172a;">Customer Ratings &amp; Reviews</h1>
                <p style="color:#64748b;font-size:14px;">Browse and monitor real-time reviews submitted by your customers.</p>
            </div>

            <!-- Filter form -->
            <form method="GET" style="display:flex;align-items:center;gap:10px;">
                <select name="company_id" onchange="this.form.submit()" style="padding:10px 16px;border:1px solid #cbd5e1;border-radius:8px;font-size:14px;background:white;">
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
        <div style="background:white;border-radius:16px;box-shadow:0 4px 15px rgba(0,0,0,0.03);border:1px solid #e2e8f0;overflow:hidden;">
            <table style="width:100%;border-collapse:collapse;text-align:left;font-size:14px;">
                <thead>
                    <tr style="background:#f1f5f9;color:#475569;border-bottom:1px solid #e2e8f0;">
                        <th style="padding:14px 20px;font-weight:700;">ID</th>
                        <th style="padding:14px 20px;font-weight:700;">Target Company</th>
                        <th style="padding:14px 20px;font-weight:700;">Rating</th>
                        <th style="padding:14px 20px;font-weight:700;">Customer</th>
                        <th style="padding:14px 20px;font-weight:700;">Feedback / Comment</th>
                        <th style="padding:14px 20px;font-weight:700;">Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($ratings && $ratings->num_rows > 0): ?>
                        <?php while ($r = $ratings->fetch_assoc()): ?>
                            <tr style="border-bottom:1px solid #f1f5f9;">
                                <td style="padding:14px 20px;color:#94a3b8;"><?php echo $r['id']; ?></td>
                                <td style="padding:14px 20px;font-weight:700;color:#0f172a;">
                                    <?php echo htmlspecialchars($r['company_name']); ?>
                                </td>
                                <td style="padding:14px 20px;">
                                    <div style="color:#f59e0b;font-weight:700;font-size:15px;display:flex;align-items:center;gap:4px;">
                                        <?php for ($i=0; $i<$r['rating']; $i++) echo '★'; ?>
                                        <span style="color:#0f172a;font-size:13px;margin-left:4px;"><?php echo $r['rating']; ?>.0</span>
                                    </div>
                                </td>
                                <td style="padding:14px 20px;">
                                    <div style="font-weight:600;color:#0f172a;"><?php echo htmlspecialchars($r['customer_name']); ?></div>
                                    <div style="font-size:12px;color:#94a3b8;"><?php echo htmlspecialchars($r['customer_email']); ?></div>
                                </td>
                                <td style="padding:14px 20px;color:#475569;max-width:340px;line-height:1.4;">
                                    <?php echo htmlspecialchars($r['comment'] ?: 'No comment provided.'); ?>
                                </td>
                                <td style="padding:14px 20px;color:#94a3b8;font-size:13px;">
                                    <?php echo date('M d, Y', strtotime($r['created_at'])); ?>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" style="padding:40px;text-align:center;color:#94a3b8;">
                                No ratings found for the selected company.
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
