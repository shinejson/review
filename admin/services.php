<?php
/**
 * ============================================================
 *  Admin — Services
 * ============================================================
 *  CRUD for the company's services (the things they do).
 *  Each service has a title, short description, an icon and
 *  can be switched active/inactive or reordered.
 */

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/admin_helpers.php';

requireLogin();

$tenant_id     = getTenantId();
$is_tenant     = isTenant();
$workspace_id  = $is_tenant ? (int) $tenant_id : 0;

admin_ensure_schema($conn);

/* Curated icon catalogue shown in the picker (Font Awesome 6) */
$icon_groups = [
    'Business & Finance' => [
        'fa-solid fa-chart-line' => 'Chart line',
        'fa-solid fa-handshake' => 'Handshake',
        'fa-solid fa-briefcase' => 'Briefcase',
        'fa-solid fa-coins' => 'Coins',
        'fa-solid fa-building-columns' => 'Bank',
        'fa-solid fa-money-bill-trend-up' => 'Growth',
    ],
    'Food & Hospitality' => [
        'fa-solid fa-utensils' => 'Restaurant',
        'fa-solid fa-mug-hot' => 'Cafe',
        'fa-solid fa-hotel' => 'Hotel',
        'fa-solid fa-bell-concierge' => 'Concierge',
        'fa-solid fa-champagne-glasses' => 'Events',
        'fa-solid fa-birthday-cake' => 'Bakery',
    ],
    'Tech & Services' => [
        'fa-solid fa-laptop-code' => 'Development',
        'fa-solid fa-palette' => 'Design',
        'fa-solid fa-camera' => 'Photography',
        'fa-solid fa-screwdriver-wrench' => 'Repair',
        'fa-solid fa-truck-fast' => 'Delivery',
        'fa-solid fa-headset' => 'Support',
    ],
    'Health & Beauty' => [
        'fa-solid fa-spa' => 'Spa',
        'fa-solid fa-stethoscope' => 'Clinic',
        'fa-solid fa-scissors' => 'Salon',
        'fa-solid fa-dumbbell' => 'Fitness',
        'fa-solid fa-heart-pulse' => 'Wellness',
        'fa-solid fa-tooth' => 'Dental',
    ],
    'Education & More' => [
        'fa-solid fa-graduation-cap' => 'Education',
        'fa-solid fa-book-open' => 'Books',
        'fa-solid fa-car' => 'Automotive',
        'fa-solid fa-house' => 'Real estate',
        'fa-solid fa-shirt' => 'Fashion',
        'fa-solid fa-star' => 'General',
    ],
];
$all_icons = [];
foreach (array_values($icon_groups) as $_icon_group) {
    foreach (array_keys($_icon_group) as $_icon_class) {
        $all_icons[] = $_icon_class;
    }
}

/* ============================================================
   POST handlers
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!sa_csrf_ok()) {
        sa_flash('error', 'Your session expired. Please try again.');
        redirect('services.php');
    }
    $action = isset($_POST['action']) ? (string) $_POST['action'] : '';

    if ($action === 'create' || $action === 'update') {
        $id          = (int) ($_POST['service_id'] ?? 0);
        $title       = sanitize($_POST['title'] ?? '');
        $description = sanitize($_POST['description'] ?? '');
        $icon        = trim((string) ($_POST['icon'] ?? ''));
        $sort_order  = (int) ($_POST['sort_order'] ?? 0);
        $status      = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';

        if (!in_array($icon, $all_icons, true)) {
            $icon = 'fa-solid fa-star';
        }

        if ($title === '') {
            sa_flash('error', 'A service title is required.');
        } elseif ($action === 'create') {
            $stmt = $conn->prepare(
                "INSERT INTO services (tenant_id, title, description, icon, sort_order, status)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
            $stmt->bind_param('isssis', $workspace_id, $title, $description, $icon, $sort_order, $status);
            $stmt->execute();
            sa_flash($stmt->error ? 'error' : 'success', $stmt->error ? 'Could not create the service: ' . $stmt->error : '"' . $title . '" was added.');
            $stmt->close();
        } elseif ($id > 0) {
            $stmt = $conn->prepare(
                "UPDATE services SET title = ?, description = ?, icon = ?, sort_order = ?, status = ?
                  WHERE id = ? AND tenant_id = ?"
            );
            $stmt->bind_param('sssisii', $title, $description, $icon, $sort_order, $status, $id, $workspace_id);
            $stmt->execute();
            sa_flash($stmt->error ? 'error' : 'success', $stmt->error ? 'Could not save the service: ' . $stmt->error : '"' . $title . '" was updated.');
            $stmt->close();
        }
        redirect('services.php');
    }

    if ($action === 'toggle_status') {
        $id = (int) ($_POST['service_id'] ?? 0);
        if ($id) {
            $stmt = $conn->prepare("UPDATE services SET status = IF(status='active','inactive','active') WHERE id = ? AND tenant_id = ?");
            $stmt->bind_param('ii', $id, $workspace_id);
            $stmt->execute();
            $stmt->close();
            sa_flash('success', 'Service visibility updated.');
        }
        redirect('services.php');
    }

    if ($action === 'delete') {
        $id = (int) ($_POST['service_id'] ?? 0);
        if ($id) {
            $stmt = $conn->prepare("DELETE FROM services WHERE id = ? AND tenant_id = ?");
            $stmt->bind_param('ii', $id, $workspace_id);
            $stmt->execute();
            $stmt->close();
            sa_flash('success', 'Service deleted.');
        }
        redirect('services.php');
    }
}

/* ============================================================
   Data
   ============================================================ */
