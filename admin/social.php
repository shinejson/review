<?php
/**
 * ============================================================
 *  Workspace — Social
 * ============================================================
 *  Turns customer comments into social media posts and pushes
 *  them out through the networks' own APIs:
 *
 *    1. pick a review (or write from scratch),
 *    2. Optibiz drafts a caption tailored to the network,
 *    3. publish immediately, or keep it as a draft.
 *
 *  Credentials are stored per workspace in `social_accounts`
 *  and every attempt — published or failed — is recorded in
 *  `social_posts`, so the page doubles as a lead/content log.
 */

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/admin_helpers.php';
require_once dirname(__DIR__) . '/includes/social_publisher.php';

requireLogin();

$tenant_id = getTenantId();
$is_tenant = isTenant();
$workspace_id = $is_tenant ? (int) $tenant_id : 0;   // 0 = global admin workspace

admin_ensure_schema($conn);

$scope     = admin_scope_sql($tenant_id, $is_tenant);
$platforms = social_platforms();

/* ============================================================
   POST handlers
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? (string) $_POST['action'] : '';

    if (!sa_csrf_ok()) {
        sa_flash('error', 'Your session expired. Please try again.');
        redirect('social.php');
    }

    /* ---- connect / update a network ---- */
    if ($action === 'connect') {
        $is_custom = isset($_POST['is_custom']) && $_POST['is_custom'] === '1';
        $platform = isset($_POST['platform']) ? (string) $_POST['platform'] : '';
        
        // Handle custom platforms
        if ($is_custom) {
            $custom_name = sanitize($_POST['custom_platform_name'] ?? '');
            $custom_glyph = sanitize($_POST['custom_glyph'] ?? '📱');
            $custom_limit = (int) ($_POST['custom_limit'] ?? 280);
            
            if (empty($custom_name)) {
                sa_flash('error', 'Platform name is required for custom platforms.');
                redirect('social.php');
            }
            
            // Store custom platform metadata in a JSON field or separate table
            // For now, we'll use the account_name field to identify custom platforms
            $platform_display = $custom_name;
        } else {
            if (!isset($platforms[$platform])) {
                sa_flash('error', 'Unknown network.');
                redirect('social.php');
            }
            $platform_display = $platforms[$platform]['label'];
        }
        
        $name  = sanitize($_POST['account_name'] ?? '');
        $ref   = sanitize($_POST['account_ref'] ?? '');
        $token = trim((string) ($_POST['access_token'] ?? ''));

        $existing = admin_row(
            $conn,
            "SELECT * FROM social_accounts
              WHERE tenant_id = " . $workspace_id . " AND platform = " . admin_str($conn, $platform) . " LIMIT 1"
        );

        if ($token === '' && $existing) {
            // Keep the stored token when the field is left blank on an edit.
            $token = (string) $existing['access_token'];
        }
        if ($token === '') {
            sa_flash('error', 'Paste an access token to connect ' . $platform_display . '.');
            redirect('social.php');
        }

        // Store custom platform metadata
        $metadata = null;
        if ($is_custom) {
            $metadata = json_encode([
                'custom_name' => $custom_name,
                'glyph' => $custom_glyph,
                'limit' => $custom_limit,
                'is_custom' => true
            ]);
        }

        if ($existing) {
            if ($metadata !== null) {
                $stmt = $conn->prepare(
                    "UPDATE social_accounts
                        SET account_name = ?, account_ref = ?, access_token = ?, status = 'connected', last_error = NULL, metadata = ?
                      WHERE id = ?"
                );
                $eid = (int) $existing['id'];
                $stmt->bind_param('ssssi', $name, $ref, $token, $metadata, $eid);
            } else {
                $stmt = $conn->prepare(
                    "UPDATE social_accounts
                        SET account_name = ?, account_ref = ?, access_token = ?, status = 'connected', last_error = NULL
                      WHERE id = ?"
                );
                $eid = (int) $existing['id'];
                $stmt->bind_param('sssi', $name, $ref, $token, $eid);
            }
            $ok = $stmt->execute();
            $stmt->close();
        } else {
            if ($metadata !== null) {
                $stmt = $conn->prepare(
                    "INSERT INTO social_accounts (tenant_id, platform, account_name, account_ref, access_token, status, metadata)
                     VALUES (?, ?, ?, ?, ?, 'connected', ?)"
                );
                $stmt->bind_param('isssss', $workspace_id, $platform, $name, $ref, $token, $metadata);
            } else {
                $stmt = $conn->prepare(
                    "INSERT INTO social_accounts (tenant_id, platform, account_name, account_ref, access_token, status)
                     VALUES (?, ?, ?, ?, ?, 'connected')"
                );
                $stmt->bind_param('issss', $workspace_id, $platform, $name, $ref, $token);
            }
            $ok = $stmt->execute();
            $stmt->close();
        }

        sa_flash($ok ? 'success' : 'error', $ok
            ? $platform_display . ' connected.'
            : 'Could not save the connection.');
        redirect('social.php');
    }

    /* ---- disable a connection ---- */
    if ($action === 'disconnect') {
        $platform = isset($_POST['platform']) ? (string) $_POST['platform'] : '';
        if (isset($platforms[$platform])) {
            $stmt = $conn->prepare("DELETE FROM social_accounts WHERE tenant_id = ? AND platform = ?");
            $stmt->bind_param('is', $workspace_id, $platform);
            $stmt->execute();
            $stmt->close();
            sa_flash('success', $platforms[$platform]['label'] . ' disconnected.');
        }
        redirect('social.php');
    }

    /* ---- read-only credential check ---- */
    if ($action === 'test') {
        $platform = isset($_POST['platform']) ? (string) $_POST['platform'] : '';
        $account = admin_row(
            $conn,
            "SELECT * FROM social_accounts
              WHERE tenant_id = " . $workspace_id . " AND platform = " . admin_str($conn, $platform) . " LIMIT 1"
        );
        if (!$account) {
            sa_flash('error', 'Connect the network first.');
            redirect('social.php');
        }
        $check = social_verify($platform, $account);
        if ($check['ok']) {
            $stmt = $conn->prepare("UPDATE social_accounts SET last_error = NULL WHERE id = ?");
            $aid = (int) $account['id'];
            $stmt->bind_param('i', $aid);
            $stmt->execute();
            $stmt->close();
            sa_flash('success', $platforms[$platform]['label'] . ' credentials are valid'
                . (!empty($check['name']) ? ' (' . $check['name'] . ').' : '.'));
        } else {
            $stmt = $conn->prepare("UPDATE social_accounts SET last_error = ? WHERE id = ?");
            $aid = (int) $account['id'];
            $stmt->bind_param('si', $check['error'], $aid);
            $stmt->execute();
            $stmt->close();
            sa_flash('error', $platforms[$platform]['label'] . ': ' . $check['error']);
        }
        redirect('social.php');
    }

    /* ---- compose: publish now or save a draft ---- */
    if ($action === 'publish' || $action === 'draft') {
        $platform  = isset($_POST['platform']) ? (string) $_POST['platform'] : 'facebook';
        
        // Load all platforms including custom ones
        $temp_accounts = [];
        foreach (admin_rows($conn, "SELECT * FROM social_accounts WHERE tenant_id = " . $workspace_id) as $row) {
            $temp_accounts[(string) $row['platform']] = $row;
        }
        $temp_all_platforms = $platforms;
        foreach ($temp_accounts as $key => $acc) {
            if (!empty($acc['metadata'])) {
                $meta = json_decode($acc['metadata'], true);
                if ($meta && isset($meta['is_custom']) && $meta['is_custom']) {
                    $temp_all_platforms[$key] = [
                        'label' => $meta['custom_name'] ?? 'Custom Platform',
                        'limit' => $meta['limit'] ?? 280,
                    ];
                }
            }
        }
        
        $platform  = isset($temp_all_platforms[$platform]) ? $platform : 'facebook';
        $content   = trim((string) ($_POST['content'] ?? ''));
        $rating_id = (int) ($_POST['rating_id'] ?? 0);
        $company_id = (int) ($_POST['company_id'] ?? 0);

        if ($content === '') {
            sa_flash('error', 'Write the post before saving it.');
            redirect('social.php');
        }
        if (social_length($content) > $temp_all_platforms[$platform]['limit']) {
            sa_flash('error', $temp_all_platforms[$platform]['label'] . ' allows '
                . number_format($temp_all_platforms[$platform]['limit']) . ' characters — trim the post.');
            redirect('social.php');
        }

        // Only reference a review this workspace actually owns.
        if ($rating_id > 0) {
            $owned = admin_row(
                $conn,
                "SELECT r.id, r.company_id FROM ratings r
                   JOIN customers c ON c.id = r.company_id
                  WHERE r.id = " . $rating_id . $scope . " LIMIT 1"
            );
            if (!$owned) {
                $rating_id = 0;
            } else {
                $company_id = (int) $owned['company_id'];
            }
        }

        $status = 'draft';
        $remote_id = null;
        $remote_url = null;
        $error = null;

        if ($action === 'publish') {
            $account = admin_row(
                $conn,
                "SELECT * FROM social_accounts
                  WHERE tenant_id = " . $workspace_id . " AND platform = " . admin_str($conn, $platform) . " LIMIT 1"
            );
            if (!$account) {
                $status = 'failed';
                $error  = 'No ' . $platforms[$platform]['label'] . ' connection — add your access token below, or copy the post and share it manually.';
            } else {
                $result = social_publish($platform, $account, $content);
                if ($result['ok']) {
                    $status     = 'published';
                    $remote_id  = $result['id'];
                    $remote_url = $result['url'];
                } else {
                    $status = 'failed';
                    $error  = $result['error'];
                }

                $stmt = $conn->prepare("UPDATE social_accounts SET last_used_at = NOW(), last_error = ? WHERE id = ?");
                $aid = (int) $account['id'];
                $stmt->bind_param('si', $error, $aid);
                $stmt->execute();
                $stmt->close();
            }
        }

        $stmt = $conn->prepare(
            "INSERT INTO social_posts
                (tenant_id, company_id, rating_id, platform, content, status, remote_id, remote_url, error, published_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, " . ($status === 'published' ? 'NOW()' : 'NULL') . ")"
        );
        $company_param = $company_id > 0 ? $company_id : null;
        $rating_param  = $rating_id > 0 ? $rating_id : null;
        $stmt->bind_param(
            'iiissssss',
            $workspace_id,
            $company_param,
            $rating_param,
            $platform,
            $content,
            $status,
            $remote_id,
            $remote_url,
            $error
        );
        $stmt->execute();
        $stmt->close();

        if ($status === 'published') {
            sa_flash('success', 'Posted to ' . $platforms[$platform]['label'] . '.');
        } elseif ($status === 'failed') {
            sa_flash('error', 'Not published: ' . $error . ' The post was kept in your library.');
        } else {
            sa_flash('success', 'Draft saved to your library.');
        }
        redirect('social.php');
    }

        /* ---- update a draft post ---- */
    if ($action === 'update_post') {
        $post_id = (int) ($_POST['post_id'] ?? 0);
        $content = sanitize($_POST['content'] ?? '');
        if ($post_id > 0 && $content !== '') {
            $stmt = $conn->prepare(
                "UPDATE social_posts SET content = ? WHERE id = ? AND tenant_id = ? AND status = 'draft'"
            );
            $stmt->bind_param('sii', $content, $post_id, $workspace_id);
            if ($stmt->execute()) {
                sa_flash('success', 'Post updated successfully.');
            } else {
                sa_flash('error', 'Failed to update post.');
            }
            $stmt->close();
        } else {
            sa_flash('error', 'Post content cannot be empty.');
        }
        redirect('social.php');
    }

    /* ---- library actions ---- */
    if ($action === 'delete_post') {
        $post_id = (int) ($_POST['post_id'] ?? 0);
        $stmt = $conn->prepare("DELETE FROM social_posts WHERE id = ? AND tenant_id = ?");
        $stmt->bind_param('ii', $post_id, $workspace_id);
        $stmt->execute();
        $stmt->close();
        sa_flash('success', 'Post removed from the library.');
        redirect('social.php');
    }

    if ($action === 'publish_saved') {
        $post_id = (int) ($_POST['post_id'] ?? 0);
        $post = admin_row(
            $conn,
            "SELECT * FROM social_posts WHERE id = " . $post_id . " AND tenant_id = " . $workspace_id . " LIMIT 1"
        );
        if (!$post) {
            sa_flash('error', 'That post no longer exists.');
            redirect('social.php');
        }
        $platform = (string) $post['platform'];
        $account = admin_row(
            $conn,
            "SELECT * FROM social_accounts
              WHERE tenant_id = " . $workspace_id . " AND platform = " . admin_str($conn, $platform) . " LIMIT 1"
        );
        if (!$account) {
            sa_flash('error', 'Connect ' . social_platform($platform)['label'] . ' before publishing.');
            redirect('social.php');
        }
        $result = social_publish($platform, $account, (string) $post['content']);
        if ($result['ok']) {
            $stmt = $conn->prepare(
                "UPDATE social_posts
                    SET status = 'published', remote_id = ?, remote_url = ?, error = NULL, published_at = NOW()
                  WHERE id = ? AND tenant_id = ?"
            );
            $stmt->bind_param('ssii', $result['id'], $result['url'], $post_id, $workspace_id);
            $stmt->execute();
            $stmt->close();
            sa_flash('success', 'Posted to ' . social_platform($platform)['label'] . '.');
        } else {
            $stmt = $conn->prepare("UPDATE social_posts SET status = 'failed', error = ? WHERE id = ? AND tenant_id = ?");
            $stmt->bind_param('sii', $result['error'], $post_id, $workspace_id);
            $stmt->execute();
            $stmt->close();
            sa_flash('error', 'Not published: ' . $result['error']);
        }
        redirect('social.php');
    }

    redirect('social.php');
}

