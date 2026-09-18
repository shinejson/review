<?php
/**
 * ============================================================
 *  Super Admin — Support Tickets from all Workspaces
 * ============================================================
 *  Lets platform staff triage, reply to, resolve and close
 *  tenant support tickets. Supports per-ticket internal notes
 *  (hidden from tenants) and assignment.
 */

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/sa_helpers.php';
require_once dirname(__DIR__) . '/includes/notifications.php';

requireSuperAdminLogin();
$conn = $GLOBALS['conn'] ?? null;
require_sa_permission('support_tickets', $conn);

sa_ensure_platform_feedback_schema($conn);
notifications_ensure_schema($conn);

$actor = support_current_actor('superadmin');

/* ============================================================
   POST handlers
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!sa_csrf_ok()) {
        sa_flash('error', 'Your session expired. Please try again.');
        redirect('support.php');
    }
    $action = $_POST['action'] ?? '';
    $tid = (int)($_POST['ticket_id'] ?? 0);

    // Load and validate ticket ownership of tenant
    $ticket = $tid > 0 ? support_fetch_ticket($conn, $tid) : [];

    if ($action === 'reply' && $ticket) {
        $msg = trim(sanitize($_POST['message'] ?? ''));
        $internal = !empty($_POST['internal_note']) ? true : false;
        $new_status = $_POST['new_status'] ?? null;
        if (!in_array($new_status, ['open','in_progress','resolved','closed'], true)) $new_status = null;
        if ($msg === '' && $new_status === null) {
            sa_flash('error', 'Please write a reply or change the status.');
            redirect('support.php?id=' . $tid);
        }
        // Auto-assign to responding superadmin
        $assign_stmt = $conn->prepare("UPDATE platform_feedback SET assigned_to = ?, assigned_name = ? WHERE id = ? AND (assigned_to IS NULL OR assigned_to = 0)");
        if ($assign_stmt) {
            $assign_stmt->bind_param("isi", $actor['id'], $actor['name'], $tid);
            $assign_stmt->execute();
            $assign_stmt->close();
        }
        if ($msg !== '') {
            support_add_reply($conn, $tid, 'superadmin', $actor['id'], $actor['name'], $msg, $internal, $new_status);
        } elseif ($new_status) {
            $now = date('Y-m-d H:i:s');
            $upd = ["status = '" . $conn->real_escape_string($new_status) . "'"];
            if ($new_status === 'resolved') $upd[] = "resolved_at = '{$now}'";
            elseif ($new_status === 'closed') $upd[] = "closed_at = '{$now}'";
            else { $upd[] = "resolved_at = NULL"; $upd[] = "closed_at = NULL"; }
            @$conn->query("UPDATE platform_feedback SET " . implode(', ', $upd) . " WHERE id = " . (int)$tid);
        }
        // Fire tenant notification if the message was visible to them
        if ($msg !== '' && !$internal) {
            notifications_add($conn, [
                'audience'  => 'tenant',
                'tenant_id' => (int)$ticket['tenant_id'],
                'type'      => 'support_ticket_reply',
                'title'     => 'Platform replied to ticket #T-' . $tid,
                'message'   => mb_substr_safe($ticket['subject'] ?? '', 180),
                'link'      => 'support.php?id=' . $tid,
                'entity'    => ['platform_feedback', $tid],
                'dedupe_key'=> 't:' . (int)$ticket['tenant_id'] . ':support_reply:' . $tid . ':' . date('YmdHis'),
                'tone'      => 'info',
            ]);
        }
        sa_flash('success', ($internal ? 'Internal note added.' : 'Reply sent to tenant.'));
        redirect('support.php?id=' . $tid);
    }

    if ($action === 'bulk_status' && $ticket) {
        $new_status = $_POST['new_status'] ?? '';
        if (in_array($new_status, ['open','in_progress','resolved','closed'], true)) {
            $now = date('Y-m-d H:i:s');
            $upd = ["status = '" . $conn->real_escape_string($new_status) . "'"];
            if ($new_status === 'resolved') $upd[] = "resolved_at = '{$now}'";
            elseif ($new_status === 'closed') $upd[] = "closed_at = '{$now}'";
            @$conn->query("UPDATE platform_feedback SET " . implode(', ', $upd) . " WHERE id = " . (int)$tid);
            sa_flash('success', 'Ticket status updated.');
        }
        redirect('support.php?id=' . $tid);
    }

    if ($action === 'assign' && $ticket) {
        $assign_to = (int)($_POST['assign_to'] ?? 0);
        if ($assign_to === -1) {
            @$conn->query("UPDATE platform_feedback SET assigned_to = NULL, assigned_name = NULL WHERE id = " . (int)$tid);
        } elseif ($assign_to > 0) {
            $sa_row = sa_one($conn, "SELECT username FROM super_admins WHERE id = " . $assign_to . " LIMIT 1", 'super_admins');
            $nm = $sa_row ? ($sa_row['username'] ?? '') : '';
            $stmt = $conn->prepare("UPDATE platform_feedback SET assigned_to = ?, assigned_name = ? WHERE id = ?");
            if ($stmt) { $stmt->bind_param("isi", $assign_to, $nm, $tid); $stmt->execute(); $stmt->close(); }
        }
        sa_flash('success', 'Assignment updated.');
        redirect('support.php?id=' . $tid);
    }

    redirect('support.php');
}

/* ============================================================
   Fetch data
   ============================================================ */
