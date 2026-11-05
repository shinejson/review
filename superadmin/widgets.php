<?php
/**
 * Super Admin — Review Widgets Manager
 * Manage embeddable review widgets, Pro/Agency subscriptions, traffic, and domains.
 */
require_once dirname(__DIR__).'/includes/auth.php';
require_once dirname(__DIR__).'/config/database.php';
require_once dirname(__DIR__).'/includes/functions.php';
require_once dirname(__DIR__).'/includes/sa_helpers.php';
require_once dirname(__DIR__).'/includes/monetization.php';

requireSuperAdminLogin();
require_sa_permission('finance');
monetization_ensure_schema($conn);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && sa_csrf_ok()) {
    $act = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);

    if ($act === 'activate' && $id) {
        $exp = date('Y-m-d H:i:s', strtotime('+30 days'));
        @$conn->query("UPDATE widget_configs SET status='active', expires_at='".$conn->real_escape_string($exp)."' WHERE id=".$id);
        sa_flash('success', 'Widget #'.$id.' activated until '.$exp);
        redirect('widgets.php');
    }
    if ($act === 'update_status' && $id) {
        $st = in_array($_POST['status'] ?? '', ['active', 'pending', 'suspended', 'expired'], true) ? $_POST['status'] : '';
        if ($st) {
            $stmt = $conn->prepare("UPDATE widget_configs SET status=? WHERE id=?");
            $stmt->bind_param("si", $st, $id);
            $stmt->execute();
            $stmt->close();
            if ($st === 'active') {
                @$conn->query("UPDATE widget_configs SET expires_at=DATE_ADD(NOW(), INTERVAL 30 DAY) WHERE id=".$id." AND (expires_at IS NULL OR expires_at < NOW())");
            }
            sa_flash('success', 'Widget #'.$id.' marked '.$st.'.');
        }
        redirect('widgets.php');
    }
    if ($act === 'update_tier' && $id) {
        $tier = in_array($_POST['tier'] ?? '', ['free', 'pro', 'agency'], true) ? $_POST['tier'] : 'pro';
        $stmt = $conn->prepare("UPDATE widget_configs SET tier=? WHERE id=?");
        $stmt->bind_param("si", $tier, $id);
        $stmt->execute();
        $stmt->close();
        sa_flash('success', 'Widget #'.$id.' updated to '.ucfirst($tier).' tier.');
        redirect('widgets.php');
    }
    if ($act === 'extend' && $id) {
        $days = max(1, min(365, (int)($_POST['days'] ?? 30)));
        @$conn->query("UPDATE widget_configs SET status='active', expires_at = DATE_ADD(COALESCE(expires_at, NOW()), INTERVAL ".$days." DAY) WHERE id=".$id);
        sa_flash('success', 'Widget extended by '.$days.' days.');
        redirect('widgets.php');
    }
}

// Filters
$tier_filter = in_array($_GET['tier'] ?? '', ['all', 'free', 'pro', 'agency'], true) ? $_GET['tier'] : 'all';
$status_filter = in_array($_GET['status'] ?? '', ['all', 'active', 'pending', 'suspended', 'expired'], true) ? $_GET['status'] : 'all';

$where = [];
if ($tier_filter !== 'all') $where[] = "wc.tier = '".$conn->real_escape_string($tier_filter)."'";
if ($status_filter !== 'all') $where[] = "wc.status = '".$conn->real_escape_string($status_filter)."'";
$where_sql = $where ? " WHERE " . implode(' AND ', $where) : "";

$list = sa_query($conn, "SELECT wc.*, t.company_name as tenant_name, t.email as tenant_email, c.company_name, c.slug, pi.invoice_number, pi.status as invoice_status FROM widget_configs wc JOIN tenants t ON t.id=wc.tenant_id LEFT JOIN customers c ON c.id=wc.company_id LEFT JOIN payment_invoices pi ON pi.id=wc.invoice_id" . $where_sql . " ORDER BY wc.created_at DESC LIMIT 100", ['widget_configs', 'tenants', 'customers', 'payment_invoices']);

$stats = monetization_stats($conn);

$robots = 'noindex, nofollow';
$pageTitle = 'Review Widgets Manager';
$pageHeading = 'Review Widgets';
$pageSubtitle = 'Manage embeddable widgets, white-label upgrades (Pro & Agency), views and traffic.';
$activePage = 'monetization';
$BASE = '../';
$extraCss = ['assets/css/superadmin.css'];
$bodyClass = 'sa-body';

