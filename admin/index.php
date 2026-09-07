<?php
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';
requireLogin();
$tenant_id = getTenantId();
$is_tenant = isTenant();
$tenant_info = null;
if ($is_tenant && $tenant_id) {
    $stmt = $conn->prepare("SELECT t.*, p.plan_name, p.max_ratings, p.max_customers FROM tenants t LEFT JOIN subscription_plans p ON t.plan_id=p.id WHERE t.id=?");
    $stmt->bind_param('i', $tenant_id); $stmt->execute(); $tenant_info = $stmt->get_result()->fetch_assoc();
    $stmt = $conn->prepare("SELECT COUNT(*) count FROM customers WHERE tenant_id=?"); $stmt->bind_param('i',$tenant_id); $stmt->execute(); $total_customers=$stmt->get_result()->fetch_assoc()['count'] ?? 0;
    $stmt = $conn->prepare("SELECT COUNT(*) count, AVG(r.rating) avg_rating FROM ratings r JOIN customers c ON r.company_id=c.id WHERE c.tenant_id=?"); $stmt->bind_param('i',$tenant_id); $stmt->execute(); $rr=$stmt->get_result()->fetch_assoc();
    $total_ratings=$rr['count']??0; $avg_rating=round($rr['avg_rating']??0,1);
    $stmt = $conn->prepare("SELECT r.*,c.company_name FROM ratings r JOIN customers c ON r.company_id=c.id WHERE c.tenant_id=? ORDER BY r.created_at DESC LIMIT 5"); $stmt->bind_param('i',$tenant_id); $stmt->execute(); $recent_ratings=$stmt->get_result();
    $stmt = $conn->prepare("SELECT id,company_name FROM customers WHERE tenant_id=? ORDER BY company_name"); $stmt->bind_param('i',$tenant_id); $stmt->execute(); $tenant_companies=$stmt->get_result();
    
    // Rating distribution for score breakdown chart
    $stmt = $conn->prepare("SELECT r.rating, COUNT(*) as count FROM ratings r JOIN customers c ON r.company_id=c.id WHERE c.tenant_id=? GROUP BY r.rating ORDER BY r.rating DESC");
    $stmt->bind_param('i',$tenant_id); $stmt->execute(); $rating_dist_result=$stmt->get_result();
    
    // Monthly ratings trend for last 7 months
    $stmt = $conn->prepare("SELECT DATE_FORMAT(r.created_at, '%Y-%m') as month, COUNT(*) as count, AVG(r.rating) as avg_rating FROM ratings r JOIN customers c ON r.company_id=c.id WHERE c.tenant_id=? AND r.created_at >= DATE_SUB(NOW(), INTERVAL 7 MONTH) GROUP BY month ORDER BY month ASC");
    $stmt->bind_param('i',$tenant_id); $stmt->execute(); $monthly_trend=$stmt->get_result();
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

$BASE = '../';
$pageTitle = 'Dashboard';
$activeNav = 'dashboard';
include __DIR__ . '/_shell.php';
?>
  <div class="welcome-row"><div><p class="eyebrow">Good morning, <?php echo htmlspecialchars($is_tenant?($tenant_info['company_name']??'there'):($_SESSION['admin_username']??'Admin')); ?></p><h1>Performance overview</h1><p class="muted">Track your customer feedback and business health in one place.</p></div><a class="primary-button" href="company.php">＋ Add company</a></div>

  <div class="metric-grid">
   <div class="metric-card"><div class="metric-icon lime">⌂</div><span>Total companies</span><strong><?php echo number_format($total_customers); ?></strong><small>Active locations</small></div>
   <div class="metric-card"><div class="metric-icon purple">☆</div><span>Total reviews</span><strong><?php echo number_format($total_ratings); ?></strong><small>All-time feedback</small></div>
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
   <section class="panel links-panel"><div class="panel-head"><div><h2>Public rating links</h2><p class="muted">Share and collect feedback</p></div></div><?php if($tenant_companies && $tenant_companies->num_rows): $shown=0; while($c=$tenant_companies->fetch_assoc()): if($shown++>=4) break; $url=$assetBase.'/rate/index.php?company='.$c['id']; ?><div class="link-row"><span class="link-icon">↗</span><div><strong><?php echo htmlspecialchars($c['company_name']); ?></strong><small><?php echo htmlspecialchars($url); ?></small></div><a href="<?php echo $url; ?>" target="_blank">View</a></div><?php endwhile; else: ?><div class="empty-state">Add a company to create rating links.</div><?php endif; ?></section>
  </div>
<?php include __DIR__ . '/_shell_footer.php'; ?>
