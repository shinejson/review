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
        $platform = isset($_POST['platform']) ? (string) $_POST['platform'] : '';
        if (!isset($platforms[$platform])) {
            sa_flash('error', 'Unknown network.');
            redirect('social.php');
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
            sa_flash('error', 'Paste an access token to connect ' . $platforms[$platform]['label'] . '.');
            redirect('social.php');
        }

        if ($existing) {
            $stmt = $conn->prepare(
                "UPDATE social_accounts
                    SET account_name = ?, account_ref = ?, access_token = ?, status = 'connected', last_error = NULL
                  WHERE id = ?"
            );
            $eid = (int) $existing['id'];
            $stmt->bind_param('sssi', $name, $ref, $token, $eid);
            $ok = $stmt->execute();
            $stmt->close();
        } else {
            $stmt = $conn->prepare(
                "INSERT INTO social_accounts (tenant_id, platform, account_name, account_ref, access_token, status)
                 VALUES (?, ?, ?, ?, ?, 'connected')"
            );
            $stmt->bind_param('issss', $workspace_id, $platform, $name, $ref, $token);
            $ok = $stmt->execute();
            $stmt->close();
        }

        sa_flash($ok ? 'success' : 'error', $ok
            ? $platforms[$platform]['label'] . ' connected.'
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
        $platform  = isset($platforms[$platform]) ? $platform : 'facebook';
        $content   = trim((string) ($_POST['content'] ?? ''));
        $rating_id = (int) ($_POST['rating_id'] ?? 0);
        $company_id = (int) ($_POST['company_id'] ?? 0);

        if ($content === '') {
            sa_flash('error', 'Write the post before saving it.');
            redirect('social.php');
        }
        if (social_length($content) > $platforms[$platform]['limit']) {
            sa_flash('error', $platforms[$platform]['label'] . ' allows '
                . number_format($platforms[$platform]['limit']) . ' characters — trim the post.');
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
foreach (admin_rows($conn, "SELECT * FROM social_accounts WHERE tenant_id = " . $workspace_id) as $row) {
    $accounts[(string) $row['platform']] = $row;
}

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
$selected_platform = isset($_GET['platform']) && isset($platforms[$_GET['platform']])
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
        <div class="page-header">
            <div>
                <h1>Social &amp; lead content</h1>
                <p>Turn what customers wrote about you into posts that bring the next customer in.</p>
            </div>
            <div class="admin-head-stats">
                <div><strong><?php echo sa_e(sa_num($reviewable)); ?></strong><span>Quotable reviews</span></div>
                <div><strong><?php echo sa_e(sa_num($published_count)); ?></strong><span>Published</span></div>
                <div><strong><?php echo sa_e(sa_num($draft_count)); ?></strong><span>Drafts</span></div>
            </div>
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
                    <?php foreach ($platforms as $key => $meta): ?>
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
                                  data-social-limit="<?php echo (int) $platforms[$selected_platform]['limit']; ?>"
                                  placeholder="Write your post…"><?php echo sa_e($composer_text); ?></textarea>
                    </div>

                    <div class="admin-composer-meta">
                        <span data-social-counter>
                            <?php echo sa_e(sa_num(social_length($composer_text))); ?> /
                            <?php echo sa_e(sa_num($platforms[$selected_platform]['limit'])); ?> characters
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
        <div class="form-card">
            <h3>Network connections</h3>
            <p class="muted" style="margin-bottom:18px;">
                Paste the access token from each network's developer console. Optibiz posts straight to their API —
                tokens are stored for this workspace only and never shown again in full.
            </p>

            <div class="admin-connection-grid">
                <?php foreach ($platforms as $key => $meta): ?>
                    <?php $account = isset($accounts[$key]) ? $accounts[$key] : null; ?>
                    <div class="admin-connection<?php echo $account ? ' is-connected' : ''; ?>">
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
                                            <a class="btn-link" href="<?php echo sa_e($row['remote_url']); ?>" target="_blank" rel="noopener">View ↗</a>
                                        <?php endif; ?>
                                        <?php if ($row['status'] !== 'published'): ?>
                                            <form method="POST" onsubmit="return confirm('Publish this post now?');">
                                                <?php echo sa_csrf_field(); ?>
                                                <input type="hidden" name="post_id" value="<?php echo (int) $row['id']; ?>">
                                                <button type="submit" name="action" value="publish_saved" class="btn-link">Publish</button>
                                            </form>
                                        <?php endif; ?>
                                        <form method="POST" onsubmit="return confirm('Delete this post from the library?');">
                                            <?php echo sa_csrf_field(); ?>
                                            <input type="hidden" name="post_id" value="<?php echo (int) $row['id']; ?>">
                                            <button type="submit" name="action" value="delete_post" class="btn-danger">Delete</button>
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
