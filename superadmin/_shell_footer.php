        </main>

        <footer class="sa-foot" role="contentinfo">

            <!-- Left: Status + branding -->
            <div class="sa-foot-left">
                <div class="sa-foot-status" title="Platform systems are running normally">
                    <span class="sa-pulse-dot" aria-hidden="true"></span>
                    <span>All Systems Operational</span>
                </div>
                <span class="sa-foot-sep" aria-hidden="true"></span>
                <span class="sa-foot-copy">&copy; <?php echo date('Y'); ?> <?php echo sa_e(sa_setting($conn, 'site_name', 'Optibiz')); ?> Control Center</span>
                <span class="sa-foot-badge">v2.4 Enterprise</span>
            </div>

            <!-- Right: Quick nav + back to top -->
            <div class="sa-foot-right">
                <nav class="sa-foot-nav" aria-label="Quick navigation links">
                    <a href="<?php echo $sa_base; ?>index.php" target="_blank" rel="noopener" title="Open public site">
                        <?php echo sa_icon('globe'); ?> <span>Public site</span>
                    </a>
                    <a href="<?php echo sa_e($BASE . admin_url('index.php')); ?>" title="Open tenant admin portal">
                        <?php echo sa_icon('building'); ?> <span>Tenant portal</span>
                    </a>
                    <a href="<?php echo $sa_base; ?>superadmin/finance.php" title="Financial operations and ledger">
                        <?php echo sa_icon('card'); ?> <span>Financials</span>
                    </a>
                    <a href="<?php echo $sa_base; ?>superadmin/settings.php" title="Superadmin system settings">
                        <?php echo sa_icon('settings'); ?> <span>Settings</span>
                    </a>
                </nav>
                <a href="#saContent" class="sa-totop-btn" data-sa-totop title="Scroll to top of page" aria-label="Back to top">
                    <?php echo sa_icon('chevron-up'); ?> <span>Back to top</span>
                </a>
            </div>

        </footer>
    </div>
</div>

<!-- ============ SUPERADMIN LOGOUT CONFIRMATION MODAL ============ -->
<dialog class="sa-dialog sa-logout-dialog" id="saLogoutModal" aria-labelledby="saLogoutModalTitle" aria-describedby="saLogoutModalDesc">
    <div class="sa-logout-card">
        <button type="button" class="sa-dialog-close sa-logout-close" data-sa-close-dialog aria-label="Close dialog">
            <?php echo sa_icon('x'); ?>
        </button>
        <div class="sa-logout-icon-wrap">
            <div class="sa-logout-icon">
                <?php echo sa_icon('logout'); ?>
            </div>
        </div>
        <h3 id="saLogoutModalTitle" class="sa-logout-title">Sign Out of Control Center</h3>
        <p id="saLogoutModalDesc" class="sa-logout-desc">Are you sure you want to sign out? Your administrative session will be terminated and you will need to sign in again to access Optibiz Control Center.</p>
        <div class="sa-logout-actions">
            <button type="button" class="sa-btn sa-btn-ghost" data-sa-close-dialog>Stay Signed In</button>
            <a href="<?php echo sa_e(auth_logout_url()); ?>" class="sa-btn sa-btn-danger sa-logout-btn-confirm">
                <?php echo sa_icon('logout'); ?>
                <span>Yes, Sign Out</span>
            </a>
        </div>
    </div>
</dialog>

<script src="<?php echo $BASE; ?>assets/js/superadmin.js"></script>
</body>
</html>