$services = sa_query(
    $conn,
    "SELECT * FROM services WHERE tenant_id = " . $workspace_id . " ORDER BY sort_order ASC, id ASC",
    'services'
);

$BASE      = '../';
$pageTitle = 'Services';
$activeNav = 'services';
include __DIR__ . '/_shell.php';
?>

<!-- Page Header -->
<div class="welcome-row" style="margin-bottom:22px;">
    <div>
        <p class="eyebrow">Workspace &middot; Catalogue</p>
        <h1>Services</h1>
        <p class="muted">Showcase the things your company does. Each service gets an icon and appears on your public rating page.</p>
    </div>
    <span class="status-dot">● <?php echo count($services); ?> service<?php echo count($services) === 1 ? '' : 's'; ?></span>
</div>

<?php if ($flash = sa_take_flash()): ?>
    <div class="alert alert-<?php echo $flash['type'] === 'error' ? 'error' : 'success'; ?>" role="alert">
        <?php echo $flash['type'] === 'error' ? '⚠' : '✓'; ?> <?php echo htmlspecialchars($flash['message']); ?>
    </div>
<?php endif; ?>

<div class="grid-2col" style="align-items:start;">

    <!-- Add / Edit Service Form -->
    <div class="form-card" id="serviceFormCard">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;padding-bottom:14px;border-bottom:1px solid var(--line);">
            <div>
                <h3 style="margin:0;" id="formTitle">Add New Service</h3>
                <p class="muted" style="margin:4px 0 0;">Pick an icon, give it a name and a short description.</p>
            </div>
            <span style="font-size:26px;" id="formIconPreview"><i class="fa-solid fa-star"></i></span>
        </div>

        <form method="POST" action="services.php" id="serviceForm">
            <?php echo sa_csrf_field(); ?>
            <input type="hidden" name="action" value="create" id="formAction">
            <input type="hidden" name="service_id" value="0" id="formServiceId">

            <div class="form-group" style="margin-bottom:16px;">
                <label for="svc_title">Service Title *</label>
                <input type="text" id="svc_title" name="title" maxlength="150" required
                       placeholder="e.g. Home Delivery, Wedding Catering, Free Consultation">
            </div>

            <div class="form-group" style="margin-bottom:16px;">
                <label for="svc_description">Short Description</label>
                <textarea id="svc_description" name="description" rows="3" maxlength="500"
                          placeholder="A one or two sentence summary of this service…"
                          style="width:100%;padding:10px 14px;border:1px solid #cbd5e1;border-radius:8px;font-size:14px;font-family:inherit;resize:vertical;"></textarea>
            </div>

            <input type="hidden" name="icon" id="svc_icon" value="fa-solid fa-star">

            <div class="form-group" style="margin-bottom:16px;">
                <label>Choose an Icon</label>
                <div id="iconPicker" style="max-height:230px;overflow-y:auto;border:1px solid var(--line);border-radius:10px;padding:14px;background:var(--bg);">