$view_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$active_ticket = null;
$replies = [];
if ($view_id > 0) {
    $active_ticket = support_fetch_ticket($conn, $view_id);
    if (!$active_ticket) {
        sa_flash('error', 'Ticket not found.');
        redirect('support.php');
    }
    $replies = support_fetch_replies($conn, $view_id, true);
}

$filter = $_GET['filter'] ?? 'open';
$priority_f = $_GET['priority'] ?? 'all';
$type_f = $_GET['type'] ?? 'all';
$assigned_f = $_GET['assigned'] ?? 'all';
$search = trim($_GET['q'] ?? '');
$allowed_filters = ['all','open','in_progress','resolved','closed','awaiting'];
if (!in_array($filter, $allowed_filters, true)) $filter = 'open';

$where = "1=1";
if ($filter === 'awaiting') {
    $where .= " AND f.status IN ('open','in_progress') AND (f.last_reply_by = 'tenant' OR f.last_reply_by IS NULL)";
} elseif ($filter !== 'all') {
    $where .= " AND f.status = '" . $conn->real_escape_string($filter) . "'";
}
if ($priority_f !== 'all' && in_array($priority_f, ['low','medium','high','urgent'], true)) {
    $where .= " AND f.priority = '" . $conn->real_escape_string($priority_f) . "'";
}
if ($type_f !== 'all' && in_array($type_f, ['support','feature_request','bug_report','billing','general'], true)) {
    $where .= " AND f.ticket_type = '" . $conn->real_escape_string($type_f) . "'";
}
if ($assigned_f === 'mine') {
    $where .= " AND f.assigned_to = " . (int)$actor['id'];
} elseif ($assigned_f === 'unassigned') {
    $where .= " AND (f.assigned_to IS NULL OR f.assigned_to = 0)";
}
if ($search !== '') {
    $s = '%' . $conn->real_escape_string($search) . '%';
    $where .= " AND (f.subject LIKE '{$s}' OR f.message LIKE '{$s}' OR t.company_name LIKE '{$s}' OR t.email LIKE '{$s}')";
}

$tickets = sa_query($conn,
    "SELECT f.*, t.company_name AS tenant_name, t.email AS tenant_email, t.public_id AS tenant_public_id
       FROM platform_feedback f
       LEFT JOIN tenants t ON t.id = f.tenant_id
      WHERE {$where}
      ORDER BY FIELD(f.priority,'urgent','high','medium','low'),
               CASE WHEN f.last_reply_by = 'tenant' OR f.last_reply_by IS NULL THEN 0 ELSE 1 END ASC,
               f.updated_at DESC, f.id DESC
      LIMIT 200",
    'platform_feedback');

$counts = [
    'all'         => (int)sa_scalar($conn, "SELECT COUNT(*) FROM platform_feedback", 0, 'platform_feedback'),
    'open'        => (int)sa_scalar($conn, "SELECT COUNT(*) FROM platform_feedback WHERE status='open'", 0, 'platform_feedback'),
    'in_progress' => (int)sa_scalar($conn, "SELECT COUNT(*) FROM platform_feedback WHERE status='in_progress'", 0, 'platform_feedback'),
    'awaiting'    => (int)sa_scalar($conn, "SELECT COUNT(*) FROM platform_feedback WHERE status IN ('open','in_progress') AND (last_reply_by = 'tenant' OR last_reply_by IS NULL)", 0, 'platform_feedback'),
    'resolved'    => (int)sa_scalar($conn, "SELECT COUNT(*) FROM platform_feedback WHERE status='resolved'", 0, 'platform_feedback'),
    'closed'      => (int)sa_scalar($conn, "SELECT COUNT(*) FROM platform_feedback WHERE status='closed'", 0, 'platform_feedback'),
    'urgent'      => (int)sa_scalar($conn, "SELECT COUNT(*) FROM platform_feedback WHERE status IN ('open','in_progress') AND priority='urgent'", 0, 'platform_feedback'),
];