/* ============================================================
   Page data
   ============================================================ */
$flash = sa_take_flash();

$accounts = [];
$custom_platforms_data = [];
foreach (admin_rows($conn, "SELECT * FROM social_accounts WHERE tenant_id = " . $workspace_id) as $row) {
    $accounts[(string) $row['platform']] = $row;
    
    // Load custom platform metadata
    if (!empty($row['metadata'])) {
        $meta = json_decode($row['metadata'], true);
        if ($meta && isset($meta['is_custom']) && $meta['is_custom']) {
            $custom_platforms_data[(string) $row['platform']] = [
                'label' => $meta['custom_name'] ?? 'Custom Platform',
                'glyph' => $meta['glyph'] ?? '📱',
                'limit' => $meta['limit'] ?? 280,
                'ref_label' => 'Account Handle',
                'ref_hint' => 'Your account handle or profile URL',
                'token_hint' => 'API access token or credentials',
                'docs' => '#',
                'is_custom' => true
            ];
        }
    }
}

// Merge custom platforms with default platforms
$all_platforms = array_merge($platforms, $custom_platforms_data);

/* Reviews worth posting: newest strong ratings that carry a comment. */
$inspiration = admin_rows(
    $conn,
    "SELECT r.id, r.rating, r.comment, r.customer_name, r.created_at, r.company_id,
            c.company_name, cat.name AS category_name
       FROM ratings r
       JOIN customers c ON c.id = r.company_id
       LEFT JOIN categories cat ON cat.id = c.category_id
      WHERE r.comment IS NOT NULL AND r.comment <> '' AND r.rating >= 4" . $scope . "
      ORDER BY r.created_at DESC
      LIMIT 12"
);

