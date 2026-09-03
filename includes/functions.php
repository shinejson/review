<?php
require_once __DIR__ . '/mailer.php';

function sanitize($data) {
    return htmlspecialchars(strip_tags(trim($data)));
}

function redirect($url) {
    header("Location: $url");
    exit();
}

function getAverageRating($company_id, $conn) {
    $stmt = $conn->prepare("SELECT AVG(rating) as avg_rating FROM ratings WHERE company_id = ?");
    $stmt->bind_param("i", $company_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    return round($row['avg_rating'], 1);
}

function getRatingCount($company_id, $conn) {
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM ratings WHERE company_id = ?");
    $stmt->bind_param("i", $company_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    return $row['count'];
}
?>