include dirname(__DIR__).'/includes/header.php';
include __DIR__.'/_shell.php';
?>
<div class="sa-page-head">
  <div>
    <div class="sa-crumbs">
      <a href="index.php">Super admin</a> 
      <?php echo sa_icon('chevron-right'); ?> 
      <a href="monetization.php">Monetization</a> 
      <?php echo sa_icon('chevron-right'); ?> 
      <span>Review Widgets</span>
    </div>
    <h2>Review Widgets &amp; Embeds Manager</h2>
    <p>Manage customer-facing badges, carousels, domain whitelisting, and Pro (GHS 79) / Agency (GHS 149) subscriptions.</p>
  </div>
  <div class="sa-head-actions">
    <a class="sa-btn sa-btn-secondary" href="subscriptions.php?stream=widgets"><?php echo sa_icon('card'); ?> View in Subscriptions Hub</a>
  </div>
</div>

<?php echo sa_render_flash(); ?>

<!-- KPI Row -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;margin-bottom:18px;">
  <div class="sa-card" style="padding:16px;">
    <div style="font-size:11px;color:#64748b;font-weight:700;">TOTAL WIDGETS</div>
    <div style="font-size:22px;font-weight:800;"><?php echo sa_num($stats['widgets_total']); ?></div>
    <div style="font-size:12px;color:#64748b;margin-top:2px;">Across all workspaces</div>
  </div>
  <div class="sa-card" style="padding:16px;">
    <div style="font-size:11px;color:#64748b;font-weight:700;">PRO &amp; AGENCY PAID SUBS</div>
    <div style="font-size:22px;font-weight:800;color:#2563eb;"><?php echo sa_num($stats['widgets_active']); ?></div>
    <div style="font-size:12px;color:#64748b;margin-top:2px;">GHS <?php echo number_format($stats['widgets_mrr'], 2); ?> widget MRR</div>
  </div>
  <div class="sa-card" style="padding:16px;">
    <div style="font-size:11px;color:#64748b;font-weight:700;">TOTAL VIEWS</div>
    <div style="font-size:22px;font-weight:800;"><?php echo sa_num($stats['widgets_views']); ?></div>
    <div style="font-size:12px;color:#64748b;margin-top:2px;">Widget impressions tracked</div>
  </div>
  <div class="sa-card" style="padding:16px;">
    <div style="font-size:11px;color:#64748b;font-weight:700;">TOTAL CLICKS</div>
    <div style="font-size:22px;font-weight:800;"><?php echo sa_num($stats['widgets_clicks']); ?></div>
    <div style="font-size:12px;color:#64748b;margin-top:2px;">Review conversions</div>
  </div>
</div>

<!-- Filters -->
<section class="sa-card sa-mb" style="padding:12px 18px;">
  <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
      <span style="font-size:12px;font-weight:700;color:var(--sa-muted);text-transform:uppercase;">Filter:</span>
      <a href="widgets.php" class="sa-chip <?php echo $tier_filter==='all'&&$status_filter==='all'?'active':''; ?>">All</a>
      <a href="widgets.php?tier=agency" class="sa-chip <?php echo $tier_filter==='agency'?'active':''; ?>">Agency (GHS 149)</a>
      <a href="widgets.php?tier=pro" class="sa-chip <?php echo $tier_filter==='pro'?'active':''; ?>">Pro (GHS 79)</a>
      <a href="widgets.php?tier=free" class="sa-chip <?php echo $tier_filter==='free'?'active':''; ?>">Free Tier</a>
      <a href="widgets.php?status=pending" class="sa-chip <?php echo $status_filter==='pending'?'active':''; ?>">Pending Upgrades</a>
      <a href="widgets.php?status=active" class="sa-chip <?php echo $status_filter==='active'?'active':''; ?>">Active</a>
    </div>
    <div style="font-size:12px;color:var(--sa-muted);">
      <?php echo count($list); ?> widget<?php echo count($list)===1?'':'s'; ?> shown
    </div>
  </div>
</section>

