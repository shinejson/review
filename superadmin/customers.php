<?php
/**
 * ============================================================
 *  Super Admin — Customers (tenant companies)
 * ============================================================
 *  "Customers" are the companies registered on the platform:
 *  each one belongs to a tenant account and carries the industry
 *  category that was chosen when that tenant signed up. Only the
 *  super admin manages this directory; the admin/ portal is
 *  read-only for tenants' own ratings and settings.
 */

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/sa_helpers.php';

requireSuperAdminLogin();
require_sa_permission('customers');

/* ---------- reference data (fetched first so the POST guards can use it) ---------- */
$categories = sa_query(
    $conn,
    "SELECT c.*,
            (SELECT COUNT(*) FROM customers WHERE category_id = c.id) AS company_count
       FROM categories c
      ORDER BY c.name ASC",
    ['categories', 'customers']
);

$tenants = sa_query(
    $conn,
    "SELECT id, company_name FROM tenants ORDER BY company_name ASC",
    ['tenants']
);

/* ---------- POST handlers ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!sa_csrf_ok()) {
        sa_flash('error', 'Your session expired. Please try again.');
        redirect('customers.php');
    }
    $action = isset($_POST['action']) ? $_POST['action'] : '';

    if ($action === 'create') {
        $tenant_id    = (int) ($_POST['tenant_id'] ?? 0);
        $company_name = sanitize($_POST['company_name'] ?? '');
        $category_id  = !empty($_POST['category_id']) ? (int) $_POST['category_id'] : null;
        $email        = sanitize($_POST['email'] ?? '');
        $phone        = sanitize($_POST['phone'] ?? '');
        $website      = sanitize($_POST['website'] ?? '');

        $tenant = null;
        foreach ($tenants as $t) {
            if ((int) $t['id'] === $tenant_id) {
                $tenant = $t;
            }
        }
        $category_ok = $category_id === null;
        foreach ($categories as $c) {
            if ((int) $c['id'] === (int) $category_id) {
                $category_ok = true;
            }
        }

        if (!$tenant) {
            sa_flash('error', 'Choose the tenant account this customer belongs to.');
        } elseif ($company_name === '') {
            sa_flash('error', 'A company / branch name is required.');
        } elseif (!$category_ok) {
            sa_flash('error', 'That category no longer exists.');
        } else {
            $limit = (int) sa_scalar(
                $conn,
                "SELECT p.max_customers FROM tenants t JOIN subscription_plans p ON t.plan_id = p.id WHERE t.id = " . $tenant_id,
                0,
                ['tenants', 'subscription_plans']
            );
            $current = (int) sa_scalar(
                $conn,
                "SELECT COUNT(*) FROM customers WHERE tenant_id = " . $tenant_id,
                0,
                ['customers']
            );

            if ($limit > 0 && $limit < 999 && $current >= $limit) {
                sa_flash(
                    'error',
                    $tenant['company_name'] . ' has reached its plan limit of ' . sa_num($limit)
                        . ' customers (' . sa_num($current) . ' used). Upgrade the tenant\'s subscription first.'
                );
            } else {
                $stmt = $conn->prepare(
                    "INSERT INTO customers (tenant_id, company_name, category_id, email, phone, website)
                     VALUES (?, ?, ?, ?, ?, ?)"
                );
                $stmt->bind_param("isisss", $tenant_id, $company_name, $category_id, $email, $phone, $website);
                $stmt->execute();

                if ($stmt->error) {
                    sa_flash('error', 'Could not add the customer: ' . $stmt->error);
                } else {
                    $cat_name = 'Uncategorised';
                    foreach ($categories as $c) {
                        if ((int) $c['id'] === (int) $category_id) {
                            $cat_name = $c['name'];
                        }
                    }
                    sa_flash('success', $company_name . ' was added to ' . $tenant['company_name'] . ' under ' . $cat_name . '.');
                }
                $stmt->close();
            }
        }
        redirect('customers.php');
    }

    if ($action === 'delete') {
        $id = (int) ($_POST['customer_id'] ?? 0);
        if ($id) {
            $stmt = $conn->prepare("DELETE FROM customers WHERE id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();

            if ($stmt->error) {
                sa_flash('error', 'Could not delete the customer: ' . $stmt->error);
            } elseif ($stmt->affected_rows > 0) {
                sa_flash('success', 'The customer and all of its ratings were deleted.');
            } else {
                sa_flash('error', 'That customer no longer exists.');
            }
            $stmt->close();
        } else {
            sa_flash('error', 'That customer no longer exists.');
        }
        redirect('customers.php');
    }
}

/* ---------- filters ---------- */
$category_filter = (int) ($_GET['category'] ?? 0);
$q = isset($_GET['q']) ? trim($_GET['q']) : '';

