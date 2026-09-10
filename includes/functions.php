<?php
require_once __DIR__ . '/mailer.php';

function sanitize($data) {
    if (is_array($data)) {
        return array_map('sanitize', $data);
    }
    if ($data === null) {
        return '';
    }
    return htmlspecialchars(strip_tags(trim((string)$data)));
}

function redirect($url) {
    header("Location: $url");
    exit();
}

function getAverageRating($company_id, $conn) {
    $stmt = $conn->prepare("SELECT AVG(rating) as avg_rating FROM ratings WHERE company_id = ? AND reported = 0");
    $stmt->bind_param("i", $company_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    return round($row['avg_rating'], 1);
}

function getRatingCount($company_id, $conn) {
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM ratings WHERE company_id = ? AND reported = 0");
    $stmt->bind_param("i", $company_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    return $row['count'];
}

function getRatingDistribution($company_id, $conn) {
    $dist = [];
    $total = getRatingCount($company_id, $conn);
    for ($i = 5; $i >= 1; $i--) {
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM ratings WHERE company_id = ? AND rating = ? AND reported = 0");
        $stmt->bind_param("ii", $company_id, $i);
        $stmt->execute();
        $count = (int)$stmt->get_result()->fetch_assoc()['count'];
        $percentage = $total > 0 ? round(($count / $total) * 100) : 0;
        $dist[$i] = ['count' => $count, 'percentage' => $percentage];
    }
    return $dist;
}

/* ============================================================
 *  WhatsApp click-to-chat
 * ============================================================
 *  Every business stores its number however it likes
 *  ("+233 24 555 0118", "024 555 0118", "00233245550118" …).
 *  wa.me only accepts international digits, so everything is
 *  normalised here once and shared by the public rating page,
 *  the company directory and the workspace profile screen.
 */

/**
 * Normalise any typed phone number into wa.me digits.
 * Returns '' when nothing usable is left, so callers can simply
 * hide the button instead of linking to a broken chat.
 */
function whatsappDigits($raw) {
    $digits = preg_replace('/\D+/', '', (string)$raw);
    if ($digits === '') {
        return '';
    }
    // Trunk prefix written the long way: 00233… -> 233…
    if (strncmp($digits, '00', 2) === 0) {
        $digits = substr($digits, 2);
    }
    // Local Ghanaian format: 024 555 0118 -> 233245550118
    if (strncmp($digits, '0', 1) === 0 && strlen($digits) >= 9 && strlen($digits) <= 10) {
        $digits = '233' . substr($digits, 1);
    }
    return (strlen($digits) >= 7 && strlen($digits) <= 15) ? $digits : '';
}

/**
 * Build the wa.me deep link for a business number.
 * Returns '' when there is no usable number.
 */
function whatsappChatUrl($rawNumber, $companyName = '', $message = '') {
    $digits = whatsappDigits($rawNumber);
    if ($digits === '') {
        return '';
    }
    $name = trim((string)$companyName);
    if (trim((string)$message) === '') {
        $message = 'Hello' . ($name !== '' ? ' ' . $name : '') . ', I found you on Optibiz and would like to inquire.';
    }
    return 'https://wa.me/' . $digits . '?text=' . rawurlencode($message);
}

/** Human-readable copy of a normalised number: "+233 24 555 0118". */
function whatsappDisplay($rawNumber) {
    $digits = whatsappDigits($rawNumber);
    if ($digits === '') {
        return '';
    }
    if (strncmp($digits, '233', 3) === 0 && strlen($digits) === 12) {
        return '+233 ' . substr($digits, 3, 2) . ' ' . substr($digits, 5, 3) . ' ' . substr($digits, 8);
    }
    return '+' . $digits;
}

/**
 * Make sure customers.whatsapp_number exists, so installs created
 * before the WhatsApp feature keep working without a manual SQL
 * update (mirrors sa_ensure_user_schema()). Runs once per request
 * and is only called from the workspace profile save handler.
 */
function ensureWhatsappColumn($conn) {
    static $done = false;
    if ($done || !is_object($conn) || !method_exists($conn, 'query')) {
        return;
    }
    $done = true;

    $res = @$conn->query("SHOW COLUMNS FROM customers LIKE 'whatsapp_number'");
    if ($res) {
        $exists = (int)$res->num_rows > 0;
        if (method_exists($res, 'free')) {
            $res->free();
        }
        if ($exists) {
            return;
        }
    }
    @$conn->query("ALTER TABLE customers ADD COLUMN whatsapp_number VARCHAR(30) NULL AFTER phone");
}

/**
 * Auto-ensure booster and sentiment routing columns exist in customers and ratings tables.
 * Self-healing across environments.
 */
function ensureBoosterColumns($conn) {
    static $done = false;
    if ($done || !is_object($conn) || !method_exists($conn, 'query')) {
        return;
    }
    $done = true;

    // Check customers table
    $chkCust = @$conn->query("SHOW COLUMNS FROM customers LIKE 'booster_enabled'");
    if ($chkCust && (int)$chkCust->num_rows === 0) {
        @$conn->query("ALTER TABLE customers ADD COLUMN booster_enabled TINYINT(1) NOT NULL DEFAULT 1, ADD COLUMN booster_min_stars TINYINT(1) NOT NULL DEFAULT 4");
    }
    if ($chkCust && method_exists($chkCust, 'free')) {
        $chkCust->free();
    }

    // Check ratings table
    $chkRatings = @$conn->query("SHOW COLUMNS FROM ratings LIKE 'is_escalated'");
    if ($chkRatings && (int)$chkRatings->num_rows === 0) {
        @$conn->query("ALTER TABLE ratings ADD COLUMN is_escalated TINYINT(1) NOT NULL DEFAULT 0, ADD COLUMN escalation_status VARCHAR(20) NOT NULL DEFAULT 'none'");
    }
    if ($chkRatings && method_exists($chkRatings, 'free')) {
        $chkRatings->free();
    }
}

/**
 * Format and validate Google Review / Business URL.
 */
function cleanGoogleReviewUrl($url) {
    $url = trim((string)$url);
    if ($url === '') {
        return '';
    }
    if (!preg_match('~^(?:f|ht)tps?://~i', $url)) {
        $url = 'https://' . $url;
    }
    return filter_var($url, FILTER_VALIDATE_URL) ? $url : '';
}

/**
 * Auto-ensure Google Map direction/location and social profile columns exist in customers table.
 */
function ensureLocationAndSocialColumns($conn) {
    static $done = false;
    if ($done || !is_object($conn) || !method_exists($conn, 'query')) {
        return;
    }
    $done = true;

    $cols = [
        'google_map_url'       => "VARCHAR(1000) NULL COMMENT 'Google Maps direction / location URL'",
        'map_embed_code'       => "TEXT NULL COMMENT 'Google Map embed iframe code or embed URL'",
        'location_description' => "TEXT NULL COMMENT 'Location directions and landmark description'",
        'facebook_url'         => "VARCHAR(255) NULL",
        'instagram_url'        => "VARCHAR(255) NULL",
        'twitter_url'          => "VARCHAR(255) NULL",
        'linkedin_url'         => "VARCHAR(255) NULL",
        'tiktok_url'           => "VARCHAR(255) NULL",
        'youtube_url'          => "VARCHAR(255) NULL",
    ];

    foreach ($cols as $colName => $colDef) {
        $chk = @$conn->query("SHOW COLUMNS FROM customers LIKE '$colName'");
        if ($chk && (int)$chk->num_rows === 0) {
            @$conn->query("ALTER TABLE customers ADD COLUMN $colName $colDef");
        }
        if ($chk && method_exists($chk, 'free')) {
            $chk->free();
        }
    }
}

/**
 * Format and sanitize map URL.
 */
function cleanMapUrl($url) {
    $url = trim((string)$url);
    if ($url === '') return '';
    if (!preg_match('~^(?:f|ht)tps?://~i', $url)) {
        $url = 'https://' . $url;
    }
    return filter_var($url, FILTER_VALIDATE_URL) ? $url : '';
}

/**
 * Extract clean map embed source URL from an iframe or raw URL.
 */
function extractMapEmbedSrc($code) {
    $code = trim((string)$code);
    if ($code === '') return '';
    if (preg_match('/src=["\']([^"\']+)["\']/i', $code, $matches)) {
        return $matches[1];
    }
    if (preg_match('~^https://(?:www\.)?google\.[a-z.]+/maps/embed~i', $code)) {
        return $code;
    }
    return '';
}

/**
 * Returns configured social profiles for a company.
 */
function getCompanySocialLinks($company) {
    if (!is_array($company)) return [];
    
    $networks = [
        'facebook' => [
            'key'   => 'facebook',
            'label' => 'Facebook',
            'class' => 'social-facebook',
            'color' => '#1877F2',
            'bg'    => '#e7f0fd',
            'url'   => cleanMapUrl($company['facebook_url'] ?? ''),
            'svg'   => '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>'
        ],
        'instagram' => [
            'key'   => 'instagram',
            'label' => 'Instagram',
            'class' => 'social-instagram',
            'color' => '#E4405F',
            'bg'    => '#fdecef',
            'url'   => cleanMapUrl($company['instagram_url'] ?? ''),
            'svg'   => '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zm0-2.163c-3.259 0-3.667.014-4.947.072-4.358.2-6.78 2.618-6.98 6.98-.059 1.281-.073 1.689-.073 4.948 0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98 1.281.058 1.689.072 4.948.072 3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98-1.281-.059-1.69-.073-4.949-.073zm0 5.838c-3.403 0-6.162 2.759-6.162 6.162s2.759 6.163 6.162 6.163 6.162-2.759 6.162-6.163c0-3.403-2.759-6.162-6.162-6.162zm0 10.162c-2.209 0-4-1.79-4-4 0-2.209 1.791-4 4-4s4 1.791 4 4c0 2.21-1.791 4-4 4zm6.406-11.845c-.796 0-1.441.645-1.441 1.44s.645 1.44 1.441 1.44c.795 0 1.439-.645 1.439-1.44s-.644-1.44-1.439-1.44z"/></svg>'
        ],
        'twitter' => [
            'key'   => 'twitter',
            'label' => 'X (Twitter)',
            'class' => 'social-twitter',
            'color' => '#0f1419',
            'bg'    => '#e7e7e7',
            'url'   => cleanMapUrl($company['twitter_url'] ?? ''),
            'svg'   => '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>'
        ],
        'linkedin' => [
            'key'   => 'linkedin',
            'label' => 'LinkedIn',
            'class' => 'social-linkedin',
            'color' => '#0A66C2',
            'bg'    => '#e8f0fe',
            'url'   => cleanMapUrl($company['linkedin_url'] ?? ''),
            'svg'   => '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M19 3a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h14m-.5 15.5v-5.3a3.26 3.26 0 0 0-3.26-3.26c-.85 0-1.84.52-2.28 1.3v-1.11h-2.79v8.37h2.79v-4.93c0-.77.62-1.4 1.39-1.4a1.4 1.4 0 0 1 1.4 1.4v4.93h2.75M6.46 10.9v8.37H9.2V10.9H6.46M7.83 6.88a1.64 1.64 0 1 0 1.64 1.64 1.64 1.64 0 0 0-1.64-1.64z"/></svg>'
        ],
        'tiktok' => [
            'key'   => 'tiktok',
            'label' => 'TikTok',
            'class' => 'social-tiktok',
            'color' => '#000000',
            'bg'    => '#e2e8f0',
            'url'   => cleanMapUrl($company['tiktok_url'] ?? ''),
            'svg'   => '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M12.525.02c1.31-.02 2.61-.01 3.91-.02.08 1.53.63 3.09 1.75 4.17 1.12 1.11 2.7 1.62 4.24 1.79v4.03c-1.44-.05-2.89-.35-4.2-.97-.57-.26-1.1-.59-1.62-.93-.01 2.92.01 5.84-.02 8.75-.08 1.4-.54 2.79-1.35 3.94-1.31 1.92-3.58 3.17-5.91 3.21-1.43.08-2.86-.31-4.08-1.03-2.02-1.19-3.44-3.37-3.65-5.71-.02-.5-.03-1-.01-1.49.18-1.9 1.12-3.72 2.58-4.96 1.66-1.44 3.98-2.13 6.15-1.72.02 1.48-.04 2.96-.04 4.44-.99-.32-2.15-.23-3.02.37-.63.41-1.11 1.04-1.36 1.75-.21.51-.24 1.07-.14 1.61.24 1.64 1.82 3.02 3.5 2.87 1.12-.01 2.19-.66 2.77-1.61.19-.33.4-.67.41-1.06.1-1.79.06-3.57.07-5.36.01-4.03-.01-8.05.02-12.07z"/></svg>'
        ],
        'youtube' => [
            'key'   => 'youtube',
            'label' => 'YouTube',
            'class' => 'social-youtube',
            'color' => '#FF0000',
            'bg'    => '#fde8e8',
            'url'   => cleanMapUrl($company['youtube_url'] ?? ''),
            'svg'   => '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"/></svg>'
        ],
    ];

    $active = [];
    foreach ($networks as $netKey => $item) {
        if (!empty($item['url'])) {
            $active[$netKey] = $item;
        }
    }
    return $active;
}

function getInitials($name) {
    $words = explode(' ', trim($name));
    $initials = '';
    foreach ($words as $w) {
        $initials .= strtoupper(substr($w, 0, 1));
        if (strlen($initials) >= 2) break;
    }
    return $initials ?: '?';
}

function timeAgo($datetime) {
    $now = new DateTime();
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);
    
    if ($diff->y > 0) return $diff->y . ' year' . ($diff->y > 1 ? 's' : '') . ' ago';
    if ($diff->m > 0) return $diff->m . ' month' . ($diff->m > 1 ? 's' : '') . ' ago';
    if ($diff->d > 7) return floor($diff->d / 7) . ' week' . (floor($diff->d / 7) > 1 ? 's' : '') . ' ago';
    if ($diff->d > 0) return $diff->d . ' day' . ($diff->d > 1 ? 's' : '') . ' ago';
    if ($diff->h > 0) return $diff->h . ' hour' . ($diff->h > 1 ? 's' : '') . ' ago';
    if ($diff->i > 0) return $diff->i . ' minute' . ($diff->i > 1 ? 's' : '') . ' ago';
    return 'Just now';
}

function getReviews($company_id, $conn, $sort = 'newest', $filter = 0, $limit = 10, $offset = 0) {
    $sql = "SELECT r.*, c.company_name FROM ratings r JOIN customers c ON r.company_id = c.id WHERE r.company_id = ? AND r.reported = 0";
    $params = [$company_id];
    $types = "i";
    
    if ($filter > 0) {
        $sql .= " AND r.rating = ?";
        $params[] = $filter;
        $types .= "i";
    }
    
    switch ($sort) {
        case 'highest':
            $sql .= " ORDER BY r.rating DESC, r.created_at DESC";
            break;
        case 'lowest':
            $sql .= " ORDER BY r.rating ASC, r.created_at DESC";
            break;
        case 'helpful':
            $sql .= " ORDER BY r.helpful_count DESC, r.created_at DESC";
            break;
        default:
            $sql .= " ORDER BY r.created_at DESC";
    }
    
    $sql .= " LIMIT ? OFFSET ?";
    $params[] = $limit;
    $params[] = $offset;
    $types .= "ii";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    return $stmt->get_result();
}

function markHelpful($rating_id, $conn) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $rating_id = (int)$rating_id;
    
    $check = $conn->prepare("SELECT id FROM helpful_votes WHERE rating_id = ? AND voter_ip = ?");
    $check->bind_param("is", $rating_id, $ip);
    $check->execute();
    
    if ($check->get_result()->num_rows > 0) {
        // Already voted -> Toggle unlike
        $del = $conn->prepare("DELETE FROM helpful_votes WHERE rating_id = ? AND voter_ip = ?");
        $del->bind_param("is", $rating_id, $ip);
        $del->execute();
        $conn->query("UPDATE ratings SET helpful_count = GREATEST(0, helpful_count - 1) WHERE id = $rating_id");
        $cnt_res = $conn->query("SELECT helpful_count FROM ratings WHERE id = $rating_id")->fetch_assoc();
        $count = (int)($cnt_res['helpful_count'] ?? 0);
        return ['success' => true, 'liked' => false, 'count' => $count, 'message' => 'Like removed'];
    }
    
    $stmt = $conn->prepare("INSERT INTO helpful_votes (rating_id, voter_ip) VALUES (?, ?)");
    $stmt->bind_param("is", $rating_id, $ip);
    
    if ($stmt->execute()) {
        $conn->query("UPDATE ratings SET helpful_count = helpful_count + 1 WHERE id = $rating_id");
        $cnt_res = $conn->query("SELECT helpful_count FROM ratings WHERE id = $rating_id")->fetch_assoc();
        $count = (int)($cnt_res['helpful_count'] ?? 0);
        return ['success' => true, 'liked' => true, 'count' => $count, 'message' => 'Review liked!'];
    }
    
    return ['success' => false, 'message' => 'Error recording vote'];
}