<?php foreach ($icon_groups as $group_label => $icons): ?>
                    <div style="font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:1px;color:var(--muted);margin:10px 0 8px;"><?php echo htmlspecialchars($group_label); ?></div>
                    <div style="display:flex;flex-wrap:wrap;gap:8px;">
<?php foreach ($icons as $icon_class => $icon_label): ?>
                        <button type="button" class="icon-option" data-icon="<?php echo htmlspecialchars($icon_class); ?>"
                                title="<?php echo htmlspecialchars($icon_label); ?>"
                                style="width:44px;height:44px;border-radius:10px;border:1px solid var(--line);background:#fff;cursor:pointer;display:grid;place-items:center;font-size:18px;color:var(--ink);">
                            <i class="<?php echo htmlspecialchars($icon_class); ?>"></i>
                        </button>
<?php endforeach; ?>
                    </div>
<?php endforeach; ?>
                </div>
            </div>

            <div class="form-grid" style="grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px;">
                <div class="form-group">
                    <label for="svc_sort">Sort Order</label>
                    <input type="number" id="svc_sort" name="sort_order" value="0" min="0" step="1">
                    <small class="muted">Lower numbers appear first.</small>
                </div>
                <div class="form-group">
                    <label for="svc_status">Status</label>
                    <select id="svc_status" name="status">
                        <option value="active">Active (visible)</option>
                        <option value="inactive">Hidden</option>
                    </select>
                </div>
            </div>

            <div style="display:flex;gap:10px;">
                <button type="submit" class="btn btn-primary" id="formSubmitBtn">＋ Add Service</button>
                <button type="button" class="btn btn-secondary" id="formResetBtn" style="display:none;" onclick="resetServiceForm()">Cancel</button>
            </div>
        </form>
    </div>

    <!-- Services List -->
    <div class="form-card" style="display:flex;flex-direction:column;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;padding-bottom:14px;border-bottom:1px solid var(--line);">
            <div>
                <h3 style="margin:0;">Your Services</h3>
                <p class="muted" style="margin:4px 0 0;">Edit, hide or remove any service below.</p>
            </div>
        </div>
<?php if (empty($services)): ?>
        <div class="empty-state" style="padding:60px 20px;text-align:center;">
            <div style="font-size:44px;margin-bottom:12px;">🧰</div>
            <p style="margin:0;color:var(--muted);">No services yet. Add your first service using the form.</p>
        </div>
<?php else: ?>
        <div style="display:flex;flex-direction:column;gap:12px;">
<?php foreach ($services as $svc): ?>
            <div class="service-row" style="display:flex;align-items:center;gap:14px;padding:14px;border:1px solid var(--line);border-radius:12px;background:var(--bg);<?php echo $svc['status'] === 'inactive' ? 'opacity:.55;' : ''; ?>">
                <span style="width:48px;height:48px;border-radius:12px;background:linear-gradient(135deg,var(--lime),#a8e030);color:var(--navy);display:grid;place-items:center;font-size:20px;flex-shrink:0;">
                    <i class="<?php echo htmlspecialchars($svc['icon']); ?>"></i>
                </span>
                <div style="flex:1;min-width:0;">
                    <strong style="display:block;font-size:14.5px;color:var(--ink);">
                        <?php echo htmlspecialchars($svc['title']); ?>
<?php if ($svc['status'] === 'inactive'): ?>
                        <span style="font-size:10px;font-weight:800;padding:2px 8px;border-radius:6px;background:#fee2e2;color:#b91c1c;margin-left:6px;">HIDDEN</span>
<?php endif; ?>
                    </strong>
<?php if (!empty($svc['description'])): ?>
                    <span class="muted" style="display:block;font-size:12.5px;margin-top:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo htmlspecialchars(admin_trim($svc['description'], 90)); ?></span>