$where = '';
if ($category_filter) {
    $where = " WHERE c.category_id = " . $category_filter;
}
if ($q !== '') {
    $like = '%' . $q . '%';
    $escaped = $conn->real_escape_string($like);
    $where .= ($where === '' ? ' WHERE ' : ' AND ')
        . " (c.company_name LIKE '" . $escaped . "' OR t.company_name LIKE '" . $escaped . "')";
}

/* ---------- data ---------- */
$customers = sa_query(
    $conn,
    "SELECT c.*, cat.name AS category_name, t.company_name AS tenant_company
       FROM customers c
       LEFT JOIN categories cat ON c.category_id = cat.id
       LEFT JOIN tenants t ON c.tenant_id = t.id"
    . $where . " ORDER BY c.company_name ASC",
    ['customers', 'categories', 'tenants']
);

$total_customers = (int) sa_scalar($conn, "SELECT COUNT(*) FROM customers", 0, ['customers']);
$uncategorised = (int) sa_scalar($conn, "SELECT COUNT(*) FROM customers WHERE category_id IS NULL", 0, ['customers']);
$platform_avg = (float) sa_scalar($conn, "SELECT AVG(rating) FROM ratings", 0, ['ratings']);
$categorised_pct = $total_customers > 0 ? sa_pct($total_customers - $uncategorised, $total_customers, 0) : 0;
$categories_in_use = 0;
foreach ($categories as $c) {
    if ((int) $c['company_count'] > 0) {
        $categories_in_use++;
    }
}

/* ---------- page meta ---------- */
$robots    = 'noindex, nofollow';
$pageTitle = 'Customers';
$pageHeading = 'Customers';
$pageSubtitle = 'The tenant companies on the platform, each filed under the category chosen at registration.';
$activePage = 'customers';
$BASE = '../';
$extraCss = ['assets/css/superadmin.css'];
$bodyClass = 'sa-body';

include dirname(__DIR__) . '/includes/header.php';
include __DIR__ . '/_shell.php';
?>

<div class="sa-page-head">
    <div>
        <div class="sa-crumbs">
            <a href="index.php">Super admin</a>
            <?php echo sa_icon('chevron-right'); ?>
            <span>Customers</span>
        </div>
        <h2>Customers</h2>
        <p><?php echo sa_e(sa_num($total_customers)); ?> customers &middot;
           <?php echo sa_e(sa_num($categories_in_use)); ?> categories in use &middot;
           average rating <?php echo sa_e(number_format($platform_avg, 1)); ?>/5</p>
    </div>
    <div class="sa-head-actions">
        <button type="button" class="sa-btn sa-btn-primary" data-sa-open-dialog="#customerDialog">
            <?php echo sa_icon('plus'); ?> New customer
        </button>
    </div>
</div>

<?php echo sa_render_flash(); ?>

<div class="sa-grid sa-kpis sa-anim">
    <article class="sa-card sa-kpi" style="--kpi-accent:var(--sa-info);--kpi-soft:var(--sa-info-soft);--kpi-line:var(--sa-info-line)">
        <div class="sa-kpi-top">
            <span class="sa-kpi-label">Customers</span>
            <span class="sa-kpi-icon"><?php echo sa_icon('building'); ?></span>
        </div>
        <div class="sa-kpi-value"><?php echo sa_e(sa_num($total_customers)); ?></div>
        <div class="sa-kpi-note">Companies registered across every tenant</div>
    </article>

    <article class="sa-card sa-kpi" style="--kpi-accent:var(--sa-success);--kpi-soft:var(--sa-success-soft);--kpi-line:var(--sa-success-line)">
        <div class="sa-kpi-top">
            <span class="sa-kpi-label">Categorised</span>
            <span class="sa-kpi-icon"><?php echo sa_icon('check-circle'); ?></span>
        </div>
        <div class="sa-kpi-value"><?php echo sa_e($categorised_pct); ?>%</div>
        <div class="sa-kpi-note">Customers filed under a registration category</div>
    </article>

    <article class="sa-card sa-kpi" style="--kpi-accent:var(--sa-warning);--kpi-soft:var(--sa-warning-soft);--kpi-line:var(--sa-warning-line)">
        <div class="sa-kpi-top">
            <span class="sa-kpi-label">Average rating</span>
            <span class="sa-kpi-icon"><?php echo sa_icon('star'); ?></span>
        </div>
        <div class="sa-kpi-value"><?php echo sa_e(number_format($platform_avg, 1)); ?><span style="font-size:.5em;color:var(--sa-muted)"> / 5</span></div>
        <div class="sa-kpi-note">Across every review collected</div>
    </article>
