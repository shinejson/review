<?php
/**
 * ============================================================
 *  Workspace — Platform Support & Feedback
 * ============================================================
 *  Enables tenants to submit platform support tickets, bug
 *  reports, and feature requests directly to the Superadmin.
 */

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/admin_helpers.php';
require_once dirname(__DIR__) . '/includes/sa_helpers.php';

requireLogin();

$tenant_id = getTenantId();
$is_tenant = isTenant();

if (!$tenant_id) {
    sa_flash('error', 'Support tickets are submitted from inside an active workspace.');
    redirect('index.php');
}

sa_ensure_platform_feedback_schema($conn);

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
        $subject = trim(sanitize($_POST['subject'] ?? ''));
        $ticket_type = trim($_POST['ticket_type'] ?? 'support');
        $priority = trim($_POST['priority'] ?? 'medium');
        $message = trim($_POST['message'] ?? '');
        $company_id = !empty($_SESSION['active_company_id']) ? (int)$_SESSION['active_company_id'] : null;

        $allowed_types = ['support', 'feature_request', 'bug_report', 'billing', 'general'];
        $allowed_priorities = ['low', 'medium', 'high', 'urgent'];

        if (!in_array($ticket_type, $allowed_types, true)) {
            $ticket_type = 'support';
        }
        if (!in_array($priority, $allowed_priorities, true)) {
            $priority = 'medium';
        }

        if (empty($subject) || empty($message)) {
            sa_flash('error', 'Please provide both a subject and a description for your request.');
            redirect('support.php');
        }

        $stmt = $conn->prepare("INSERT INTO platform_feedback (tenant_id, company_id, ticket_type, priority, subject, message, status) VALUES (?, ?, ?, ?, ?, ?, 'open')");
        if ($stmt) {
            $stmt->bind_param("iissss", $tenant_id, $company_id, $ticket_type, $priority, $subject, $message);
            if ($stmt->execute()) {
                $new_id = $stmt->insert_id;
                sa_flash('success', "Ticket #T-{$new_id} created successfully! Our team will review and reply shortly.");
            } else {
                sa_flash('error', 'Could not create ticket. Please try again.');
            }
            $stmt->close();
        } else {
            sa_flash('error', 'Database error. Please try again.');
        }

        redirect('support.php');
    }
}

/* ============================================================
   Fetch Tickets
   ============================================================ */
$tickets = [];
$t_res = $conn->query("SELECT * FROM platform_feedback WHERE tenant_id = " . (int)$tenant_id . " ORDER BY created_at DESC");
if ($t_res) {
    while ($row = $t_res->fetch_assoc()) {
        $tickets[] = $row;
    }
}

$counts = [
    'all' => count($tickets),
    'open' => 0,
    'in_progress' => 0,
    'resolved' => 0,
];
foreach ($tickets as $t) {
    if ($t['status'] === 'open') $counts['open']++;
    elseif ($t['status'] === 'in_progress') $counts['in_progress']++;
    elseif ($t['status'] === 'resolved' || $t['status'] === 'closed') $counts['resolved']++;
}

/* ============================================================
   Page Meta & View
   ============================================================ */
$pageTitle = 'Platform Support & Feedback';
$activeNav = 'support';
$BASE = '../';

include __DIR__ . '/_shell.php';
?>

