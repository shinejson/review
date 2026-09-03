<?php
/**
 * ============================================================
 *  Super Admin — Industry categories
 * ============================================================
 *  Categories are platform-wide reference data: the super admin
 *  curates the list that companies choose from when they sign up
 *  through the public "Get Started" wizard, and the one tenants
 *  use to classify their branches. Tenants cannot edit it.
 */

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/sa_helpers.php';

requireSuperAdminLogin();
require_sa_permission('categories');

/* ---------- data (fetched first so the POST guards can use it) ---------- */
$categories = sa_query(
    $conn,
    "SELECT c.*,
            (SELECT COUNT(*) FROM customers WHERE category_id = c.id) AS company_count,
            (SELECT COUNT(*) FROM quote_requests WHERE category_id = c.id) AS quote_count
       FROM categories c
      ORDER BY c.name ASC",
    ['categories', 'customers', 'quote_requests']
);

$uncategorised = (int) sa_scalar(
    $conn,
    "SELECT COUNT(*) FROM customers WHERE category_id IS NULL",
    0,
    ['customers']
);

/* ---------- POST handlers ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!sa_csrf_ok()) {
        sa_flash('error', 'Your session expired. Please try again.');
        redirect('categories.php');
    }
    $action = isset($_POST['action']) ? $_POST['action'] : '';

    if ($action === 'create' || $action === 'rename') {
        $id   = (int) ($_POST['category_id'] ?? 0);
        $name = sanitize($_POST['name'] ?? '');

        $taken = false;
        $exists = false;
        foreach ($categories as $c) {
            if ((int) $c['id'] === $id) {
                $exists = true;
            }
            if (strcasecmp((string) $c['name'], $name) === 0 && (int) $c['id'] !== $id) {
                $taken = true;
            }
        }

        if ($name === '') {
            sa_flash('error', 'A category name is required.');
        } elseif ($taken) {
            sa_flash('error', 'A category called “' . $name . '” already exists.');
        } elseif ($action === 'rename' && !$exists) {
            sa_flash('error', 'That category no longer exists.');
        } elseif ($action === 'create') {
            $stmt = $conn->prepare("INSERT INTO categories (name) VALUES (?)");
            $stmt->bind_param("s", $name);
            $stmt->execute();
            sa_flash(
                $stmt->error ? 'error' : 'success',
                $stmt->error ? 'Could not create the category: ' . $stmt->error
                             : $name . ' was added to the category list.'
            );
            $stmt->close();
        } else {
            $stmt = $conn->prepare("UPDATE categories SET name = ? WHERE id = ?");
            $stmt->bind_param("si", $name, $id);
            $stmt->execute();
            sa_flash(
                $stmt->error ? 'error' : 'success',
                $stmt->error ? 'Could not rename the category: ' . $stmt->error
                             : 'The category was renamed to ' . $name . '.'
            );
            $stmt->close();
        }
        redirect('categories.php');
    }

    if ($action === 'delete') {
        $id = (int) ($_POST['category_id'] ?? 0);
        $row = null;
        foreach ($categories as $c) {
            if ((int) $c['id'] === $id) {
                $row = $c;
            }
        }

        if (!$row) {
            sa_flash('error', 'That category no longer exists.');
        } elseif ((int) $row['company_count'] > 0 || (int) $row['quote_count'] > 0) {
            sa_flash(
                'error',
                $row['name'] . ' is still used by ' . sa_num($row['company_count']) . ' customer'
                    . ((int) $row['company_count'] === 1 ? '' : 's')
                    . ' and ' . sa_num($row['quote_count']) . ' quote request'
                    . ((int) $row['quote_count'] === 1 ? '' : 's')
                    . '. Reassign those first, then delete it.'
            );
        } else {
            $stmt = $conn->prepare("DELETE FROM categories WHERE id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            sa_flash(
                $stmt->error ? 'error' : 'success',
                $stmt->error ? 'Could not delete the category: ' . $stmt->error
                             : $row['name'] . ' was deleted.'
            );
            $stmt->close();
        }
        redirect('categories.php');
    }
}

/* ---------- stats ---------- */
$total_categories = count($categories);
$in_use = 0;
foreach ($categories as $c) {
    if ((int) $c['company_count'] > 0) {
        $in_use++;
    }
}