</div>

<section class="sa-card">
    <div class="sa-card-head">
        <div>
            <h3>Customer directory</h3>
            <p>Each customer belongs to a tenant account and keeps the category that tenant chose when registering.</p>
        </div>
    </div>

    <div class="sa-filters">
        <div class="sa-chips">
            <a class="sa-chip<?php echo $category_filter === 0 ? ' active' : ''; ?>"
               href="customers.php<?php echo $q !== '' ? '?q=' . urlencode($q) : ''; ?>"
               aria-pressed="<?php echo $category_filter === 0 ? 'true' : 'false'; ?>">
                All categories<span class="count"><?php echo (int) $total_customers; ?></span>
            </a>
<?php foreach ($categories as $c): ?>
            <a class="sa-chip<?php echo $category_filter === (int) $c['id'] ? ' active' : ''; ?>"
               href="customers.php?category=<?php echo (int) $c['id']; ?><?php echo $q !== '' ? '&q=' . urlencode($q) : ''; ?>"
               aria-pressed="<?php echo $category_filter === (int) $c['id'] ? 'true' : 'false'; ?>">
                <?php echo sa_e($c['name']); ?><span class="count"><?php echo (int) $c['company_count']; ?></span>
            </a>
<?php endforeach; ?>
        </div>

        <form method="GET" action="customers.php" style="margin-left:auto;display:flex;gap:8px;align-items:center">
            <?php if ($category_filter): ?>
            <input type="hidden" name="category" value="<?php echo (int) $category_filter; ?>">
            <?php endif; ?>
            <div class="sa-search" style="display:block;width:min(280px,52vw)">
                <?php echo sa_icon('search'); ?>
                <input type="search" name="q" value="<?php echo sa_e($q); ?>" placeholder="Search customer or tenant…" aria-label="Search customers">
            </div>
            <button type="submit" class="sa-btn sa-btn-sm sa-btn-ghost">Search</button>
<?php if ($q !== '' || $category_filter): ?>
            <a class="sa-btn sa-btn-sm sa-btn-ghost" href="customers.php" title="Clear filters"><?php echo sa_icon('x'); ?></a>
<?php endif; ?>
        </form>
    </div>
</section>

<!-- ============ TABLE ============ -->
<section class="sa-card">
    <div class="sa-table-wrap">
        <table class="sa-table" id="customersTable" data-sa-sortable-table>
            <thead>
                <tr>
                    <th data-sa-sort="0" data-type="num" scope="col" aria-sort="none">ID</th>
                    <th data-sa-sort="1" scope="col" aria-sort="none">Customer</th>
                    <th data-sa-sort="2" scope="col" aria-sort="none">Tenant account</th>
                    <th data-sa-sort="3" scope="col" aria-sort="none">Category chosen</th>
                    <th data-sa-sort="4" data-type="num" scope="col" aria-sort="none">Rating</th>
                    <th data-sa-sort="5" data-type="date" scope="col" aria-sort="none">Added</th>
                    <th data-no-export scope="col"><span class="sa-sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody>
<?php if (!$customers): ?>
                <tr data-static>
                    <td colspan="7">
                        <div class="sa-empty">
                            <?php echo sa_icon('building'); ?>
                            <strong>No customers found</strong>
                            <p><?php echo $q !== '' || $category_filter
                                ? 'Try clearing the search box or switching the category filter.'
                                : 'Convert a quote request into a tenant, then add its first customer here.'; ?></p>
                        </div>
                    </td>
                </tr>
<?php else: ?>
<?php foreach ($customers as $row):
    $avg = getAverageRating($row['id'], $conn);
    $cnt = getRatingCount($row['id'], $conn);
    $rate_url = $assetBase . '/rate/index.php?company=' . (int) $row['id'];
?>
                <tr>
                    <td><?php echo (int) $row['id']; ?></td>
                    <td>
                        <div class="sa-cell-main">
                            <strong><?php echo sa_e($row['company_name']); ?></strong>
                            <?php if (!empty($row['email']) || !empty($row['phone'])): ?>
                            <span><?php echo sa_e(trim(($row['email'] ?? '') . ' ' . ($row['phone'] ?? ''))); ?></span>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td>
<?php if (!empty($row['tenant_company'])): ?>
                        <a href="tenant_details.php?id=<?php echo (int) $row['tenant_id']; ?>"><?php echo sa_e($row['tenant_company']); ?></a>
<?php else: ?>
                        <span class="sa-faint">Unknown tenant</span>
