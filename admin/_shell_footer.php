  </main>
</section>
</div>

<!-- ============ ADMIN LOGOUT MODAL ============ -->
<dialog class="admin-logout-modal" id="adminLogoutModal" aria-labelledby="adminLogoutModalTitle" aria-describedby="adminLogoutModalDesc">
  <div class="admin-logout-card">
    <button type="button" class="admin-logout-close" data-admin-logout-close aria-label="Close dialog">
      <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
    </button>
    <div class="admin-logout-icon-wrap">
      <div class="admin-logout-icon">
        <svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
          <polyline points="16 17 21 12 16 7"/>
          <line x1="21" y1="12" x2="9" y2="12"/>
        </svg>
      </div>
    </div>
    <h3 id="adminLogoutModalTitle" class="admin-logout-title">Sign Out</h3>
    <p id="adminLogoutModalDesc" class="admin-logout-desc">Are you sure you want to sign out of your workspace? You will need to enter your credentials to access your business dashboard again.</p>
    <div class="admin-logout-actions">
      <button type="button" class="btn-logout-cancel" data-admin-logout-close>Stay Signed In</button>
      <a href="<?php echo htmlspecialchars(auth_logout_url()); ?>" class="btn-logout-confirm">
        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
          <polyline points="16 17 21 12 16 7"/>
          <line x1="21" y1="12" x2="9" y2="12"/>
        </svg>
        <span>Yes, Sign Out</span>
      </a>
    </div>
  </div>
</dialog>

<script src="<?php echo $BASE; ?>assets/js/admin.js"></script>

</body>
</html>

