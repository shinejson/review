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

$robots = 'noindex, nofollow';
$extraCss = ['assets/css/auth.css', 'assets/css/admin-dashboard.css'];
include dirname(__DIR__) . '/includes/header.php';

$activeNav = $activeNav ?? 'dashboard';
?>
<div class="admin-app">
<aside class="admin-sidebar">
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
      <span>▦</span><span class="nav-label">Dashboard</span>
    </a>
    <?php if ($is_tenant): ?>
    <a <?php echo $activeNav === 'company' ? 'class="active"' : ''; ?> href="company.php" title="Company Profile">
      <span>⌂</span><span class="nav-label">Company Profile</span>
    </a>
    <?php endif; ?>
    <a <?php echo $activeNav === 'analysis' ? 'class="active"' : ''; ?> href="analysis.php" title="Analysis">
      <span>◔</span><span class="nav-label">Analysis</span>
    </a>
    <a <?php echo $activeNav === 'ratings' ? 'class="active"' : ''; ?> href="ratings.php" title="Ratings & Reviews">
      <span>☆</span><span class="nav-label">Ratings &amp; Reviews</span>
    </a>
    <a <?php echo $activeNav === 'social' ? 'class="active"' : ''; ?> href="social.php" title="Social">
      <span>❋</span><span class="nav-label">Social</span>
    </a>
  </nav>
  
  <div class="nav-caption">Manage</div>
  <nav>
    <a <?php echo $activeNav === 'subscription' ? 'class="active"' : ''; ?> href="subscription.php" title="Subscription">
      <span>◈</span><span class="nav-label">Subscription</span>
    </a>
    <a <?php echo $activeNav === 'settings' ? 'class="active"' : ''; ?> href="settings.php" title="Settings">
      <span>⚙</span><span class="nav-label">Settings</span>
    </a>
  </nav>
  
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

<section class="admin-main">
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
          <span class="admin-notification-badge">2</span>
        </button>
        <div class="admin-notification-panel">
          <div class="admin-notification-head">
            <strong>Notifications</strong>
            <span>2 new</span>
          </div>
          <div class="admin-notification-list">
            <a href="ratings.php" class="admin-notification-item">
              <div class="admin-list-icon is-success">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
              </div>
              <div class="admin-list-body">
                <strong>New 5-star review</strong>
                <span>Someone left a great rating</span>
              </div>
            </a>
            <a href="analysis.php" class="admin-notification-item">
              <div class="admin-list-icon is-info">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
              </div>
              <div class="admin-list-body">
                <strong>Weekly analysis is ready</strong>
                <span>See how your companies are trending</span>
              </div>
            </a>
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