/* Composer state — prefilled from ?rating_id / ?platform */
$selected_platform = isset($_GET['platform']) && isset($all_platforms[$_GET['platform']])
    ? (string) $_GET['platform']
    : 'facebook';
$selected_rating_id = isset($_GET['rating_id']) ? (int) $_GET['rating_id'] : 0;
$selected_review = [];
if ($selected_rating_id > 0) {
    $selected_review = admin_row(
        $conn,
        "SELECT r.id, r.rating, r.comment, r.customer_name, r.created_at, r.company_id,
                c.company_name, cat.name AS category_name
           FROM ratings r
           JOIN customers c ON c.id = r.company_id
           LEFT JOIN categories cat ON cat.id = c.category_id
          WHERE r.id = " . $selected_rating_id . $scope . " LIMIT 1"
    );
}
if (!$selected_review && $inspiration) {
    $selected_review = $inspiration[0];
}
$selected_rating_id = (int) ($selected_review['id'] ?? 0);

$composer_text = $selected_review
    ? social_caption($selected_review, $selected_platform, (string) ($selected_review['category_name'] ?? ''))
    : '';

/* Library */
$posts = admin_rows(
    $conn,
    "SELECT sp.*, c.company_name
       FROM social_posts sp
       LEFT JOIN customers c ON c.id = sp.company_id
      WHERE sp.tenant_id = " . $workspace_id . "
      ORDER BY sp.created_at DESC
      LIMIT 40"
);