/* ---------- page meta ---------- */
$robots    = 'noindex, nofollow';
$pageTitle = 'Categories';
$pageHeading = 'Industry categories';
$pageSubtitle = 'The list companies choose a sector from when they register. Curated by the platform team.';
$activePage = 'categories';
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
            <span>Categories</span>
        </div>
        <h2>Industry categories</h2>
        <p><?php echo sa_e(sa_num($total_categories)); ?> categories &middot;
           <?php echo sa_e(sa_num($in_use)); ?> in use &middot;
           chosen during registration and on every customer record.</p>
    </div>
    <div class="sa-head-actions">
        <button type="button" class="sa-btn sa-btn-primary" data-sa-open-dialog="#categoryDialog">
            <?php echo sa_icon('plus'); ?> New category
        </button>
    </div>
</div>

<?php echo sa_render_flash(); ?>

<div class="sa-grid sa-kpis sa-anim">
    <article class="sa-card sa-kpi" style="--kpi-accent:var(--sa-info);--kpi-soft:var(--sa-info-soft);--kpi-line:var(--sa-info-line)">
        <div class="sa-kpi-top">
            <span class="sa-kpi-label">Categories</span>
            <span class="sa-kpi-icon"><?php echo sa_icon('list'); ?></span>
        </div>
        <div class="sa-kpi-value"><?php echo sa_e(sa_num($total_categories)); ?></div>
        <div class="sa-kpi-note">Available on the public sign-up wizard</div>
    </article>

    <article class="sa-card sa-kpi" style="--kpi-accent:var(--sa-success);--kpi-soft:var(--sa-success-soft);--kpi-line:var(--sa-success-line)">
        <div class="sa-kpi-top">
            <span class="sa-kpi-label">In use</span>
            <span class="sa-kpi-icon"><?php echo sa_icon('check-circle'); ?></span>
        </div>
        <div class="sa-kpi-value"><?php echo sa_e(sa_num($in_use)); ?></div>
        <div class="sa-kpi-note">Categories already attached to customers</div>
    </article>

    <article class="sa-card sa-kpi" style="--kpi-accent:var(--sa-warning);--kpi-soft:var(--sa-warning-soft);--kpi-line:var(--sa-warning-line)">
        <div class="sa-kpi-top">
            <span class="sa-kpi-label">Uncategorised</span>
            <span class="sa-kpi-icon"><?php echo sa_icon('alert'); ?></span>
        </div>
        <div class="sa-kpi-value"><?php echo sa_e(sa_num($uncategorised)); ?></div>
        <div class="sa-kpi-note">Customer records with no category chosen</div>
    </article>
</div>

<section class="sa-card sa-anim">
    <div class="sa-card-head">
        <div>
            <h3>Category list</h3>
            <p>Renaming keeps every customer and quote request attached; deleting is only possible while a category is unused.</p>
        </div>
    </div>
    <div class="sa-table-wrap">
        <table class="sa-table" id="categoriesTable" data-sa-sortable-table>
            <thead>
                <tr>
                    <th data-sa-sort="0" data-type="num" scope="col" aria-sort="none">ID</th>
                    <th data-sa-sort="1" scope="col" aria-sort="none">Category</th>
                    <th data-sa-sort="2" data-type="num" scope="col" aria-sort="none">Customers</th>
                    <th data-sa-sort="3" data-type="num" scope="col" aria-sort="none">Quote requests</th>
                    <th data-sa-sort="4" data-type="date" scope="col" aria-sort="none">Created</th>
                    <th data-no-export scope="col"><span class="sa-sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody>
<?php if (!$categories): ?>
                <tr data-static>
                    <td colspan="6">
                        <div class="sa-empty">
                            <?php echo sa_icon('list'); ?>
                            <strong>No categories yet</strong>
                            <p>Companies choose a category while registering, so create the first ones before sharing the sign-up wizard.</p>
                            <button type="button" class="sa-btn sa-btn-primary sa-mt" data-sa-open-dialog="#categoryDialog">
                                <?php echo sa_icon('plus'); ?> Create the first category
                            </button>
                        </div>
                    </td>
                </tr>
