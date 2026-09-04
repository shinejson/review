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
?>