$published_count = 0;
$draft_count = 0;
$failed_count = 0;
foreach ($posts as $row) {
    if ($row['status'] === 'published') {
        $published_count++;
    } elseif ($row['status'] === 'failed') {
        $failed_count++;
    } else {
        $draft_count++;
    }
}

$reviewable = (int) admin_scalar(
    $conn,
    "SELECT COUNT(*) FROM ratings r JOIN customers c ON c.id = r.company_id
      WHERE r.rating >= 4 AND r.comment IS NOT NULL AND r.comment <> ''" . $scope,
    0
);

/* ---------- page ---------- */
$robots    = 'noindex, nofollow';
$BASE      = '../';
$pageTitle = 'Social';
$activeNav = 'social';
include __DIR__ . '/_shell.php';
?>
        <div class="welcome-row">
            <div>
                <p class="eyebrow">Social Media Management</p>
                <h1>Social &amp; lead content</h1>
                <p class="muted">Turn what customers wrote about you into posts that bring the next customer in.</p>
            </div>
            <button type="button" class="primary-button" onclick="openAddSocialModal()">
                ＋ Add social account
            </button>
        </div>
        
        <div class="metric-grid" style="grid-template-columns:repeat(auto-fit,minmax(200px,1fr));margin-bottom:28px;">
            <div class="metric-card"><div class="metric-icon purple">💬</div><span>Quotable reviews</span><strong><?php echo sa_e(sa_num($reviewable)); ?></strong><small>4-5 star comments</small></div>
            <div class="metric-card"><div class="metric-icon lime">✓</div><span>Published posts</span><strong><?php echo sa_e(sa_num($published_count)); ?></strong><small>Live on networks</small></div>
            <div class="metric-card"><div class="metric-icon amber">📝</div><span>Draft posts</span><strong><?php echo sa_e(sa_num($draft_count)); ?></strong><small>Ready to publish</small></div>
            <div class="metric-card"><div class="metric-icon green">🔗</div><span>Connected accounts</span><strong><?php echo count($accounts); ?></strong><small><?php echo count($platforms); ?> networks available</small></div>
        </div>

        <?php if ($flash): ?>
            <div class="alert alert-<?php echo $flash['type'] === 'error' ? 'error' : 'success'; ?>">
                <?php echo $flash['type'] === 'error' ? '⚠' : '✓'; ?> <?php echo sa_e($flash['message']); ?>
            </div>
        <?php endif; ?>

        <div class="grid-2col">
            <!-- Composer -->
            <div class="form-card">
                <h3>Compose a post</h3>

                <?php if (!$selected_review): ?>
                    <div class="empty-state">
                        No 4 or 5 star comments to work with yet — collect a few reviews and they will show up here.
                    </div>
                <?php else: ?>
                    <div class="admin-quote">
                        <span class="review-stars"><?php echo str_repeat('★', (int) $selected_review['rating']); ?></span>
                        <p>“<?php echo sa_e($selected_review['comment']); ?>”</p>
                        <small><?php echo sa_e($selected_review['customer_name']); ?>
                            · <?php echo sa_e($selected_review['company_name']); ?>
                            · <?php echo sa_e(date('M d, Y', strtotime((string) $selected_review['created_at']))); ?></small>
                    </div>
                <?php endif; ?>

                <form method="GET" class="admin-platform-picker" aria-label="Choose a network">
                    <input type="hidden" name="rating_id" value="<?php echo (int) $selected_rating_id; ?>">
                    <?php foreach ($all_platforms as $key => $meta): ?>
                        <button type="submit" name="platform" value="<?php echo sa_e($key); ?>"
                                class="admin-platform-tab<?php echo $selected_platform === $key ? ' is-active' : ''; ?>">
                            <i><?php echo sa_e($meta['glyph']); ?></i>
                            <span><?php echo sa_e($meta['label']); ?></span>
                            <?php if (isset($accounts[$key])): ?><em class="is-live" title="Connected">●</em><?php endif; ?>
                        </button>
                    <?php endforeach; ?>
                </form>

                <form method="POST" data-social-composer>
                    <?php echo sa_csrf_field(); ?>
                    <input type="hidden" name="platform" value="<?php echo sa_e($selected_platform); ?>">
                    <input type="hidden" name="rating_id" value="<?php echo (int) $selected_rating_id; ?>">
                    <input type="hidden" name="company_id" value="<?php echo (int) ($selected_review['company_id'] ?? 0); ?>">

                    <div class="form-group" style="margin-bottom:8px;">
                        <label for="social-content">Post text</label>
                        <textarea id="social-content" name="content" rows="9"
                                  data-social-input
                                  data-social-limit="<?php echo (int) $all_platforms[$selected_platform]['limit']; ?>"
                                  placeholder="Write your post…"><?php echo sa_e($composer_text); ?></textarea>
                    </div>

                    <div class="admin-composer-meta">
                        <span data-social-counter>
                            <?php echo sa_e(sa_num(social_length($composer_text))); ?> /
                            <?php echo sa_e(sa_num($all_platforms[$selected_platform]['limit'])); ?> characters
                        </span>
                        <?php if (isset($accounts[$selected_platform])): ?>
                            <?php echo admin_badge('Connected — posts go live', 'good'); ?>
                        <?php else: ?>
                            <?php echo admin_badge('Not connected — save or copy', 'warn'); ?>
                        <?php endif; ?>
                    </div>

                    <div class="admin-actions">
                        <button type="submit" name="action" value="publish" class="btn btn-primary">Publish now</button>
                        <button type="submit" name="action" value="draft" class="btn btn-secondary">Save draft</button>
                        <button type="button" class="btn btn-secondary" data-social-copy>Copy text</button>
                        <a class="btn btn-secondary" target="_blank" rel="noopener"
                           href="<?php echo sa_e(social_share_url($selected_platform, $composer_text)); ?>">Open share window</a>
                    </div>
                </form>
            </div>

            <!-- Review inspiration -->
            <div class="form-card">
                <h3>Reviews worth posting</h3>
                <p class="muted" style="margin-bottom:14px;">Pick one and the caption is written for you.</p>

                <?php if ($inspiration): ?>
                    <div class="admin-scroll-list">
                        <?php foreach ($inspiration as $row): ?>
                            <a class="admin-pick-row<?php echo (int) $row['id'] === $selected_rating_id ? ' is-active' : ''; ?>"
                               href="social.php?rating_id=<?php echo (int) $row['id']; ?>&amp;platform=<?php echo sa_e($selected_platform); ?>">
                                <span class="review-stars"><?php echo str_repeat('★', (int) $row['rating']); ?></span>
                                <div>
                                    <strong><?php echo sa_e($row['company_name']); ?></strong>
                                    <p><?php echo sa_e(admin_trim($row['comment'], 110)); ?></p>
                                    <small><?php echo sa_e($row['customer_name']); ?>
                                        · <?php echo sa_e(date('M d', strtotime((string) $row['created_at']))); ?></small>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">No quotable reviews yet.</div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Connections -->
        <div class="form-card" id="connections-section">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:16px;gap:16px;flex-wrap:wrap;">
                <div>
                    <h3 style="margin:0 0 8px;">Network connections</h3>
                    <p class="muted" style="margin:0;">
                        Paste the access token from each network's developer console. Optibiz posts straight to their API —
                        tokens are stored for this workspace only and never shown again in full.
                    </p>
                </div>
                <div style="display:flex;align-items:center;gap:8px;">
                    <?php foreach ($platforms as $key => $meta): ?>
                        <?php if (!isset($accounts[$key])): ?>
                            <button type="button" class="btn btn-secondary" 
                                    onclick="document.getElementById('connect-<?php echo sa_e($key); ?>').scrollIntoView({behavior:'smooth'});"
                                    style="padding:8px 14px;font-size:12px;display:inline-flex;align-items:center;gap:6px;">
                                <span style="font-size:16px;"><?php echo sa_e($meta['glyph']); ?></span>
                                Connect <?php echo sa_e($meta['label']); ?>
                            </button>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="admin-connection-grid">
                <?php foreach ($all_platforms as $key => $meta): ?>
                    <?php $account = isset($accounts[$key]) ? $accounts[$key] : null; ?>
                    <?php $is_custom = isset($meta['is_custom']) && $meta['is_custom']; ?>
                    <div class="admin-connection<?php echo $account ? ' is-connected' : ''; ?>" id="connect-<?php echo sa_e($key); ?>">
                        <div class="admin-connection-head">
                            <span class="admin-platform-mark"><?php echo sa_e($meta['glyph']); ?></span>
                            <div>
                                <strong><?php echo sa_e($meta['label']); ?></strong>
                                <small><?php echo $account
                                    ? sa_e($account['account_name'] !== null && $account['account_name'] !== '' ? $account['account_name'] : 'Connected')
                                    : 'Not connected'; ?></small>
                            </div>
                            <?php echo $account ? admin_badge('Live', 'good') : admin_badge('Off', 'neutral'); ?>
                        </div>

                        <?php if ($account && !empty($account['last_error'])): ?>
                            <p class="admin-connection-error">⚠ <?php echo sa_e($account['last_error']); ?></p>
                        <?php endif; ?>

                        <form method="POST">
                            <?php echo sa_csrf_field(); ?>
                            <input type="hidden" name="platform" value="<?php echo sa_e($key); ?>">

                            <div class="form-group">
                                <label for="name-<?php echo sa_e($key); ?>">Display name</label>
                                <input id="name-<?php echo sa_e($key); ?>" type="text" name="account_name"
                                       value="<?php echo sa_e($account['account_name'] ?? ''); ?>"
                                       placeholder="e.g. Volta Logistics official">
                            </div>

                            <div class="form-group">
                                <label for="ref-<?php echo sa_e($key); ?>"><?php echo sa_e($meta['ref_label']); ?></label>
                                <input id="ref-<?php echo sa_e($key); ?>" type="text" name="account_ref"
                                       value="<?php echo sa_e($account['account_ref'] ?? ''); ?>"
                                       placeholder="<?php echo sa_e($meta['ref_hint']); ?>">
                            </div>

                            <div class="form-group">
                                <label for="token-<?php echo sa_e($key); ?>">Access token</label>
                                <input id="token-<?php echo sa_e($key); ?>" type="password" name="access_token"
                                       autocomplete="off"
                                       placeholder="<?php echo $account
                                           ? sa_e(social_mask_token($account['access_token']) . ' — leave blank to keep')
                                           : sa_e($meta['token_hint']); ?>">
                                <small class="admin-field-hint"><?php echo sa_e($meta['token_hint']); ?>
                                    <a href="<?php echo sa_e($meta['docs']); ?>" target="_blank" rel="noopener">API docs ↗</a>
                                </small>
                            </div>

                            <div class="admin-actions">
                                <button type="submit" name="action" value="connect" class="btn btn-primary">
                                    <?php echo $account ? 'Update' : 'Connect'; ?>
                                </button>
                                <?php if ($account): ?>
                                    <button type="submit" name="action" value="test" class="btn btn-secondary">Test</button>
                                    <button type="submit" name="action" value="disconnect" class="btn btn-danger"
                                            onclick="return confirm('Disconnect <?php echo sa_e($meta['label']); ?>?');">Disconnect</button>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Library -->
        <div class="data-table-card">
            <div class="admin-card-head">
                <div>
                    <h3>Post library</h3>
                    <p class="muted"><?php echo sa_e(sa_num($published_count)); ?> published ·
                        <?php echo sa_e(sa_num($draft_count)); ?> drafts ·
                        <?php echo sa_e(sa_num($failed_count)); ?> failed</p>
                </div>
            </div>
            <div class="admin-table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th scope="col">Created</th>
                            <th scope="col">Network</th>
                            <th scope="col">Company</th>
                            <th scope="col">Post</th>
                            <th scope="col">Status</th>
                            <th scope="col">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($posts): ?>
                        <?php foreach ($posts as $row): ?>
                            <?php $meta = social_platform((string) $row['platform']); ?>
                            <tr>
                                <td class="table-meta"><?php echo sa_e(date('M d, Y H:i', strtotime((string) $row['created_at']))); ?></td>
                                <td class="table-title"><?php echo sa_e($meta['label']); ?></td>
                                <td><?php echo sa_e($row['company_name'] !== null ? $row['company_name'] : '—'); ?></td>
                                <td class="table-text"><?php echo sa_e(admin_trim($row['content'], 120)); ?></td>
                                <td>
                                    <?php
                                    if ($row['status'] === 'published') {
                                        echo admin_badge('Published', 'good');
                                    } elseif ($row['status'] === 'failed') {
                                        echo admin_badge('Failed', 'bad');
                                    } else {
                                        echo admin_badge('Draft', 'info');
                                    }
                                    ?>
                                    <?php if (!empty($row['error'])): ?>
                                        <div class="table-meta"><?php echo sa_e(admin_trim($row['error'], 90)); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="admin-row-actions">
                                        <?php if (!empty($row['remote_url'])): ?>
                                            <a class="btn btn-secondary" href="<?php echo sa_e($row['remote_url']); ?>" target="_blank" rel="noopener" style="padding:6px 12px;font-size:12px;">
                                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                                                View
                                            </a>
                                        <?php endif; ?>
                                        <?php if ($row['status'] === 'draft'): ?>
                                            <button type="button" class="btn btn-secondary" onclick="openEditModal(<?php echo (int) $row['id']; ?>, '<?php echo sa_e(addslashes($row['content'])); ?>')" style="padding:6px 12px;font-size:12px;">
                                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                                Edit
                                            </button>
                                            <form method="POST" onsubmit="return confirm('Publish this post now?');" style="display:inline;">
                                                <?php echo sa_csrf_field(); ?>
                                                <input type="hidden" name="post_id" value="<?php echo (int) $row['id']; ?>">
                                                <button type="submit" name="action" value="publish_saved" class="btn btn-primary" style="padding:6px 12px;font-size:12px;">
                                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                                                    Publish
                                                </button>
                                            </form>
                                        <?php elseif ($row['status'] === 'failed'): ?>
                                            <form method="POST" onsubmit="return confirm('Retry publishing this post?');" style="display:inline;">
                                                <?php echo sa_csrf_field(); ?>
                                                <input type="hidden" name="post_id" value="<?php echo (int) $row['id']; ?>">
                                                <button type="submit" name="action" value="publish_saved" class="btn btn-primary" style="padding:6px 12px;font-size:12px;">
                                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
                                                    Retry
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                        <form method="POST" onsubmit="return confirm('Delete this post from the library?');" style="display:inline;">
                                            <?php echo sa_csrf_field(); ?>
                                            <input type="hidden" name="post_id" value="<?php echo (int) $row['id']; ?>">
                                            <button type="submit" name="action" value="delete_post" class="btn btn-danger" style="padding:6px 12px;font-size:12px;">
                                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                                                Delete
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="6" class="table-empty">Nothing here yet — compose your first post above.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
<?php include __DIR__ . '/_shell_footer.php'; ?>