<?php else: ?>
<?php foreach ($categories as $c): ?>
                <tr>
                    <td><?php echo (int) $c['id']; ?></td>
                    <td>
                        <span class="sa-badge sa-badge-info"><?php echo sa_e($c['name']); ?></span>
                    </td>
                    <td>
                        <?php echo sa_e(sa_num((int) $c['company_count'])); ?>
                        <?php if ((int) $c['company_count'] > 0): ?>
                            <a class="sa-faint" href="customers.php?category=<?php echo (int) $c['id']; ?>">view &rarr;</a>
                        <?php endif; ?>
                    </td>
                    <td><?php echo sa_e(sa_num((int) $c['quote_count'])); ?></td>
                    <td><?php echo sa_date(isset($c['created_at']) ? $c['created_at'] : null); ?></td>
                    <td>
                        <div class="sa-flex" style="gap:6px;justify-content:flex-end">
                            <button type="button" class="sa-btn sa-btn-sm sa-btn-ghost"
                                    data-sa-edit-category
                                    data-id="<?php echo (int) $c['id']; ?>"
                                    data-name="<?php echo sa_e($c['name']); ?>">
                                <?php echo sa_icon('edit'); ?> Rename
                            </button>
<?php if ((int) $c['company_count'] === 0 && (int) $c['quote_count'] === 0): ?>
                            <form method="POST" action="categories.php" style="display:inline"
                                  onsubmit="return confirm('Delete the <?php echo sa_e(addslashes($c['name'])); ?> category?');">
                                <?php echo sa_csrf_field(); ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="category_id" value="<?php echo (int) $c['id']; ?>">
                                <button type="submit" class="sa-btn sa-btn-sm sa-btn-danger" title="Delete category"><?php echo sa_icon('trash'); ?></button>
                            </form>
<?php else: ?>
                            <button type="button" class="sa-btn sa-btn-sm sa-btn-ghost" disabled
                                    title="In use — reassign its customers and quotes first">
                                <?php echo sa_icon('trash'); ?>
                            </button>
<?php endif; ?>
                        </div>
                    </td>
                </tr>
<?php endforeach; ?>
<?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<!-- ============ CATEGORY FORM (create + rename share one dialog) ============ -->
<dialog class="sa-dialog" id="categoryDialog" aria-labelledby="cat_title">
    <form method="POST" action="categories.php" class="sa-form">
        <?php echo sa_csrf_field(); ?>
        <input type="hidden" name="action" id="cat_action" value="create">
        <input type="hidden" name="category_id" id="cat_id" value="">
        <div class="sa-dialog-head">
            <div>
                <h3 id="cat_title">New category</h3>
                <p id="cat_subtitle">Companies pick from this list while registering and when tenants classify a branch.</p>
            </div>
            <button type="button" class="sa-dialog-close" data-sa-close-dialog aria-label="Close"><?php echo sa_icon('x'); ?></button>
        </div>

        <div class="sa-dialog-body">
            <div class="sa-form-grid">
                <div class="sa-field" style="grid-column:1/-1">
                    <label for="cat_name">Category name *</label>
                    <input id="cat_name" type="text" name="name" placeholder="e.g. Legal &amp; Compliance" required maxlength="100">
                    <span class="sa-hint">Keep it short — it appears in the sign-up wizard dropdown.</span>
                </div>
            </div>
        </div>

        <div class="sa-dialog-foot">
            <button type="button" class="sa-btn sa-btn-ghost" data-sa-close-dialog>Cancel</button>
            <button type="submit" class="sa-btn sa-btn-primary" id="cat_submit"><?php echo sa_icon('check'); ?> Create category</button>
        </div>
    </form>
</dialog>

<script>
/* Reuse the category dialog for renaming (generic open/close lives in superadmin.js) */
(function () {
    function open(sel) {
        var d = document.querySelector(sel);
        if (!d) { return; }
        if (typeof d.showModal === 'function') { d.showModal(); }
        else { d.setAttribute('open', ''); d.classList.add('is-open-fallback'); }
    }

    document.querySelectorAll('[data-sa-edit-category]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.getElementById('cat_action').value = 'rename';
            document.getElementById('cat_id').value = btn.dataset.id;
            document.getElementById('cat_name').value = btn.dataset.name;
            document.getElementById('cat_title').textContent = 'Rename category';
            document.getElementById('cat_subtitle').textContent = 'Renaming keeps every customer and quote request attached.';
            document.getElementById('cat_submit').textContent = 'Save changes';
            open('#categoryDialog');
        });
    });

    document.querySelectorAll('[data-sa-open-dialog="#categoryDialog"]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.getElementById('cat_action').value = 'create';
            document.getElementById('cat_id').value = '';
            document.getElementById('cat_name').value = '';
            document.getElementById('cat_title').textContent = 'New category';
            document.getElementById('cat_subtitle').textContent = 'Companies pick from this list while registering and when tenants classify a branch.';
            document.getElementById('cat_submit').textContent = 'Create category';
        });
    });
})();
</script>

<?php include __DIR__ . '/_shell_footer.php'; ?>
