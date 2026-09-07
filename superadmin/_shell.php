<?php
/**
 * ============================================================
 *  Optibiz Super Admin — application shell
 * ============================================================
 *  Renders the sidebar + topbar and opens the content area.
 *
 *  Usage in a page:
 *      requireSuperAdminLogin();
 *      $pageTitle    = 'Dashboard';
 *      $pageHeading  = 'Control center';
 *      $pageSubtitle = 'Platform health at a glance.';
 *      $activePage   = 'dashboard';   // dashboard|tenants|subscriptions|plans|analytics|quotes|customers|categories|settings
 *      $BASE         = '../';
 *      include dirname(__DIR__) . '/includes/header.php';
 *      include __DIR__ . '/_shell.php';
 *      ... page markup ...
 *      include __DIR__ . '/_shell_footer.php';
 */

if (!function_exists('sa_icon')) {
    /** Small inline SVG icon helper (feather-style stroke icons). */
    function sa_icon($name, $attrs = '')
    {
        $p = [
            'grid'        => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/>',
            'building'    => '<path d="M3 21h18"/><path d="M5 21V7l7-4v18"/><path d="M19 21V11l-7-4"/><path d="M9 9v.01M9 12v.01M9 15v.01M9 18v.01"/>',
            'card'        => '<rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/><line x1="6" y1="15" x2="10" y2="15"/>',
            'layers'      => '<polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/>',
            'chart'       => '<line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/><line x1="3" y1="20" x2="21" y2="20"/>',
            'inbox'       => '<polyline points="22 12 16 12 14 15 10 15 8 12 2 12"/><path d="M5.45 5.11L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/>',
            'settings'    => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.6 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.6a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9v0a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>',
            'logout'      => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>',
            'search'      => '<circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>',
            'sun'         => '<circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/>',
            'moon'        => '<path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>',
            'menu'        => '<line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/>',
            'panel-left'  => '<rect x="3" y="3" width="18" height="18" rx="2"/><line x1="9" y1="3" x2="9" y2="21"/>',
            'chevron-down'=> '<polyline points="6 9 12 15 18 9"/>',
            'chevron-right'=> '<polyline points="9 18 15 12 9 6"/>',
            'chevron-left'=> '<polyline points="15 18 9 12 15 6"/>',
            'bell'        => '<path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/>',
            'star'        => '<path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/>',
            'plus'        => '<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>',
            'download'    => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>',
            'upload'      => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/>',
            'users'       => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
            'user'        => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
            'globe'       => '<circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>',
            'dollar'      => '<line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>',
            'trending-up' => '<polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/>',
            'trending-down'=> '<polyline points="23 18 13.5 8.5 8.5 13.5 1 6"/><polyline points="17 18 23 18 23 12"/>',
            'calendar'    => '<rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>',
            'clock'       => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
            'mail'        => '<path d="M4 4h16a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2z"/><polyline points="22,6 12,13 2,6"/>',
            'phone'       => '<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6A19.79 19.79 0 0 1 2.12 4.18 2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.9.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z"/>',
            'check'       => '<polyline points="20 6 9 17 4 12"/>',
            'check-circle'=> '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>',
            'alert'       => '<path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
            'info'        => '<circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/>',
            'x'           => '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>',
            'eye'         => '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>',
            'edit'        => '<path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.12 2.12 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>',
            'trash'       => '<polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6m5 0V4a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v2"/>',
            'refresh'     => '<polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/>',
            'filter'      => '<polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/>',
            'external'    => '<path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/>',
            'shield'      => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
            'zap'         => '<polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>',
            'file-text'   => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>',
            'message'     => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
            'arrow-left'  => '<line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/>',
            'save'        => '<path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/>',
            'key'         => '<path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"/>',
            'activity'    => '<polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>',
            'pie'         => '<path d="M21.21 15.89A10 10 0 1 1 8 2.83"/><path d="M22 12A10 10 0 0 0 12 2v10z"/>',
            'list'        => '<line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/>',
            'send'        => '<line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/>',
            'server'      => '<rect x="2" y="2" width="20" height="8" rx="2" ry="2"/><rect x="2" y="14" width="20" height="8" rx="2" ry="2"/><line x1="6" y1="6" x2="6.01" y2="6"/><line x1="6" y1="18" x2="6.01" y2="18"/>',
            'users'       => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        ];
        $body = isset($p[$name]) ? $p[$name] : $p['info'];
        $fill = in_array($name, ['star', 'zap', 'pie'], true) ? 'currentColor' : 'none';
        return '<svg class="sa-icon" width="16" height="16" viewBox="0 0 24 24" fill="' . $fill . '" stroke="currentColor" stroke-width="2" '
             . 'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" ' . $attrs . '>' . $body . '</svg>';
    }
}

