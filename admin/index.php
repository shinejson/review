<?php
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';
requireLogin();
ensureRealIdSchema($conn);
$tenant_id = getTenantId();
$is_tenant = isTenant();
$tenant_info = null;
$active_company_id = ($is_tenant && $tenant_id) ? getActiveCompanyId($conn, $tenant_id) : 0;
$active_company_profile = ($is_tenant && $tenant_id) ? getActiveCompanyProfile($conn, $tenant_id) : null;
$active_company_name = !empty($active_company_profile['company_name']) ? $active_company_profile['company_name'] : '';
$view_mode = $_GET['view'] ?? '';

if ($is_tenant && $tenant_id) {
    $stmt = $conn->prepare("SELECT t.*, p.plan_name, p.max_ratings, p.max_customers FROM tenants t LEFT JOIN subscription_plans p ON t.plan_id=p.id WHERE t.id=?");
    $stmt->bind_param('i', $tenant_id); $stmt->execute(); $tenant_info = $stmt->get_result()->fetch_assoc();
    $stmt = $conn->prepare("SELECT COUNT(*) count FROM customers WHERE tenant_id=?"); $stmt->bind_param('i',$tenant_id); $stmt->execute(); $total_customers=$stmt->get_result()->fetch_assoc()['count'] ?? 0;

    if ($view_mode !== 'all' && $active_company_id > 0) {
        // Scoped to active branch
        $stmt = $conn->prepare("SELECT COUNT(*) count, AVG(r.rating) avg_rating FROM ratings r WHERE r.company_id=?");
        $stmt->bind_param('i', $active_company_id); $stmt->execute(); $rr=$stmt->get_result()->fetch_assoc();
        $total_ratings=$rr['count']??0; $avg_rating=round($rr['avg_rating']??0,1);

        $stmt = $conn->prepare("SELECT r.*,c.company_name FROM ratings r JOIN customers c ON r.company_id=c.id WHERE r.company_id=? ORDER BY r.created_at DESC LIMIT 5");
        $stmt->bind_param('i', $active_company_id); $stmt->execute(); $recent_ratings=$stmt->get_result();

        // Rating distribution for score breakdown chart
        $stmt = $conn->prepare("SELECT r.rating, COUNT(*) as count FROM ratings r WHERE r.company_id=? GROUP BY r.rating ORDER BY r.rating DESC");
        $stmt->bind_param('i', $active_company_id); $stmt->execute(); $rating_dist_result=$stmt->get_result();

        // Monthly ratings trend for last 7 months
        $stmt = $conn->prepare("SELECT DATE_FORMAT(r.created_at, '%Y-%m') as month, COUNT(*) as count, AVG(r.rating) as avg_rating FROM ratings r WHERE r.company_id=? AND r.created_at >= DATE_SUB(NOW(), INTERVAL 7 MONTH) GROUP BY month ORDER BY month ASC");
        $stmt->bind_param('i', $active_company_id); $stmt->execute(); $monthly_trend=$stmt->get_result();
    } else {
        // Consolidated view across all tenant branches
        $stmt = $conn->prepare("SELECT COUNT(*) count, AVG(r.rating) avg_rating FROM ratings r JOIN customers c ON r.company_id=c.id WHERE c.tenant_id=?");
        $stmt->bind_param('i',$tenant_id); $stmt->execute(); $rr=$stmt->get_result()->fetch_assoc();
        $total_ratings=$rr['count']??0; $avg_rating=round($rr['avg_rating']??0,1);

        $stmt = $conn->prepare("SELECT r.*,c.company_name FROM ratings r JOIN customers c ON r.company_id=c.id WHERE c.tenant_id=? ORDER BY r.created_at DESC LIMIT 5");
        $stmt->bind_param('i',$tenant_id); $stmt->execute(); $recent_ratings=$stmt->get_result();

        // Rating distribution for score breakdown chart
        $stmt = $conn->prepare("SELECT r.rating, COUNT(*) as count FROM ratings r JOIN customers c ON r.company_id=c.id WHERE c.tenant_id=? GROUP BY r.rating ORDER BY r.rating DESC");
        $stmt->bind_param('i',$tenant_id); $stmt->execute(); $rating_dist_result=$stmt->get_result();

        // Monthly ratings trend for last 7 months
        $stmt = $conn->prepare("SELECT DATE_FORMAT(r.created_at, '%Y-%m') as month, COUNT(*) as count, AVG(r.rating) as avg_rating FROM ratings r JOIN customers c ON r.company_id=c.id WHERE c.tenant_id=? AND r.created_at >= DATE_SUB(NOW(), INTERVAL 7 MONTH) GROUP BY month ORDER BY month ASC");
        $stmt->bind_param('i',$tenant_id); $stmt->execute(); $monthly_trend=$stmt->get_result();
    }

    $stmt = $conn->prepare("SELECT id,company_name FROM customers WHERE tenant_id=? ORDER BY (id=?) DESC, company_name ASC");
    $stmt->bind_param('ii', $tenant_id, $active_company_id); $stmt->execute(); $tenant_companies=$stmt->get_result();
} else {
    $total_customers=$conn->query('SELECT COUNT(*) count FROM customers')->fetch_assoc()['count']??0;
    $rr=$conn->query('SELECT COUNT(*) count,AVG(rating) avg_rating FROM ratings')->fetch_assoc(); $total_ratings=$rr['count']??0; $avg_rating=round($rr['avg_rating']??0,1);
    $recent_ratings=$conn->query('SELECT r.*,c.company_name FROM ratings r JOIN customers c ON r.company_id=c.id ORDER BY r.created_at DESC LIMIT 5');
    $tenant_companies=$conn->query('SELECT id,company_name FROM customers ORDER BY company_name');

    // Rating distribution for score breakdown chart
    $rating_dist_result=$conn->query("SELECT rating, COUNT(*) as count FROM ratings GROUP BY rating ORDER BY rating DESC");

    // Monthly ratings trend for last 7 months
    $monthly_trend=$conn->query("SELECT DATE_FORMAT(created_at, '%Y-%m') as month, COUNT(*) as count, AVG(rating) as avg_rating FROM ratings WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 MONTH) GROUP BY month ORDER BY month ASC");
}

