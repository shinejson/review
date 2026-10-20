<?php
/**
 * ============================================================
 *  Optibiz — Google Sign-In (server-side ID token verification)
 * ============================================================
 *  The Android/iOS client obtains an ID token from Google Sign-In
 *  and posts it to /api/v1/auth/customer/google.php. This file
 *  verifies the RS256 signature against Google's published JWKS,
 *  the issuer, the audience (your OAuth client IDs), the expiry
 *  and the verified-email flag before the token is trusted.
 *
 *  The client is never asked to assert "this is my email" by itself.
 *
 *  Configuration (environment):
 *    OPTIBIZ_GOOGLE_CLIENT_IDS   Comma-separated OAuth client IDs
 *    OPTIBIZ_GOOGLE_JWKS_JSON    Optional JSON override (tests/offline)
 */

require_once __DIR__ . '/app_auth.php';

if (!function_exists('optibiz_b64url_decode')) {
    function optibiz_b64url_decode($data)
    {
        $data = strtr((string) $data, '-_', '+/');
        $pad = strlen($data) % 4;
        if ($pad) {
            $data .= str_repeat('=', 4 - $pad);
        }
        return base64_decode($data, true);
    }
}

if (!function_exists('optibiz_google_client_ids')) {
    function optibiz_google_client_ids()
    {
        $raw = getenv('OPTIBIZ_GOOGLE_CLIENT_IDS');
        if (!$raw) {
            $raw = getenv('OPTIBIZ_GOOGLE_CLIENT_ID');
        }
        $ids = [];
        foreach (explode(',', (string) $raw) as $id) {
            $id = trim($id);
            if ($id !== '') {
                $ids[] = $id;
            }
        }
        return $ids;
    }
}

/* ------------------------------------------------------------
   Minimal DER encoder for RSA SubjectPublicKeyInfo
   ------------------------------------------------------------ */

if (!function_exists('optibiz_asn1_len')) {
    function optibiz_asn1_len($length)
    {
        if ($length < 0x80) {
            return chr($length);
        }
        $bytes = ltrim(pack('N', $length), "\0");
        return chr(0x80 | strlen($bytes)) . $bytes;
    }
}
if (!function_exists('optibiz_asn1_tlv')) {
    function optibiz_asn1_tlv($tag, $value)
    {
        return chr($tag) . optibiz_asn1_len(strlen($value)) . $value;
    }
}
if (!function_exists('optibiz_asn1_integer')) {
    function optibiz_asn1_integer($bytes)
    {
        $bytes = ltrim((string) $bytes, "\0");
        if ($bytes === '') {
            $bytes = "\0";
        }
        if (ord($bytes[0]) > 0x7F) {
            $bytes = "\0" . $bytes;
        }
        return optibiz_asn1_tlv(0x02, $bytes);
    }
}

if (!function_exists('optibiz_rsa_jwk_to_pem')) {
    /**
     * Convert the RSA `n`/`e` members of a JWK into a PEM public key.
     * Returns null when the values are not usable.
     */
    function optibiz_rsa_jwk_to_pem($n_b64, $e_b64)
    {
        $modulus = optibiz_b64url_decode($n_b64);
        $exponent = optibiz_b64url_decode($e_b64);
        if ($modulus === false || $exponent === false || strlen($modulus) < 128 || $exponent === '') {
            return null;
        }

        $rsaPublicKey = optibiz_asn1_tlv(0x30, optibiz_asn1_integer($modulus) . optibiz_asn1_integer($exponent));
        $algorithm = optibiz_asn1_tlv(0x30,
            optibiz_asn1_tlv(0x06, "\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01") . "\x05\x00"
        );
        $bitString = optibiz_asn1_tlv(0x03, "\0" . $rsaPublicKey);
        $spki = optibiz_asn1_tlv(0x30, $algorithm . $bitString);

        $pem = "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($spki), 64, "\n")
            . "-----END PUBLIC KEY-----\n";

        return $pem;
    }
}

/* ------------------------------------------------------------
   JWKS retrieval (cached; injectable for tests and offline hosts)
   ------------------------------------------------------------ */

if (!function_exists('optibiz_google_jwks')) {
    function optibiz_google_jwks($forceRefresh = false)
    {
        $injected = getenv('OPTIBIZ_GOOGLE_JWKS_JSON');
        if ($injected) {
            $decoded = json_decode((string) $injected, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        $cacheFile = sys_get_temp_dir() . '/optibiz-google-jwks-' . substr(hash('sha256', __FILE__), 0, 12) . '.json';
        $fresh = !$forceRefresh && is_readable($cacheFile) && (time() - (int) filemtime($cacheFile) < 3600);
        if ($fresh) {
            $decoded = json_decode((string) file_get_contents($cacheFile), true);
            if (is_array($decoded) && !empty($decoded['keys'])) {
                return $decoded;
            }
        }

        $url = 'https://www.googleapis.com/oauth2/v3/certs';
        $raw = false;
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT        => 8,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            ]);
            $raw = curl_exec($ch);
            curl_close($ch);
        } else {
            $ctx = stream_context_create([
                'http' => ['timeout' => 8, 'ignore_errors' => true, 'header' => "Accept: application/json\r\n"],
                'ssl'  => ['verify_peer' => true, 'verify_peer_name' => true],
            ]);
            $raw = @file_get_contents($url, false, $ctx);
        }

        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && !empty($decoded['keys'])) {
                @file_put_contents($cacheFile, $raw, LOCK_EX);
                return $decoded;
            }
        }

        // Fall back to a stale cache rather than locking everyone out
        // during a short outage of Google's certificate endpoint.
        if (is_readable($cacheFile)) {
            $decoded = json_decode((string) file_get_contents($cacheFile), true);
            if (is_array($decoded) && !empty($decoded['keys'])) {
                return $decoded;
            }
        }

        return null;
    }
}