function reportReview($rating_id, $reason, $conn) {
    $ip = $_SERVER['REMOTE_ADDR'];
    
    $check = $conn->prepare("SELECT id FROM reported_reviews WHERE rating_id = ? AND reporter_ip = ?");
    $check->bind_param("is", $rating_id, $ip);
    $check->execute();
    
    if ($check->get_result()->num_rows > 0) {
        return ['success' => false, 'message' => 'Already reported'];
    }
    
    $stmt = $conn->prepare("INSERT INTO reported_reviews (rating_id, reason, reporter_ip) VALUES (?, ?, ?)");
    $stmt->bind_param("iss", $rating_id, $reason, $ip);
    
    if ($stmt->execute()) {
        $count = $conn->query("SELECT COUNT(*) as cnt FROM reported_reviews WHERE rating_id = $rating_id")->fetch_assoc()['cnt'];
        if ($count >= 3) {
            $conn->query("UPDATE ratings SET reported = 1 WHERE id = $rating_id");
        }
        return ['success' => true, 'message' => 'Review reported'];
    }
    
    return ['success' => false, 'message' => 'Error reporting'];
}

function uploadReviewPhoto($file, $rating_id) {
    $upload_dir = __DIR__ . '/../uploads/reviews/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    
    $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    if (!in_array($file['type'], $allowed)) {
        return ['success' => false, 'message' => 'Invalid file type'];
    }
    
    if ($file['size'] > 5 * 1024 * 1024) {
        return ['success' => false, 'message' => 'File too large (max 5MB)'];
    }
    
    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = 'review_' . $rating_id . '_' . time() . '.' . $ext;
    $filepath = $upload_dir . $filename;
    
    if (move_uploaded_file($file['tmp_name'], $filepath)) {
        return ['success' => true, 'path' => 'uploads/reviews/' . $filename];
    }
    
    return ['success' => false, 'message' => 'Upload failed'];
}

