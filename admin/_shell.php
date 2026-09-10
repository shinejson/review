<?php
/**
 * Admin Shell Layout
 * Provides consistent sidebar and topbar for all admin pages.
 * 
 * Usage: Include this at the top of admin pages after setting required variables.
 * Required variables before include:
 *   - All auth & database setup
 *   - $tenant_id, $is_tenant
 *   - $BASE = '../';
 *   - $pageTitle
 *   - $activeNav (e.g., 'dashboard', 'company', 'analysis', 'ratings', 'social',
 *                 'subscription', 'settings')
 */

if (!isset($BASE)) {
    $BASE = '../';
}

// Fetch dynamic notifications for admin
$admin_notifications = [];
$notification_count = 0;

if (isset($conn)) {
    // Get recent ratings (last 24 hours)
    if ($is_tenant && $tenant_id) {
        $notif_stmt = $conn->prepare("SELECT r.id, r.rating, r.customer_name, r.created_at, c.company_name 
                                       FROM ratings r 
                                       JOIN customers c ON r.company_id = c.id 
                                       WHERE c.tenant_id = ? AND r.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                                       ORDER BY r.created_at DESC LIMIT 5");
        $notif_stmt->bind_param('i', $tenant_id);
    } else {
        $notif_stmt = $conn->prepare("SELECT r.id, r.rating, r.customer_name, r.created_at, c.company_name 
                                       FROM ratings r 
                                       JOIN customers c ON r.company_id = c.id 
                                       WHERE r.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                                       ORDER BY r.created_at DESC LIMIT 5");
    }
    
    if ($notif_stmt && $notif_stmt->execute()) {
        $notif_result = $notif_stmt->get_result();
        while ($notif = $notif_result->fetch_assoc()) {
            $admin_notifications[] = $notif;
        }
        $notification_count = count($admin_notifications);
    }
}

$robots = 'noindex, nofollow';
$extraCss = ['assets/css/auth.css', 'assets/css/admin-dashboard.css'];
include dirname(__DIR__) . '/includes/header.php';

$activeNav = $activeNav ?? 'dashboard';
?>
<div class="admin-app">
<aside class="admin-sidebar">
  <div class="admin-sidebar-scroll">
  <a class="admin-brand" href="index.php" title="Dashboard">
    <span class="brand-mark">★</span>
    <span class="brand-text">
      <b>Optibiz</b>
      <small>Admin workspace</small>
    </span>
  </a>
  
  <div class="nav-caption">Workspace</div>
  <nav>
    <a <?php echo $activeNav === 'dashboard' ? 'class="active"' : ''; ?> href="index.php" title="Dashboard">
      <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg></span><span class="nav-label">Dashboard</span>
    </a>
    <?php if ($is_tenant): ?>
    <a <?php echo $activeNav === 'company' ? 'class="active"' : ''; ?> href="company.php" title="Company Profile">
      <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg></span><span class="nav-label">Company Profile</span>
    </a>
    <a <?php echo $activeNav === 'qr_stand' ? 'class="active"' : ''; ?> href="qr_stand.php" title="Counter QR Stand">
      <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><path d="M6 6h.01M17 6h.01M17 17h.01M6 17h.01"/></svg></span><span class="nav-label">Counter QR Stand</span>
    </a>
    <a <?php echo $activeNav === 'whatsapp_sender' ? 'class="active"' : ''; ?> href="whatsapp_sender.php" title="Ask for Reviews (WhatsApp)">
      <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg></span><span class="nav-label">Ask for Reviews</span>
    </a>
    <a <?php echo $activeNav === 'social_card' ? 'class="active"' : ''; ?> href="social_card.php" title="Social Proof Cards">
      <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="7" width="20" height="15" rx="2" ry="2"/><polyline points="17 2 12 7 7 2"/></svg></span><span class="nav-label">Social Proof Cards</span>
    </a>
    <a <?php echo $activeNav === 'qa' ? 'class="active"' : ''; ?> href="qa.php" title="Community Q&amp;A">
      <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg></span><span class="nav-label">Community Q&amp;A</span>
    </a>
    <?php endif; ?>
    <a <?php echo $activeNav === 'analysis' ? 'class="active"' : ''; ?> href="analysis.php" title="Analysis">
      <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="20" x2="12" y2="10"/><line x1="18" y1="20" x2="18" y2="4"/><line x1="6" y1="20" x2="6" y2="16"/></svg></span><span class="nav-label">Analysis</span>
    </a>
    <a <?php echo $activeNav === 'ratings' ? 'class="active"' : ''; ?> href="ratings.php" title="Ratings & Reviews">
      <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg></span><span class="nav-label">Ratings &amp; Reviews</span>
    </a>
    <a <?php echo $activeNav === 'services' ? 'class="active"' : ''; ?> href="services.php" title="Services">
      <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg></span><span class="nav-label">Services</span>
    </a>
    <a <?php echo $activeNav === 'social' ? 'class="active"' : ''; ?> href="social.php" title="Social">
      <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/></svg></span><span class="nav-label">Social</span>
    </a>
    <a <?php echo $activeNav === 'ads' ? 'class="active"' : ''; ?> href="ads.php" title="Ads &amp; Funnels">
      <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg></span><span class="nav-label">Ads &amp; Funnel</span>
    </a>
  </nav>
  
  <div class="nav-caption">Manage</div>
  <nav>
    <a <?php echo $activeNav === 'subscription' ? 'class="active"' : ''; ?> href="subscription.php" title="Subscription">
      <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="7" width="20" height="14" rx="2" ry="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg></span><span class="nav-label">Subscription</span>
    </a>
    <a <?php echo $activeNav === 'settings' ? 'class="active"' : ''; ?> href="settings.php" title="Settings">
      <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg></span><span class="nav-label">Settings</span>
    </a>
  </nav>
  </div>
  
  <div class="sidebar-bottom">
    <div class="mini-avatar">
      <?php echo htmlspecialchars(strtoupper(substr($_SESSION['admin_username'] ?? 'A', 0, 1))); ?>
    </div>
    <div class="sb-user">
      <strong><?php echo htmlspecialchars($_SESSION['admin_username'] ?? 'Admin'); ?></strong>
      <small><?php echo $is_tenant ? 'Workspace admin' : 'Global administrator'; ?></small>
    </div>
    <a href="<?php echo htmlspecialchars(auth_logout_url()); ?>" title="Log out" data-admin-confirm="Sign out of the workspace?">↪</a>
  </div>
</aside>
<div class="admin-sidebar-backdrop" data-admin-backdrop></div>

<section class="admin-main">
<?php if (!empty($_SESSION['impersonator_super_admin_id'])): ?>
  <div class="sa-impersonate-bar" style="background: linear-gradient(90deg, #1e1b4b 0%, #312e81 100%); color: #ffffff; padding: 10px 20px; display: flex; align-items: center; justify-content: space-between; gap: 14px; font-size: 13px; border-bottom: 2px solid #818cf8; z-index: 999; flex-wrap: wrap;">
    <div style="display: flex; align-items: center; gap: 10px;">
      <span style="background: #ef4444; color: #fff; font-size: 11px; font-weight: 700; text-transform: uppercase; padding: 3px 8px; border-radius: 999px; letter-spacing: 0.5px; display: inline-flex; align-items: center; gap: 4px;">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg> Support Mode
      </span>
      <span>Operating as <strong><?php echo htmlspecialchars($_SESSION['tenant_name'] ?? 'Tenant'); ?></strong> (ID #<?php echo (int)($_SESSION['tenant_id'] ?? 0); ?>) &middot; Super Admin Support Session</span>
    </div>
    <a href="<?php echo htmlspecialchars($BASE); ?>superadmin/exit_impersonate.php" style="background: #ffffff; color: #1e1b4b; text-decoration: none; padding: 6px 14px; border-radius: 6px; font-weight: 600; font-size: 12px; display: inline-flex; align-items: center; gap: 6px; box-shadow: 0 1px 3px rgba(0,0,0,0.2); transition: all 0.15s; white-space: nowrap;">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 14 4 9 9 4"/><path d="M20 20v-7a4 4 0 0 0-4-4H4"/></svg>
      Exit Support Mode &amp; Return to Super Admin
    </a>
  </div>
<?php endif; ?>
  <header class="admin-topbar">
    <!-- Sidebar Collapse Toggle (desktop) -->
    <button type="button" class="admin-collapse-btn" data-admin-collapse aria-label="Toggle sidebar" aria-expanded="true" title="Collapse / expand sidebar">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="11 17 6 12 11 7"/><polyline points="18 17 13 12 18 7"/></svg>
    </button>
    <button class="mobile-menu admin-burger" aria-label="Open menu">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
    </button>
    <div class="crumb">
      Overview <b>/</b> <strong><?php echo htmlspecialchars($pageTitle ?? 'Dashboard'); ?></strong>
    </div>

    <!-- Search Bar -->
    <div class="admin-search">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
      <input type="text" placeholder="Search..." aria-label="Search" data-admin-search>
      <kbd>/</kbd>
    </div>

    <div class="admin-topbar-actions">
      <span class="status-dot">● Live data</span>

      <!-- Dark Mode Toggle -->
      <button type="button" class="admin-theme-toggle" data-admin-theme aria-pressed="false" aria-label="Switch theme" title="Switch theme">
        <span class="admin-theme-thumb">
          <span class="icon-moon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
          </span>
          <span class="icon-sun">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>
          </span>
        </span>
      </button>

      <!-- Notification Dropdown -->
      <div class="admin-notification-wrap">
        <button type="button" class="admin-icon-btn admin-notification-btn" aria-label="Notifications" aria-expanded="false" data-admin-notification-trigger>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
          <?php if ($notification_count > 0): ?>
          <span class="admin-notification-badge"><?php echo $notification_count; ?></span>
          <?php endif; ?>
        </button>
        <div class="admin-notification-panel">
          <div class="admin-notification-head">
            <strong>Notifications</strong>
            <?php if ($notification_count > 0): ?>
            <span><?php echo $notification_count; ?> new</span>
            <?php endif; ?>
          </div>
          <div class="admin-notification-list">
            <?php if (!empty($admin_notifications)): ?>
              <?php foreach ($admin_notifications as $notif): ?>
            <a href="ratings.php" class="admin-notification-item">
              <div class="admin-list-icon <?php echo $notif['rating'] >= 4 ? 'is-success' : 'is-info'; ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
              </div>
              <div class="admin-list-body">
                <strong>New <?php echo $notif['rating']; ?>-star review</strong>
                <span><?php echo htmlspecialchars($notif['customer_name']); ?> reviewed <?php echo htmlspecialchars($notif['company_name']); ?></span>
              </div>
            </a>
              <?php endforeach; ?>
            <?php else: ?>
            <div class="admin-notification-item" style="pointer-events:none;">
              <div class="admin-list-body">
                <strong>No new notifications</strong>
                <span>You're all caught up!</span>
              </div>
            </div>
            <?php endif; ?>
          </div>
          <div class="admin-notification-foot">
            <a href="ratings.php">View all notifications</a>
          </div>
        </div>
      </div>

            <!-- User Profile Dropdown -->
      <div class="admin-menu-wrap" data-admin-menu>
        <button type="button" class="admin-avatar-btn" data-admin-menu-trigger aria-haspopup="true" aria-expanded="false">
          <?php if ($is_tenant && !empty($_SESSION['tenant_logo'])): ?>
            <img src="<?php echo $BASE . htmlspecialchars($_SESSION['tenant_logo']); ?>" alt="Logo" class="admin-avatar-img">
          <?php else: ?>
          <span class="admin-avatar"><?php echo htmlspecialchars(strtoupper(substr(getCurrentUserName() ?? 'A', 0, 1))); ?></span>
          <?php endif; ?>
          <span><?php echo htmlspecialchars(getCurrentUserName() ?? 'Admin'); ?></span>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
        </button>
        <div class="admin-menu" role="menu">
          <div class="admin-menu-head">
            <strong><?php echo htmlspecialchars(getCurrentUserName() ?? 'Admin'); ?></strong>
            <span><?php echo $is_tenant ? htmlspecialchars($_SESSION['tenant_name'] ?? 'Workspace admin') : 'Global administrator'; ?></span>
          </div>
          <a href="settings.php" role="menuitem">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.6 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.6a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9v0a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
            Settings
          </a>
          <a href="<?php echo $BASE; ?>index.php" target="_blank" rel="noopener" role="menuitem">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
            View public site
          </a>
          <a class="is-danger" href="<?php echo htmlspecialchars(auth_logout_url()); ?>" role="menuitem" data-admin-confirm="Sign out of the workspace?">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
            Sign out
          </a>
        </div>
      </div>
    </div>
  </header>

  <main class="dashboard-content">
<?php if (isset($_GET['logout']) && $_GET['logout'] === 'invalid'): ?>
    <div class="alert alert-error" role="alert">
      ⚠ That sign-out link is not valid, so you are still signed in.
    </div>
<?php endif; ?>
    <?php
    // Content of individual pages goes here
    // This is where _shell_content.php would be included
    ?>