<?php endif; ?>
                    </td>
                    <td>
<?php if (!empty($row['category_name'])): ?>
                        <span class="sa-badge sa-badge-info"><?php echo sa_e($row['category_name']); ?></span>
<?php else: ?>
                        <span class="sa-badge sa-badge-inactive">Uncategorised</span>
<?php endif; ?>
                    </td>
                    <td>
                        <?php echo sa_stars($avg); ?>
                        <span class="sa-faint">(<?php echo sa_e(sa_num($cnt)); ?>)</span>
                    </td>
                    <td><?php echo sa_date(isset($row['created_at']) ? $row['created_at'] : null); ?></td>
                    <td>
                        <div class="sa-flex" style="gap:6px;justify-content:flex-end">
                            <a class="sa-btn sa-btn-sm sa-btn-ghost" href="<?php echo sa_e($rate_url); ?>" target="_blank" rel="noopener" title="Open the public rating form">
                                <?php echo sa_icon('external'); ?> Rate
                            </a>
                            <form method="POST" action="customers.php" style="display:inline"
                                  onsubmit="return confirm('Delete <?php echo sa_e(addslashes($row['company_name'])); ?> and all of its ratings?');">
                                <?php echo sa_csrf_field(); ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="customer_id" value="<?php echo (int) $row['id']; ?>">
                                <button type="submit" class="sa-btn sa-btn-sm sa-btn-danger" title="Delete customer"><?php echo sa_icon('trash'); ?></button>
                            </form>
                        </div>
                    </td>
                </tr>
<?php endforeach; ?>
<?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="sa-card-foot">
        <span><?php echo sa_e(sa_num(count($customers))); ?> customer<?php echo count($customers) === 1 ? '' : 's'; ?> listed</span>
        <a class="sa-faint" href="categories.php">Manage the category list &rarr;</a>
    </div>
</section>

<!-- ============ NEW CUSTOMER DIALOG ============ -->
<dialog class="sa-dialog" id="customerDialog" aria-labelledby="cust_title">
    <form method="POST" action="customers.php" class="sa-form">
        <?php echo sa_csrf_field(); ?>
        <input type="hidden" name="action" value="create">
        <div class="sa-dialog-head">
            <div>
                <h3 id="cust_title">New customer</h3>
                <p>A company or branch registered under a tenant account, filed with its industry category.</p>
            </div>
            <button type="button" class="sa-dialog-close" data-sa-close-dialog aria-label="Close"><?php echo sa_icon('x'); ?></button>
        </div>

        <div class="sa-dialog-body">
            <div class="sa-form-grid">
                <div class="sa-field">
                    <label for="cust_tenant">Tenant account *</label>
                    <select id="cust_tenant" name="tenant_id" required>
                        <option value="">-- Select tenant --</option>
<?php foreach ($tenants as $t): ?>
                        <option value="<?php echo (int) $t['id']; ?>"><?php echo sa_e($t['company_name']); ?></option>
<?php endforeach; ?>
                    </select>
                    <span class="sa-hint">The tenant that chose this category when registering.</span>
                </div>

                <div class="sa-field">
                    <label for="cust_category">Category</label>
                    <select id="cust_category" name="category_id">
                        <option value="">-- Uncategorised --</option>
<?php foreach ($categories as $c): ?>
                        <option value="<?php echo (int) $c['id']; ?>"><?php echo sa_e($c['name']); ?></option>
<?php endforeach; ?>
                    </select>
                </div>

                <div class="sa-field" style="grid-column:1/-1">
                    <label for="cust_name">Company / branch name *</label>
                    <input id="cust_name" type="text" name="company_name" placeholder="e.g. Apex Health Clinic" required maxlength="255">
                </div>

                <div class="sa-field">
                    <label for="cust_email">Email</label>
                    <input id="cust_email" type="email" name="email" placeholder="branch@example.com">
                </div>

                <div class="sa-field">
                    <label for="cust_phone">Phone</label>
                    <input id="cust_phone" type="text" name="phone" placeholder="+1 (555) 000-0000">
                </div>

                <div class="sa-field" style="grid-column:1/-1">
                    <label for="cust_website">Website</label>
                    <input id="cust_website" type="text" name="website" placeholder="www.example.com">
                </div>
            </div>
        </div>

        <div class="sa-dialog-foot">
            <button type="button" class="sa-btn sa-btn-ghost" data-sa-close-dialog>Cancel</button>
            <button type="submit" class="sa-btn sa-btn-primary"><?php echo sa_icon('check'); ?> Save customer</button>
        </div>
    </form>
</dialog>

<?php include __DIR__ . '/_shell_footer.php'; ?>