function uploadReceiptPhoto($file, $rating_id) {
    $upload_dir = __DIR__ . '/../uploads/receipts/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    
    $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    if (!in_array($file['type'], $allowed)) {
        return ['success' => false, 'message' => 'Invalid file type'];
    }
    
    if ($file['size'] > 5 * 1024 * 1024) {
        return ['success' => false, 'message' => 'File too large (max 5MB)'];
    }
    
    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = 'receipt_' . (int)$rating_id . '_' . time() . '.' . $ext;
    $filepath = $upload_dir . $filename;
    
    if (move_uploaded_file($file['tmp_name'], $filepath)) {
        return ['success' => true, 'path' => 'uploads/receipts/' . $filename];
    }
    
    return ['success' => false, 'message' => 'Upload failed'];
}

/**
 * Profile Strength & Setup Completion Engine
 * Calculates a gamified score (0-100%) and actionable checklist for the workspace.
 *
 * @param int $tenant_id
 * @param mysqli $conn
 * @return array
 */
function getProfileStrength($tenant_id, $conn) {
    $tenant_id = (int)$tenant_id;
    if ($tenant_id <= 0 || !is_object($conn)) {
        return [
            'score' => 0,
            'tier' => 'Starter',
            'tier_icon' => '🥉',
            'tier_color' => '#f59e0b',
            'message' => 'Complete your setup to build trust.',
            'completed_count' => 0,
            'total_items' => 6,
            'items' => []
        ];
    }

    ensureBoosterColumns($conn);

    // 1. Fetch tenant branding info
    $tenant = null;
    $t_stmt = $conn->prepare("SELECT company_name, email, phone, logo, banner FROM tenants WHERE id = ? LIMIT 1");
    if ($t_stmt) {
        $t_stmt->bind_param("i", $tenant_id);
        $t_stmt->execute();
        $tenant = $t_stmt->get_result()->fetch_assoc();
        $t_stmt->close();
    }

    // 2. Fetch primary company profile
    $cust = null;
    $c_stmt = $conn->prepare("SELECT * FROM customers WHERE tenant_id = ? ORDER BY id ASC LIMIT 1");
    if ($c_stmt) {
        $c_stmt->bind_param("i", $tenant_id);
        $c_stmt->execute();
        $cust = $c_stmt->get_result()->fetch_assoc();
        $c_stmt->close();
    }

    // 3. Count rating questions
    $question_count = 0;
    $q_stmt = $conn->prepare("SELECT COUNT(*) cnt FROM rating_questions WHERE tenant_id = ? AND is_active = 1");
    if ($q_stmt) {
        $q_stmt->bind_param("i", $tenant_id);
        $q_stmt->execute();
        $question_count = (int)($q_stmt->get_result()->fetch_assoc()['cnt'] ?? 0);
        $q_stmt->close();
    }

    // 4. Count reviews and verified reviews
    $total_reviews = 0;
    $verified_reviews = 0;
    $r_stmt = $conn->prepare("SELECT COUNT(*) cnt, SUM(CASE WHEN is_verified = 1 THEN 1 ELSE 0 END) verified_cnt FROM ratings r JOIN customers c ON r.company_id = c.id WHERE c.tenant_id = ?");
    if ($r_stmt) {
        $r_stmt->bind_param("i", $tenant_id);
        $r_stmt->execute();
        $r_row = $r_stmt->get_result()->fetch_assoc();
        $total_reviews = (int)($r_row['cnt'] ?? 0);
        $verified_reviews = (int)($r_row['verified_cnt'] ?? 0);
        $r_stmt->close();
    }

    // Evaluate the 6 Pillars:
    // Pillar 1: Company Essentials (15 pts)
    $has_basics = !empty($cust['company_name']) 
        && !empty($cust['category_id']) 
        && (!empty($cust['email']) || !empty($cust['phone']))
        && !empty($cust['address']);

    // Pillar 2: WhatsApp Chat (15 pts)
    $has_whatsapp = !empty($cust['whatsapp_number']) && strlen(whatsappDigits($cust['whatsapp_number'])) >= 7;

    // Pillar 3: Google Review Booster (15 pts)
    $has_google_booster = !empty($cust['google_store_url']) && !empty($cust['booster_enabled']);

    // Pillar 4: Brand Identity (Logo) (15 pts)
    $has_logo = !empty($tenant['logo']);

    // Pillar 5: Rating Questions (20 pts)
    $has_questions = $question_count >= 1;

    // Pillar 6: Customer Reviews & Social Proof (20 pts)
    $review_pts = 0;
    if ($total_reviews >= 3 || $verified_reviews >= 1) {
        $review_pts = 20;
    } elseif ($total_reviews >= 1) {
        $review_pts = 10;
    }

    $items = [
        [
            'id' => 'basics',
            'title' => 'Company Profile & Address',
            'desc' => 'Business category, address, and contact information.',
            'weight' => 15,
            'earned' => $has_basics ? 15 : 0,
            'done' => $has_basics,
            'action_url' => 'company.php',
            'action_label' => $has_basics ? 'Edit Profile' : 'Complete Profile ↗'
        ],
        [
            'id' => 'whatsapp',
            'title' => 'WhatsApp Direct Chat',
            'desc' => 'Add your WhatsApp number for 1-click customer inquiries.',
            'weight' => 15,
            'earned' => $has_whatsapp ? 15 : 0,
            'done' => $has_whatsapp,
            'action_url' => 'company.php#whatsapp_number',
            'action_label' => $has_whatsapp ? 'Configured ✓' : 'Add WhatsApp ↗'
        ],
        [
            'id' => 'google_booster',
            'title' => 'Google Review Booster & Gating',
            'desc' => 'Boost Google 5-star ratings and gate negative complaints.',
            'weight' => 15,
            'earned' => $has_google_booster ? 15 : 0,
            'done' => $has_google_booster,
            'action_url' => 'company.php#google_store_url',
            'action_label' => $has_google_booster ? 'Active ✓' : 'Connect Google ↗'
        ],
        [
            'id' => 'branding',
            'title' => 'Company Logo & Branding',
            'desc' => 'Upload your brand logo for public pages and widgets.',
            'weight' => 15,
            'earned' => $has_logo ? 15 : 0,
            'done' => $has_logo,
            'action_url' => 'settings.php#tab=profile',
            'action_label' => $has_logo ? 'Uploaded ✓' : 'Upload Logo ↗'
        ],
        [
            'id' => 'questions',
            'title' => 'Rating Questions (' . $question_count . ' active)',
            'desc' => 'Ask targeted feedback questions (staff, speed, quality).',
            'weight' => 20,
            'earned' => $has_questions ? 20 : 0,
            'done' => $has_questions,
            'action_url' => 'ratings.php#tab=questions',
            'action_label' => $has_questions ? 'Manage Questions' : 'Add Questions ↗'
        ],
        [
            'id' => 'reviews',
            'title' => 'Customer Feedback (' . $total_reviews . ' review' . ($total_reviews === 1 ? '' : 's') . ')',
            'desc' => $verified_reviews > 0 ? $verified_reviews . ' verified review(s) collected.' : 'Collect at least 3 customer reviews or 1 verified review.',
            'weight' => 20,
            'earned' => $review_pts,
            'done' => ($review_pts >= 20),
            'action_url' => 'whatsapp_sender.php',
            'action_label' => ($review_pts >= 20) ? 'Send WhatsApp Invite' : 'Send WhatsApp Invite ↗'
        ]
    ];

    $total_score = 0;
    $completed_count = 0;
    foreach ($items as $item) {
        $total_score += $item['earned'];
        if ($item['done']) {
            $completed_count++;
        }
    }
    $total_score = min(100, max(0, $total_score));

    // Determine Tier
    if ($total_score >= 90) {
        $tier = 'All-Star Business';
        $tier_icon = '🏆';
        $tier_color = '#c2f542';
        $message = 'Outstanding! Your workspace is fully optimized for customer trust and Google conversions.';
    } elseif ($total_score >= 70) {
        $tier = 'Trusted Business';
        $tier_icon = '🥇';
        $tier_color = '#10b981';
        $message = 'High-trust profile! Complete the final steps to unlock All-Star status.';
    } elseif ($total_score >= 40) {
        $tier = 'Growing';
        $tier_icon = '🥈';
        $tier_color = '#3b82f6';
        $message = 'Good progress! Add conversion tools to boost your customer response rate.';
    } else {
        $tier = 'Starter';
        $tier_icon = '🥉';
        $tier_color = '#f59e0b';
        $message = 'Profile incomplete. Complete the checklist to build customer credibility.';
    }

    return [
        'score' => $total_score,
        'tier' => $tier,
        'tier_icon' => $tier_icon,
        'tier_color' => $tier_color,
        'message' => $message,
        'completed_count' => $completed_count,
        'total_items' => count($items),
        'items' => $items,
        'has_basics' => $has_basics,
        'has_whatsapp' => $has_whatsapp,
        'has_google_booster' => $has_google_booster,
        'has_logo' => $has_logo,
        'has_questions' => $has_questions,
        'total_reviews' => $total_reviews,
        'verified_reviews' => $verified_reviews
    ];
}

