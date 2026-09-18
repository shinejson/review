<?php
/**
 * ============================================================
 *  Workspace — Customer Support / Contact Platform
 * ============================================================
 *  Allows workspace owners AND team members (with 'support'
 *  permission) to report concerns, ask questions and reply to
 *  platform support. Full two-way conversation thread.
 */

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/admin_helpers.php';
require_once dirname(__DIR__) . '/includes/sa_helpers.php';
require_once dirname(__DIR__) . '/includes/notifications.php';

requireLogin();

$tenant_id = getTenantId();
// Legacy global admin accounts (admin_id without a tenant) have no workspace.
if ((isAdmin() || !empty($_SESSION['admin_id'])) && !$tenant_id) {
    sa_flash('error', 'Platform administrators manage tickets from the Superadmin Control Center. Impersonate a workspace to file tickets from inside it.');
    redirect('index.php');
}
if (!$tenant_id) {
    sa_flash('error', 'Support tickets are submitted from inside an active workspace.');
    redirect('index.php');
}

// Team members need the 'support' module to reach this page
if (!teamHasAccess('support')) {
    sa_flash('error', 'Your role does not have access to Platform Support. Ask the workspace owner.');
    redirect('index.php');
}

sa_ensure_platform_feedback_schema($conn);
notifications_ensure_schema($conn);

$actor = support_current_actor('admin');

