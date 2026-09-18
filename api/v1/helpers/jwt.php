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
}