<!-- Widgets Table -->
<section class="sa-card" style="padding:0;overflow:hidden;">
  <div style="overflow-x:auto;">
    <table class="sa-table" style="width:100%;font-size:13px;">
      <thead>
        <tr>
          <th>Tenant / Business</th>
          <th>Tier</th>
          <th>Layout &amp; Theme</th>
          <th>Whitelisted Domains</th>
          <th>Traffic</th>
          <th>Status</th>
          <th>Expires</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($list as $w): ?>
        <?php
          $tier_style = $w['tier'] === 'agency' ? 'background:#fef3c7;color:#92400e;border:1px solid #fde68a;' : ($w['tier'] === 'pro' ? 'background:#ede9fe;color:#6d28d9;border:1px solid #ddd6fe;' : 'background:#f1f5f9;color:#475569;');
          $days = sa_days_until($w['expires_at']);
        ?>
        <tr>
          <td>
            <strong><?php echo htmlspecialchars($w['company_name'] ?: $w['tenant_name']); ?></strong>
            <small style="display:block;color:#64748b;">Tenant: <?php echo htmlspecialchars($w['tenant_name']); ?> (#<?php echo (int)$w['tenant_id']; ?>)</small>
          </td>
          <td>
            <form method="POST" style="display:inline;">
              <?php echo sa_csrf_field(); ?>
              <input type="hidden" name="action" value="update_tier">
              <input type="hidden" name="id" value="<?php echo (int)$w['id']; ?>">
              <select class="sa-inline-select" name="tier" onchange="this.form.submit()" style="<?php echo $tier_style; ?>font-weight:700;">
                <option value="free"<?php echo $w['tier']==='free'?' selected':''; ?>>Free</option>
                <option value="pro"<?php echo $w['tier']==='pro'?' selected':''; ?>>Pro (GHS 79)</option>
                <option value="agency"<?php echo $w['tier']==='agency'?' selected':''; ?>>Agency (GHS 149)</option>
              </select>
            </form>
          </td>
          <td>
            <span class="sa-badge" style="background:#f8fafc;color:#334155;border:1px solid #e2e8f0;text-transform:capitalize;">
              <?php echo htmlspecialchars($w['layout']); ?> · <?php echo htmlspecialchars($w['theme']); ?>
            </span>
          </td>
          <td style="font-size:12px;color:#64748b;">
            <?php echo htmlspecialchars($w['allowed_domains'] ? substr($w['allowed_domains'], 0, 30) : 'Any domain (*)'); ?>
          </td>
          <td>
            <strong><?php echo sa_num($w['views_total']); ?></strong> <small style="color:#64748b;">views</small> · 
            <strong><?php echo sa_num($w['clicks_total']); ?></strong> <small style="color:#64748b;">clicks</small>
          </td>
          <td>
            <form method="POST" style="display:inline;">
              <?php echo sa_csrf_field(); ?>
              <input type="hidden" name="action" value="update_status">
              <input type="hidden" name="id" value="<?php echo (int)$w['id']; ?>">
              <select class="sa-inline-select" name="status" onchange="this.form.submit()">
                <option value="pending"<?php echo $w['status']==='pending'?' selected':''; ?>>Pending</option>
                <option value="active"<?php echo $w['status']==='active'?' selected':''; ?>>Active</option>
                <option value="suspended"<?php echo $w['status']==='suspended'?' selected':''; ?>>Suspended</option>
                <option value="expired"<?php echo $w['status']==='expired'?' selected':''; ?>>Expired</option>
              </select>
            </form>
          </td>
          <td style="font-size:12px;">
            <?php if ($w['expires_at']): ?>
              <strong><?php echo sa_date($w['expires_at']); ?></strong>
              <small style="display:block;color:#64748b;">
                <?php if ($days === null || $days < 0): ?>
                  <span style="color:#ef4444;font-weight:600;">Expired</span>
                <?php else: ?>
                  <?php echo (int)$days; ?> days left
                <?php endif; ?>
              </small>
            <?php else: ?>
              <span class="sa-faint">Perpetual</span>
            <?php endif; ?>
          </td>
          <td>
            <div style="display:flex;gap:6px;align-items:center;">
              <?php if ($w['status'] === 'pending'): ?>
                <form method="POST" style="display:inline;">
                  <?php echo sa_csrf_field(); ?>
                  <input type="hidden" name="action" value="activate">
                  <input type="hidden" name="id" value="<?php echo (int)$w['id']; ?>">
                  <button class="sa-btn sa-btn-primary" style="font-size:11px;padding:3px 7px;">Activate</button>
                </form>
              <?php endif; ?>
              <form method="POST" style="display:inline;">
                <?php echo sa_csrf_field(); ?>
                <input type="hidden" name="action" value="extend">
                <input type="hidden" name="id" value="<?php echo (int)$w['id']; ?>">
                <input type="hidden" name="days" value="30">
                <button class="sa-btn sa-btn-secondary" style="font-size:11px;padding:3px 7px;" title="Extend 30 days">+30d</button>
              </form>
              <a href="../widget.php?key=<?php echo urlencode($w['widget_key']); ?>" target="_blank" class="sa-btn sa-btn-ghost" style="font-size:11px;padding:3px 7px;" title="Preview Live Widget">
                <?php echo sa_icon('eye'); ?>
              </a>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$list): ?>
          <tr><td colspan="8" style="text-align:center;padding:24px;color:#64748b;">No widgets match the selected filter.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</section>

<?php include __DIR__.'/_shell_footer.php'; ?>