/**
 * Auto-ensure review_invites table exists in database.
 */
function ensureInviteTable($conn) {
    static $done = false;
    if ($done || !is_object($conn) || !method_exists($conn, 'query')) {
        return;
    }
    $done = true;

    $sql = "CREATE TABLE IF NOT EXISTS review_invites (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT NOT NULL,
        customer_name VARCHAR(100) NOT NULL,
        customer_phone VARCHAR(30) NOT NULL,
        order_ref VARCHAR(100) NULL,
        template_key VARCHAR(50) NOT NULL,
        invite_message TEXT NOT NULL,
        channel VARCHAR(20) NOT NULL DEFAULT 'whatsapp',
        status VARCHAR(20) NOT NULL DEFAULT 'sent',
        sent_at DATETIME NOT NULL,
        created_at DATETIME NOT NULL,
        INDEX idx_tenant (tenant_id),
        INDEX idx_sent (sent_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    @$conn->query($sql);
}

/**
 * Build a personalized public review link with prefilled parameters.
 */
function buildPersonalizedRatingUrl($base_public_url, $customer_name = '', $ref_code = '', $channel = 'wa') {
    $url = $base_public_url;
    $params = [];
    if ($channel !== '') {
        $params['ref'] = $channel;
    }
    if (trim((string)$customer_name) !== '') {
        $params['name'] = trim((string)$customer_name);
    }
    if (trim((string)$ref_code) !== '') {
        $params['ref_code'] = trim((string)$ref_code);
    }
    if (!empty($params)) {
        $sep = (strpos($url, '?') !== false) ? '&' : '?';
        $url .= $sep . http_build_query($params);
    }
    return $url;
}

/**
 * Returns available WhatsApp review request templates with pre-compiled content.
 */
function getWhatsAppTemplates($business_name, $review_url, $customer_name = '', $order_ref = '') {
    $cname = trim((string)$customer_name);
    $bname = trim((string)$business_name) ?: 'our team';
    $name_salutation = $cname !== '' ? ' ' . $cname : '';
    $ref_note = trim((string)$order_ref) !== '' ? ' (Ref: ' . trim((string)$order_ref) . ')' : '';

    return [
        'retail_service' => [
            'key' => 'retail_service',
            'title' => 'Post-Visit / Retail & Dine-in',
            'subtitle' => 'Ideal right after an in-store purchase or restaurant meal',
            'badge' => 'Most Popular',
            'template' => "Hi{name}! 👋 Thank you for visiting {business} today! We hope you had a great experience.\n\nCould you please take 30 seconds to drop us a quick review? Your feedback means the world to our team: {link}\n\nThank you for choosing us! 🙏",
            'compiled' => "Hi{$name_salutation}! 👋 Thank you for visiting {$bname} today! We hope you had a great experience.\n\nCould you please take 30 seconds to drop us a quick review? Your feedback means the world to our team: {$review_url}\n\nThank you for choosing us! 🙏"
        ],
        'delivery_order' => [
            'key' => 'delivery_order',
            'title' => 'Delivery / Online Order',
            'subtitle' => 'Perfect after delivering goods or fulfilling a customer order',
            'badge' => 'Orders',
            'template' => "Hello{name}! 📦 We hope you loved your recent order from {business}{order}.\n\nHow did everything go? Let us know with a quick 30-second review here: {link}\n\nWe really appreciate your support! ✨",
            'compiled' => "Hello{$name_salutation}! 📦 We hope you loved your recent order from {$bname}{$ref_note}.\n\nHow did everything go? Let us know with a quick 30-second review here: {$review_url}\n\nWe really appreciate your support! ✨"
        ],
        'quick_favor' => [
            'key' => 'quick_favor',
            'title' => 'Quick & Casual (High Conversion)',
            'subtitle' => 'Short and sweet message with highest click-through rate',
            'badge' => 'Highest CTR',
            'template' => "Hi{name}, quick favor! 🙏 If you have 30 seconds, could you leave {business} a quick rating on our official page? {link}\n\nIt takes less than a minute and helps us a lot. Thank you! ⭐",
            'compiled' => "Hi{$name_salutation}, quick favor! 🙏 If you have 30 seconds, could you leave {$bname} a quick rating on our official page? {$review_url}\n\nIt takes less than a minute and helps us a lot. Thank you! ⭐"
        ],
        'momo_verified' => [
            'key' => 'momo_verified',
            'title' => 'Verified Customer / MoMo Badge',
            'subtitle' => 'Encourages customers to attach MoMo receipt for a green badge',
            'badge' => 'Verified',
            'template' => "Hi{name}! 🌟 Thanks for your purchase at {business}. Share your experience and add your MoMo reference{order} to receive an official Verified Customer badge: {link}\n\nThank you for trusting us!",
            'compiled' => "Hi{$name_salutation}! 🌟 Thanks for your purchase at {$bname}. Share your experience and add your MoMo reference{$ref_note} to receive an official Verified Customer badge: {$review_url}\n\nThank you for trusting us!"
        ]
    ];
}

/**
 * Records an invitation sent to a customer.
 */
function logReviewInvite($conn, $tenant_id, $customer_name, $customer_phone, $order_ref, $template_key, $message) {
    if (!is_object($conn) || !method_exists($conn, 'prepare')) {
        return false;
    }
    ensureInviteTable($conn);
    $tenant_id = (int)$tenant_id;
    $cname = trim((string)$customer_name);
    $cphone = whatsappDigits($customer_phone) ?: trim((string)$customer_phone);
    $oref = trim((string)$order_ref);
    $tkey = trim((string)$template_key) ?: 'retail_service';
    $msg = trim((string)$message);
    $now = date('Y-m-d H:i:s');

    $stmt = $conn->prepare("INSERT INTO review_invites (tenant_id, customer_name, customer_phone, order_ref, template_key, invite_message, channel, status, sent_at, created_at) VALUES (?, ?, ?, ?, ?, ?, 'whatsapp', 'sent', ?, ?)");
    if (!$stmt) return false;
    $stmt->bind_param("isssssss", $tenant_id, $cname, $cphone, $oref, $tkey, $msg, $now, $now);
    $res = $stmt->execute();
    $stmt->close();
    return $res;
}

/**
 * Fetches recent review invitations sent by a tenant.
 */
function getRecentInvites($conn, $tenant_id, $limit = 10) {
    if (!is_object($conn) || !method_exists($conn, 'prepare')) {
        return [];
    }
    ensureInviteTable($conn);
    $tenant_id = (int)$tenant_id;
    $limit = max(1, min(100, (int)$limit));

    $stmt = $conn->prepare("SELECT * FROM review_invites WHERE tenant_id = ? ORDER BY sent_at DESC LIMIT ?");
    if (!$stmt) return [];
    $stmt->bind_param("ii", $tenant_id, $limit);
    $stmt->execute();
    $res = $stmt->get_result();
    $invites = [];
    while ($row = $res->fetch_assoc()) {
        $invites[] = $row;
    }
    $stmt->close();
    return $invites;
}

/**
 * Auto-ensure community_questions and qa_helpful_votes tables exist in database.
 */
function ensureQaTable($conn) {
    static $done = false;
    if ($done || !is_object($conn) || !method_exists($conn, 'query')) {
        return;
    }
    $done = true;

    $sql1 = "CREATE TABLE IF NOT EXISTS community_questions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT NOT NULL,
        company_id INT NOT NULL,
        customer_name VARCHAR(100) NOT NULL,
        customer_email VARCHAR(100) NULL,
        customer_phone VARCHAR(30) NULL,
        question_text TEXT NOT NULL,
        official_answer TEXT NULL,
        answered_by INT NULL,
        answered_at DATETIME NULL,
        helpful_count INT NOT NULL DEFAULT 0,
        is_pinned TINYINT(1) NOT NULL DEFAULT 0,
        status ENUM('published', 'pending', 'hidden') NOT NULL DEFAULT 'published',
        created_at DATETIME NOT NULL,
        INDEX idx_comp_status (company_id, status),
        INDEX idx_tenant (tenant_id),
        INDEX idx_pinned (is_pinned)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    @$conn->query($sql1);

    $sql2 = "CREATE TABLE IF NOT EXISTS qa_helpful_votes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        question_id INT NOT NULL,
        voter_ip VARCHAR(50) NOT NULL,
        created_at DATETIME NOT NULL,
        UNIQUE KEY uniq_qa_vote (question_id, voter_ip)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    @$conn->query($sql2);
}

/**
 * Auto-ensure rating_replies table exists in database.
 */
function ensureRatingRepliesTable($conn) {
    static $done = false;
    if ($done || !is_object($conn) || !method_exists($conn, 'query')) {
        return;
    }
    $done = true;

    $sql = "CREATE TABLE IF NOT EXISTS rating_replies (
        id INT AUTO_INCREMENT PRIMARY KEY,
        rating_id INT NOT NULL,
        company_id INT NOT NULL,
        user_name VARCHAR(100) NOT NULL DEFAULT 'Guest',
        user_email VARCHAR(100) NULL,
        reply_text TEXT NOT NULL,
        is_official TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_rating (rating_id),
        INDEX idx_company (company_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    @$conn->query($sql);
}

/**
 * Fetch all replies for a company's ratings, grouped by rating_id.
 */
function getRatingRepliesMap($conn, $company_id) {
    if (!is_object($conn) || !method_exists($conn, 'prepare')) {
        return [];
    }
    ensureRatingRepliesTable($conn);
    $company_id = (int)$company_id;
    $map = [];

    $stmt = $conn->prepare("SELECT rr.* FROM rating_replies rr INNER JOIN ratings r ON rr.rating_id = r.id WHERE r.company_id = ? ORDER BY rr.created_at ASC");
    if ($stmt) {
        $stmt->bind_param("i", $company_id);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $rid = (int)$row['rating_id'];
            if (!isset($map[$rid])) {
                $map[$rid] = [];
            }
            $map[$rid][] = $row;
        }
        $stmt->close();
    }
    return $map;
}

/**
 * Fetch questions for public portal or admin view.
 */
function getCommunityQuestions($company_id, $conn, $only_published = true, $search = '') {
    if (!is_object($conn) || !method_exists($conn, 'prepare')) {
        return [];
    }
    ensureQaTable($conn);
    $company_id = (int)$company_id;

    $sql = "SELECT q.*, c.company_name FROM community_questions q JOIN customers c ON q.company_id = c.id WHERE q.company_id = ?";
    $params = [$company_id];
    $types = "i";

    if ($only_published) {
        $sql .= " AND q.status = 'published'";
    } else {
        $sql .= " AND q.status != 'hidden'";
    }

    if (trim((string)$search) !== '') {
        $sql .= " AND (q.question_text LIKE ? OR q.official_answer LIKE ?)";
        $sTerm = '%' . trim((string)$search) . '%';
        $params[] = $sTerm;
        $params[] = $sTerm;
        $types .= "ss";
    }

    $sql .= " ORDER BY q.is_pinned DESC, q.helpful_count DESC, q.created_at DESC";

    $stmt = $conn->prepare($sql);
    if (!$stmt) return [];
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    $list = [];
    while ($row = $res->fetch_assoc()) {
        $list[] = $row;
    }
    $stmt->close();
    return $list;
}

/**
 * Submit a customer question.
 */
function submitCommunityQuestion($conn, $company_id, $tenant_id, $name, $email = '', $question = '', $phone = '') {
    if (!is_object($conn) || !method_exists($conn, 'prepare')) {
        return false;
    }
    ensureQaTable($conn);
    $company_id = (int)$company_id;
    $tenant_id  = (int)$tenant_id;
    $cname      = trim((string)$name) ?: 'Prospective Customer';
    $cemail     = trim((string)$email) ?: null;
    $cphone     = whatsappDigits($phone) ?: null;
    $qtext      = trim((string)$question);

    if ($company_id <= 0 || $qtext === '') {
        return false;
    }

    $now = date('Y-m-d H:i:s');
    $stmt = $conn->prepare("INSERT INTO community_questions (tenant_id, company_id, customer_name, customer_email, customer_phone, question_text, status, created_at) VALUES (?, ?, ?, ?, ?, ?, 'published', ?)");
    if (!$stmt) return false;
    $stmt->bind_param("iisssss", $tenant_id, $company_id, $cname, $cemail, $cphone, $qtext, $now);
    $res = $stmt->execute();
    $insId = $stmt->insert_id;
    $stmt->close();
    return $res ? $insId : false;
}

/**
 * Answer a question from admin panel.
 */
function answerCommunityQuestion($conn, $question_id, $tenant_id, $official_answer, $admin_id = null) {
    if (!is_object($conn) || !method_exists($conn, 'prepare')) {
        return false;
    }
    ensureQaTable($conn);
    $qid = (int)$question_id;
    $tid = (int)$tenant_id;
    $ans = trim((string)$official_answer);
    $now = date('Y-m-d H:i:s');

    if ($tid > 0) {
        $stmt = $conn->prepare("UPDATE community_questions SET official_answer = ?, answered_by = ?, answered_at = ?, status = 'published' WHERE id = ? AND tenant_id = ?");
        if (!$stmt) return false;
        $stmt->bind_param("sisii", $ans, $admin_id, $now, $qid, $tid);
    } else {
        $stmt = $conn->prepare("UPDATE community_questions SET official_answer = ?, answered_by = ?, answered_at = ?, status = 'published' WHERE id = ?");
        if (!$stmt) return false;
        $stmt->bind_param("sisi", $ans, $admin_id, $now, $qid);
    }
    $res = $stmt->execute();
    $stmt->close();
    return $res;
}

/**
 * Toggle pinned status for FAQ.
 */
function togglePinQuestion($conn, $question_id, $tenant_id) {
    if (!is_object($conn) || !method_exists($conn, 'prepare')) {
        return false;
    }
    ensureQaTable($conn);
    $qid = (int)$question_id;
    $tid = (int)$tenant_id;

    if ($tid > 0) {
        $stmt = $conn->prepare("UPDATE community_questions SET is_pinned = IF(is_pinned=1, 0, 1) WHERE id = ? AND tenant_id = ?");
        if (!$stmt) return false;
        $stmt->bind_param("ii", $qid, $tid);
    } else {
        $stmt = $conn->prepare("UPDATE community_questions SET is_pinned = IF(is_pinned=1, 0, 1) WHERE id = ?");
        if (!$stmt) return false;
        $stmt->bind_param("i", $qid);
    }
    $res = $stmt->execute();
    $stmt->close();
    return $res;
}

/**
 * Record a helpful upvote for a question.
 */
function voteQuestionHelpful($conn, $question_id) {
    if (!is_object($conn) || !method_exists($conn, 'prepare')) {
        return ['success' => false, 'message' => 'Database error'];
    }
    ensureQaTable($conn);
    $qid = (int)$question_id;
    $ip  = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

    $chk = $conn->prepare("SELECT id FROM qa_helpful_votes WHERE question_id = ? AND voter_ip = ?");
    if (!$chk) return ['success' => false, 'message' => 'Database error'];
    $chk->bind_param("is", $qid, $ip);
    $chk->execute();
    if ($chk->get_result()->num_rows > 0) {
        $chk->close();
        return ['success' => false, 'message' => 'Already voted helpful'];
    }
    $chk->close();

    $now = date('Y-m-d H:i:s');
    $ins = $conn->prepare("INSERT INTO qa_helpful_votes (question_id, voter_ip, created_at) VALUES (?, ?, ?)");
    if (!$ins) return ['success' => false, 'message' => 'Database error'];
    $ins->bind_param("iss", $qid, $ip, $now);
    if ($ins->execute()) {
        $ins->close();
        $conn->query("UPDATE community_questions SET helpful_count = helpful_count + 1 WHERE id = $qid");
        return ['success' => true, 'message' => 'Helpful vote recorded'];
    }
    $ins->close();
    return ['success' => false, 'message' => 'Error recording vote'];
}

/**
 * Fetch questions for admin management console with filtering & search.
 */
function getAdminCommunityQuestions($conn, $tenant_id, $filter = 'all', $search = '') {
    if (!is_object($conn) || !method_exists($conn, 'prepare')) return [];
    ensureQaTable($conn);
    $tenant_id = (int)$tenant_id;
    
    $sql = "SELECT q.*, c.company_name 
            FROM community_questions q 
            LEFT JOIN customers c ON q.company_id = c.id 
            WHERE 1=1";
    $params = [];
    $types = "";

    if ($tenant_id > 0) {
        $sql .= " AND (q.tenant_id = ? OR c.tenant_id = ?)";
        $params[] = $tenant_id;
        $params[] = $tenant_id;
        $types .= "ii";
    }

    if ($filter === 'unanswered') {
        $sql .= " AND (q.official_answer IS NULL OR TRIM(q.official_answer) = '')";
    } elseif ($filter === 'answered') {
        $sql .= " AND (q.official_answer IS NOT NULL AND TRIM(q.official_answer) != '')";
    } elseif ($filter === 'pinned') {
        $sql .= " AND q.is_pinned = 1";
    }

    if (trim((string)$search) !== '') {
        $sql .= " AND (q.question_text LIKE ? OR q.official_answer LIKE ? OR q.customer_name LIKE ?)";
        $sTerm = '%' . trim((string)$search) . '%';
        $params[] = $sTerm;
        $params[] = $sTerm;
        $params[] = $sTerm;
        $types .= "sss";
    }

    $sql .= " ORDER BY q.is_pinned DESC, (q.official_answer IS NULL OR TRIM(q.official_answer) = '') DESC, q.created_at DESC";

    if (!empty($params)) {
        $stmt = $conn->prepare($sql);
        if (!$stmt) return [];
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
    } else {
        $res = $conn->query($sql);
    }
    
    $list = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $list[] = $row;
        }
    }
    return $list;
}