/* ---- shell state ------------------------------------------------ */
$sa_active   = isset($activePage) ? $activePage : 'dashboard';
$sa_heading  = isset($pageHeading) ? $pageHeading : (isset($pageTitle) ? $pageTitle : 'Dashboard');
$sa_subtitle = isset($pageSubtitle) ? $pageSubtitle : 'Optibiz platform control center';
$sa_site     = sa_setting($conn, 'site_name', 'Optibiz');
$sa_base     = isset($BASE) ? $BASE : '../';
$sa_logo_url = sa_platform_logo($conn);

$sa_admin_name = 'Super Admin';
$sa_admin_mail = '';
if (isset($_SESSION['super_admin_id'])) {
    $sa_row = sa_one(
        $conn,
        "SELECT username, email FROM super_admins WHERE id = " . (int) $_SESSION['super_admin_id'],
        'super_admins'
    );
    if ($sa_row) {
        $sa_admin_name = $sa_row['username'];
        $sa_admin_mail = isset($sa_row['email']) ? $sa_row['email'] : '';
    }
}

/* Nav badge counts (cheap queries, degrade to null when unavailable) */
$sa_badges = [
    'tenants' => (int) sa_scalar($conn, "SELECT COUNT(*) FROM tenants", 0, 'tenants'),
    'quotes'  => (int) sa_scalar($conn, "SELECT COUNT(*) FROM quote_requests WHERE status = 'pending'", 0, 'quote_requests'),
    'subs'    => (int) sa_scalar(
        $conn,
        "SELECT COUNT(*) FROM tenants
          WHERE subscription_status IN ('active','trial')
            AND subscription_end_date IS NOT NULL
            AND subscription_end_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)",
        0,
        'tenants'
    ),
];

$sa_nav = [
    ['section' => 'Overview'],
    ['key' => 'dashboard',     'label' => 'Dashboard',      'href' => 'index.php',            'icon' => 'grid'],
    ['key' => 'analytics',     'label' => 'Analytics',      'href' => 'analytics.php',        'icon' => 'chart'],
    ['section' => 'Billing'],
    ['key' => 'tenants',       'label' => 'Tenants',        'href' => 'tenants.php',          'icon' => 'building', 'badge' => 'tenants'],
    ['key' => 'subscriptions', 'label' => 'Subscriptions',  'href' => 'subscriptions.php',    'icon' => 'card',     'badge' => 'subs', 'alert' => true],
    ['key' => 'plans',         'label' => 'Plans',          'href' => 'plans.php',            'icon' => 'layers'],
    ['key' => 'quotes',        'label' => 'Quote Requests', 'href' => 'quote_requests.php',   'icon' => 'inbox',    'badge' => 'quotes', 'alert' => true],
    ['section' => 'Directory'],
    ['key' => 'customers',     'label' => 'Customers',      'href' => 'customers.php',        'icon' => 'star'],
    ['key' => 'categories',    'label' => 'Categories',     'href' => 'categories.php',       'icon' => 'list'],
    ['section' => 'System'],
    ['key' => 'users',         'label' => 'Users',          'href' => 'users.php',            'icon' => 'users'],
    ['key' => 'settings',      'label' => 'Settings',       'href' => 'settings.php',         'icon' => 'settings'],
];

/* Only keep the nav items the signed-in super admin may open.
   A section label is dropped when every item under it is hidden. */