<!-- Add Social Account Modal -->
<div id="addSocialModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.6);z-index:9999;justify-content:center;align-items:center;padding:20px;">
    <div style="background:var(--card-bg, #fff);border-radius:16px;padding:32px;width:100%;max-width:600px;box-shadow:0 20px 60px rgba(0,0,0,0.3);max-height:90vh;overflow-y:auto;position:relative;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;">
            <h3 style="margin:0;font-size:20px;font-weight:700;color:var(--ink, #0f2438);">Add Social Account</h3>
            <button type="button" onclick="closeAddSocialModal()" style="background:none;border:none;font-size:28px;cursor:pointer;color:var(--muted, #64748b);line-height:1;padding:0;width:32px;height:32px;display:flex;align-items:center;justify-content:center;">&times;</button>
        </div>

        <p class="muted" style="margin-bottom:24px;color:var(--muted, #64748b);">
            Select a platform to connect or add a custom social network.
        </p>

        <!-- Platform Selection Grid -->
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:12px;margin-bottom:28px;">
            <?php foreach ($platforms as $key => $meta): ?>
                <?php $is_connected = isset($accounts[$key]); ?>
                <button type="button" 
                        onclick="selectPlatformInModal('<?php echo sa_e($key); ?>', '<?php echo sa_e(addslashes($meta['label'])); ?>', '<?php echo sa_e($meta['glyph']); ?>', <?php echo (int)$meta['limit']; ?>, '<?php echo sa_e(addslashes($meta['ref_label'])); ?>', '<?php echo sa_e(addslashes($meta['ref_hint'])); ?>', '<?php echo sa_e(addslashes($meta['token_hint'])); ?>', '<?php echo sa_e($meta['docs']); ?>')"
                        style="padding:18px 12px;border:2px solid var(--line, #cbd5e1);border-radius:12px;background:var(--card-bg, #fff);cursor:pointer;display:flex;flex-direction:column;align-items:center;gap:8px;transition:all 0.2s;<?php echo $is_connected ? 'opacity:0.5;cursor:not-allowed;' : ''; ?>"
                        <?php echo $is_connected ? 'disabled' : ''; ?>
                        onmouseover="if(!this.disabled){ this.style.borderColor='var(--lime, #c2f542)'; this.style.background='var(--bg, #f8f9fa)'; }"
                        onmouseout="if(!this.disabled){ this.style.borderColor='var(--line, #cbd5e1)'; this.style.background='var(--card-bg, #fff)'; }">
                    <span style="font-size:32px;line-height:1;"><?php echo sa_e($meta['glyph']); ?></span>
                    <span style="font-size:13px;font-weight:600;color:var(--ink, #0f2438);text-align:center;"><?php echo sa_e($meta['label']); ?></span>
                    <?php if ($is_connected): ?>
                        <span style="font-size:10px;color:var(--lime, #c2f542);font-weight:700;">✓ Connected</span>
                    <?php endif; ?>
                </button>
            <?php endforeach; ?>
            
            <!-- Custom Platform Button -->
            <button type="button" 
                    onclick="selectCustomPlatform()"
                    style="padding:18px 12px;border:2px dashed var(--line, #cbd5e1);border-radius:12px;background:var(--card-bg, #fff);cursor:pointer;display:flex;flex-direction:column;align-items:center;gap:8px;transition:all 0.2s;"
                    onmouseover="this.style.borderColor='var(--lime, #c2f542)'; this.style.background='var(--bg, #f8f9fa)';"
                    onmouseout="this.style.borderColor='var(--line, #cbd5e1)'; this.style.background='var(--card-bg, #fff)';">
                <span style="font-size:32px;line-height:1;">+</span>
                <span style="font-size:13px;font-weight:600;color:var(--ink, #0f2438);text-align:center;">Custom<br>Platform</span>
            </button>
        </div>

        <!-- Connection Form (Hidden until platform selected) -->
        <div id="connectionFormContainer" style="display:none;">
            <hr style="border:none;border-top:1px solid var(--line, #cbd5e1);margin:24px 0;">
            
            <form method="POST" id="addSocialForm">
                <?php echo sa_csrf_field(); ?>
                <input type="hidden" name="action" value="connect">
                <input type="hidden" name="platform" id="modal_platform">
                <input type="hidden" name="is_custom" id="modal_is_custom" value="0">

                <div id="customPlatformFields" style="display:none;margin-bottom:20px;">
                    <div class="form-group" style="margin-bottom:16px;">
                        <label for="modal_custom_platform_name" style="display:block;margin-bottom:6px;font-size:13px;font-weight:600;color:var(--ink, #334155);">Platform Name <span style="color:#ef4444;">*</span></label>
                        <input id="modal_custom_platform_name" type="text" name="custom_platform_name" 
                               placeholder="e.g., TikTok, Threads, Pinterest" 
                               style="width:100%;padding:12px;border:1px solid var(--line, #cbd5e1);border-radius:8px;font-size:14px;box-sizing:border-box;">
                    </div>

                    <div class="form-group" style="margin-bottom:16px;">
                        <label for="modal_custom_glyph" style="display:block;margin-bottom:6px;font-size:13px;font-weight:600;color:var(--ink, #334155);">Platform Icon/Emoji</label>
                        <input id="modal_custom_glyph" type="text" name="custom_glyph" 
                               placeholder="e.g., 🎵, 📌, 🧵" maxlength="4"
                               style="width:100%;padding:12px;border:1px solid var(--line, #cbd5e1);border-radius:8px;font-size:14px;box-sizing:border-box;">
                    </div>

                    <div class="form-group" style="margin-bottom:16px;">
                        <label for="modal_custom_limit" style="display:block;margin-bottom:6px;font-size:13px;font-weight:600;color:var(--ink, #334155);">Character Limit</label>
                        <input id="modal_custom_limit" type="number" name="custom_limit" value="280" min="1" max="100000"
                               style="width:100%;padding:12px;border:1px solid var(--line, #cbd5e1);border-radius:8px;font-size:14px;box-sizing:border-box;">
                    </div>
                </div>

                <div class="form-group" style="margin-bottom:16px;">
                    <label for="modal_account_name" style="display:block;margin-bottom:6px;font-size:13px;font-weight:600;color:var(--ink, #334155);">Display Name <span style="color:#ef4444;">*</span></label>
                    <input id="modal_account_name" type="text" name="account_name" required
                           placeholder="e.g., Acme Corp Official"
                           style="width:100%;padding:12px;border:1px solid var(--line, #cbd5e1);border-radius:8px;font-size:14px;box-sizing:border-box;">
                </div>

                <div class="form-group" style="margin-bottom:16px;">
                    <label for="modal_account_ref" id="modal_ref_label" style="display:block;margin-bottom:6px;font-size:13px;font-weight:600;color:var(--ink, #334155);">Account ID / Reference</label>
                    <input id="modal_account_ref" type="text" name="account_ref"
                           style="width:100%;padding:12px;border:1px solid var(--line, #cbd5e1);border-radius:8px;font-size:14px;box-sizing:border-box;">
                    <small class="muted" id="modal_ref_hint" style="font-size:11.5px;display:block;margin-top:4px;color:var(--muted, #64748b);"></small>
                </div>

                <div class="form-group" style="margin-bottom:20px;">
                    <label for="modal_access_token" style="display:block;margin-bottom:6px;font-size:13px;font-weight:600;color:var(--ink, #334155);">Access Token <span style="color:#ef4444;">*</span></label>
                    <textarea id="modal_access_token" name="access_token" rows="3" required
                              style="width:100%;padding:12px;border:1px solid var(--line, #cbd5e1);border-radius:8px;font-size:13px;font-family:monospace;resize:vertical;box-sizing:border-box;"></textarea>
                    <small class="muted" id="modal_token_hint" style="font-size:11.5px;display:block;margin-top:4px;color:var(--muted, #64748b);"></small>
                    <a href="#" id="modal_docs_link" target="_blank" rel="noopener" style="font-size:11.5px;color:var(--lime, #c2f542);text-decoration:none;display:inline-block;margin-top:4px;">View API documentation ↗</a>
                </div>

                <div style="display:flex;gap:10px;justify-content:flex-end;">
                    <button type="button" onclick="closeAddSocialModal()" class="btn btn-secondary" style="padding:12px 24px;">Cancel</button>
                    <button type="submit" class="btn btn-primary" style="padding:12px 24px;">
                        <span id="modal_submit_text">Connect Account</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Post Modal -->