// Process rating distribution
$rating_distribution = [5=>0, 4=>0, 3=>0, 2=>0, 1=>0];
$total_for_dist = 0;
while($row = $rating_dist_result->fetch_assoc()) {
    $rating_distribution[(int)$row['rating']] = (int)$row['count'];
    $total_for_dist += (int)$row['count'];
}

// Process monthly trend data
$trend_data = [];
$trend_months = [];
while($row = $monthly_trend->fetch_assoc()) {
    $trend_data[] = ['month'=>$row['month'], 'count'=>(int)$row['count'], 'avg'=>round((float)$row['avg_rating'],1)];
    $trend_months[] = date('M', strtotime($row['month'].'-01'));
}

// Fill in missing months with zeros if needed
while(count($trend_data) < 7) {
    $month = date('Y-m', strtotime('-'.(7-count($trend_data)).' months'));
    array_unshift($trend_data, ['month'=>$month, 'count'=>0, 'avg'=>0]);
    array_unshift($trend_months, date('M', strtotime($month.'-01')));
}

// Calculate Profile Strength (Gamified Setup Checklist & Circular Progress Ring)
$profile_strength = null;
if ($is_tenant && $tenant_id) {
    $profile_strength = getProfileStrength($tenant_id, $conn);
}

$BASE = '../';
$pageTitle = 'Dashboard';
$activeNav = 'dashboard';
include __DIR__ . '/_shell.php';
?>
<?php if (isset($_SESSION['flash_error'])): ?>
<div class="alert alert-error" role="alert">⚠ <?php echo htmlspecialchars($_SESSION['flash_error']); unset($_SESSION['flash_error']); ?></div>
<?php endif; ?>
  <div class="welcome-row">
    <div>
      <p class="eyebrow">Good morning, <?php echo htmlspecialchars($is_tenant?($tenant_info['company_name']??'there'):($_SESSION['admin_username']??'Admin')); ?></p>
      <h1 style="margin:0;">Performance overview</h1>
      <p class="muted" style="margin-top:4px;">Track your customer feedback and business health in one place.</p>
      <?php if ($is_tenant && !empty($tenant_info['public_id'])): ?>
      <div style="margin-top:10px;display:inline-flex;align-items:center;gap:8px;flex-wrap:wrap;">
        <span style="font-size:11px;font-weight:700;letter-spacing:0.5px;text-transform:uppercase;color:#64748b;">Account ID</span>
        <span style="font-family:monospace;background:rgba(99,102,241,0.12);color:#4338ca;border:1px solid rgba(99,102,241,0.25);font-weight:800;letter-spacing:1px;padding:5px 12px;border-radius:8px;font-size:14px;"><?php echo htmlspecialchars($tenant_info['public_id']); ?></span>
        <?php if (!empty($tenant_info['email_verified_at'])): ?>
          <span style="font-size:11px;background:#dcfce7;color:#166534;border:1px solid #86efac;padding:3px 8px;border-radius:99px;font-weight:700;">✓ Verified</span>
        <?php elseif (!empty($tenant_info['setup_token'])): ?>
          <span style="font-size:11px;background:#fef3c7;color:#92400e;border:1px solid #fde68a;padding:3px 8px;border-radius:99px;font-weight:700;">◷ Setup pending — check email</span>
        <?php endif; ?>
      </div>
      <?php endif; ?>
      <?php if ($is_tenant && $active_company_id > 0): ?>
      <div style="margin-top:10px;display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
        <?php if ($view_mode === 'all'): ?>
          <span class="badge" style="background:rgba(16,185,129,0.12);color:#059669;border:1px solid rgba(16,185,129,0.25);font-size:12px;padding:4px 10px;border-radius:20px;font-weight:600;">
            🌐 Viewing All Branches (Consolidated)
          </span>
          <a href="index.php" class="btn btn-secondary" style="font-size:11.5px;padding:3px 10px;text-decoration:none;border-radius:20px;">
            Filter to <?php echo htmlspecialchars($active_company_name ?: 'Active Branch'); ?> →
          </a>
        <?php else: ?>
          <span class="badge" style="background:rgba(99,102,241,0.12);color:#6366f1;border:1px solid rgba(99,102,241,0.25);font-size:12px;padding:4px 10px;border-radius:20px;font-weight:600;">
            📍 Active Branch: <?php echo htmlspecialchars($active_company_name ?: 'Main Branch'); ?>
          </span>
          <?php if (($total_customers ?? 0) > 1): ?>
          <a href="?view=all" class="btn btn-secondary" style="font-size:11.5px;padding:3px 10px;text-decoration:none;border-radius:20px;">
            View All Branches (Consolidated) →
          </a>
          <?php endif; ?>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
      <?php if ($is_tenant): ?>
      <a href="whatsapp_sender.php" class="btn btn-secondary" style="display:inline-flex;align-items:center;gap:8px;padding:10px 16px;text-decoration:none;font-weight:700;background:#dcfce7;color:#15803d;border:1px solid #86efac;">
        💬 Ask for Reviews
      </a>
      <a href="qr_stand.php" class="btn btn-secondary" style="display:inline-flex;align-items:center;gap:6px;padding:10px 16px;text-decoration:none;font-weight:600;">
        ◫ QR Stand
      </a>
      <a href="qa.php" class="btn btn-secondary" style="display:inline-flex;align-items:center;gap:6px;padding:10px 16px;text-decoration:none;font-weight:600;">
        💡 Community Q&amp;A
      </a>
      <?php endif; ?>
      <a class="primary-button" href="company.php" style="text-decoration:none;">＋ Manage Company</a>
    </div>
  </div>

  <?php if ($profile_strength): ?>
  <!-- Profile Strength & Setup Completion Card -->
  <div class="profile-strength-card" style="background:var(--ps-card-bg, #ffffff);border:1px solid var(--line, #e2e8f0);border-radius:18px;padding:24px 28px;margin-bottom:24px;box-shadow:0 4px 20px rgba(0,0,0,0.03);">
      <div style="display:grid;grid-template-columns:auto 1fr;gap:28px;align-items:center;" class="profile-strength-grid">
          
          <!-- Left: Gamified Ring & Tier Summary -->
          <div style="display:flex;align-items:center;gap:20px;padding-right:24px;border-right:1px solid var(--line, #e2e8f0);min-width:240px;" class="profile-strength-left">
              <div style="position:relative;width:96px;height:96px;flex-shrink:0;">
                  <svg width="96" height="96" viewBox="0 0 96 96" style="transform:rotate(-90deg);">
                      <!-- Background Track -->
                      <circle cx="48" cy="48" r="42" stroke="#f1f5f9" stroke-width="8" fill="none" style="stroke:var(--ps-track, #f1f5f9);" />
                      <!-- Progress Arc -->
                      <circle cx="48" cy="48" r="42"
                              stroke="<?php echo htmlspecialchars($profile_strength['tier_color']); ?>"
                              stroke-width="8"
                              stroke-linecap="round"
                              stroke-dasharray="263.89"
                              stroke-dashoffset="<?php echo round(263.89 * (1 - ($profile_strength['score'] / 100)), 2); ?>"
                              fill="none"
                              style="transition:stroke-dashoffset 1.2s cubic-bezier(0.4, 0, 0.2, 1);" />
                  </svg>
                  <!-- Percentage in Center -->
                  <div style="position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;">
                      <span style="font-size:22px;font-weight:800;color:var(--ink, #091a27);line-height:1;"><?php echo $profile_strength['score']; ?>%</span>
                      <span style="font-size:10px;font-weight:700;color:var(--muted, #64748b);letter-spacing:0.5px;margin-top:2px;">STRENGTH</span>
                  </div>
              </div>

              <div>
                  <div style="display:inline-flex;align-items:center;gap:6px;background:var(--ps-tier-bg, rgba(0,0,0,0.04));padding:4px 10px;border-radius:99px;font-size:12px;font-weight:700;color:var(--ink, #091a27);margin-bottom:6px;">
                      <span><?php echo $profile_strength['tier_icon']; ?></span>
                      <span><?php echo htmlspecialchars($profile_strength['tier']); ?></span>
                  </div>
                  <h3 style="margin:0 0 4px;font-size:16px;color:var(--ink, #091a27);font-weight:700;">Profile Strength</h3>
                  <p class="muted" style="margin:0;font-size:12.5px;line-height:1.4;">
                      <?php echo htmlspecialchars($profile_strength['completed_count']); ?> of <?php echo htmlspecialchars($profile_strength['total_items']); ?> tasks completed
                  </p>
              </div>
          </div>

          <!-- Right: Actionable Checklist Grid -->
          <div>
              <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;flex-wrap:wrap;gap:8px;">
                  <p style="margin:0;font-size:13px;color:var(--ink, #091a27);font-weight:600;">
                      <?php echo htmlspecialchars($profile_strength['message']); ?>
                  </p>
                  <button type="button" onclick="toggleStrengthChecklist()" id="strengthToggleBtn" class="btn btn-secondary" style="padding:4px 10px;font-size:11.5px;cursor:pointer;">
                      Hide Tasks ▴
                  </button>
              </div>

              <div id="strengthChecklistGrid" style="display:grid;grid-template-columns:repeat(auto-fit, minmax(280px, 1fr));gap:10px;">
                  <?php foreach ($profile_strength['items'] as $item): ?>
                      <div class="ps-item <?php echo $item['done'] ? 'ps-done' : 'ps-todo'; ?>">
                          <div style="display:flex;align-items:center;gap:10px;min-width:0;">
                              <div class="ps-dot <?php echo $item['done'] ? 'ps-dot-done' : 'ps-dot-todo'; ?>">
                                  <?php echo $item['done'] ? '✓' : '○'; ?>
                              </div>
                              <div style="min-width:0;">
                                  <strong style="font-size:13px;color:var(--ink, #091a27);display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                                      <?php echo htmlspecialchars($item['title']); ?>
                                  </strong>
                                  <span class="muted" style="font-size:11px;display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                                      <?php echo htmlspecialchars($item['desc']); ?>
                                  </span>
                              </div>
                          </div>
                          <div style="display:flex;align-items:center;gap:8px;flex-shrink:0;margin-left:10px;">
                              <span class="ps-badge <?php echo $item['done'] ? 'ps-badge-done' : 'ps-badge-todo'; ?>" style="font-size:11px;font-weight:700;padding:2px 7px;border-radius:99px;background:<?php echo $item['done'] ? 'rgba(22,163,74,0.1)' : 'rgba(59,130,246,0.1)'; ?>;color:<?php echo $item['done'] ? '#16a34a' : '#2563eb'; ?>;">
                                  <?php echo $item['done'] ? 'Done' : '+' . $item['weight'] . '%'; ?>
                              </span>
                              <a href="<?php echo htmlspecialchars($item['action_url']); ?>" class="btn btn-secondary" style="padding:5px 10px;font-size:11px;text-decoration:none;font-weight:600;white-space:nowrap;border-radius:6px;">
                                  <?php echo htmlspecialchars($item['action_label']); ?>
                              </a>
                          </div>
                      </div>
                  <?php endforeach; ?>
              </div>
          </div>

      </div>
  </div>

  <style>
  /* Profile Strength card — theme aware (light defaults, dark overrides) */
  .profile-strength-card {
      --ps-card-bg: #ffffff;
      --ps-track: #f1f5f9;
      --ps-tier-bg: rgba(0, 0, 0, 0.04);
  }
  .ps-item {
      display: flex; align-items: center; justify-content: space-between;
      padding: 10px 14px; border-radius: 10px; border: 1px solid #e2e8f0;
      background: #ffffff; transition: all 0.2s;
  }
  .ps-done { background: #f8fafc; border-color: #e2e8f0; }
  .ps-todo { background: #ffffff; border-color: rgba(194, 245, 66, 0.8); }
  .ps-dot {
      width: 22px; height: 22px; border-radius: 50%;
      display: flex; align-items: center; justify-content: center;
      font-size: 12px; font-weight: 800; flex-shrink: 0;
  }
  .ps-dot-done { background: #dcfce7; color: #16a34a; }
  .ps-dot-todo { background: #f1f5f9; color: #94a3b8; }
  :root[data-theme='dark'] .ps-badge-done { background: rgba(52, 211, 153, 0.15) !important; color: #34d399 !important; }
  :root[data-theme='dark'] .ps-badge-todo { background: rgba(59, 130, 246, 0.15) !important; color: #93c5fd !important; }

  :root[data-theme='dark'] .profile-strength-card {
      --ps-card-bg: #0f1f2e;
      --ps-track: rgba(255, 255, 255, 0.08);
      --ps-tier-bg: rgba(194, 245, 66, 0.15);
  }
  :root[data-theme='dark'] .ps-item { background: #0f1f2e; border-color: rgba(255, 255, 255, 0.08); }
  :root[data-theme='dark'] .ps-done { background: #13293b; }
  :root[data-theme='dark'] .ps-todo { border-color: rgba(194, 245, 66, 0.8); }
  :root[data-theme='dark'] .ps-dot-done { background: rgba(52, 211, 153, 0.18); color: #34d399; }
  :root[data-theme='dark'] .ps-dot-todo { background: rgba(255, 255, 255, 0.08); color: #94a3b8; }

  @media (max-width: 820px) {
      .profile-strength-grid { grid-template-columns: 1fr !important; }
      .profile-strength-left { border-right: none !important; border-bottom: 1px solid var(--line, #e2e8f0); padding-right: 0 !important; padding-bottom: 18px !important; }
  }
  </style>

  <script>
  function toggleStrengthChecklist() {
      var grid = document.getElementById('strengthChecklistGrid');
      var btn = document.getElementById('strengthToggleBtn');
      if (!grid || !btn) return;
      if (grid.style.display === 'none') {
          grid.style.display = 'grid';
          btn.innerHTML = 'Hide Tasks ▴';
          localStorage.setItem('optibiz_strength_checklist', 'expanded');
      } else {
          grid.style.display = 'none';
          btn.innerHTML = 'View Tasks (<?php echo $profile_strength['completed_count']; ?>/<?php echo $profile_strength['total_items']; ?>) ▾';
          localStorage.setItem('optibiz_strength_checklist', 'collapsed');
      }
  }
  document.addEventListener('DOMContentLoaded', function() {
      if (localStorage.getItem('optibiz_strength_checklist') === 'collapsed' && <?php echo $profile_strength['score']; ?> >= 70) {
          toggleStrengthChecklist();
      }
  });
  </script>
  <?php endif; ?>

  <div class="metric-grid">
   <div class="metric-card"><div class="metric-icon lime">⌂</div><span>Total companies</span><strong><?php echo number_format($total_customers); ?></strong><small>Active locations</small></div>
   <div class="metric-card"><div class="metric-icon purple">☆</div><span>Total reviews</span><strong><?php echo number_format($total_ratings); ?></strong><small><?php echo ($view_mode === 'all' || !$is_tenant) ? 'All-time feedback' : htmlspecialchars($active_company_name ?: 'Active branch'); ?></small></div>
   <div class="metric-card"><div class="metric-icon amber">★</div><span>Average score</span><strong><?php echo $avg_rating; ?><i>/ 5.0</i></strong><small class="positive">↑ Customer satisfaction</small></div>
   <div class="metric-card"><div class="metric-icon green">✓</div><span>Account status</span><strong class="active-text"><?php echo htmlspecialchars($tenant_info['subscription_status']??'Active'); ?></strong><small><?php echo $is_tenant?'Subscription is healthy':'Platform administrator'; ?></small></div>
  </div>
  <div class="chart-grid">
   <section class="panel performance-panel"><div class="panel-head"><div><h2>Ratings performance</h2><p class="muted">Review volume and customer sentiment over time</p></div><select aria-label="Chart period"><option>Last 7 months</option></select></div>
    <div class="chart-legend"><span><i class="legend-line lime"></i> Reviews</span><span><i class="legend-line amber-line"></i> Average score</span></div>
    <div class="chart">
      <div class="y-labels"><span>5.0</span><span>4.0</span><span>3.0</span><span>2.0</span><span>1.0</span></div>
      <div class="chart-area">
        <div class="grid-lines"><i></i><i></i><i></i><i></i><i></i></div>
        <?php
        // Generate dynamic SVG paths from trend data
        $max_count = max(array_column($trend_data, 'count')) ?: 1;
        $svg_height = 220;
        $svg_width = 700;
        $point_spacing = count($trend_data) > 1 ? $svg_width / (count($trend_data) - 1) : $svg_width;
        
        // Build path data for review count (lime line)
        $count_path_data = "M";
        $count_area_data = "M";
        foreach($trend_data as $i => $data) {
          $x = $i * $point_spacing;
          $y = $svg_height - (($data['count'] / $max_count) * ($svg_height * 0.7)) - 20;
          $count_path_data .= ($i > 0 ? " L" : "") . round($x) . "," . round($y);
          $count_area_data .= ($i > 0 ? " L" : "") . round($x) . "," . round($y);
        }
        $count_area_data .= " V{$svg_height} H0 Z";
        
        // Build path data for average rating (amber line)
        $avg_path_data = "M";
        foreach($trend_data as $i => $data) {
          $x = $i * $point_spacing;
          $y = $data['avg'] > 0 ? $svg_height - (($data['avg'] / 5.0) * ($svg_height * 0.7)) - 20 : $svg_height - 20;
          $avg_path_data .= ($i > 0 ? " L" : "") . round($x) . "," . round($y);
        }
        ?>
        <svg viewBox="0 0 700 220" preserveAspectRatio="none" role="img" aria-label="Ratings performance trend">
          <defs><linearGradient id="fill" x1="0" x2="0" y1="0" y2="1"><stop offset="0" stop-color="#c2f542" stop-opacity=".28"/><stop offset="1" stop-color="#c2f542" stop-opacity="0"/></linearGradient></defs>
          <path class="area" d="<?php echo htmlspecialchars($count_area_data); ?>" fill="url(#fill)"/>
          <path class="trend lime-stroke" d="<?php echo htmlspecialchars($count_path_data); ?>"/>
          <path class="trend amber-stroke" d="<?php echo htmlspecialchars($avg_path_data); ?>"/>
        </svg>
        <div class="x-labels"><?php foreach($trend_months as $month): ?><span><?php echo $month; ?></span><?php endforeach; ?></div>
      </div>
    </div>
   </section>
   <section class="panel score-panel"><div class="panel-head"><div><h2>Score breakdown</h2><p class="muted">How customers rate you</p></div><span class="total-score"><?php echo $avg_rating; ?> <b>★</b></span></div><?php 
   $bar_colors=['#c2f542','#a3e635','#fbbf24','#fb923c','#f87171'];
   for($star=5; $star>=1; $star--): 
     $count = $rating_distribution[$star];
     $percentage = $total_for_dist > 0 ? round(($count / $total_for_dist) * 100, 1) : 0;
   ?><div class="score-row"><span><?php echo $star; ?> star<?php echo $star>1?'s':''; ?></span><div><i style="width:<?php echo $percentage; ?>%;background:<?php echo $bar_colors[5-$star]; ?>"></i></div><b><?php echo $percentage; ?>%</b></div><?php endfor; ?><a class="panel-link" href="ratings.php">View all reviews →</a></section>
  </div>
  <div class="bottom-grid"><section class="panel recent-panel"><div class="panel-head"><div><h2>Recent reviews</h2><p class="muted">The latest feedback from your customers</p></div><a class="panel-link" href="ratings.php">View all →</a></div><?php if($recent_ratings && $recent_ratings->num_rows): while($r=$recent_ratings->fetch_assoc()): ?><div class="review-row"><div class="review-avatar"><?php echo htmlspecialchars(strtoupper(substr($r['customer_name']??'C',0,1))); ?></div><div class="review-body"><strong><?php echo htmlspecialchars($r['customer_name']); ?></strong><small><?php echo htmlspecialchars($r['company_name']); ?> · <?php echo date('M d, Y',strtotime($r['created_at'])); ?></small><p><?php echo htmlspecialchars($r['comment']?:'No comment provided.'); ?></p></div><span class="review-stars"><?php echo str_repeat('★',(int)$r['rating']); ?> <b><?php echo $r['rating']; ?>.0</b></span></div><?php endwhile; else: ?><div class="empty-state">No reviews received yet.</div><?php endif; ?></section>
   <section class="panel links-panel"><div class="panel-head"><div><h2>Public rating links</h2><p class="muted">Share and collect feedback</p></div><?php if ($is_tenant): ?><a class="panel-link" href="qr_stand.php" style="font-weight:700;">◫ Print QR Stand →</a><?php endif; ?></div><?php if($tenant_companies && $tenant_companies->num_rows): $shown=0; while($c=$tenant_companies->fetch_assoc()): if($shown++>=4) break; $url=getCompanyPublicRatingUrl($c['id'], $c['company_name']); ?><div class="link-row"><span class="link-icon">↗</span><div><strong><?php echo htmlspecialchars($c['company_name']); ?><?php if($is_tenant && (int)$c['id']===$active_company_id): ?> <span style="font-size:10px;padding:2px 7px;border-radius:10px;background:rgba(99,102,241,0.15);color:#6366f1;font-weight:700;margin-left:4px;">Active</span><?php endif; ?></strong><small><?php echo htmlspecialchars($url); ?></small></div><a href="<?php echo $url; ?>" target="_blank">View</a></div><?php endwhile; else: ?><div class="empty-state">Add a company to create rating links.</div><?php endif; ?></section>
  </div>
<?php include __DIR__ . '/_shell_footer.php'; ?>