/**
 * Create a pre-emptive management FAQ.
 */
function createPreEmptiveFaq($conn, $tenant_id, $company_id, $question, $answer, $is_pinned = 1, $admin_id = null) {
    if (!is_object($conn) || !method_exists($conn, 'prepare')) return false;
    ensureQaTable($conn);
    $tenant_id  = (int)$tenant_id;
    $company_id = (int)$company_id;
    $qtext      = trim((string)$question);
    $atext      = trim((string)$answer);
    $pinned     = $is_pinned ? 1 : 0;
    $now        = date('Y-m-d H:i:s');

    if ($qtext === '' || $atext === '') return false;

    $stmt = $conn->prepare("INSERT INTO community_questions (tenant_id, company_id, customer_name, question_text, official_answer, answered_by, answered_at, is_pinned, status, created_at) VALUES (?, ?, 'Management FAQ', ?, ?, ?, ?, ?, 'published', ?)");
    if (!$stmt) return false;
    $stmt->bind_param("iissssis", $tenant_id, $company_id, $qtext, $atext, $admin_id, $now, $pinned, $now);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

/**
 * Delete a community question.
 */
function deleteCommunityQuestion($conn, $question_id, $tenant_id) {
    if (!is_object($conn) || !method_exists($conn, 'prepare')) return false;
    ensureQaTable($conn);
    $qid = (int)$question_id;
    $tid = (int)$tenant_id;

    if ($tid > 0) {
        $stmt = $conn->prepare("DELETE FROM community_questions WHERE id = ? AND tenant_id = ?");
        if (!$stmt) return false;
        $stmt->bind_param("ii", $qid, $tid);
    } else {
        $stmt = $conn->prepare("DELETE FROM community_questions WHERE id = ?");
        if (!$stmt) return false;
        $stmt->bind_param("i", $qid);
    }
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

/**
 * ============================================================
 *  Feature #6: Performance & Interaction Analytics Helpers
 * ============================================================
 */

/**
 * Ensure analytics_events table exists.
 */
function ensureAnalyticsTable($conn) {
    static $done = false;
    if ($done || !is_object($conn) || !method_exists($conn, 'query')) {
        return;
    }
    $done = true;

    $sql = "CREATE TABLE IF NOT EXISTS analytics_events (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT NOT NULL,
        company_id INT NOT NULL,
        event_type VARCHAR(40) NOT NULL,
        event_category VARCHAR(60) NULL,
        event_label VARCHAR(255) NULL,
        traffic_source VARCHAR(50) NOT NULL DEFAULT 'direct',
        utm_source VARCHAR(60) NULL,
        utm_medium VARCHAR(60) NULL,
        utm_campaign VARCHAR(100) NULL,
        utm_content VARCHAR(100) NULL,
        click_id VARCHAR(120) NULL,
        page_url VARCHAR(255) NULL,
        referrer VARCHAR(255) NULL,
        session_id VARCHAR(64) NULL,
        visitor_ip VARCHAR(45) NULL,
        user_agent VARCHAR(255) NULL,
        device_type VARCHAR(20) DEFAULT 'desktop',
        created_at DATETIME NOT NULL,
        INDEX idx_tenant_created (tenant_id, created_at),
        INDEX idx_comp_event_created (company_id, event_type, created_at),
        INDEX idx_type_created (event_type, created_at),
        INDEX idx_source (traffic_source),
        INDEX idx_utm_camp (tenant_id, utm_campaign),
        INDEX idx_click_id (click_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    @$conn->query($sql);

    // Self-healing migration for existing tables: check if utm_campaign column exists
    $col_check = @$conn->query("SHOW COLUMNS FROM analytics_events LIKE 'utm_campaign'");
    if ($col_check && $col_check->num_rows === 0) {
        @$conn->query("ALTER TABLE analytics_events 
            ADD COLUMN utm_source VARCHAR(60) NULL AFTER traffic_source,
            ADD COLUMN utm_medium VARCHAR(60) NULL AFTER utm_source,
            ADD COLUMN utm_campaign VARCHAR(100) NULL AFTER utm_medium,
            ADD COLUMN utm_content VARCHAR(100) NULL AFTER utm_campaign,
            ADD COLUMN click_id VARCHAR(120) NULL AFTER utm_content,
            ADD INDEX idx_utm_camp (tenant_id, utm_campaign),
            ADD INDEX idx_click_id (click_id)");
    }
    if ($col_check) {
        $col_check->close();
    }
}

/**
 * Detect client device type from User-Agent string.
 */
function detectDeviceType($user_agent = '') {
    $ua = strtolower((string)$user_agent);
    if ($ua === '' && !empty($_SERVER['HTTP_USER_AGENT'])) {
        $ua = strtolower($_SERVER['HTTP_USER_AGENT']);
    }
    if (preg_match('/(ipad|tablet|(android(?!.*mobile))|(windows(?!.*phone)(.*touch))|kindle|playbook|silk)/i', $ua)) {
        return 'tablet';
    }
    if (preg_match('/(mobi|iphone|ipod|phone|blackberry|opera mini|fennec|minimo|symbian|psp|android)/i', $ua)) {
        return 'mobile';
    }
    return 'desktop';
}

/**
 * Log an analytics event (page view, WhatsApp click, QR scan, etc.).
 */
function logAnalyticsEvent($conn, $tenant_id, $company_id, $event_type, $event_category = '', $event_label = '', $traffic_source = 'direct', $page_url = '', $referrer = '', $session_id = '', $visitor_ip = '', $user_agent = '', $utm_source = '', $utm_medium = '', $utm_campaign = '', $utm_content = '', $click_id = '') {
    if (!is_object($conn) || !method_exists($conn, 'prepare')) {
        return false;
    }
    ensureAnalyticsTable($conn);

    $tenant_id  = (int)$tenant_id;
    $company_id = (int)$company_id;
    $event_type = strtolower(trim((string)$event_type));
    if ($event_type === '') return false;

    $category   = mb_substr(trim((string)$event_category), 0, 60);
    $label      = mb_substr(trim((string)$event_label), 0, 255);
    $source     = mb_substr(strtolower(trim((string)$traffic_source)), 0, 50) ?: 'direct';
    $utm_s      = mb_substr(trim((string)$utm_source), 0, 60);
    $utm_m      = mb_substr(trim((string)$utm_medium), 0, 60);
    $utm_c      = mb_substr(trim((string)$utm_campaign), 0, 100);
    $utm_cnt    = mb_substr(trim((string)$utm_content), 0, 100);
    $cid        = mb_substr(trim((string)$click_id), 0, 120);
    $url        = mb_substr(trim((string)$page_url), 0, 255);
    $ref        = mb_substr(trim((string)$referrer), 0, 255);
    $sess       = mb_substr(trim((string)$session_id), 0, 64);
    $ip         = mb_substr(trim((string)$visitor_ip), 0, 45) ?: ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
    $ua         = mb_substr(trim((string)$user_agent), 0, 255) ?: ($_SERVER['HTTP_USER_AGENT'] ?? '');
    $device     = detectDeviceType($ua);
    $now        = date('Y-m-d H:i:s');

    $stmt = $conn->prepare("INSERT INTO analytics_events (tenant_id, company_id, event_type, event_category, event_label, traffic_source, utm_source, utm_medium, utm_campaign, utm_content, click_id, page_url, referrer, session_id, visitor_ip, user_agent, device_type, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    if (!$stmt) return false;
    $stmt->bind_param("iissssssssssssssss", $tenant_id, $company_id, $event_type, $category, $label, $source, $utm_s, $utm_m, $utm_c, $utm_cnt, $cid, $url, $ref, $sess, $ip, $ua, $device, $now);
    $res = $stmt->execute();
    $insId = $stmt->insert_id;
    $stmt->close();
    return $res ? $insId : false;
}

/**
 * Fetch high-level interaction metrics for an admin reporting period.
 */
function getInteractionMetrics($conn, $tenant_id, $company_id = 0, $days = 30) {
    if (!is_object($conn) || !method_exists($conn, 'query')) {
        return ['page_views' => 0, 'unique_visitors' => 0, 'whatsapp_clicks' => 0, 'qr_scans' => 0, 'form_starts' => 0, 'reviews_submitted' => 0, 'lead_ctr' => 0, 'review_cvr' => 0];
    }
    ensureAnalyticsTable($conn);
    $tenant_id  = (int)$tenant_id;
    $company_id = (int)$company_id;
    $days       = max(1, (int)$days);
    $cutoff     = date('Y-m-d H:i:s', strtotime("-{$days} days"));

    $where = " WHERE created_at >= '{$cutoff}'";
    if ($tenant_id > 0) {
        $where .= " AND tenant_id = {$tenant_id}";
    }
    if ($company_id > 0) {
        $where .= " AND company_id = {$company_id}";
    }

    // Aggregated counts
    $sql = "SELECT 
        SUM(CASE WHEN event_type = 'page_view' THEN 1 ELSE 0 END) AS page_views,
        COUNT(DISTINCT CASE WHEN event_type = 'page_view' THEN COALESCE(NULLIF(session_id, ''), visitor_ip) ELSE NULL END) AS unique_visitors,
        SUM(CASE WHEN event_type = 'whatsapp_click' THEN 1 ELSE 0 END) AS whatsapp_clicks,
        SUM(CASE WHEN (event_type = 'qr_scan' OR traffic_source = 'qr') THEN 1 ELSE 0 END) AS qr_scans,
        SUM(CASE WHEN event_type = 'form_start' THEN 1 ELSE 0 END) AS form_starts,
        SUM(CASE WHEN event_type = 'review_submit' THEN 1 ELSE 0 END) AS reviews_submitted
    FROM analytics_events {$where}";

    $res = $conn->query($sql);
    $row = $res ? $res->fetch_assoc() : [];

    $views   = (int)($row['page_views'] ?? 0);
    $uniques = (int)($row['unique_visitors'] ?? 0);
    $wa      = (int)($row['whatsapp_clicks'] ?? 0);
    $qr      = (int)($row['qr_scans'] ?? 0);
    $starts  = (int)($row['form_starts'] ?? 0);
    $submits = (int)($row['reviews_submitted'] ?? 0);

    $lead_ctr   = $uniques > 0 ? round(($wa / $uniques) * 100, 1) : 0.0;
    $review_cvr = $views > 0 ? round(($submits / $views) * 100, 1) : 0.0;

    return [
        'page_views'        => $views,
        'unique_visitors'   => $uniques,
        'whatsapp_clicks'   => $wa,
        'qr_scans'          => $qr,
        'form_starts'       => $starts,
        'reviews_submitted' => $submits,
        'lead_ctr'          => $lead_ctr,
        'review_cvr'        => $review_cvr
    ];
}

/**
 * Fetch WhatsApp click breakdown by button placement.
 */
function getWhatsAppClicksBreakdown($conn, $tenant_id, $company_id = 0, $days = 30) {
    if (!is_object($conn) || !method_exists($conn, 'query')) return [];
    ensureAnalyticsTable($conn);
    $tenant_id  = (int)$tenant_id;
    $company_id = (int)$company_id;
    $days       = max(1, (int)$days);
    $cutoff     = date('Y-m-d H:i:s', strtotime("-{$days} days"));

    $where = " WHERE event_type = 'whatsapp_click' AND created_at >= '{$cutoff}'";
    if ($tenant_id > 0) $where .= " AND tenant_id = {$tenant_id}";
    if ($company_id > 0) $where .= " AND company_id = {$company_id}";

    $sql = "SELECT COALESCE(NULLIF(event_category, ''), 'general') AS category, COUNT(*) AS click_count 
            FROM analytics_events {$where} 
            GROUP BY category 
            ORDER BY click_count DESC";

    $res = $conn->query($sql);
    $list = [];
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $list[] = [
                'category'    => $r['category'],
                'click_count' => (int)$r['click_count']
            ];
        }
    }
    return $list;
}

/**
 * Fetch Traffic Source breakdown.
 */
function getTrafficSourceBreakdown($conn, $tenant_id, $company_id = 0, $days = 30) {
    if (!is_object($conn) || !method_exists($conn, 'query')) return [];
    ensureAnalyticsTable($conn);
    $tenant_id  = (int)$tenant_id;
    $company_id = (int)$company_id;
    $days       = max(1, (int)$days);
    $cutoff     = date('Y-m-d H:i:s', strtotime("-{$days} days"));

    $where = " WHERE event_type = 'page_view' AND created_at >= '{$cutoff}'";
    if ($tenant_id > 0) $where .= " AND tenant_id = {$tenant_id}";
    if ($company_id > 0) $where .= " AND company_id = {$company_id}";

    $sql = "SELECT traffic_source, COUNT(*) AS view_count 
            FROM analytics_events {$where} 
            GROUP BY traffic_source 
            ORDER BY view_count DESC";

    $res = $conn->query($sql);
    $list = [];
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $list[] = [
                'source'     => $r['traffic_source'],
                'view_count' => (int)$r['view_count']
            ];
        }
    }
    return $list;
}

/**
 * Fetch device breakdown (mobile vs desktop vs tablet).
 */
function getDeviceBreakdown($conn, $tenant_id, $company_id = 0, $days = 30) {
    if (!is_object($conn) || !method_exists($conn, 'query')) return [];
    ensureAnalyticsTable($conn);
    $tenant_id  = (int)$tenant_id;
    $company_id = (int)$company_id;
    $days       = max(1, (int)$days);
    $cutoff     = date('Y-m-d H:i:s', strtotime("-{$days} days"));

    $where = " WHERE event_type = 'page_view' AND created_at >= '{$cutoff}'";
    if ($tenant_id > 0) $where .= " AND tenant_id = {$tenant_id}";
    if ($company_id > 0) $where .= " AND company_id = {$company_id}";

    $sql = "SELECT COALESCE(NULLIF(device_type, ''), 'desktop') AS device, COUNT(*) AS count 
            FROM analytics_events {$where} 
            GROUP BY device 
            ORDER BY count DESC";

    $res = $conn->query($sql);
    $list = [];
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $list[] = [
                'device' => $r['device'],
                'count'  => (int)$r['count']
            ];
        }
    }
    return $list;
}