<div style="max-width:1100px;margin:0 auto;padding:10px 0 40px;">
  
  <!-- Page Header -->
  <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:16px;margin-bottom:24px;">
    <div>
      <h1 style="font-size:24px;font-weight:800;color:var(--heading,#0f172a);margin:0 0 4px;display:flex;align-items:center;gap:10px;">
        <span>Platform Support &amp; Feedback</span>
      </h1>
      <p style="color:var(--muted,#64748b);font-size:14px;margin:0;">
        Reach out directly to platform engineers for technical assistance, bug reports, or feature suggestions.
      </p>
    </div>
    <button type="button" class="btn btn-primary" onclick="document.getElementById('newTicketCard').scrollIntoView({behavior:'smooth'});document.getElementById('ticketSubject').focus();" style="display:inline-flex;align-items:center;gap:8px;font-weight:700;">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
      New Request
    </button>
  </div>

  <?php echo sa_render_flash(); ?>

  <!-- Summary Cards -->
  <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(200px, 1fr));gap:16px;margin-bottom:28px;">
    <div style="background:var(--card-bg,#fff);border:1px solid var(--line,#e2e8f0);border-radius:12px;padding:18px;">
      <div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;color:var(--muted,#64748b);">Total Requests</div>
      <div style="font-size:26px;font-weight:800;color:var(--heading,#0f172a);margin-top:6px;"><?php echo (int)$counts['all']; ?></div>
    </div>
    <div style="background:var(--card-bg,#fff);border:1px solid var(--line,#e2e8f0);border-radius:12px;padding:18px;">
      <div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;color:#d97706;">Awaiting Review</div>
      <div style="font-size:26px;font-weight:800;color:#b45309;margin-top:6px;"><?php echo (int)$counts['open']; ?></div>
    </div>
    <div style="background:var(--card-bg,#fff);border:1px solid var(--line,#e2e8f0);border-radius:12px;padding:18px;">
      <div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;color:#2563eb;">In Progress</div>
      <div style="font-size:26px;font-weight:800;color:#1d4ed8;margin-top:6px;"><?php echo (int)$counts['in_progress']; ?></div>
    </div>
    <div style="background:var(--card-bg,#fff);border:1px solid var(--line,#e2e8f0);border-radius:12px;padding:18px;">
      <div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;color:#16a34a;">Resolved</div>
      <div style="font-size:26px;font-weight:800;color:#15803d;margin-top:6px;"><?php echo (int)$counts['resolved']; ?></div>
    </div>
  </div>

  <!-- New Ticket Submission Form -->
  <div id="newTicketCard" style="background:var(--card-bg,#fff);border:1px solid var(--line,#e2e8f0);border-radius:14px;padding:24px;margin-bottom:32px;box-shadow:0 2px 8px rgba(0,0,0,0.03);">
    <h2 style="font-size:17px;font-weight:700;color:var(--heading,#0f172a);margin:0 0 6px;">Submit a New Request</h2>
    <p style="font-size:13.5px;color:var(--muted,#64748b);margin:0 0 20px;">Send a question, report an issue, or propose a platform feature directly to superadmins.</p>

    <form method="POST" action="support.php">
      <?php echo sa_csrf_field(); ?>
      <input type="hidden" name="action" value="create_ticket">

      <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(260px, 1fr));gap:16px;margin-bottom:16px;">
        <div>
          <label style="display:block;font-size:12.5px;font-weight:700;margin-bottom:6px;color:var(--heading,#0f172a);">Subject *</label>
          <input type="text" id="ticketSubject" name="subject" required placeholder="e.g. Issue with QR stand scan URL / Feature suggestion" style="width:100%;padding:10px 12px;border-radius:8px;border:1px solid var(--line,#cbd5e1);font-size:14px;background:var(--input-bg,#fff);color:inherit;">
        </div>

        <div>
          <label style="display:block;font-size:12.5px;font-weight:700;margin-bottom:6px;color:var(--heading,#0f172a);">Category *</label>
          <select name="ticket_type" style="width:100%;padding:10px 12px;border-radius:8px;border:1px solid var(--line,#cbd5e1);font-size:14px;background:var(--input-bg,#fff);color:inherit;">
            <option value="support">🛠️ Technical Support</option>
            <option value="feature_request">💡 Feature Suggestion</option>
            <option value="bug_report">🐞 Bug Report</option>
            <option value="billing">💳 Billing &amp; Subscription</option>
            <option value="general">💬 General Question</option>
          </select>
        </div>

        <div>
          <label style="display:block;font-size:12.5px;font-weight:700;margin-bottom:6px;color:var(--heading,#0f172a);">Priority</label>
          <select name="priority" style="width:100%;padding:10px 12px;border-radius:8px;border:1px solid var(--line,#cbd5e1);font-size:14px;background:var(--input-bg,#fff);color:inherit;">
            <option value="low">Low</option>
            <option value="medium" selected>Medium / Standard</option>
            <option value="high">High</option>
            <option value="urgent">Urgent</option>
          </select>
        </div>
      </div>

      <div style="margin-bottom:20px;">
        <label style="display:block;font-size:12.5px;font-weight:700;margin-bottom:6px;color:var(--heading,#0f172a);">Message Details *</label>
        <textarea name="message" required rows="4" placeholder="Explain what happened, steps to reproduce, or details of your requested feature..." style="width:100%;padding:12px;border-radius:8px;border:1px solid var(--line,#cbd5e1);font-size:14px;background:var(--input-bg,#fff);color:inherit;font-family:inherit;"></textarea>
      </div>

      <div style="text-align:right;">
        <button type="submit" class="btn btn-primary" style="font-weight:700;padding:10px 22px;">Submit Request</button>
      </div>
    </form>
  </div>

  <!-- Your Previous Tickets -->
  <div style="background:var(--card-bg,#fff);border:1px solid var(--line,#e2e8f0);border-radius:14px;padding:24px;box-shadow:0 2px 8px rgba(0,0,0,0.03);">
    <h2 style="font-size:17px;font-weight:700;color:var(--heading,#0f172a);margin:0 0 16px;">Request History</h2>

    <?php if (empty($tickets)): ?>
      <div style="text-align:center;padding:48px 20px;color:var(--muted,#64748b);">
        <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="margin-bottom:12px;opacity:0.6;"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
        <p style="font-weight:700;margin-bottom:4px;color:var(--heading,#0f172a);">No support requests yet</p>
        <p style="font-size:13px;margin:0;">Use the form above whenever you need assistance or want to share feedback.</p>
      </div>
    <?php else: ?>
      <div style="display:flex;flex-direction:column;gap:14px;">
        <?php foreach ($tickets as $t): ?>
          <?php
            $type_labels = [
                'support' => 'Technical Support',
                'feature_request' => 'Feature Suggestion',
                'bug_report' => 'Bug Report',
                'billing' => 'Billing & Subscription',
                'general' => 'General Question',
            ];
            $type_colors = [
                'support' => '#6366f1',
                'feature_request' => '#8b5cf6',
                'bug_report' => '#ef4444',
                'billing' => '#0ea5e9',
                'general' => '#64748b',
            ];
            $priority_colors = [
                'urgent' => ['#fef2f2', '#991b1b', '#fca5a5'],
                'high'   => ['#fff7ed', '#9a3412', '#fed7aa'],
                'medium' => ['#eff6ff', '#1e40af', '#bfdbfe'],
                'low'    => ['#f8fafc', '#475569', '#e2e8f0'],
            ];
            $status_badges = [
                'open' => ['Awaiting Review', '#fef3c7', '#92400e', '#fde68a'],
                'in_progress' => ['In Progress', '#eff6ff', '#1e40af', '#bfdbfe'],
                'resolved' => ['Resolved', '#f0fdf4', '#166534', '#bbf7d0'],
                'closed' => ['Closed', '#f1f5f9', '#475569', '#cbd5e1'],
            ];
            $cur_status = $status_badges[$t['status']] ?? ['Open', '#fef3c7', '#92400e', '#fde68a'];
            $cur_pri = $priority_colors[$t['priority']] ?? $priority_colors['medium'];
          ?>
          <div style="border:1px solid var(--line,#e2e8f0);border-radius:10px;padding:16px;background:var(--card-sub,#fcfcfd);">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:10px;margin-bottom:8px;">
              <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                <span style="font-family:monospace;font-size:12px;font-weight:700;color:var(--muted,#64748b);">#T-<?php echo (int)$t['id']; ?></span>
                <span style="font-size:11px;font-weight:700;padding:2px 8px;border-radius:6px;background:<?php echo $type_colors[$t['ticket_type']] ?? '#6366f1'; ?>15;color:<?php echo $type_colors[$t['ticket_type']] ?? '#6366f1'; ?>;">
                  <?php echo htmlspecialchars($type_labels[$t['ticket_type']] ?? 'Support'); ?>
                </span>
                <span style="font-size:11px;font-weight:700;padding:2px 7px;border-radius:6px;background:<?php echo $cur_pri[0]; ?>;color:<?php echo $cur_pri[1]; ?>;border:1px solid <?php echo $cur_pri[2]; ?>;">
                  <?php echo ucfirst($t['priority']); ?> Priority
                </span>
              </div>
              <div style="display:flex;align-items:center;gap:10px;">
                <span style="font-size:11.5px;font-weight:700;padding:3px 10px;border-radius:12px;background:<?php echo $cur_status[1]; ?>;color:<?php echo $cur_status[2]; ?>;border:1px solid <?php echo $cur_status[3]; ?>;">
                  <?php echo $cur_status[0]; ?>
                </span>
                <span style="font-size:12px;color:var(--muted,#64748b);"><?php echo date('M j, Y', strtotime($t['created_at'])); ?></span>
              </div>
            </div>

            <h3 style="font-size:15px;font-weight:700;color:var(--heading,#0f172a);margin:0 0 6px;">
              <?php echo htmlspecialchars($t['subject']); ?>
            </h3>
            <p style="font-size:13.5px;color:var(--body,#334155);margin:0 0 12px;line-height:1.5;white-space:pre-wrap;"><?php echo htmlspecialchars($t['message']); ?></p>

            <?php if (!empty($t['admin_reply'])): ?>
              <div style="background:#eff6ff;border-left:3px solid #3b82f6;border-radius:0 8px 8px 0;padding:12px 14px;margin-top:12px;">
                <div style="display:flex;justify-content:space-between;font-size:12px;font-weight:700;color:#1e40af;margin-bottom:4px;">
                  <span>Platform Engineer Reply:</span>
                  <?php if (!empty($t['replied_at'])): ?>
                    <span style="font-weight:400;color:#60a5fa;"><?php echo date('M j, Y g:ia', strtotime($t['replied_at'])); ?></span>
                  <?php endif; ?>
                </div>
                <div style="font-size:13.5px;color:#1e3a8a;line-height:1.5;white-space:pre-wrap;"><?php echo htmlspecialchars($t['admin_reply']); ?></div>
              </div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

</div>

<?php include __DIR__ . '/_shell_footer.php'; ?>