<?php endif; ?>
                    <small class="muted" style="font-size:11px;">Order: <?php echo (int)$svc['sort_order']; ?></small>
                </div>
                <div style="display:flex;gap:6px;flex-shrink:0;">
                    <button type="button" class="btn btn-secondary" style="padding:7px 10px;font-size:12px;"
                            onclick='editService(<?php echo json_encode([
                                'id' => (int)$svc['id'],
                                'title' => $svc['title'],
                                'description' => (string)$svc['description'],
                                'icon' => $svc['icon'],
                                'sort_order' => (int)$svc['sort_order'],
                                'status' => $svc['status'],
                            ], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>);'>
                        ✎ Edit
                    </button>
                    <form method="POST" onsubmit="return confirm('Toggle visibility of this service?');" style="display:inline;">
                        <?php echo sa_csrf_field(); ?>
                        <input type="hidden" name="action" value="toggle_status">
                        <input type="hidden" name="service_id" value="<?php echo (int)$svc['id']; ?>">
                        <button type="submit" class="btn btn-secondary" style="padding:7px 10px;font-size:12px;" title="<?php echo $svc['status'] === 'active' ? 'Hide' : 'Show'; ?>">
                            <?php echo $svc['status'] === 'active' ? '👁' : '🚫'; ?>
                        </button>
                    </form>
                    <form method="POST" onsubmit="return confirm('Delete this service permanently?');" style="display:inline;">
                        <?php echo sa_csrf_field(); ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="service_id" value="<?php echo (int)$svc['id']; ?>">
                        <button type="submit" class="btn btn-secondary" style="padding:7px 10px;font-size:12px;color:#dc2626;border-color:#fecaca;" title="Delete">🗑</button>
                    </form>
                </div>
            </div>
<?php endforeach; ?>
        </div>
<?php endif; ?>
    </div>
</div>

<style>
.icon-option.is-selected { border-color: var(--lime) !important; background: rgba(194,245,66,.15) !important; box-shadow: 0 0 0 2px rgba(194,245,66,.35); }
#iconPicker::-webkit-scrollbar { width: 6px; }
#iconPicker::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }
</style>

<script>
/* Icon picker */
var selectedIcon = 'fa-solid fa-star';
document.querySelectorAll('.icon-option').forEach(function (btn) {
    btn.addEventListener('click', function () {
        document.querySelectorAll('.icon-option').forEach(function (b) { b.classList.remove('is-selected'); });
        btn.classList.add('is-selected');
        selectedIcon = btn.getAttribute('data-icon');
        document.getElementById('svc_icon').value = selectedIcon;
        document.getElementById('formIconPreview').innerHTML = '<i class="' + selectedIcon + '"></i>';
    });
});

/* Edit an existing service */
function editService(data) {
    document.getElementById('formAction').value = 'update';
    document.getElementById('formServiceId').value = data.id;
    document.getElementById('svc_title').value = data.title;
    document.getElementById('svc_description').value = data.description || '';
    document.getElementById('svc_sort').value = data.sort_order;
    document.getElementById('svc_status').value = data.status;
    document.getElementById('svc_icon').value = data.icon;
    document.getElementById('formTitle').textContent = 'Edit Service';
    document.getElementById('formSubmitBtn').textContent = '✓ Save Changes';
    document.getElementById('formResetBtn').style.display = 'inline-flex';
    document.getElementById('formIconPreview').innerHTML = '<i class="' + data.icon + '"></i>';
    selectedIcon = data.icon;
    document.querySelectorAll('.icon-option').forEach(function (b) {
        b.classList.toggle('is-selected', b.getAttribute('data-icon') === data.icon);
    });
    document.getElementById('serviceFormCard').scrollIntoView({ behavior: 'smooth', block: 'start' });
}

/* Reset the form back to "create" mode */
function resetServiceForm() {
    document.getElementById('serviceForm').reset();
    document.getElementById('formAction').value = 'create';
    document.getElementById('formServiceId').value = '0';
    document.getElementById('svc_icon').value = 'fa-solid fa-star';
    document.getElementById('formTitle').textContent = 'Add New Service';
    document.getElementById('formSubmitBtn').textContent = '＋ Add Service';
    document.getElementById('formResetBtn').style.display = 'none';
    document.getElementById('formIconPreview').innerHTML = '<i class="fa-solid fa-star"></i>';
    document.querySelectorAll('.icon-option').forEach(function (b) { b.classList.remove('is-selected'); });
}
</script>

<?php include __DIR__ . '/_shell_footer.php'; ?>