/**
 * Fetch daily interaction trend (page views, WhatsApp clicks, reviews submitted) over period.
 */
function getDailyInteractionTrend($conn, $tenant_id, $company_id = 0, $days = 14) {
    if (!is_object($conn) || !method_exists($conn, 'query')) return [];
    ensureAnalyticsTable($conn);
    $tenant_id  = (int)$tenant_id;
    $company_id = (int)$company_id;
    $days       = max(7, min(90, (int)$days));

    // Initialize daily map
    $daily = [];
    for ($i = $days - 1; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-{$i} days"));
        $daily[$d] = [
            'date'              => $d,
            'label'             => date('M j', strtotime($d)),
            'page_views'        => 0,
            'whatsapp_clicks'   => 0,
            'reviews_submitted' => 0
        ];
    }

    $cutoff = date('Y-m-d 00:00:00', strtotime("-" . ($days - 1) . " days"));
    $where = " WHERE created_at >= '{$cutoff}'";
    if ($tenant_id > 0) $where .= " AND tenant_id = {$tenant_id}";
    if ($company_id > 0) $where .= " AND company_id = {$company_id}";

    $sql = "SELECT 
        DATE(created_at) AS event_date,
        SUM(CASE WHEN event_type = 'page_view' THEN 1 ELSE 0 END) AS page_views,
        SUM(CASE WHEN event_type = 'whatsapp_click' THEN 1 ELSE 0 END) AS whatsapp_clicks,
        SUM(CASE WHEN event_type = 'review_submit' THEN 1 ELSE 0 END) AS reviews_submitted
    FROM analytics_events {$where}
    GROUP BY DATE(created_at)";

    $res = $conn->query($sql);
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $ed = $r['event_date'];
            if (isset($daily[$ed])) {
                $daily[$ed]['page_views']        = (int)$r['page_views'];
                $daily[$ed]['whatsapp_clicks']   = (int)$r['whatsapp_clicks'];
                $daily[$ed]['reviews_submitted'] = (int)$r['reviews_submitted'];
            }
        }
    }

    return array_values($daily);
}

