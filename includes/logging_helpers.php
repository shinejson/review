<?php
/**
 * System activity logging helper functions for Optibiz
 * 
 * Provides functions to log and retrieve system activities
 * for both superadmin and admin panels.
 */

if (!function_exists('sa_ensure_system_logs_schema')) {
    /**
     * Ensure system_logs table exists
     */
    function sa_ensure_system_logs_schema($conn) {
        static $done = false;
        if ($done || !$conn) return;
        $done = true;
        @$conn->query("
            CREATE TABLE IF NOT EXISTS system_logs (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                portal VARCHAR(20) NOT NULL DEFAULT 'superadmin',
                user_id INT NULL,
                user_label VARCHAR(100) NULL,
                action VARCHAR(60) NOT NULL,
                description TEXT NULL,
                entity_type VARCHAR(40) NULL,
                entity_id INT NULL,
                ip_address VARCHAR(45) NULL,
                user_agent VARCHAR(255) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_portal (portal),
                INDEX idx_user (user_id),
                INDEX idx_action (action),
                INDEX idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }
}

if (!function_exists('sa_log_activity')) {
    /**
     * Log a system activity
     * 
     * @param mysqli $conn Database connection
     * @param string $portal 'superadmin' or 'admin'
     * @param int|null $user_id User ID
     * @param string $action Action name
     * @param string|null $description Description
     * @param string|null $entity_type Entity type
     * @param int|null $entity_id Entity ID
     * @return bool Success status
     */
    function sa_log_activity($conn, $portal, $user_id, $action, $description = null, $entity_type = null, $entity_id = null) {
        if (!$conn || !isset($portal) || !isset($action)) {
            return false;
        }
        
        sa_ensure_system_logs_schema($conn);
        
        $user_id = $user_id ? (int) $user_id : null;
        $entity_id = $entity_id ? (int) $entity_id : null;
        
        // Get user label
        $user_label = null;
        if ($user_id) {
            $portal_type = ($portal === 'superadmin') ? 'super_admins' : 'admins';
            if (function_exists('sa_one')) {
                $row = sa_one($conn, "SELECT username FROM {$portal_type} WHERE id = " . (int) $user_id, $portal_type);
                if ($row) {
                    $user_label = $row['username'];
                }
            } else {
                $res = @$conn->query("SELECT username FROM {$portal_type} WHERE id = " . (int) $user_id);
                if ($res && ($r = $res->fetch_assoc())) {
                    $user_label = $r['username'];
                }
            }
        }
        
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? null;
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        
        $stmt = $conn->prepare("
            INSERT INTO system_logs 
            (portal, user_id, user_label, action, description, entity_type, entity_id, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        if (!$stmt) {
            return false;
        }
        
        $stmt->bind_param('sisssssss', $portal, $user_id, $user_label, $action, $description, $entity_type, $entity_id, $ip_address, $user_agent);
        
        $result = $stmt->execute();
        $stmt->close();
        
        return $result;
    }
}

if (!function_exists('sa_get_logs')) {
    /**
     * Get system logs with filtering
     * 
     * @param mysqli $conn Database connection
     * @param string $portal Portal filter
     * @param array $filters Filter options
     * @return array Log entries
     */
    function sa_get_logs($conn, $portal, $filters = []) {
        if (!$conn) {
            return [];
        }
        
        sa_ensure_system_logs_schema($conn);
        
        $conditions = ["portal IN ('superadmin', 'admin')"];
        
        if ($portal && $portal !== 'all') {
            $conditions[] = "portal = '" . $conn->real_escape_string($portal) . "'";
        }
        
        if (!empty($filters['user_id'])) {
            $conditions[] = "user_id = " . (int) $filters['user_id'];
        }
        
        if (!empty($filters['action'])) {
            if (is_array($filters['action'])) {
                $escaped = array_map(function($a) use ($conn) { return "'" . $conn->real_escape_string($a) . "'"; }, $filters['action']);
                $conditions[] = "action IN (" . implode(',', $escaped) . ")";
            } else {
                $conditions[] = "action = '" . $conn->real_escape_string($filters['action']) . "'";
            }
        }
        
        if (!empty($filters['entity_type'])) {
            $conditions[] = "entity_type = '" . $conn->real_escape_string($filters['entity_type']) . "'";
        }
        
        if (!empty($filters['entity_id'])) {
            $conditions[] = "entity_id = " . (int) $filters['entity_id'];
        }
        
        if (!empty($filters['start_date'])) {
            $conditions[] = "DATE(created_at) >= '" . $conn->real_escape_string($filters['start_date']) . "'";
        }
        
        if (!empty($filters['end_date'])) {
            $conditions[] = "DATE(created_at) <= '" . $conn->real_escape_string($filters['end_date']) . "'";
        }
        
        if (!empty($filters['search'])) {
            $search = $conn->real_escape_string($filters['search']);
            $conditions[] = "(action LIKE '%{$search}%' OR description LIKE '%{$search}%' OR user_label LIKE '%{$search}%')";
        }
        
        $limit = isset($filters['limit']) ? (int) $filters['limit'] : 100;
        $offset = isset($filters['offset']) ? (int) $filters['offset'] : 0;
        
        $where_clause = implode(' AND ', $conditions);
        
        $sql = "SELECT * FROM system_logs 
                WHERE {$where_clause} 
                ORDER BY created_at DESC 
                LIMIT {$limit} OFFSET {$offset}";
        
        $result = @$conn->query($sql);
        if (!$result) {
            return [];
        }
        
        $logs = [];
        while ($row = $result->fetch_assoc()) {
            $logs[] = $row;
        }
        
        return $logs;
    }
}

if (!function_exists('sa_get_logs_count')) {
    /**
     * Get total count of logs matching filters
     * 
     * @param mysqli $conn Database connection
     * @param string $portal Portal filter
     * @param array $filters Filter options
     * @return int Count of matching logs
     */
    function sa_get_logs_count($conn, $portal, $filters = []) {
        if (!$conn) {
            return 0;
        }
        
        sa_ensure_system_logs_schema($conn);
        
        $conditions = ["portal IN ('superadmin', 'admin')"];
        
        if ($portal && $portal !== 'all') {
            $conditions[] = "portal = '" . $conn->real_escape_string($portal) . "'";
        }
        
        if (!empty($filters['user_id'])) {
            $conditions[] = "user_id = " . (int) $filters['user_id'];
        }
        
        if (!empty($filters['action'])) {
            if (is_array($filters['action'])) {
                $escaped = array_map(function($a) use ($conn) { return "'" . $conn->real_escape_string($a) . "'"; }, $filters['action']);
                $conditions[] = "action IN (" . implode(',', $escaped) . ")";
            } else {
                $conditions[] = "action = '" . $conn->real_escape_string($filters['action']) . "'";
            }
        }
        
        if (!empty($filters['entity_type'])) {
            $conditions[] = "entity_type = '" . $conn->real_escape_string($filters['entity_type']) . "'";
        }
        
        if (!empty($filters['entity_id'])) {
            $conditions[] = "entity_id = " . (int) $filters['entity_id'];
        }
        
        if (!empty($filters['start_date'])) {
            $conditions[] = "DATE(created_at) >= '" . $conn->real_escape_string($filters['start_date']) . "'";
        }
        
        if (!empty($filters['end_date'])) {
            $conditions[] = "DATE(created_at) <= '" . $conn->real_escape_string($filters['end_date']) . "'";
        }
        
        if (!empty($filters['search'])) {
            $search = $conn->real_escape_string($filters['search']);
            $conditions[] = "(action LIKE '%{$search}%' OR description LIKE '%{$search}%' OR user_label LIKE '%{$search}%')";
        }
        
        $where_clause = implode(' AND ', $conditions);
        $sql = "SELECT COUNT(*) as count FROM system_logs WHERE {$where_clause}";
        
        $result = @$conn->query($sql);
        if (!$result) {
            return 0;
        }
        
        $row = $result->fetch_assoc();
        return (int) ($row['count'] ?? 0);
    }
}

if (!function_exists('sa_get_log_actions')) {
    /**
     * Get list of all log action types
     * 
     * @param mysqli $conn Database connection
     * @param string|null $portal Portal filter
     * @return array List of actions with counts
     */
    function sa_get_log_actions($conn, $portal = null) {
        if (!$conn) {
            return [];
        }
        
        sa_ensure_system_logs_schema($conn);
        
        $sql = "SELECT action, COUNT(*) as count FROM system_logs";
        
        if ($portal && $portal !== 'all') {
            $sql .= " WHERE portal = '" . $conn->real_escape_string($portal) . "'";
        }
        
        $sql .= " GROUP BY action ORDER BY count DESC";
        
        $result = @$conn->query($sql);
        if (!$result) {
            return [];
        }
        
        $actions = [];
        while ($row = $result->fetch_assoc()) {
            $actions[] = $row;
        }
        
        return $actions;
    }
}

if (!function_exists('sa_export_logs_csv')) {
    /**
     * Export logs to CSV file
     * 
     * @param mysqli $conn Database connection
     * @param string $portal Portal filter
     * @param array $filters Filter options
     * @param string $filename Output filename
     * @return bool Success status
     */
    function sa_export_logs_csv($conn, $portal, $filters = [], $filename = 'activity_logs.csv') {
        if (!$conn) {
            return false;
        }
        
        $logs = sa_get_logs($conn, $portal, $filters);
        
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        $output = fopen('php://output', 'w');
        
        fputcsv($output, [
            'ID', 'Portal', 'User', 'Action', 'Description',
            'Entity Type', 'Entity ID', 'IP Address', 'User Agent', 'Created At'
        ]);
        
        foreach ($logs as $log) {
            fputcsv($output, [
                $log['id'],
                $log['portal'],
                $log['user_label'] ?? 'System',
                $log['action'],
                $log['description'] ?? '',
                $log['entity_type'] ?? '',
                $log['entity_id'] ?? '',
                $log['ip_address'] ?? '',
                $log['user_agent'] ?? '',
                $log['created_at']
            ]);
        }
        
        fclose($output);
        return true;
    }
}