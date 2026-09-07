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
    $ip = $_SERVER['REMOTE_ADDR'];
    
    $check = $conn->prepare("SELECT id FROM helpful_votes WHERE rating_id = ? AND voter_ip = ?");
    $check->bind_param("is", $rating_id, $ip);
    $check->execute();
    
    if ($check->get_result()->num_rows > 0) {
        return ['success' => false, 'message' => 'Already voted'];
    }
    
    $stmt = $conn->prepare("INSERT INTO helpful_votes (rating_id, voter_ip) VALUES (?, ?)");
    $stmt->bind_param("is", $rating_id, $ip);
    
    if ($stmt->execute()) {
        $conn->query("UPDATE ratings SET helpful_count = helpful_count + 1 WHERE id = $rating_id");
        return ['success' => true, 'message' => 'Vote recorded'];
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
?>