/**
 * Fetch ad campaign attribution breakdown for marketing analytics.
 */
function getAdCampaignAttributionBreakdown($conn, $tenant_id, $company_id = 0, $days = 30) {
    if (!is_object($conn) || !method_exists($conn, 'query')) return [];
    ensureAnalyticsTable($conn);
    $tenant_id  = (int)$tenant_id;
    $company_id = (int)$company_id;
    $days       = max(1, (int)$days);
    $cutoff     = date('Y-m-d H:i:s', strtotime("-{$days} days"));

    $where = " WHERE created_at >= '{$cutoff}'";
    if ($tenant_id > 0) $where .= " AND tenant_id = {$tenant_id}";
    if ($company_id > 0) $where .= " AND company_id = {$company_id}";

    $sql = "SELECT 
        COALESCE(NULLIF(utm_campaign, ''), '(organic / direct)') AS campaign_name,
        COALESCE(NULLIF(utm_source, ''), traffic_source) AS source_name,
        COALESCE(NULLIF(utm_medium, ''), '-') AS medium_name,
        SUM(CASE WHEN event_type = 'page_view' THEN 1 ELSE 0 END) AS page_views,
        COUNT(DISTINCT CASE WHEN event_type = 'page_view' THEN COALESCE(NULLIF(session_id, ''), visitor_ip) ELSE NULL END) AS unique_visitors,
        SUM(CASE WHEN event_type = 'whatsapp_click' THEN 1 ELSE 0 END) AS whatsapp_clicks,
        SUM(CASE WHEN event_type = 'review_submit' THEN 1 ELSE 0 END) AS reviews_submitted,
        SUM(CASE WHEN click_id IS NOT NULL AND click_id != '' THEN 1 ELSE 0 END) AS ad_clicks_tracked
    FROM analytics_events {$where}
    GROUP BY campaign_name, source_name, medium_name
    ORDER BY whatsapp_clicks DESC, page_views DESC
    LIMIT 50";

    $res = $conn->query($sql);
    $list = [];
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $uniques = (int)$r['unique_visitors'];
            $leads   = (int)$r['whatsapp_clicks'];
            $revs    = (int)$r['reviews_submitted'];
            $total_conv = $leads + $revs;
            $cvr     = $uniques > 0 ? round(($total_conv / $uniques) * 100, 1) : 0.0;
            $is_paid = (!empty($r['campaign_name']) && $r['campaign_name'] !== '(organic / direct)') ||
                       ((int)$r['ad_clicks_tracked'] > 0) ||
                       in_array(strtolower($r['medium_name']), ['cpc', 'cpm', 'paid', 'paidsocial', 'paid_social', 'ad']);

            $list[] = [
                'campaign'          => $r['campaign_name'],
                'source'            => $r['source_name'],
                'medium'            => $r['medium_name'],
                'page_views'        => (int)$r['page_views'],
                'unique_visitors'   => $uniques,
                'whatsapp_clicks'   => $leads,
                'reviews_submitted' => $revs,
                'total_conversions' => $total_conv,
                'conversion_rate'   => $cvr,
                'is_paid'           => $is_paid,
                'ad_clicks'         => (int)$r['ad_clicks_tracked']
            ];
        }
    }
    return $list;
}

