<?php
/**
 * Optibiz REST API - Pure PHP HS256 JWT Helper
 * Cryptographically secure, zero-dependency token generation & verification
 */
require_once dirname(__DIR__) . '/config.php';

class JWT {

    private static function base64UrlEncode(string $data): string {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $data): string {
        return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', 3 - (3 + strlen($data)) % 4));
    }

    /**
     * Generate an HS256 JWT Token
     *
     * @param array $payload Custom user/tenant claims
     * @param int $lifetimeInSeconds Expiry offset
     * @param string $tokenType 'access' or 'refresh'
     * @return string Signed JWT
     */
    public static function generate(array $payload, int $lifetimeInSeconds, string $tokenType = 'access'): string {
        $header = [
            'alg' => 'HS256',
            'typ' => 'JWT'
        ];

        $now = time();
        $claims = array_merge([
            'iss'  => API_JWT_ISSUER,
            'aud'  => API_JWT_AUDIENCE,
            'iat'  => $now,
            'nbf'  => $now,
            'exp'  => $now + $lifetimeInSeconds,
            'type' => $tokenType,
            'jti'  => bin2hex(random_bytes(12))
        ], $payload);

        $base64Header  = self::base64UrlEncode(json_encode($header, JSON_UNESCAPED_SLASHES));
        $base64Payload = self::base64UrlEncode(json_encode($claims, JSON_UNESCAPED_SLASHES));

        $signature = hash_hmac('sha256', "$base64Header.$base64Payload", API_JWT_SECRET, true);
        $base64Signature = self::base64UrlEncode($signature);

        return "$base64Header.$base64Payload.$base64Signature";
    }

    /**
     * Verify and decode a JWT Token
     *
     * @param string $token
     * @param string|null $expectedType 'access', 'refresh', or null
     * @return array|false Returns decoded payload array or false on failure
     */
        public static function verify(string $token, ?string $expectedType = null) {
        $parts = explode('.', trim($token));
        if (count($parts) !== 3) {
            return false;
        }

        list($b64Header, $b64Payload, $b64Signature) = $parts;

        // Verify signature with constant-time comparison
        $expectedSignature = hash_hmac('sha256', "$b64Header.$b64Payload", API_JWT_SECRET, true);
        $actualSignature   = self::base64UrlDecode($b64Signature);

        if (!hash_equals($expectedSignature, $actualSignature)) {
            return false; // Signature invalid or tampered
        }

        $payload = json_decode(self::base64UrlDecode($b64Payload), true);
        if (!is_array($payload)) {
            return false;
        }

        $now = time();

        // Check expiration
        if (isset($payload['exp']) && $now > $payload['exp']) {
            return false; // Token expired
        }

        // Check not-before
        if (isset($payload['nbf']) && $now < $payload['nbf']) {
            return false; // Token not yet active
        }

        // Validate issuer — prevents tokens minted by a different deployment
        if (isset($payload['iss']) && !empty(API_JWT_ISSUER)
            && !hash_equals((string) API_JWT_ISSUER, (string) $payload['iss'])
        ) {
            return false;
        }

        // Validate audience — prevents tokens issued for a different client
        if (isset($payload['aud']) && !empty(API_JWT_AUDIENCE)
            && !hash_equals((string) API_JWT_AUDIENCE, (string) $payload['aud'])
        ) {
            return false;
        }

        // Check token type if specified
        if ($expectedType !== null && (!isset($payload['type']) || $payload['type'] !== $expectedType)) {
            return false; // Type mismatch
        }

        return $payload;
    }

    /**
     * Auto-ensure revoked tokens table exists
     */
    public static function ensureRevocationSchema(mysqli $conn): void {
        static $ensured = false;
        if ($ensured) return;
        $ensured = true;
        $conn->query("CREATE TABLE IF NOT EXISTS api_revoked_tokens (
            token_hash VARCHAR(64) PRIMARY KEY,
            token_type VARCHAR(16) NOT NULL DEFAULT 'refresh',
            tenant_id INT NOT NULL DEFAULT 0,
            revoked_at DATETIME NOT NULL,
            expires_at INT NOT NULL DEFAULT 0,
            INDEX idx_revoked_exp (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    /**
     * Check if a token has been explicitly revoked
     */
    public static function isRevoked(mysqli $conn, string $token): bool {
        self::ensureRevocationSchema($conn);
        $hash = hash('sha256', trim($token));
        $stmt = $conn->prepare("SELECT token_hash FROM api_revoked_tokens WHERE token_hash = ? LIMIT 1");
        if (!$stmt) return false;
        $stmt->bind_param("s", $hash);
        $stmt->execute();
        $stmt->store_result();
        $isRevoked = ($stmt->num_rows > 0);
        $stmt->close();
        return $isRevoked;
    }

    /**
     * Revoke a token (e.g. on logout or rotation)
     */
    public static function revoke(mysqli $conn, string $token, string $type = 'refresh', int $tenantId = 0, int $expiresAt = 0): bool {
        self::ensureRevocationSchema($conn);
        $hash = hash('sha256', trim($token));
        $now = date('Y-m-d H:i:s');
        $stmt = $conn->prepare("INSERT IGNORE INTO api_revoked_tokens (token_hash, token_type, tenant_id, revoked_at, expires_at) VALUES (?, ?, ?, ?, ?)");
        if (!$stmt) return false;
        $stmt->bind_param("ssisi", $hash, $type, $tenantId, $now, $expiresAt);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }
}