/* ============================================================
   POST handlers
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!sa_csrf_ok()) {
        sa_flash('error', 'Your session expired. Please try again.');
        redirect('support.php');
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'create_ticket') {
        $subject   = trim(sanitize($_POST['subject'] ?? ''));
        $ticket_type = trim($_POST['ticket_type'] ?? 'support');
        $priority  = trim($_POST['priority'] ?? 'medium');
        $message   = trim(sanitize($_POST['message'] ?? ''));
        $company_id = !empty($_SESSION['active_company_id']) ? (int)$_SESSION['active_company_id'] : null;

        $allowed_types = ['support','feature_request','bug_report','billing','general'];
        $allowed_priorities = ['low','medium','high','urgent'];
        if (!in_array($ticket_type, $allowed_types, true)) $ticket_type = 'support';
        if (!in_array($priority, $allowed_priorities, true)) $priority = 'medium';

        if (empty($subject) || empty($message)) {
            sa_flash('error', 'Please provide both a subject and a description.');
            redirect('support.php');
        }

        $stmt = $conn->prepare(
            "INSERT INTO platform_feedback
                (tenant_id, company_id, ticket_type, priority, subject, message, status,
                 submitted_by_kind, submitted_by_id, submitted_by_name, last_reply_by)
             VALUES (?, ?, ?, ?, ?, ?, 'open', ?, ?, ?, 'tenant')"
        );
        if ($stmt) {
            $stmt->bind_param("iisssssis",
                $tenant_id, $company_id, $ticket_type, $priority, $subject, $message,
                $actor['kind'], $actor['id'], $actor['name']);
            if ($stmt->execute()) {
                $new_id = $stmt->insert_id;
                // Log the original message as the first reply entry so the
                // thread reads chronologically from the top.
                support_add_reply($conn, $new_id, $actor['kind'], $actor['id'], $actor['name'], $message, false, null);
                // Fire platform notification immediately.
                notifications_add($conn, [
                    'audience'  => 'platform',
                    'type'      => 'support_ticket_new',
                    'title'     => 'New support ticket #T-' . (int)$new_id . ' from ' . ($actor['name'] ?: 'a workspace'),
                    'message'   => mb_substr_safe($subject, 180),
                    'link'      => 'support.php?id=' . (int)$new_id,
                    'tenant_id' => (int)$tenant_id,
                    'entity'    => ['platform_feedback', (int)$new_id],
                    'dedupe_key'=> 'p:support_ticket:' . (int)$new_id . ':' . date('YmdHis'),
                    'tone'      => in_array($priority, ['urgent','high'], true) ? 'danger' : 'warning',
                ]);
                sa_flash('success', "Ticket #T-{$new_id} created. Our team will review and reply shortly.");
            } else {
                sa_flash('error', 'Could not create ticket. Please try again.');
            }
            $stmt->close();
        } else {
            sa_flash('error', 'Database error. Please try again.');
        }
        redirect('support.php');
    }

    if ($action === 'reply_ticket') {
        $tid = (int)($_POST['ticket_id'] ?? 0);
        $msg = trim(sanitize($_POST['message'] ?? ''));
        if ($tid <= 0 || $msg === '') {
            sa_flash('error', 'Your reply is empty.');
            redirect('support.php');
        }
        // Verify ownership
        $t = support_fetch_ticket($conn, $tid);
        if (!$t || (int)$t['tenant_id'] !== (int)$tenant_id) {
            sa_flash('error', 'Ticket not found.');
            redirect('support.php');
        }
        if (in_array(($t['status'] ?? ''), ['closed'], true)) {
            // Re-open when a customer replies after closure
        }
        support_add_reply($conn, $tid, $actor['kind'], $actor['id'], $actor['name'], $msg, false, null);
        notifications_add($conn, [
            'audience'  => 'platform',
            'type'      => 'support_ticket_new',
            'title'     => 'New reply on ticket #T-' . $tid,
            'message'   => mb_substr_safe(($t['subject'] ?? ''), 180),
            'link'      => 'support.php?id=' . $tid,
            'tenant_id' => (int)$tenant_id,
            'entity'    => ['platform_feedback', $tid],
            'dedupe_key'=> 'p:support_ticket:' . $tid . ':' . date('YmdHis'),
            'tone'      => 'info',
        ]);
        sa_flash('success', 'Your reply was sent.');
        redirect('support.php?id=' . $tid);
    }

    if ($action === 'close_ticket') {
        $tid = (int)($_POST['ticket_id'] ?? 0);
        if ($tid > 0) {
            $t = support_fetch_ticket($conn, $tid);
            if ($t && (int)$t['tenant_id'] === (int)$tenant_id) {
                support_add_reply($conn, $tid, $actor['kind'], $actor['id'], $actor['name'],
                    'Ticket closed by requester.', true, 'closed');
                sa_flash('success', 'Ticket #T-' . $tid . ' has been closed.');
            }
        }
        redirect('support.php?id=' . $tid);
    }

    if ($action === 'reopen_ticket') {
        $tid = (int)($_POST['ticket_id'] ?? 0);
        if ($tid > 0) {
            $t = support_fetch_ticket($conn, $tid);
            if ($t && (int)$t['tenant_id'] === (int)$tenant_id) {
                @$conn->query("UPDATE platform_feedback SET status='open', resolved_at=NULL, closed_at=NULL WHERE id=" . (int)$tid);
                sa_flash('success', 'Ticket #T-' . $tid . ' has been re-opened.');
            }
        }
        redirect('support.php?id=' . $tid);
    }
}

/* ============================================================
   Fetch ticket list or single-ticket detail
   ============================================================ */
$view_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$active_ticket = null;
$replies = [];

if ($view_id > 0) {
    $active_ticket = support_fetch_ticket($conn, $view_id);
    if (!$active_ticket || (int)$active_ticket['tenant_id'] !== (int)$tenant_id) {
        sa_flash('error', 'Ticket not found.');
        redirect('support.php');
    }
    $replies = support_fetch_replies($conn, $view_id, false); // hide internal notes
    // Mark any reply-from-superadmin as "read" by clearing the unread notif next sync
}

$tickets = [];
$filter = $_GET['filter'] ?? 'all';
$allowed_filters = ['all','open','in_progress','resolved','closed'];
if (!in_array($filter, $allowed_filters, true)) $filter = 'all';

$where = "tenant_id = " . (int)$tenant_id;
if ($filter !== 'all') $where .= " AND status = '" . $conn->real_escape_string($filter) . "'";
$t_res = $conn->query("SELECT * FROM platform_feedback WHERE {$where} ORDER BY updated_at DESC, id DESC");
if ($t_res) {
    while ($row = $t_res->fetch_assoc()) $tickets[] = $row;
}

