        </main>

        <footer class="sa-foot">
            <span>&copy; <?php echo date('Y'); ?> <?php echo sa_e(sa_setting($conn, 'site_name', 'Optibiz')); ?> &middot; Super admin control center</span>
            <span class="sa-foot-links">
                <a href="<?php echo $sa_base; ?>index.php" target="_blank" rel="noopener">Public site</a>
                <a href="<?php echo $sa_base; ?>admin/index.php">Tenant admin</a>
                <a href="#saContent" data-sa-totop>Back to top</a>
            </span>
        </footer>
    </div>
</div>

<script src="<?php echo sa_asset('assets/js/superadmin.js'); ?>"></script>
</body>
</html>
