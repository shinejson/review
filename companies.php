<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

$customers = $conn->query("SELECT c.*, cat.name as category_name FROM customers c LEFT JOIN categories cat ON c.category_id = cat.id ORDER BY c.company_name");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Companies - Optibiz Rating Platform</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: #f8fafc;
            color: #1e293b;
        }
        
        .cmp-header {
            background: #1e293b;
            padding: 20px 80px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .cmp-logo {
            font-size: 24px;
            font-weight: 700;
            color: white;
        }
        .cmp-nav {
            display: flex;
            gap: 40px;
            align-items: center;
        }
        .cmp-nav a {
            color: white;
            text-decoration: none;
            font-size: 14px;
            transition: opacity 0.3s;
        }
        .cmp-nav a:hover { opacity: 0.7; }
        
        .cmp-page-header {
            background: linear-gradient(135deg, #1e293b 0%, #334155 100%);
            padding: 60px 80px;
            text-align: center;
            color: white;
        }
        .cmp-page-header h1 {
            font-size: 48px;
            margin-bottom: 15px;
        }
        .cmp-page-header p {
            font-size: 18px;
            color: #cbd5e1;
        }
        
        .cmp-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 60px 80px;
        }
        
        .cmp-search-filter {
            background: white;
            padding: 30px;
            border-radius: 12px;
            margin-bottom: 40px;
            display: flex;
            gap: 20px;
            align-items: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        .cmp-search-input {
            flex: 1;
            padding: 12px 20px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
        }
        .cmp-search-input:focus {
            outline: none;
            border-color: #a3e635;
        }
        .cmp-filter-select {
            padding: 12px 20px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
            background: white;
            cursor: pointer;
        }
        
        .cmp-companies-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 30px;
        }
        
        .cmp-company-card {
            background: white;
            border-radius: 16px;
            padding: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            transition: transform 0.3s, box-shadow 0.3s;
        }
        .cmp-company-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }
        
        .cmp-company-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 20px;
        }
        .cmp-company-logo {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, #a3e635 0%, #84cc16 100%);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            font-weight: 700;
            color: white;
        }
        .cmp-company-category {
            background: #f1f5f9;
            color: #64748b;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .cmp-company-name {
            font-size: 22px;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 10px;
        }
        
        .cmp-company-info {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin-bottom: 20px;
        }
        .cmp-info-item {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #64748b;
            font-size: 14px;
        }
        .cmp-info-icon {
            color: #a3e635;
        }
        
        .cmp-rating-section {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 15px 0;
            border-top: 1px solid #e2e8f0;
            border-bottom: 1px solid #e2e8f0;
            margin-bottom: 20px;
        }
        .cmp-rating-score {
            font-size: 32px;
            font-weight: 700;
            color: #1e293b;
        }
        .cmp-rating-details {
            flex: 1;
        }
        .cmp-stars {
            color: #fbbf24;
            font-size: 18px;
            margin-bottom: 5px;
        }
        .cmp-rating-count {
            color: #64748b;
            font-size: 13px;
        }
        
        .cmp-rate-btn {
            width: 100%;
            background: #a3e635;
            color: #1e293b;
            padding: 14px;
            border: none;
            border-radius: 8px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: block;
            text-align: center;
            transition: background 0.3s;
        }
        .cmp-rate-btn:hover {
            background: #84cc16;
        }
        
        .cmp-empty-state {
            text-align: center;
            padding: 80px 20px;
            color: #64748b;
        }
        .cmp-empty-state h2 {
            font-size: 24px;
            margin-bottom: 10px;
        }
    </style>