$sa_nav_visible = [];
$sa_pending_section = null;
foreach ($sa_nav as $sa_item) {
    if (isset($sa_item['section'])) { $sa_pending_section = $sa_item; continue; }
    if (!sa_can($sa_item['key'], $conn)) continue;
    if ($sa_pending_section !== null) { $sa_nav_visible[] = $sa_pending_section; $sa_pending_section = null; }
    $sa_nav_visible[] = $sa_item;
}
?>
<a class="sa-skip" href="#saContent">Skip to content</a>

<div class="sa-app" id="saApp">

    <!-- ============ SIDEBAR ============ -->
    <aside class="sa-sidebar">
        <a class="sa-brand" href="<?php echo $sa_base; ?>index.php" title="<?php echo sa_e($sa_site); ?>">
            <span class="sa-brand-badge">
    <?php if (!empty($sa_logo_url)): ?>
        <img src="<?php echo sa_e($sa_logo_url); ?>" alt="">
    <?php else: ?>
        <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
    <?php endif; ?>
</span>
            <span class="sa-brand-name">
                <strong>Optibiz</strong>
                <span>Control Center</span>
            </span>
        </a>

        <nav class="sa-nav" aria-label="Super admin">
<?php foreach ($sa_nav_visible as $item): ?>
<?php if (isset($item['section'])): ?>
            <div class="sa-nav-label"><span><?php echo sa_e($item['section']); ?></span></div>
<?php else: ?>
<?php
    $is_active = ($item['key'] === $sa_active);
    $badge_key = isset($item['badge']) ? $item['badge'] : null;
    $badge_val = $badge_key && isset($sa_badges[$badge_key]) ? (int) $sa_badges[$badge_key] : 0;
    $show_badge = $badge_val > 0;
    if ($badge_key === 'subs') {
        $show_badge = $badge_val > 0;
    }
?>
            <a href="<?php echo sa_e($item['href']); ?>" data-label="<?php echo sa_e($item['label']); ?>"<?php echo $is_active ? ' class="active" aria-current="page"' : ''; ?>>
                <?php echo sa_icon($item['icon']); ?>
                <span><?php echo sa_e($item['label']); ?></span>
<?php if ($show_badge): ?>
                <span class="sa-nav-count<?php echo !empty($item['alert']) && $badge_key !== 'tenants' ? ' is-alert' : ''; ?>"><?php echo $badge_val; ?></span>
<?php endif; ?>
            </a>
<?php endif; ?>
<?php endforeach; ?>
        </nav>

        <div class="sa-side-foot">
            <div class="sa-user">
                <span class="sa-user-avatar"><?php echo sa_e(sa_initials($sa_admin_name)); ?></span>
                <span class="sa-user-meta">
                    <strong><?php echo sa_e($sa_admin_name); ?></strong>
                    <span><?php echo sa_is_owner($conn) ? 'Platform owner' : 'Administrator'; ?></span>
                </span>
            </div>
            <a class="sa-logout" href="<?php echo sa_e(auth_logout_url()); ?>" data-label="Sign out" data-sa-confirm="Sign out of the control center?">
                <?php echo sa_icon('logout'); ?>
                <span>Sign out</span>
            </a>
        </div>
    </aside>

    <div class="sa-scrim" data-sa-scrim></div>

    <!-- ============ MAIN ============ -->
    <div class="sa-main">
        <header class="sa-topbar">
            <button type="button" class="sa-icon-btn sa-burger" data-sa-burger aria-label="Open navigation" aria-expanded="false">
                <?php echo sa_icon('menu'); ?>
            </button>

            <button type="button" class="sa-icon-btn sa-collapse-btn" data-sa-collapse aria-label="Toggle sidebar" title="Toggle sidebar">
                <?php echo sa_icon('panel-left'); ?>
            </button>

            <div class="sa-topbar-title">
                <h1><?php echo sa_e($sa_heading); ?></h1>
                <p><?php echo sa_e($sa_subtitle); ?></p>
            </div>

            <!-- Global search bar -->
            <div class="sa-search">
                <?php echo sa_icon('search'); ?>
                <input type="search" placeholder="Search tenants, quotes, subscriptions..."
                       aria-label="Global search"
                       data-sa-search="global" autocomplete="off">
                <kbd>/</kbd>
            </div>

            <div class="sa-topbar-actions">
                <!-- Notifications bell -->
                <div class="sa-notification-wrap">
                    <button type="button" class="sa-icon-btn sa-notification-btn" aria-label="Notifications" title="Notifications">
                        <?php echo sa_icon('bell'); ?>