$counts = ['all' => 0, 'open' => 0, 'in_progress' => 0, 'resolved' => 0, 'closed' => 0];
foreach ($tickets as $t) {
    $counts['all']++;
    if (isset($counts[$t['status']])) $counts[$t['status']]++;
}
$counts['resolved'] += isset($counts['closed']) ? 0 : 0; // closed tracked separately; roll up for UI later if needed

$pageTitle = 'Platform Support';
$activeNav = 'support';
$BASE = '../';

include __DIR__ . '/_shell.php';
?>

<div style="max-width:1180px;margin:0 auto;padding:10px 0 40px;">

  <?php if ($active_ticket): ?>
    <?php
      $t = $active_ticket;
      $type_labels = [
        'support' => 'Technical Support', 'feature_request' => 'Feature Suggestion',
        'bug_report' => 'Bug Report', 'billing' => 'Billing', 'general' => 'General',
      ];
      $type_colors = ['support'=>'#6366f1','feature_request'=>'#8b5cf6','bug_report'=>'#ef4444','billing'=>'#0ea5e9','general'=>'#64748b'];
      $status_badges = [
        'open'        => ['Awaiting Review', '#fef3c7','#92400e','#fde68a'],
        'in_progress' => ['In Progress',     '#eff6ff','#1e40af','#bfdbfe'],
        'resolved'    => ['Resolved',        '#f0fdf4','#166534','#bbf7d0'],
        'closed'      => ['Closed',          '#f1f5f9','#475569','#cbd5e1'],
      ];
      $pri_colors = [
        'urgent'=>['#fef2f2','#991b1b','#fca5a5'],'high'=>['#fff7ed','#9a3412','#fed7aa'],
        'medium'=>['#eff6ff','#1e40af','#bfdbfe'],'low'=>['#f8fafc','#475569','#e2e8f0'],
      ];
      $cs = $status_badges[$t['status']] ?? $status_badges['open'];
      $cp = $pri_colors[$t['priority']] ?? $pri_colors['medium'];
    ?>

    <div style="margin-bottom:16px;">
      <a href="support.php" style="display:inline-flex;align-items:center;gap:6px;color:var(--muted,#64748b);font-size:13px;font-weight:600;text-decoration:none;margin-bottom:10px;">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
        Back to all requests
      </a>
      <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap;">
        <div>
          <h1 style="font-size:22px;font-weight:800;color:var(--heading,#0f172a);margin:0 0 4px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
            <span style="font-family:monospace;font-size:13px;font-weight:700;color:var(--muted,#64748b);background:var(--card-sub,#f1f5f9);padding:3px 8px;border-radius:6px;">#T-<?php echo (int)$t['id']; ?></span>
            <span><?php echo htmlspecialchars($t['subject']); ?></span>
          </h1>
          <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px;">
            <span style="font-size:11px;font-weight:700;padding:2px 8px;border-radius:6px;background:<?php echo $type_colors[$t['ticket_type']] ?? '#6366f1'; ?>15;color:<?php echo $type_colors[$t['ticket_type']] ?? '#6366f1'; ?>;">
              <?php echo htmlspecialchars($type_labels[$t['ticket_type']] ?? 'Support'); ?>
            </span>
            <span style="font-size:11px;font-weight:700;padding:2px 7px;border-radius:6px;background:<?php echo $cp[0]; ?>;color:<?php echo $cp[1]; ?>;border:1px solid <?php echo $cp[2]; ?>;">
              <?php echo ucfirst($t['priority']); ?>
            </span>
            <span style="font-size:11.5px;font-weight:700;padding:3px 10px;border-radius:12px;background:<?php echo $cs[1]; ?>;color:<?php echo $cs[2]; ?>;border:1px solid <?php echo $cs[3]; ?>;"><?php echo $cs[0]; ?></span>
            <span style="font-size:12px;color:var(--muted,#64748b);">Opened <?php echo sa_time_ago($t['created_at']); ?></span>
            <?php if (!empty($t['branch_name'])): ?>
              <span style="font-size:12px;color:var(--muted,#64748b);">· Branch: <?php echo htmlspecialchars($t['branch_name']); ?></span>
            <?php endif; ?>
          </div>
        </div>
        <?php if ($t['status'] !== 'closed'): ?>
          <form method="POST" action="support.php?id=<?php echo (int)$t['id']; ?>" onsubmit="return confirm('Close this ticket? You can re-open it later if the issue returns.');">
            <?php echo sa_csrf_field(); ?>
            <input type="hidden" name="action" value="close_ticket">
            <input type="hidden" name="ticket_id" value="<?php echo (int)$t['id']; ?>">
            <button type="submit" class="btn" style="background:#f1f5f9;color:#475569;font-weight:700;">Close ticket</button>
          </form>
        <?php else: ?>
          <form method="POST" action="support.php?id=<?php echo (int)$t['id']; ?>">
            <?php echo sa_csrf_field(); ?>
            <input type="hidden" name="action" value="reopen_ticket">
            <input type="hidden" name="ticket_id" value="<?php echo (int)$t['id']; ?>">
            <button type="submit" class="btn btn-primary" style="font-weight:700;">Re-open ticket</button>
          </form>
        <?php endif; ?>
      </div>
    </div>

    <!-- Conversation thread -->
    <div style="background:var(--card-bg,#fff);border:1px solid var(--line,#e2e8f0);border-radius:14px;padding:24px;margin-bottom:20px;box-shadow:0 2px 8px rgba(0,0,0,0.03);">
      <h2 style="font-size:15px;font-weight:700;color:var(--heading,#0f172a);margin:0 0 18px;display:flex;align-items:center;gap:8px;">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
        Conversation
      </h2>
      <div style="display:flex;flex-direction:column;gap:14px;">
        <?php if (empty($replies)): ?>
          <p style="color:var(--muted,#64748b);text-align:center;padding:20px;">No messages yet.</p>
        <?php else: ?>
          <?php foreach ($replies as $r):
            $is_support = in_array($r['author_kind'] ?? '', ['superadmin'], true);
          ?>
            <div style="display:flex;gap:12px;<?php echo $is_support ? 'flex-direction:row-reverse;' : ''; ?>">
              <div style="width:36px;height:36px;border-radius:50%;flex-shrink:0;display:grid;place-items:center;font-size:12px;font-weight:800;color:#fff;background:<?php echo $is_support ? '#6366f1' : '#0f172a'; ?>;">
                <?php echo htmlspecialchars(sa_initials($r['author_name'] ?? ($is_support ? 'S' : 'Y'))); ?>
              </div>
              <div style="max-width:78%;flex:1;">
                <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;<?php echo $is_support ? 'justify-content:flex-end;' : ''; ?>">
                  <strong style="font-size:13px;color:var(--heading,#0f172a);"><?php echo htmlspecialchars($r['author_name'] ?: ($is_support ? 'Platform Support' : 'You')); ?></strong>
                  <?php if ($is_support): ?>
                    <span style="font-size:10px;font-weight:700;padding:1px 7px;border-radius:10px;background:#eef2ff;color:#4338ca;">Platform</span>
                  <?php else: ?>
                    <span style="font-size:10px;font-weight:700;padding:1px 7px;border-radius:10px;background:#f1f5f9;color:#475569;">You</span>
                  <?php endif; ?>
                  <span style="font-size:11px;color:var(--muted,#94a3b8);"><?php echo sa_time_ago($r['created_at']); ?></span>
                </div>
                <div style="background:<?php echo $is_support ? '#eef2ff' : 'var(--card-sub,#f8fafc)'; ?>;border:1px solid <?php echo $is_support ? '#c7d2fe' : 'var(--line,#e2e8f0)'; ?>;border-radius:12px;padding:12px 14px;font-size:13.5px;line-height:1.6;color:<?php echo $is_support ? '#312e81' : 'var(--body,#334155)'; ?>;white-space:pre-wrap;">
                  <?php echo htmlspecialchars($r['message']); ?>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($t['status'] !== 'closed'): ?>
    <div style="background:var(--card-bg,#fff);border:1px solid var(--line,#e2e8f0);border-radius:14px;padding:20px;box-shadow:0 2px 8px rgba(0,0,0,0.03);">
      <h3 style="font-size:14px;font-weight:700;color:var(--heading,#0f172a);margin:0 0 10px;">Reply to platform support</h3>
      <form method="POST" action="support.php?id=<?php echo (int)$t['id']; ?>">
        <?php echo sa_csrf_field(); ?>
        <input type="hidden" name="action" value="reply_ticket">
        <input type="hidden" name="ticket_id" value="<?php echo (int)$t['id']; ?>">
        <textarea name="message" required rows="3" placeholder="Type your reply..." style="width:100%;padding:12px;border-radius:8px;border:1px solid var(--line,#cbd5e1);font-size:14px;background:var(--input-bg,#fff);color:inherit;font-family:inherit;resize:vertical;"></textarea>
        <div style="display:flex;justify-content:space-between;align-items:center;margin-top:10px;flex-wrap:wrap;gap:8px;">
          <p style="font-size:12px;color:var(--muted,#94a3b8);margin:0;">Replies are delivered to platform engineers. Expect a response within 24 hours on business days.</p>
          <button type="submit" class="btn btn-primary" style="font-weight:700;padding:9px 20px;">Send reply</button>
        </div>
      </form>
    </div>
    <?php else: ?>
    <div style="background:#f8fafc;border:1px dashed #cbd5e1;border-radius:12px;padding:18px;text-align:center;">
      <p style="margin:0 0 10px;color:var(--muted,#64748b);font-size:13.5px;">This ticket is closed. If the issue persists, re-open it using the button above or start a new request.</p>
    </div>
    <?php endif; ?>

  <?php else: /* list view */ ?>

  <!-- Page Header -->
  <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:16px;margin-bottom:24px;">
    <div>
      <h1 style="font-size:24px;font-weight:800;color:var(--heading,#0f172a);margin:0 0 4px;display:flex;align-items:center;gap:10px;">
        <span>Platform Support &amp; Feedback</span>
      </h1>
      <p style="color:var(--muted,#64748b);font-size:14px;margin:0;">
        Reach out directly to platform engineers for technical help, bug reports, or feature suggestions.
      </p>
    </div>
    <button type="button" class="btn btn-primary" onclick="document.getElementById('newTicketCard').scrollIntoView({behavior:'smooth'});document.getElementById('ticketSubject').focus();" style="display:inline-flex;align-items:center;gap:8px;font-weight:700;">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
      New Request
    </button>
  </div>

  <?php echo sa_render_flash(); ?>

  <!-- Summary Cards -->
  <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(160px, 1fr));gap:14px;margin-bottom:24px;">
    <?php
      $cards = [
        ['all',         'Total Requests', $counts['all'],                  '#64748b', '#475569', '#cbd5e1'],
        ['open',        'Awaiting Review', $counts['open'],                '#d97706', '#b45309', '#fde68a'],
        ['in_progress', 'In Progress',    $counts['in_progress'],         '#2563eb', '#1d4ed8', '#bfdbfe'],
        ['resolved',    'Resolved',       $counts['resolved'] + $counts['closed'], '#16a34a', '#15803d', '#bbf7d0'],
      ];
      foreach ($cards as $c): ?>
        <a href="?filter=<?php echo $c[0]; ?>" style="text-decoration:none;background:var(--card-bg,#fff);border:1px solid <?php echo $filter === $c[0] ? $c[3] : 'var(--line,#e2e8f0)'; ?>;border-radius:12px;padding:16px;transition:.15s;">
          <div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;color:<?php echo $c[3]; ?>;"><?php echo $c[1]; ?></div>
          <div style="font-size:24px;font-weight:800;color:<?php echo $c[4]; ?>;margin-top:6px;"><?php echo (int)$c[2]; ?></div>
        </a>
    <?php endforeach; ?>
  </div>

  <!-- New Ticket Form -->
  <div id="newTicketCard" style="background:var(--card-bg,#fff);border:1px solid var(--line,#e2e8f0);border-radius:14px;padding:22px;margin-bottom:28px;box-shadow:0 2px 8px rgba(0,0,0,0.03);">
    <h2 style="font-size:16px;font-weight:700;color:var(--heading,#0f172a);margin:0 0 4px;">Submit a new request</h2>
    <p style="font-size:13px;color:var(--muted,#64748b);margin:0 0 18px;">Describe your concern clearly and our team will respond.</p>
    <form method="POST" action="support.php">
      <?php echo sa_csrf_field(); ?>
      <input type="hidden" name="action" value="create_ticket">
      <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(240px, 1fr));gap:14px;margin-bottom:14px;">
        <div>
          <label style="display:block;font-size:12px;font-weight:700;margin-bottom:5px;color:var(--heading,#0f172a);">Subject *</label>
          <input type="text" id="ticketSubject" name="subject" required placeholder="e.g. Issue with QR scan URL / Billing question" style="width:100%;padding:9px 12px;border-radius:8px;border:1px solid var(--line,#cbd5e1);font-size:14px;background:var(--input-bg,#fff);color:inherit;">
        </div>
        <div>
          <label style="display:block;font-size:12px;font-weight:700;margin-bottom:5px;color:var(--heading,#0f172a);">Category *</label>
          <select name="ticket_type" style="width:100%;padding:9px 12px;border-radius:8px;border:1px solid var(--line,#cbd5e1);font-size:14px;background:var(--input-bg,#fff);color:inherit;">
            <option value="support">🛠️ Technical Support</option>
            <option value="bug_report">🐞 Bug Report</option>
            <option value="billing">💳 Billing & Subscription</option>
            <option value="feature_request">💡 Feature Suggestion</option>
            <option value="general">💬 General Question</option>
          </select>
        </div>
        <div>
          <label style="display:block;font-size:12px;font-weight:700;margin-bottom:5px;color:var(--heading,#0f172a);">Priority</label>
          <select name="priority" style="width:100%;padding:9px 12px;border-radius:8px;border:1px solid var(--line,#cbd5e1);font-size:14px;background:var(--input-bg,#fff);color:inherit;">
            <option value="low">Low — general question</option>
            <option value="medium" selected>Medium — standard response</option>
            <option value="high">High — impacting my work</option>
            <option value="urgent">Urgent — service down</option>
          </select>
        </div>
      </div>
      <div style="margin-bottom:14px;">
        <label style="display:block;font-size:12px;font-weight:700;margin-bottom:5px;color:var(--heading,#0f172a);">Describe your concern *</label>
        <textarea name="message" required rows="4" placeholder="Explain what happened, steps to reproduce (for bugs), or details of your request..." style="width:100%;padding:12px;border-radius:8px;border:1px solid var(--line,#cbd5e1);font-size:14px;background:var(--input-bg,#fff);color:inherit;font-family:inherit;resize:vertical;"></textarea>
      </div>
      <div style="text-align:right;">
        <button type="submit" class="btn btn-primary" style="font-weight:700;padding:9px 22px;">Submit Request</button>
      </div>
    </form>
  </div>

  <!-- Ticket List -->
  <div style="background:var(--card-bg,#fff);border:1px solid var(--line,#e2e8f0);border-radius:14px;padding:22px;box-shadow:0 2px 8px rgba(0,0,0,0.03);">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;flex-wrap:wrap;gap:10px;">
      <h2 style="font-size:16px;font-weight:700;color:var(--heading,#0f172a);margin:0;">Your requests</h2>
      <?php if ($filter !== 'all'): ?>
        <a href="?filter=all" style="font-size:12.5px;color:var(--muted,#64748b);font-weight:600;text-decoration:none;">Clear filter</a>
      <?php endif; ?>
    </div>

    <?php if (empty($tickets)): ?>
      <div style="text-align:center;padding:48px 20px;color:var(--muted,#64748b);">
        <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="margin-bottom:12px;opacity:0.6;"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
        <p style="font-weight:700;margin-bottom:4px;color:var(--heading,#0f172a);">No requests yet</p>
        <p style="font-size:13px;margin:0;">Use the form above to contact our team.</p>
      </div>
    <?php else: ?>
      <div style="display:flex;flex-direction:column;gap:10px;">
        <?php foreach ($tickets as $t):
          $type_labels = ['support'=>'Technical Support','feature_request'=>'Feature','bug_report'=>'Bug','billing'=>'Billing','general'=>'General'];
          $type_colors = ['support'=>'#6366f1','feature_request'=>'#8b5cf6','bug_report'=>'#ef4444','billing'=>'#0ea5e9','general'=>'#64748b'];
          $status_badges = [
            'open'        => ['Awaiting Review','#fef3c7','#92400e','#fde68a'],
            'in_progress' => ['In Progress','#eff6ff','#1e40af','#bfdbfe'],
            'resolved'    => ['Resolved','#f0fdf4','#166534','#bbf7d0'],
            'closed'      => ['Closed','#f1f5f9','#475569','#cbd5e1'],
          ];
          $cs = $status_badges[$t['status']] ?? $status_badges['open'];
          $unread_reply = $t['last_reply_by'] === 'superadmin' && $t['status'] !== 'closed';
        ?>
          <a href="?id=<?php echo (int)$t['id']; ?>" style="text-decoration:none;color:inherit;display:block;border:1px solid <?php echo $unread_reply ? '#c7d2fe' : 'var(--line,#e2e8f0)'; ?>;border-radius:10px;padding:14px 16px;background:<?php echo $unread_reply ? '#f5f7ff' : 'var(--card-sub,#fcfcfd)'; ?>;transition:.12s;">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:10px;flex-wrap:wrap;">
              <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                <span style="font-family:monospace;font-size:12px;font-weight:700;color:var(--muted,#64748b);">#T-<?php echo (int)$t['id']; ?></span>
                <span style="font-size:11px;font-weight:700;padding:2px 8px;border-radius:6px;background:<?php echo $type_colors[$t['ticket_type']] ?? '#6366f1'; ?>15;color:<?php echo $type_colors[$t['ticket_type']] ?? '#6366f1'; ?>;">
                  <?php echo htmlspecialchars($type_labels[$t['ticket_type']] ?? 'Support'); ?>
                </span>
                <?php if ($unread_reply): ?>
                  <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#6366f1;"></span>
                <?php endif; ?>
              </div>
              <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                <span style="font-size:11px;font-weight:700;padding:2px 9px;border-radius:12px;background:<?php echo $cs[1]; ?>;color:<?php echo $cs[2]; ?>;border:1px solid <?php echo $cs[3]; ?>;"><?php echo $cs[0]; ?></span>
                <span style="font-size:12px;color:var(--muted,#64748b);"><?php echo sa_time_ago($t['updated_at'] ?: $t['created_at']); ?></span>
              </div>
            </div>
            <h3 style="font-size:14.5px;font-weight:700;color:var(--heading,#0f172a);margin:8px 0 4px;"><?php echo htmlspecialchars($t['subject']); ?></h3>
            <p style="font-size:12.5px;color:var(--muted,#64748b);margin:0;line-height:1.5;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;"><?php echo htmlspecialchars($t['message']); ?></p>
            <?php if (!empty($t['admin_reply']) && $t['last_reply_by'] === 'superadmin'): ?>
              <p style="font-size:12px;color:#4338ca;margin:8px 0 0;font-weight:600;">↳ New reply from platform support</p>
            <?php endif; ?>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

</div>

<?php include __DIR__ . '/_shell_footer.php'; ?>