// Super-admins list for the assignment dropdown
$sa_admins = sa_query($conn, "SELECT id, username FROM super_admins ORDER BY username ASC", 'super_admins');

$pageTitle = 'Support Tickets';
$pageHeading = 'Support Inbox';
$pageSubtitle = 'Help workspaces, triage bugs and review feature requests.';
$activePage = 'support_tickets';
$searchPlaceholder = 'Search tickets, tenants, subjects...';
$BASE = '../';

include __DIR__ . '/_shell.php';
?>

<style>
  .sa-card {
    background: var(--sa-surface-solid, #fff);
    border: 1px solid var(--sa-line, #e2e8f0);
    border-radius: 14px;
    padding: 22px;
    transition: background-color .2s ease, border-color .2s ease;
  }
  .sa-ticket-link {
    text-decoration: none;
    color: inherit;
    display: block;
    border: 1px solid var(--sa-line, #e2e8f0);
    border-radius: 10px;
    padding: 13px 14px;
    background: var(--sa-surface-solid, #fff);
    transition: .12s;
  }
  .sa-ticket-link:hover {
    border-color: var(--sa-accent, #c2f542);
    background: var(--sa-surface-hover, #fcfcfc);
  }
  :root[data-theme='dark'] .sa-ticket-link:hover {
    background: rgba(255,255,255,0.04);
  }
  .sa-thread {
    display: flex;
    flex-direction: column;
    gap: 14px;
  }
  .sa-msg {
    display: flex;
    gap: 12px;
  }
  .sa-msg.tenant {
    flex-direction: row;
  }
  .sa-msg.support {
    flex-direction: row-reverse;
  }
  .sa-msg .av {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    flex-shrink: 0;
    display: grid;
    place-items: center;
    font-size: 12px;
    font-weight: 800;
    color: #fff;
  }
  .sa-msg.tenant .av {
    background: #1e3a5f;
    color: #93c5fd;
    border: 1px solid rgba(147,197,253,0.3);
  }
  .sa-msg.support .av {
    background: #6366f1;
    color: #fff;
  }
  .sa-msg.internal .av {
    background: #d97706;
    color: #fff;
  }
  .sa-msg .body {
    max-width: 78%;
    flex: 1;
  }
  .sa-msg.support .body, .sa-msg.internal .body {
    text-align: right;
  }
  .sa-msg .meta {
    font-size: 11.5px;
    color: var(--sa-muted, #64748b);
    display: inline-flex;
    gap: 6px;
    align-items: center;
    margin-bottom: 4px;
    flex-wrap: wrap;
    justify-content: flex-end;
  }
  .sa-msg.tenant .meta {
    justify-content: flex-start;
  }
  .sa-msg .bubble {
    display: inline-block;
    text-align: left;
    background: var(--sa-surface-2, #f8fafc);
    border: 1px solid var(--sa-line, #e2e8f0);
    border-radius: 12px;
    padding: 11px 14px;
    font-size: 13.5px;
    line-height: 1.6;
    color: var(--sa-heading, #0f172a);
    white-space: pre-wrap;
    max-width: 100%;
  }
  :root[data-theme='dark'] .sa-msg .bubble {
    background: rgba(255,255,255,0.05);
    border-color: rgba(255,255,255,0.1);
    color: var(--sa-text, #eef2f7);
  }
  .sa-msg.support .bubble {
    background: #eef2ff;
    border-color: #c7d2fe;
    color: #312e81;
  }
  :root[data-theme='dark'] .sa-msg.support .bubble {
    background: rgba(99,102,241,0.18);
    border-color: rgba(99,102,241,0.35);
    color: #e0e7ff;
  }
  .sa-msg.internal .bubble {
    background: #fffbeb;
    border-color: #fcd34d;
    color: #78350f;
    font-style: italic;
  }
  :root[data-theme='dark'] .sa-msg.internal .bubble {
    background: rgba(245,158,11,0.14);
    border-color: rgba(245,158,11,0.32);
    color: #fde68a;
  }

  /* KPI cards */
  .sa-kpi-card {
    text-decoration: none;
    background: var(--sa-surface-solid, #fff);
    border: 1px solid var(--sa-line, #e2e8f0);
    border-radius: 10px;
    padding: 14px 16px;
    transition: all .15s ease;
    display: block;
  }
  .sa-kpi-card:hover {
    transform: translateY(-2px);
  }
  .sa-kpi-num {
    font-size: 24px;
    font-weight: 800;
    color: var(--sa-heading, #0f172a);
    margin-top: 4px;
  }

  /* Form controls */
  .sa-control {
    padding: 8px 10px;
    border-radius: 7px;
    border: 1px solid var(--sa-line, #cbd5e1);
    font-size: 13px;
    background: var(--sa-surface-solid, #fff);
    color: var(--sa-heading, #0f172a);
    font-family: inherit;
    color-scheme: inherit;
    transition: border-color .15s ease, background-color .15s ease;
  }
  :root[data-theme='dark'] .sa-control {
    background: rgba(8, 21, 34, 0.6);
    border-color: rgba(148, 163, 184, 0.2);
    color: #eef2f7;
  }
  .sa-control:focus {
    border-color: var(--sa-accent, #c2f542);
    outline: none;
  }

  /* Meta header block */
  .sa-meta-box {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 12px;
    padding: 12px;
    background: var(--sa-surface-2, #f8fafc);
    border-radius: 10px;
    font-size: 13px;
  }
  :root[data-theme='dark'] .sa-meta-box {
    background: rgba(255,255,255,0.035);
    border: 1px solid rgba(255,255,255,0.06);
  }

  /* Reset button */
  .sa-btn-reset {
    padding: 8px 14px;
    border-radius: 7px;
    background: var(--sa-surface-2, #f1f5f9);
    color: var(--sa-muted, #475569);
    text-decoration: none;
    font-weight: 600;
    font-size: 13px;
    display: inline-flex;
    align-items: center;
  }
  :root[data-theme='dark'] .sa-btn-reset {
    background: rgba(255,255,255,0.06);
    color: var(--sa-text, #e2e8f0);
    border: 1px solid rgba(255,255,255,0.1);
  }
</style>

<?php if ($active_ticket):
  $t = $active_ticket;
  $type_labels = ['support'=>'Technical Support','feature_request'=>'Feature Request','bug_report'=>'Bug Report','billing'=>'Billing','general'=>'General'];
  $type_colors = ['support'=>'#6366f1','feature_request'=>'#8b5cf6','bug_report'=>'#ef4444','billing'=>'#0ea5e9','general'=>'#64748b'];
  $status_badges = [
    'open'        => ['Open','var(--sa-warning)','#fef3c7','#92400e'],
    'in_progress' => ['In Progress','var(--sa-info)','#eff6ff','#1e40af'],
    'resolved'    => ['Resolved','var(--sa-success)','#f0fdf4','#166534'],
    'closed'      => ['Closed','var(--sa-faint)','#f1f5f9','#475569'],
  ];
  $pri_colors = ['urgent'=>'#dc2626','high'=>'#ea580c','medium'=>'#2563eb','low'=>'#64748b'];
  $cs = $status_badges[$t['status']] ?? $status_badges['open'];
?>

<div style="margin-bottom:16px;">
  <a href="support.php" style="display:inline-flex;align-items:center;gap:6px;color:var(--sa-muted,#94a3b8);font-size:13px;font-weight:600;text-decoration:none;margin-bottom:10px;">
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
    Back to inbox
  </a>

  <div class="sa-card" style="margin-bottom:18px;">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap;margin-bottom:14px;">
      <div style="flex:1;min-width:280px;">
        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:8px;">
          <span style="font-family:monospace;font-size:12px;font-weight:700;color:var(--sa-muted,#64748b);background:var(--sa-surface-2,#f1f5f9);padding:3px 8px;border-radius:6px;">#T-<?php echo (int)$t['id']; ?></span>
          <span class="sa-badge" style="background:<?php echo $cs[2]; ?>;color:<?php echo $cs[3]; ?>;"><?php echo $cs[0]; ?></span>
          <span style="font-size:11px;font-weight:700;padding:2px 8px;border-radius:6px;background:<?php echo $type_colors[$t['ticket_type']] ?? '#6366f1'; ?>15;color:<?php echo $type_colors[$t['ticket_type']] ?? '#6366f1'; ?>;"><?php echo htmlspecialchars($type_labels[$t['ticket_type']] ?? 'Support'); ?></span>
          <span style="font-size:11px;font-weight:700;padding:2px 8px;border-radius:6px;background:<?php echo $pri_colors[$t['priority']] ?? '#64748b'; ?>15;color:<?php echo $pri_colors[$t['priority']] ?? '#64748b'; ?>;border:1px solid <?php echo $pri_colors[$t['priority']] ?? '#64748b'; ?>44;"><?php echo ucfirst($t['priority']); ?> priority</span>
        </div>
        <h1 style="font-size:20px;font-weight:800;color:var(--sa-heading,#0f172a);margin:0;line-height:1.3;"><?php echo htmlspecialchars($t['subject']); ?></h1>
      </div>
    </div>

    <div class="sa-meta-box">
      <div>
        <div style="font-size:11px;font-weight:700;text-transform:uppercase;color:var(--sa-muted,#64748b);letter-spacing:0.4px;">From workspace</div>
        <?php if (!empty($t['tenant_name'])): ?>
          <div style="font-weight:700;color:var(--sa-heading,#0f172a);margin-top:4px;">
            <a href="tenant_details.php?id=<?php echo (int)$t['tenant_id']; ?>" style="color:inherit;text-decoration:none;"><?php echo htmlspecialchars($t['tenant_name']); ?></a>
            <?php if (!empty($t['tenant_public_id'])): ?><span style="font-family:monospace;font-size:11px;color:var(--sa-muted,#94a3b8);margin-left:4px;"><?php echo htmlspecialchars($t['tenant_public_id']); ?></span><?php endif; ?>
          </div>
          <?php if (!empty($t['tenant_email'])): ?><div style="color:var(--sa-muted,#64748b);font-size:12px;"><?php echo htmlspecialchars($t['tenant_email']); ?></div><?php endif; ?>
        <?php else: ?>
          <div style="color:var(--sa-muted,#64748b);margin-top:4px;">Unknown (deleted tenant)</div>
        <?php endif; ?>
      </div>
      <?php if (!empty($t['branch_name'])): ?>
      <div>
        <div style="font-size:11px;font-weight:700;text-transform:uppercase;color:var(--sa-muted,#64748b);letter-spacing:0.4px;">Related branch</div>
        <div style="font-weight:600;color:var(--sa-heading,#0f172a);margin-top:4px;"><?php echo htmlspecialchars($t['branch_name']); ?></div>
      </div>
      <?php endif; ?>
      <div>
        <div style="font-size:11px;font-weight:700;text-transform:uppercase;color:var(--sa-muted,#64748b);letter-spacing:0.4px;">Submitted by</div>
        <div style="font-weight:600;color:var(--sa-heading,#0f172a);margin-top:4px;"><?php echo htmlspecialchars($t['submitted_by_name'] ?? 'Workspace owner'); ?></div>
        <div style="color:var(--sa-muted,#94a3b8);font-size:11px;text-transform:capitalize;"><?php echo str_replace('_',' ',$t['submitted_by_kind'] ?? 'tenant'); ?></div>
      </div>
      <div>
        <div style="font-size:11px;font-weight:700;text-transform:uppercase;color:var(--sa-muted,#64748b);letter-spacing:0.4px;">Assigned to</div>
        <form method="POST" action="support.php?id=<?php echo (int)$t['id']; ?>" style="margin-top:4px;">
          <?php echo sa_csrf_field(); ?>
          <input type="hidden" name="action" value="assign">
          <input type="hidden" name="ticket_id" value="<?php echo (int)$t['id']; ?>">
          <select name="assign_to" onchange="this.form.submit()" class="sa-control" style="font-size:12.5px;min-width:130px;">
            <option value="0" <?php echo empty($t['assigned_to']) ? 'selected' : ''; ?>>Unassigned</option>
            <?php foreach ($sa_admins as $sa): ?>
              <option value="<?php echo (int)$sa['id']; ?>" <?php echo ((int)$t['assigned_to'] === (int)$sa['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($sa['username']); ?></option>
            <?php endforeach; ?>
          </select>
          <noscript><button type="submit" class="btn btn-primary btn-sm">Assign</button></noscript>
        </form>
      </div>
      <div>
        <div style="font-size:11px;font-weight:700;text-transform:uppercase;color:var(--sa-muted,#64748b);letter-spacing:0.4px;">Opened</div>
        <div style="font-weight:600;color:var(--sa-heading,#0f172a);margin-top:4px;"><?php echo sa_date($t['created_at'], 'M j, Y g:ia'); ?></div>
        <div style="color:var(--sa-muted,#94a3b8);font-size:11px;"><?php echo sa_time_ago($t['created_at']); ?></div>
      </div>
    </div>
  </div>

  <!-- Thread -->
  <div class="sa-card" style="margin-bottom:18px;">
    <h2 style="font-size:15px;font-weight:700;color:var(--sa-heading,#0f172a);margin:0 0 18px;display:flex;align-items:center;gap:8px;">
      <?php echo sa_icon('message'); ?> Conversation
      <?php
        $note_count = 0;
        foreach ($replies as $rr) if (!empty($rr['is_internal_note'])) $note_count++;
        if ($note_count > 0): ?>
          <span style="font-size:11px;font-weight:700;padding:2px 8px;border-radius:10px;background:#fef3c7;color:#92400e;margin-left:6px;"><?php echo (int)$note_count; ?> internal note<?php echo $note_count > 1 ? 's' : ''; ?></span>
      <?php endif; ?>
    </h2>
    <div class="sa-thread">
      <?php if (empty($replies)): ?>
        <p style="color:var(--sa-muted,#94a3b8);text-align:center;padding:20px;">No messages yet.</p>
      <?php else: ?>
        <?php foreach ($replies as $r):
          $kind = $r['author_kind'] ?? 'tenant';
          $is_support = ($kind === 'superadmin');
          $is_note = !empty($r['is_internal_note']);
          $cls = $is_note ? 'internal' : ($is_support ? 'support' : 'tenant');
          $label = $is_note ? 'Internal note' : ($is_support ? 'Platform' : 'Requester');
        ?>
          <div class="sa-msg <?php echo $cls; ?>">
            <div class="av"><?php echo htmlspecialchars(sa_initials($r['author_name'] ?? '?')); ?></div>
            <div class="body">
              <div class="meta">
                <strong style="color:var(--sa-heading,#0f172a);font-size:12.5px;"><?php echo htmlspecialchars($r['author_name'] ?: $label); ?></strong>
                <span style="font-size:10px;font-weight:700;padding:1px 6px;border-radius:8px;background:<?php echo $is_note ? '#fde68a' : ($is_support ? '#c7d2fe' : '#e2e8f0'); ?>;color:<?php echo $is_note ? '#92400e' : ($is_support ? '#3730a3' : '#475569'); ?>;"><?php echo $label; ?></span>
                <span style="color:var(--sa-muted,#94a3b8);"><?php echo sa_time_ago($r['created_at']); ?></span>
              </div>
              <div class="bubble"><?php echo htmlspecialchars($r['message']); ?></div>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- Reply form -->
  <div class="sa-card">
    <h3 style="font-size:14px;font-weight:700;color:var(--sa-heading,#0f172a);margin:0 0 12px;">Reply</h3>
    <form method="POST" action="support.php?id=<?php echo (int)$t['id']; ?>">
      <?php echo sa_csrf_field(); ?>
      <input type="hidden" name="action" value="reply">
      <input type="hidden" name="ticket_id" value="<?php echo (int)$t['id']; ?>">
      <textarea name="message" rows="4" placeholder="Type your reply to the workspace..." class="sa-control" style="width:100%;font-size:14px;resize:vertical;margin-bottom:10px;"></textarea>

      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;align-items:end;">
        <div>
          <label style="display:block;font-size:12px;font-weight:700;color:var(--sa-muted,#475569);margin-bottom:5px;">Change status</label>
          <select name="new_status" class="sa-control" style="width:100%;font-size:13.5px;">
            <option value="">— leave as <?php echo htmlspecialchars($cs[0]); ?> —</option>
            <option value="open" <?php echo $t['status']==='open'?'selected':''; ?>>Open</option>
            <option value="in_progress" <?php echo $t['status']==='in_progress'?'selected':''; ?>>In Progress</option>
            <option value="resolved" <?php echo $t['status']==='resolved'?'selected':''; ?>>Resolved</option>
            <option value="closed" <?php echo $t['status']==='closed'?'selected':''; ?>>Closed</option>
          </select>
        </div>
        <div style="display:flex;align-items:center;gap:8px;">
          <label style="display:flex;align-items:center;gap:6px;font-size:12.5px;color:var(--sa-text,#475569);cursor:pointer;">
            <input type="checkbox" name="internal_note" value="1" style="width:15px;height:15px;">
            <strong>Internal note</strong> (hidden from tenant)
          </label>
        </div>
        <div style="text-align:right;">
          <button type="submit" class="btn btn-primary" style="font-weight:700;padding:9px 20px;"><?php echo sa_icon('send'); ?> Send</button>
        </div>
      </div>
    </form>
  </div>

<?php else: /* inbox list view */ ?>

  <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap;margin-bottom:20px;">
    <div>
      <h1 style="font-size:22px;font-weight:800;color:var(--sa-heading,#0f172a);margin:0 0 4px;">Support Inbox</h1>
      <p style="margin:0;color:var(--sa-muted,#94a3b8);font-size:13.5px;">Workspaces reach out here when they need help, find a bug or have a billing question.</p>
    </div>
    <?php if ($counts['urgent'] > 0): ?>
      <div style="background:rgba(239,68,68,0.12);border:1px solid rgba(239,68,68,0.3);color:#f87171;border-radius:10px;padding:10px 14px;font-size:13px;font-weight:700;display:flex;align-items:center;gap:8px;">
        <?php echo sa_icon('alert'); ?> <?php echo (int)$counts['urgent']; ?> urgent ticket<?php echo $counts['urgent'] > 1 ? 's' : ''; ?> need attention
      </div>
    <?php endif; ?>
  </div>

  <?php echo sa_render_flash(); ?>

  <!-- KPI cards -->
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin-bottom:20px;">
    <?php
    $kpis = [
      ['awaiting',    'Awaiting reply', $counts['awaiting'],    '#d97706', true],
      ['open',        'Open',           $counts['open'],        '#ea580c', false],
      ['in_progress', 'In progress',    $counts['in_progress'], '#2563eb', false],
      ['resolved',    'Resolved',       $counts['resolved'],    '#16a34a', false],
      ['closed',      'Closed',         $counts['closed'],      '#64748b', false],
      ['all',         'All tickets',    $counts['all'],         'var(--sa-heading,#0f172a)', false],
    ];
    foreach ($kpis as $k):
      $active = ($filter === $k[0]);
    ?>
      <a href="?filter=<?php echo $k[0]; ?>" class="sa-kpi-card" style="border:1px solid <?php echo $active ? $k[3] : 'var(--sa-line,#e2e8f0)'; ?>;border-left:3px solid <?php echo $k[3]; ?>;">
        <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;color:<?php echo $k[3]; ?>;"><?php echo $k[1]; ?></div>
        <div class="sa-kpi-num"><?php echo (int)$k[2]; ?></div>
      </a>
    <?php endforeach; ?>
  </div>

  <!-- Filter bar -->
  <form method="GET" action="support.php" class="sa-card" style="padding:12px 14px;margin-bottom:16px;display:flex;gap:10px;flex-wrap:wrap;align-items:end;">
    <input type="hidden" name="filter" value="<?php echo htmlspecialchars($filter); ?>">
    <div style="flex:1;min-width:200px;">
      <label style="display:block;font-size:11px;font-weight:700;color:var(--sa-muted,#64748b);margin-bottom:4px;text-transform:uppercase;letter-spacing:0.4px;">Search</label>
      <input type="search" name="q" value="<?php echo htmlspecialchars($search); ?>" placeholder="Subject, message, tenant name or email..." class="sa-control" style="width:100%;">
    </div>
    <div>
      <label style="display:block;font-size:11px;font-weight:700;color:var(--sa-muted,#64748b);margin-bottom:4px;text-transform:uppercase;letter-spacing:0.4px;">Priority</label>
      <select name="priority" class="sa-control">
        <option value="all" <?php echo $priority_f==='all'?'selected':''; ?>>All priorities</option>
        <option value="urgent" <?php echo $priority_f==='urgent'?'selected':''; ?>>Urgent</option>
        <option value="high" <?php echo $priority_f==='high'?'selected':''; ?>>High</option>
        <option value="medium" <?php echo $priority_f==='medium'?'selected':''; ?>>Medium</option>
        <option value="low" <?php echo $priority_f==='low'?'selected':''; ?>>Low</option>
      </select>
    </div>
    <div>
      <label style="display:block;font-size:11px;font-weight:700;color:var(--sa-muted,#64748b);margin-bottom:4px;text-transform:uppercase;letter-spacing:0.4px;">Type</label>
      <select name="type" class="sa-control">
        <option value="all" <?php echo $type_f==='all'?'selected':''; ?>>All types</option>
        <option value="support" <?php echo $type_f==='support'?'selected':''; ?>>Technical support</option>
        <option value="bug_report" <?php echo $type_f==='bug_report'?'selected':''; ?>>Bug report</option>
        <option value="billing" <?php echo $type_f==='billing'?'selected':''; ?>>Billing</option>
        <option value="feature_request" <?php echo $type_f==='feature_request'?'selected':''; ?>>Feature request</option>
        <option value="general" <?php echo $type_f==='general'?'selected':''; ?>>General</option>
      </select>
    </div>
    <div>
      <label style="display:block;font-size:11px;font-weight:700;color:var(--sa-muted,#64748b);margin-bottom:4px;text-transform:uppercase;letter-spacing:0.4px;">Assigned</label>
      <select name="assigned" class="sa-control">
        <option value="all" <?php echo $assigned_f==='all'?'selected':''; ?>>Anyone</option>
        <option value="mine" <?php echo $assigned_f==='mine'?'selected':''; ?>>Assigned to me</option>
        <option value="unassigned" <?php echo $assigned_f==='unassigned'?'selected':''; ?>>Unassigned</option>
      </select>
    </div>
    <button type="submit" class="btn btn-primary" style="font-weight:700;">Filter</button>
    <?php if ($filter !== 'open' || $priority_f !== 'all' || $type_f !== 'all' || $assigned_f !== 'all' || $search !== ''): ?>
      <a href="support.php" class="sa-btn-reset">Reset</a>
    <?php endif; ?>
  </form>

  <?php if (empty($tickets)): ?>
    <div class="sa-card" style="padding:60px 20px;text-align:center;color:var(--sa-muted,#94a3b8);">
      <?php echo sa_icon('check-circle'); ?>
      <p style="font-size:15px;font-weight:700;color:var(--sa-heading,#0f172a);margin:14px 0 6px;">Inbox zero! 🎉</p>
      <p style="margin:0;font-size:13px;">No tickets match this view.</p>
    </div>
  <?php else: ?>
    <div style="display:flex;flex-direction:column;gap:8px;">
      <?php foreach ($tickets as $t):
        $type_colors = ['support'=>'#6366f1','feature_request'=>'#8b5cf6','bug_report'=>'#ef4444','billing'=>'#0ea5e9','general'=>'#64748b'];
        $status_badges = [
          'open'        => ['Open','#fef3c7','#92400e'],
          'in_progress' => ['In Progress','#eff6ff','#1e40af'],
          'resolved'    => ['Resolved','#f0fdf4','#166534'],
          'closed'      => ['Closed','#f1f5f9','#475569'],
        ];
        $pri_dot = ['urgent'=>'#dc2626','high'=>'#ea580c','medium'=>'#2563eb','low'=>'#94a3b8'];
        $cs = $status_badges[$t['status']] ?? $status_badges['open'];
        $awaiting_reply = in_array($t['status'], ['open','in_progress'], true) && ($t['last_reply_by'] === 'tenant' || $t['last_reply_by'] === null);
        $new_from_tenant = $t['last_reply_by'] === 'tenant';
      ?>
        <a href="?id=<?php echo (int)$t['id']; ?>&filter=<?php echo urlencode($filter); ?>" class="sa-ticket-link" data-sa-searchable>
          <div style="display:flex;gap:12px;align-items:flex-start;">
            <span style="width:10px;height:10px;border-radius:50%;background:<?php echo $pri_dot[$t['priority']] ?? '#94a3b8'; ?>;margin-top:7px;flex-shrink:0;<?php if ($t['priority']==='urgent') echo 'box-shadow:0 0 0 3px rgba(220,38,38,0.18);'; ?>"></span>
            <div style="flex:1;min-width:0;">
              <div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:4px;">
                <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
                  <span style="font-family:monospace;font-size:11.5px;font-weight:700;color:var(--sa-muted,#64748b);">#T-<?php echo (int)$t['id']; ?></span>
                  <span style="font-size:10.5px;font-weight:700;padding:2px 7px;border-radius:5px;background:<?php echo $type_colors[$t['ticket_type']] ?? '#6366f1'; ?>15;color:<?php echo $type_colors[$t['ticket_type']] ?? '#6366f1'; ?>;text-transform:capitalize;"><?php echo str_replace('_',' ',$t['ticket_type']); ?></span>
                  <?php if ($awaiting_reply): ?>
                    <span style="font-size:10.5px;font-weight:700;padding:2px 7px;border-radius:5px;background:#fef2f2;color:#991b1b;"><?php echo $new_from_tenant ? 'Tenant replied' : 'Awaiting reply'; ?></span>
                  <?php endif; ?>
                </div>
                <div style="display:flex;gap:8px;align-items:center;">
                  <span style="font-size:10.5px;font-weight:700;padding:2px 8px;border-radius:10px;background:<?php echo $cs[1]; ?>;color:<?php echo $cs[2]; ?>;"><?php echo $cs[0]; ?></span>
                  <span style="font-size:11.5px;color:var(--sa-muted,#94a3b8);white-space:nowrap;"><?php echo sa_time_ago($t['updated_at'] ?: $t['created_at']); ?></span>
                </div>
              </div>
              <h3 style="font-size:14px;font-weight:700;color:var(--sa-heading,#0f172a);margin:0 0 3px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?php echo htmlspecialchars($t['subject']); ?></h3>
              <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;font-size:12px;color:var(--sa-muted,#64748b);">
                <span style="font-weight:600;color:var(--sa-text,#334155);"><?php echo htmlspecialchars($t['tenant_name'] ?? 'Unknown tenant'); ?></span>
                <?php if (!empty($t['tenant_public_id'])): ?>
                  <span style="font-family:monospace;color:var(--sa-muted,#94a3b8);"><?php echo htmlspecialchars($t['tenant_public_id']); ?></span>
                <?php endif; ?>
                <?php if (!empty($t['assigned_name'])): ?>
                  <span style="display:inline-flex;align-items:center;gap:4px;">
                    <?php echo sa_icon('user'); ?> <?php echo htmlspecialchars($t['assigned_name']); ?>
                  </span>
                <?php else: ?>
                  <span style="color:var(--sa-faint,#94a3b8);">· Unassigned</span>
                <?php endif; ?>
                <?php if (!empty($t['submitted_by_name']) && $t['submitted_by_name'] !== $t['tenant_name']): ?>
                  <span>· by <?php echo htmlspecialchars($t['submitted_by_name']); ?></span>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

<?php endif; ?>

<?php include __DIR__ . '/_shell_footer.php'; ?>
