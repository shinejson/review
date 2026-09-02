<?php
require_once '../includes/auth.php';
require_once '../config/database.php';
require_once '../includes/functions.php';

requireLogin();

$tenant_id = getTenantId();
$is_tenant = isTenant();

$tenant_info = null;
$plan_info = null;

if ($is_tenant && $tenant_id) {
    // Fetch tenant details and plan
    $stmt = $conn->prepare("SELECT t.*, p.plan_name, p.max_ratings, p.max_customers, p.features 
                            FROM tenants t 
                            LEFT JOIN subscription_plans p ON t.plan_id = p.id 
                            WHERE t.id = ?");
    $stmt->bind_param("i", $tenant_id);
    $stmt->execute();
    $tenant_info = $stmt->get_result()->fetch_assoc();
    
    // Scoped metrics
    $stmt1 = $conn->prepare("SELECT COUNT(*) as count FROM customers WHERE tenant_id = ?");
    $stmt1->bind_param("i", $tenant_id);
    $stmt1->execute();
    $total_customers = $stmt1->get_result()->fetch_assoc()['count'] ?? 0;

    $stmt2 = $conn->prepare("SELECT COUNT(*) as count, AVG(r.rating) as avg_rating 
                            FROM ratings r 
                            JOIN customers c ON r.company_id = c.id 
                            WHERE c.tenant_id = ?");
    $stmt2->bind_param("i", $tenant_id);
    $stmt2->execute();
    $rating_res = $stmt2->get_result()->fetch_assoc();
    $total_ratings = $rating_res['count'] ?? 0;
    $avg_rating = round($rating_res['avg_rating'] ?? 0, 1);

    // Recent ratings
    $stmt3 = $conn->prepare("SELECT r.*, c.company_name 
                            FROM ratings r 
                            JOIN customers c ON r.company_id = c.id 
                            WHERE c.tenant_id = ? 
                            ORDER BY r.created_at DESC LIMIT 5");
    $stmt3->bind_param("i", $tenant_id);
    $stmt3->execute();
    $recent_ratings = $stmt3->get_result();

    // Tenant customers for quick links
    $stmt4 = $conn->prepare("SELECT id, company_name FROM customers WHERE tenant_id = ? ORDER BY company_name ASC");
    $stmt4->bind_param("i", $tenant_id);
    $stmt4->execute();
    $tenant_companies = $stmt4->get_result();
} else {
    // Global admin view
    $total_customers = $conn->query("SELECT COUNT(*) as count FROM customers")->fetch_assoc()['count'] ?? 0;
    $rating_res = $conn->query("SELECT COUNT(*) as count, AVG(rating) as avg_rating FROM ratings")->fetch_assoc();
    $total_ratings = $rating_res['count'] ?? 0;
    $avg_rating = round($rating_res['avg_rating'] ?? 0, 1);

    $recent_ratings = $conn->query("SELECT r.*, c.company_name FROM ratings r JOIN customers c ON r.company_id = c.id ORDER BY r.created_at DESC LIMIT 5");
    $tenant_companies = $conn->query("SELECT id, company_name FROM customers ORDER BY company_name ASC");
}

$pageTitle = 'Dashboard - Optibiz';
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
                <?php echo $is_tenant ? htmlspecialchars($tenant_info['company_name'] ?? 'Tenant Portal') : 'Global Admin'; ?>
            </span>
            <?php if ($is_tenant && !empty($tenant_info['plan_name'])): ?>
                <span style="background:rgba(194,245,66,0.2);color:#c2f542;padding:3px 10px;border-radius:12px;font-size:11px;font-weight:700;text-transform:uppercase;">
                    <?php echo htmlspecialchars($tenant_info['plan_name']); ?> Plan
                </span>
            <?php endif; ?>
        </div>

        <nav style="display:flex;align-items:center;gap:20px;">
            <a href="index.php" style="color:#c2f542;text-decoration:none;font-size:14px;font-weight:600;">Dashboard</a>
            <a href="customers.php" style="color:#cbd5e1;text-decoration:none;font-size:14px;font-weight:500;">Companies / Branches</a>
            <a href="ratings.php" style="color:#cbd5e1;text-decoration:none;font-size:14px;font-weight:500;">Ratings &amp; Reviews</a>
            <a href="categories.php" style="color:#cbd5e1;text-decoration:none;font-size:14px;font-weight:500;">Categories</a>
            <a href="settings.php" style="color:#cbd5e1;text-decoration:none;font-size:14px;font-weight:500;">Settings</a>
            <a href="logout.php" style="background:rgba(239,68,68,0.2);color:#f87171;padding:6px 14px;border-radius:20px;text-decoration:none;font-size:13px;font-weight:600;">Logout</a>
        </nav>
    </header>

    <!-- Main Container -->
    <main style="max-width:1240px;margin:30px auto;padding:0 20px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:28px;">
            <div>
                <h1 style="font-size:28px;font-weight:800;color:#0f172a;margin-bottom:4px;">
                    Welcome back, <?php echo htmlspecialchars($is_tenant ? ($tenant_info['company_name'] ?? 'Tenant') : ($_SESSION['admin_username'] ?? 'Admin')); ?>!
                </h1>
                <p style="color:#64748b;font-size:14px;">Here is an overview of your feedback and ratings performance.</p>
            </div>
            <div style="display:flex;gap:12px;">
                <a href="customers.php" style="background:#0a1926;color:white;padding:10px 20px;border-radius:10px;text-decoration:none;font-size:14px;font-weight:700;display:inline-flex;align-items:center;gap:8px;">
                    + Add Company / Branch
                </a>
            </div>
        </div>

        <!-- Metrics Grid -->
        <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(240px, 1fr));gap:20px;margin-bottom:30px;">
            <div style="background:white;padding:24px;border-radius:16px;box-shadow:0 4px 15px rgba(0,0,0,0.03);border:1px solid #e2e8f0;">
                <div style="font-size:13px;color:#64748b;font-weight:600;margin-bottom:8px;text-transform:uppercase;">Total Companies</div>
                <div style="font-size:36px;font-weight:800;color:#0f172a;"><?php echo number_format($total_customers); ?></div>
                <?php if ($is_tenant && !empty($tenant_info['max_customers'])): ?>
                    <div style="font-size:12px;color:#94a3b8;margin-top:4px;">Limit: <?php echo $total_customers; ?> / <?php echo $tenant_info['max_customers']; ?> allowed</div>
                <?php endif; ?>
            </div>

            <div style="background:white;padding:24px;border-radius:16px;box-shadow:0 4px 15px rgba(0,0,0,0.03);border:1px solid #e2e8f0;">
                <div style="font-size:13px;color:#64748b;font-weight:600;margin-bottom:8px;text-transform:uppercase;">Total Reviews</div>
                <div style="font-size:36px;font-weight:800;color:#0f172a;"><?php echo number_format($total_ratings); ?></div>
                <?php if ($is_tenant && !empty($tenant_info['max_ratings'])): ?>
                    <div style="font-size:12px;color:#94a3b8;margin-top:4px;">Limit: <?php echo $total_ratings; ?> / <?php echo $tenant_info['max_ratings']; ?> ratings</div>
                <?php endif; ?>
            </div>

            <div style="background:white;padding:24px;border-radius:16px;box-shadow:0 4px 15px rgba(0,0,0,0.03);border:1px solid #e2e8f0;">
                <div style="font-size:13px;color:#64748b;font-weight:600;margin-bottom:8px;text-transform:uppercase;">Average Score</div>
                <div style="font-size:36px;font-weight:800;color:#0f172a;display:flex;align-items:center;gap:8px;">
                    <?php echo $avg_rating; ?>
                    <span style="color:#f59e0b;font-size:24px;">★</span>
                </div>
                <div style="font-size:12px;color:#10b981;font-weight:600;margin-top:4px;">Based on verified feedback</div>
            </div>

            <div style="background:white;padding:24px;border-radius:16px;box-shadow:0 4px 15px rgba(0,0,0,0.03);border:1px solid #e2e8f0;">
                <div style="font-size:13px;color:#64748b;font-weight:600;margin-bottom:8px;text-transform:uppercase;">Subscription Status</div>
                <div style="font-size:22px;font-weight:800;color:#10b981;text-transform:capitalize;">
                    <?php echo htmlspecialchars($tenant_info['subscription_status'] ?? 'Active'); ?>
                </div>
                <div style="font-size:12px;color:#64748b;margin-top:6px;">
                    <?php echo $is_tenant ? 'Auto-renew active' : 'Platform Administrator'; ?>
                </div>
            </div>
        </div>

        <!-- Two Column Layout: Rating Links & Recent Reviews -->
        <div style="display:grid;grid-template-columns:1fr 1.6fr;gap:24px;">
            <!-- Public Rating Links Box -->
            <div style="background:white;padding:24px;border-radius:16px;box-shadow:0 4px 15px rgba(0,0,0,0.03);border:1px solid #e2e8f0;">
                <h3 style="font-size:18px;font-weight:700;color:#0f172a;margin-bottom:16px;">Your Public Rating Links</h3>
                <p style="font-size:13px;color:#64748b;margin-bottom:16px;">Share these links with your clients or print them as QR codes to collect feedback.</p>

                <?php if ($tenant_companies && $tenant_companies->num_rows > 0): ?>
                    <div style="display:flex;flex-direction:column;gap:12px;">
                        <?php while ($tc = $tenant_companies->fetch_assoc()): 
                            $rateUrl = $assetBase . "/rate/index.php?company=" . $tc['id'];
                        ?>
                            <div style="background:#f8fafc;padding:12px 14px;border-radius:10px;border:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;">
                                <div>
                                    <div style="font-weight:700;font-size:14px;color:#0f172a;"><?php echo htmlspecialchars($tc['company_name']); ?></div>
                                    <div style="font-size:12px;color:#94a3b8;"><?php echo $rateUrl; ?></div>
                                </div>
                                <div style="display:flex;gap:8px;">
                                    <a href="<?php echo $rateUrl; ?>" target="_blank" style="background:#0a1926;color:white;padding:6px 12px;border-radius:6px;font-size:12px;font-weight:600;text-decoration:none;">View</a>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    </div>
                <?php else: ?>
                    <div style="padding:20px;text-align:center;color:#94a3b8;font-size:14px;">
                        No companies registered yet. <a href="customers.php" style="color:#0a1926;font-weight:700;">Add your first company</a>.
                    </div>
                <?php endif; ?>
            </div>

            <!-- Recent Reviews -->
            <div style="background:white;padding:24px;border-radius:16px;box-shadow:0 4px 15px rgba(0,0,0,0.03);border:1px solid #e2e8f0;">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
                    <h3 style="font-size:18px;font-weight:700;color:#0f172a;">Recent Ratings &amp; Reviews</h3>
                    <a href="ratings.php" style="font-size:13px;color:#0a1926;font-weight:700;text-decoration:none;">View all &rarr;</a>
                </div>

                <?php if ($recent_ratings && $recent_ratings->num_rows > 0): ?>
                    <div style="display:flex;flex-direction:column;gap:12px;">
                        <?php while ($r = $recent_ratings->fetch_assoc()): ?>
                            <div style="background:#f8fafc;padding:14px;border-radius:10px;border:1px solid #e2e8f0;">
                                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
                                    <div style="font-weight:700;font-size:14px;color:#0f172a;">
                                        <?php echo htmlspecialchars($r['customer_name']); ?>
                                        <span style="font-weight:400;color:#64748b;font-size:12px;">for <?php echo htmlspecialchars($r['company_name']); ?></span>
                                    </div>
                                    <div style="color:#f59e0b;font-weight:700;font-size:13px;">
                                        <?php for ($i=0; $i<$r['rating']; $i++) echo '★'; ?>
                                        <span style="color:#0f172a;margin-left:4px;"><?php echo $r['rating']; ?>.0</span>
                                    </div>
                                </div>
                                <p style="font-size:13px;color:#475569;margin:0;line-height:1.4;">
                                    "<?php echo htmlspecialchars($r['comment'] ?: 'No comment left.'); ?>"
                                </p>
                                <div style="font-size:11px;color:#94a3b8;margin-top:6px;">
                                    <?php echo date('M d, Y - h:i A', strtotime($r['created_at'])); ?>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    </div>
                <?php else: ?>
                    <div style="padding:40px 20px;text-align:center;color:#94a3b8;font-size:14px;">
                        No ratings received yet. Share your rating link to collect reviews!
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>
</div>

</body>
</html>
