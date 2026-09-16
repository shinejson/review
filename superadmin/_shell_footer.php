        </main>

        <footer class="sa-foot" role="contentinfo">
            <div class="sa-foot-brand">
                <div class="sa-foot-status" title="Platform systems are running normally">
                    <span class="sa-pulse-dot" aria-hidden="true"></span>
                    <span>All Systems Operational</span>
                </div>
                <span class="sa-foot-sep">&bull;</span>
                <span class="sa-foot-copy">&copy; <?php echo date('Y'); ?> <?php echo sa_e(sa_setting($conn, 'site_name', 'Optibiz')); ?> Control Center</span>
                <span class="sa-foot-badge">v2.4 Enterprise</span>
            </div>
            <div class="sa-foot-actions">
                <nav class="sa-foot-nav" aria-label="Quick navigation links">
                    <a href="<?php echo $sa_base; ?>index.php" target="_blank" rel="noopener" title="Open public site">
                        <?php echo sa_icon('globe'); ?> <span>Public site</span>
                    </a>
                    <a href="<?php echo $sa_base; ?>admin/index.php" title="Open tenant admin portal">
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

<script src="<?php echo $BASE; ?>assets/js/superadmin.js"></script>
</body>
</html>