/**
 * Fetch 4-stage reputation ads funnel metrics.
 */
function getAdsFunnelMetrics($conn, $tenant_id, $company_id = 0, $days = 30) {
    if (!is_object($conn) || !method_exists($conn, 'query')) {
        return [
            'stage_1_ad_clicks'     => 0,
            'stage_2_page_views'    => 0,
            'stage_3_engagements'   => 0,
            'stage_4_conversions'   => 0,
            'rate_click_to_view'    => 0.0,
            'rate_view_to_engage'   => 0.0,
            'rate_engage_to_lead'   => 0.0,
            'overall_funnel_cvr'    => 0.0
        ];
    }
    ensureAnalyticsTable($conn);
    $tenant_id  = (int)$tenant_id;
    $company_id = (int)$company_id;
    $days       = max(1, (int)$days);
    $cutoff     = date('Y-m-d H:i:s', strtotime("-{$days} days"));

    $where = " WHERE created_at >= '{$cutoff}'";
    if ($tenant_id > 0) $where .= " AND tenant_id = {$tenant_id}";
    if ($company_id > 0) $where .= " AND company_id = {$company_id}";

    $sql = "SELECT 
        SUM(CASE WHEN event_type = 'page_view' THEN 1 ELSE 0 END) AS total_views,
        COUNT(DISTINCT CASE WHEN event_type = 'page_view' THEN COALESCE(NULLIF(session_id, ''), visitor_ip) ELSE NULL END) AS unique_visitors,
        SUM(CASE WHEN event_type = 'page_view' AND ((utm_campaign IS NOT NULL AND utm_campaign != '') OR (click_id IS NOT NULL AND click_id != '') OR traffic_source IN ('ads', 'google_ads', 'facebook_ads', 'meta_ads', 'tiktok_ads')) THEN 1 ELSE 0 END) AS paid_campaign_views,
        SUM(CASE WHEN (event_type = 'form_start' OR event_category IN ('service_click', 'review_read', 'qa_read')) THEN 1 ELSE 0 END) AS engagements,
        SUM(CASE WHEN event_type = 'whatsapp_click' THEN 1 ELSE 0 END) AS whatsapp_leads,
        SUM(CASE WHEN event_type = 'review_submit' THEN 1 ELSE 0 END) AS reviews_submitted,
        SUM(CASE WHEN event_type = 'map_directions_click' THEN 1 ELSE 0 END) AS map_visits
    FROM analytics_events {$where}";

    $res = $conn->query($sql);
    $r = $res ? $res->fetch_assoc() : [];

    $total_views = (int)($r['total_views'] ?? 0);
    $paid_views  = (int)($r['paid_campaign_views'] ?? 0);
    // Estimated ad clicks (campaign views + 15% estimated bounce before telemetry)
    $ad_clicks   = $paid_views > 0 ? (int)round($paid_views * 1.12) : $total_views;
    if ($ad_clicks < $total_views && $paid_views === 0) {
        $ad_clicks = $total_views;
    }

    $engagements = (int)($r['engagements'] ?? 0);
    // If no explicit micro-engagements yet, engagement base is unique visitors who didn't bounce immediately
    if ($engagements === 0 && $total_views > 0) {
        $engagements = max(1, (int)round($total_views * 0.45));
    }

    $wa_leads    = (int)($r['whatsapp_leads'] ?? 0);
    $rev_submits = (int)($r['reviews_submitted'] ?? 0);
    $map_visits  = (int)($r['map_visits'] ?? 0);
    $conversions = $wa_leads + $rev_submits + $map_visits;

    $c_to_v = $ad_clicks > 0 ? round(($total_views / $ad_clicks) * 100, 1) : 100.0;
    $v_to_e = $total_views > 0 ? round(($engagements / $total_views) * 100, 1) : 0.0;
    $e_to_l = $engagements > 0 ? round(($conversions / $engagements) * 100, 1) : 0.0;
    $ov_cvr = $total_views > 0 ? round(($conversions / $total_views) * 100, 1) : 0.0;

    return [
        'stage_1_ad_clicks'     => $ad_clicks,
        'stage_2_page_views'    => $total_views,
        'stage_3_engagements'   => $engagements,
        'stage_4_conversions'   => $conversions,
        'whatsapp_leads'        => $wa_leads,
        'reviews_submitted'     => $rev_submits,
        'map_visits'            => $map_visits,
        'rate_click_to_view'    => min(100.0, $c_to_v),
        'rate_view_to_engage'   => min(100.0, $v_to_e),
        'rate_engage_to_lead'   => min(100.0, $e_to_l),
        'overall_funnel_cvr'    => min(100.0, $ov_cvr)
    ];
}
?>