<div id="editPostModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:9999;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:16px;padding:28px;width:90%;max-width:520px;box-shadow:0 20px 60px rgba(0,0,0,0.3);">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
            <h3 style="margin:0;font-size:18px;font-weight:700;">Edit Social Post</h3>
            <button type="button" onclick="closeEditModal()" style="background:none;border:none;font-size:24px;cursor:pointer;color:#64748b;">&times;</button>
        </div>
        <form method="POST" id="editPostForm">
            <?php echo sa_csrf_field(); ?>
            <input type="hidden" name="post_id" id="editPostId">
            <input type="hidden" name="action" value="update_post">
            <div class="form-group" style="margin-bottom:16px;">
                <label for="editPostContent">Post Content</label>
                <textarea id="editPostContent" name="content" rows="5" style="width:100%;padding:12px;border:1px solid #cbd5e1;border-radius:8px;font-size:14px;font-family:inherit;resize:vertical;"></textarea>
            </div>
            <div style="display:flex;gap:10px;justify-content:flex-end;">
                <button type="button" onclick="closeEditModal()" class="btn btn-secondary" style="padding:10px 20px;">Cancel</button>
                <button type="submit" class="btn btn-primary" style="padding:10px 20px;">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
// Add Social Modal Functions
function openAddSocialModal() {
    console.log('Opening add social modal');
    document.getElementById('addSocialModal').style.display = 'flex';
    document.getElementById('connectionFormContainer').style.display = 'none';
    document.getElementById('addSocialForm').reset();
}