</head>
<body>
    <header class="cmp-header">
        <div class="cmp-logo">Optibiz</div>
        <nav class="cmp-nav">
            <a href="index.php">Home</a>
            <a href="companies.php">Companies</a>
            <a href="#about">About</a>
            <a href="admin/login.php">Admin Login</a>
        </nav>
    </header>

    <div class="cmp-page-header">
        <h1>Browse Companies</h1>
        <p>Rate and review companies to help others make informed decisions</p>
    </div>

    <div class="cmp-container">
        <div class="cmp-search-filter">
            <input type="text" class="cmp-search-input" id="searchInput" placeholder="Search companies by name...">
            <select class="cmp-filter-select" id="categoryFilter">
                <option value="">All Categories</option>
                <?php
                $categories = $conn->query("SELECT DISTINCT cat.id, cat.name FROM categories cat INNER JOIN customers c ON cat.id = c.category_id ORDER BY cat.name");
                while ($cat = $categories->fetch_assoc()):
                ?>
                <option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
                <?php endwhile; ?>
            </select>
        </div>

        <div class="cmp-companies-grid" id="companiesGrid">
            <?php if ($customers->num_rows > 0): ?>
                <?php while ($company = $customers->fetch_assoc()): 
                    $avg_rating = getAverageRating($company['id'], $conn);
                    $rating_count = getRatingCount($company['id'], $conn);
                    $initials = strtoupper(substr($company['company_name'], 0, 2));
                ?>
                <div class="cmp-company-card" data-category="<?php echo $company['category_id']; ?>" data-name="<?php echo strtolower($company['company_name']); ?>">
                    <div class="cmp-company-header">
                        <div class="cmp-company-logo"><?php echo $initials; ?></div>
                        <?php if ($company['category_name']): ?>
                        <span class="cmp-company-category"><?php echo htmlspecialchars($company['category_name']); ?></span>
                        <?php endif; ?>
                    </div>
                    
                    <h3 class="cmp-company-name"><?php echo htmlspecialchars($company['company_name']); ?></h3>
                    
                    <div class="cmp-company-info">
                        <?php if ($company['email']): ?>
                        <div class="cmp-info-item">
                            <span class="cmp-info-icon">✉</span>
                            <?php echo htmlspecialchars($company['email']); ?>
                        </div>
                        <?php endif; ?>
                        <?php if ($company['phone']): ?>
                        <div class="cmp-info-item">
                            <span class="cmp-info-icon">📞</span>
                            <?php echo htmlspecialchars($company['phone']); ?>
                        </div>
                        <?php endif; ?>
                        <?php if ($company['website']): ?>
                        <div class="cmp-info-item">
                            <span class="cmp-info-icon">🌐</span>
                            <?php echo htmlspecialchars($company['website']); ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="cmp-rating-section">
                        <div class="cmp-rating-score"><?php echo number_format($avg_rating, 1); ?></div>
                        <div class="cmp-rating-details">
                            <div class="cmp-stars">
                                <?php
                                $full_stars = floor($avg_rating);
                                for ($i = 0; $i < $full_stars; $i++) echo '★';
                                if (($avg_rating - $full_stars) >= 0.5) echo '★';
                                for ($i = 0; $i < (5 - ceil($avg_rating)); $i++) echo '☆';
                                ?>
                            </div>
                            <div class="cmp-rating-count"><?php echo $rating_count; ?> reviews</div>
                        </div>
                    </div>
                    
                    <a href="rate/index.php?company=<?php echo $company['id']; ?>" class="cmp-rate-btn">Rate This Company →</a>
                </div>
                <?php endwhile; ?>
            <?php else: ?>
                <div class="cmp-empty-state">
                    <h2>No Companies Yet</h2>
                    <p>Check back soon for companies to rate</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Search functionality
        document.getElementById('searchInput').addEventListener('input', function(e) {
            filterCompanies();
        });

        // Category filter
        document.getElementById('categoryFilter').addEventListener('change', function(e) {
            filterCompanies();
        });

        function filterCompanies() {
            const searchTerm = document.getElementById('searchInput').value.toLowerCase();
            const categoryFilter = document.getElementById('categoryFilter').value;
            const cards = document.querySelectorAll('.cmp-company-card');

            cards.forEach(card => {
                const name = card.getAttribute('data-name');
                const category = card.getAttribute('data-category');
                
                const matchesSearch = name.includes(searchTerm);
                const matchesCategory = !categoryFilter || category === categoryFilter;
                
                if (matchesSearch && matchesCategory) {
                    card.style.display = 'block';
                } else {
                    card.style.display = 'none';
                }
            });
        }
    </script>
</body>
</html>