/**
 * Verify a Google ID token.
 *
 * @return array{success:bool,status:int,message:string,claims?:array}
 */
function optibiz_google_verify_id_token($idToken)
{
    $clientIds = optibiz_google_client_ids();
    if (!$clientIds) {
        return [
            'success' => false,
            'status'  => 503,
            'message' => 'Google Sign-In is not configured on this server.',
        ];
    }
    if (!function_exists('openssl_verify')) {
        return ['success' => false, 'status' => 503, 'message' => 'OpenSSL is required for Google Sign-In.'];
    }

    $parts = explode('.', trim((string) $idToken));
    if (count($parts) !== 3) {
        return ['success' => false, 'status' => 401, 'message' => 'The Google credential is malformed.'];
    }
    list($b64Header, $b64Payload, $b64Signature) = $parts;

    $header = json_decode((string) optibiz_b64url_decode($b64Header), true);
    $payload = json_decode((string) optibiz_b64url_decode($b64Payload), true);
    $signature = optibiz_b64url_decode($b64Signature);

    if (!is_array($header) || !is_array($payload) || $signature === false) {
        return ['success' => false, 'status' => 401, 'message' => 'The Google credential is malformed.'];
    }
    if (($header['alg'] ?? '') !== 'RS256' || empty($header['kid'])) {
        return ['success' => false, 'status' => 401, 'message' => 'The Google credential uses an unexpected signing algorithm.'];
    }

    $jwks = optibiz_google_jwks();
    $pem = null;
    if (is_array($jwks) && !empty($jwks['keys'])) {
        foreach ($jwks['keys'] as $key) {
            if (($key['kid'] ?? '') === $header['kid'] && isset($key['n'], $key['e'])) {
                $pem = optibiz_rsa_jwk_to_pem($key['n'], $key['e']);
                break;
            }
        }
    }
    if ($pem === null) {
        // Certificate rotation: refresh once before rejecting.
        $jwks = optibiz_google_jwks(true);
        if (is_array($jwks) && !empty($jwks['keys'])) {
            foreach ($jwks['keys'] as $key) {
                if (($key['kid'] ?? '') === $header['kid'] && isset($key['n'], $key['e'])) {
                    $pem = optibiz_rsa_jwk_to_pem($key['n'], $key['e']);
                    break;
                }
            }
        }
    }
    if ($pem === null) {
        return ['success' => false, 'status' => 503, 'message' => 'Google signing keys are unavailable. Please try again.'];
    }

    $verified = openssl_verify($b64Header . '.' . $b64Payload, $signature, $pem, OPENSSL_ALGO_SHA256);
    if ($verified !== 1) {
        return ['success' => false, 'status' => 401, 'message' => 'The Google credential signature is invalid.'];
    }

    $now = time();
    $iss = (string) ($payload['iss'] ?? '');
    if (!in_array($iss, ['accounts.google.com', 'https://accounts.google.com'], true)) {
        return ['success' => false, 'status' => 401, 'message' => 'The Google credential was not issued by Google.'];
    }

    $aud = $payload['aud'] ?? null;
    $audiences = is_array($aud) ? $aud : [$aud];
    if (!array_intersect(array_map('strval', $audiences), $clientIds)) {
        return ['success' => false, 'status' => 401, 'message' => 'The Google credential was issued for a different app.'];
    }

    if (!isset($payload['exp']) || (int) $payload['exp'] < $now - 60) {
        return ['success' => false, 'status' => 401, 'message' => 'The Google credential has expired. Please sign in again.'];
    }
    if (isset($payload['iat']) && (int) $payload['iat'] > $now + 300) {
        return ['success' => false, 'status' => 401, 'message' => 'The Google credential has an invalid issue time.'];
    }
    if (empty($payload['sub']) || empty($payload['email'])) {
        return ['success' => false, 'status' => 401, 'message' => 'The Google credential does not contain an account.'];
    }
    $verifiedEmail = $payload['email_verified'] ?? false;
    if (!($verifiedEmail === true || $verifiedEmail === 'true' || $verifiedEmail === 1)) {
        return ['success' => false, 'status' => 401, 'message' => 'Your Google email address is not verified.'];
    }

    return [
        'success' => true,
        'status'  => 200,
        'message' => 'Google credential verified.',
        'claims'  => [
            'sub'            => (string) $payload['sub'],
            'email'          => optibiz_app_email($payload['email']),
            'email_verified' => true,
            'name'           => trim((string) ($payload['name'] ?? '')),
            'picture'        => trim((string) ($payload['picture'] ?? '')),
        ],
    ];
}