function closeAddSocialModal() {
    console.log('Closing add social modal');
    document.getElementById('addSocialModal').style.display = 'none';
}

function selectPlatformInModal(key, label, glyph, limit, refLabel, refHint, tokenHint, docs) {
    console.log('Platform selected:', key, label);
    document.getElementById('modal_platform').value = key;
    document.getElementById('modal_is_custom').value = '0';
    document.getElementById('customPlatformFields').style.display = 'none';
    
    // Update form labels and hints
    document.getElementById('modal_ref_label').textContent = refLabel;
    document.getElementById('modal_ref_hint').textContent = refHint;
    document.getElementById('modal_token_hint').textContent = tokenHint;
    document.getElementById('modal_docs_link').href = docs;
    document.getElementById('modal_docs_link').style.display = 'inline-block';
    document.getElementById('modal_submit_text').textContent = 'Connect ' + label;
    
    // Show form
    console.log('Showing connection form');
    document.getElementById('connectionFormContainer').style.display = 'block';
    setTimeout(function() {
        document.getElementById('modal_account_name').focus();
    }, 100);
}

function selectCustomPlatform() {
    console.log('Custom platform selected');
    const timestamp = Date.now();
    document.getElementById('modal_platform').value = 'custom_' + timestamp;
    document.getElementById('modal_is_custom').value = '1';
    document.getElementById('customPlatformFields').style.display = 'block';
    
    // Update form labels for custom platform
    document.getElementById('modal_ref_label').textContent = 'Account Handle / Username';
    document.getElementById('modal_ref_hint').textContent = 'Optional: your account handle or profile URL';
    document.getElementById('modal_token_hint').textContent = 'Paste your API access token or authentication credentials';
    document.getElementById('modal_docs_link').style.display = 'none';
    document.getElementById('modal_submit_text').textContent = 'Add Custom Platform';
    
    // Show form
    console.log('Showing connection form for custom platform');
    document.getElementById('connectionFormContainer').style.display = 'block';
    setTimeout(function() {
        document.getElementById('modal_custom_platform_name').focus();
    }, 100);
}

// Close modal on outside click
document.addEventListener('click', function(e) {
    var modal = document.getElementById('addSocialModal');
    if (e.target === modal) closeAddSocialModal();
});

// Edit Post Modal Functions
function openEditModal(id, content) {
    document.getElementById('editPostId').value = id;
    document.getElementById('editPostContent').value = content;
    document.getElementById('editPostModal').style.display = 'flex';
}
function closeEditModal() {
    document.getElementById('editPostModal').style.display = 'none';
}
// Close modal on outside click
document.addEventListener('click', function(e) {
    var modal = document.getElementById('editPostModal');
    if (e.target === modal) closeEditModal();
});
</script>
