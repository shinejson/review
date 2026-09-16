<?php
/**
 * Optibiz REST API - Sliding Window Rate Limiter
 * Provides IP and Device Fingerprint rate limiting with HTTP 429 handling
 */
require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/response.php';

class RateLimiter {

    /**
     * Ensure rate limit table exists in MySQL
     */
    private static function ensureTable(mysqli $conn): void {
        static $checked = false;
        if ($checked) return;

        $sql = "CREATE TABLE IF NOT EXISTS `api_rate_limits` (
            `key_hash` VARCHAR(64) NOT NULL PRIMARY KEY,
            `hits` INT(11) NOT NULL DEFAULT 1,
            `reset_at` INT(11) NOT NULL,
            INDEX `idx_reset` (`reset_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        @$conn->query($sql);
        $checked = true;
    }

    /**
     * Check and enforce rate limit
     *
     * @param mysqli $conn Database connection
     * @param string $action Action key (e.g. 'submit_rating', 'auth_login')
     * @param string $identifier Unique client identifier (IP or device fingerprint)
     * @param int $maxAttempts Maximum allowed requests within window
     * @param int $windowSeconds Window duration in seconds
     * @return bool True if request is allowed, otherwise terminates with HTTP 429
     */
    public static function check(mysqli $conn, string $action, string $identifier, int $maxAttempts, int $windowSeconds): bool {
        self::ensureTable($conn);

        $now = time();
        $key = hash('sha256', $action . ':' . trim($identifier));

        // Periodic cleanup of expired rate limits (10% chance)
        if (random_int(1, 10) === 1) {
            @$conn->query("DELETE FROM api_rate_limits WHERE reset_at < $now");
        }

        // Query current limit state
        $stmt = $conn->prepare("SELECT hits, reset_at FROM api_rate_limits WHERE key_hash = ? LIMIT 1");
        $stmt->bind_param("s", $key);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res->fetch_assoc();
        $stmt->close();

        if ($row) {
            $resetAt = (int)$row['reset_at'];
            $hits    = (int)$row['hits'];

            if ($resetAt > $now) {
                if ($hits >= $maxAttempts) {
                    $retryAfter = max(1, $resetAt - $now);
                    header("Retry-After: $retryAfter");
                    header("X-RateLimit-Limit: $maxAttempts");
                    header("X-RateLimit-Remaining: 0");
                    header("X-RateLimit-Reset: $resetAt");
                    api_send_error("Too many requests. Rate limit exceeded. Please try again in {$retryAfter} seconds.", 429, [
                        'retry_after' => $retryAfter,
                        'limit'       => $maxAttempts
                    ]);
                }

                // Increment hits
                $upd = $conn->prepare("UPDATE api_rate_limits SET hits = hits + 1 WHERE key_hash = ?");
                $upd->bind_param("s", $key);
                $upd->execute();
                $upd->close();

                $remaining = max(0, $maxAttempts - ($hits + 1));
                header("X-RateLimit-Limit: $maxAttempts");
                header("X-RateLimit-Remaining: $remaining");
                header("X-RateLimit-Reset: $resetAt");
                return true;
            } else {
                // Window expired, reset
                $resetAt = $now + $windowSeconds;
                $upd = $conn->prepare("UPDATE api_rate_limits SET hits = 1, reset_at = ? WHERE key_hash = ?");
                $upd->bind_param("is", $resetAt, $key);
                $upd->execute();
                $upd->close();

                header("X-RateLimit-Limit: $maxAttempts");
                header("X-RateLimit-Remaining: " . ($maxAttempts - 1));
                header("X-RateLimit-Reset: $resetAt");
                return true;
            }
        } else {
            // First hit in window
            $resetAt = $now + $windowSeconds;
            $ins = $conn->prepare("INSERT INTO api_rate_limits (key_hash, hits, reset_at) VALUES (?, 1, ?)");
            $ins->bind_param("si", $key, $resetAt);
            $ins->execute();
            $ins->close();

            header("X-RateLimit-Limit: $maxAttempts");
            header("X-RateLimit-Remaining: " . ($maxAttempts - 1));
            header("X-RateLimit-Reset: $resetAt");
            return true;
        }
    }
}