<?php if ($sa_badges['quotes'] > 0): ?>
                        <span class="sa-notification-badge"><?php echo $sa_badges['quotes']; ?></span>
<?php endif; ?>
                    </button>
                    <div class="sa-notification-panel">
                        <div class="sa-notification-head">
                            <strong>Notifications</strong>
                            <span><?php echo $sa_badges['quotes']; ?> new</span>
                        </div>
                        <div class="sa-notification-list">
<?php if ($sa_badges['quotes'] > 0): ?>
                            <a href="quote_requests.php" class="sa-notification-item">
                                <div class="sa-list-icon is-warning">
                                    <?php echo sa_icon('inbox'); ?>
                                </div>
                                <div class="sa-list-body">
                                    <strong>New quote requests</strong>
                                    <span><?php echo $sa_badges['quotes']; ?> pending request<?php echo $sa_badges['quotes'] > 1 ? 's' : ''; ?></span>
                                </div>
                            </a>
<?php endif; ?>
<?php if ($sa_badges['subs'] > 0): ?>
                            <a href="subscriptions.php" class="sa-notification-item">
                                <div class="sa-list-icon is-danger">
                                    <?php echo sa_icon('card'); ?>
                                </div>
                                <div class="sa-list-body">
                                    <strong>Expiring subscriptions</strong>
                                    <span><?php echo $sa_badges['subs']; ?> expiring within 30 days</span>
                                </div>
                            </a>
<?php endif; ?>
<?php if ($sa_badges['quotes'] == 0 && $sa_badges['subs'] == 0): ?>
                            <div class="sa-notification-empty">
                                <?php echo sa_icon('check-circle'); ?>
                                <p>No new notifications</p>
                            </div>
<?php endif; ?>
                        </div>
                        <div class="sa-notification-foot">
                            <a href="quote_requests.php">View all notifications</a>
                        </div>
                    </div>
                </div>

                <button type="button" class="sa-theme-toggle" data-sa-theme aria-pressed="false" aria-label="Switch theme" title="Switch theme">
                    <span class="sa-theme-thumb">
                        <span class="icon-moon"><?php echo sa_icon('moon'); ?></span>
                        <span class="icon-sun"><?php echo sa_icon('sun'); ?></span>
                    </span>
                </button>

                <div class="sa-menu-wrap" data-sa-menu>
                    <button type="button" class="sa-avatar-btn" data-sa-menu-trigger aria-haspopup="true" aria-expanded="false">
                        <span class="sa-avatar"><?php echo sa_e(sa_initials($sa_admin_name)); ?></span>
                        <span><?php echo sa_e($sa_admin_name); ?></span>
                        <?php echo sa_icon('chevron-down'); ?>
                    </button>
                    <div class="sa-menu" role="menu">
                        <div class="sa-menu-head">
                            <strong><?php echo sa_e($sa_admin_name); ?></strong>
                            <span><?php echo $sa_admin_mail ? sa_e($sa_admin_mail) : 'Platform owner'; ?></span>
                        </div>
                        <?php if (sa_can('users', $conn)): ?>
                        <a href="users.php" role="menuitem"><?php echo sa_icon('users'); ?> Users &amp; roles</a>
                        <?php endif; ?>
                        <?php if (sa_can('settings', $conn)): ?>
                        <a href="settings.php" role="menuitem"><?php echo sa_icon('settings'); ?> Platform settings</a>
                        <?php endif; ?>
                        <?php if (sa_can('analytics', $conn)): ?>
                        <a href="analytics.php" role="menuitem"><?php echo sa_icon('chart'); ?> Analytics</a>
                        <?php endif; ?>
                        <a href="<?php echo $sa_base; ?>index.php" target="_blank" rel="noopener" role="menuitem"><?php echo sa_icon('globe'); ?> View public site</a>
                        <a class="is-danger" href="<?php echo sa_e(auth_logout_url()); ?>" role="menuitem" data-sa-confirm="Sign out of the control center?"><?php echo sa_icon('logout'); ?> Sign out</a>
                    </div>
                </div>
            </div>
        </header>

        <main class="sa-content" id="saContent">
