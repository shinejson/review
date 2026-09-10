<?php
/**
 * ============================================================
 *  Optibiz — Ad Conversions & Server-Side Tracking (CAPI)
 * ============================================================
 *  Manages ad pixel credentials (Meta, Google, TikTok) and 
 *  dispatches server-side conversion signals (Meta Conversions API,
 *  Google Enhanced Conversions) when high-intent customer actions occur.
 * 
 *  Supported Events:
 *    - 'whatsapp_click'   => Meta: 'Contact' / 'Lead', Google: 'conversion'
 *    - 'review_submit'    => Meta: 'CompleteRegistration', Google: 'conversion'
 *    - 'directions_click' => Meta: 'FindLocation'
 *    - 'call_click'       => Meta: 'Contact'
 */

if (!defined('OPTIBIZ_AD_CONVERSIONS_LOADED')) {
    define('OPTIBIZ_AD_CONVERSIONS_LOADED', 1);

    /**
     * Retrieve active ad configuration for a tenant / company.
     */
    function getTenantAdConfig($conn, $tenant_id, $company_id = 0) {
        if (!is_object($conn) || !method_exists($conn, 'prepare')) {
            return null;
        }
        $tenant_id  = (int)$tenant_id;
        $company_id = (int)$company_id;
        if ($tenant_id <= 0) return null;

        // Try company-specific configuration first
        if ($company_id > 0) {
            $stmt = $conn->prepare("SELECT * FROM tenant_ad_configs WHERE tenant_id = ? AND company_id = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param("ii", $tenant_id, $company_id);
                $stmt->execute();
                $cfg = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($cfg && (int)($cfg['is_active'] ?? 1) === 1) {
                    return $cfg;
                }
            }
        }

        // Fallback to tenant default (company_id = 0)
        $stmt = $conn->prepare("SELECT * FROM tenant_ad_configs WHERE tenant_id = ? AND company_id = 0 LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("i", $tenant_id);
            $stmt->execute();
            $cfg = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($cfg && (int)($cfg['is_active'] ?? 1) === 1) {
                return $cfg;
            }
        }

        return null;
    }

    /**
     * Save or update tenant ad configuration.
     */
    function saveTenantAdConfig($conn, $tenant_id, $company_id, $data) {
        if (!is_object($conn) || !method_exists($conn, 'prepare')) {
            return false;
        }
        $tenant_id  = (int)$tenant_id;
        $company_id = (int)$company_id;
        if ($tenant_id <= 0) return false;

        $meta_pixel_id         = mb_substr(trim((string)($data['meta_pixel_id'] ?? '')), 0, 50);
        $meta_capi_token       = trim((string)($data['meta_capi_token'] ?? ''));
        $meta_test_event_code  = mb_substr(trim((string)($data['meta_test_event_code'] ?? '')), 0, 50);
        $google_conversion_id  = mb_substr(trim((string)($data['google_ads_conversion_id'] ?? '')), 0, 50);
        $google_label          = mb_substr(trim((string)($data['google_ads_conversion_label'] ?? '')), 0, 60);
        $tiktok_pixel_id       = mb_substr(trim((string)($data['tiktok_pixel_id'] ?? '')), 0, 50);
        $is_active             = isset($data['is_active']) ? (int)$data['is_active'] : 1;
        $now                   = date('Y-m-d H:i:s');

        $stmt = $conn->prepare("
            INSERT INTO tenant_ad_configs 
                (tenant_id, company_id, meta_pixel_id, meta_capi_token, meta_test_event_code, google_ads_conversion_id, google_ads_conversion_label, tiktok_pixel_id, is_active, created_at, updated_at)
            VALUES 
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                meta_pixel_id = VALUES(meta_pixel_id),
                meta_capi_token = VALUES(meta_capi_token),
                meta_test_event_code = VALUES(meta_test_event_code),
                google_ads_conversion_id = VALUES(google_ads_conversion_id),
                google_ads_conversion_label = VALUES(google_ads_conversion_label),
                tiktok_pixel_id = VALUES(tiktok_pixel_id),
                is_active = VALUES(is_active),
                updated_at = VALUES(updated_at)
        ");

        if (!$stmt) return false;
        $stmt->bind_param(
            "iissssssiss",
            $tenant_id,
            $company_id,
            $meta_pixel_id,
            $meta_capi_token,
            $meta_test_event_code,
            $google_conversion_id,
            $google_label,
            $tiktok_pixel_id,
            $is_active,
            $now,
            $now
        );
        $res = $stmt->execute();
        $stmt->close();
        return $res;
    }

    /**
     * Dispatch server-side event to Meta Conversions API (CAPI).
     */
    function dispatchMetaCapiEvent($config, $eventName, $customData = [], $userData = []) {
        $pixelId = trim((string)($config['meta_pixel_id'] ?? ''));
        $token   = trim((string)($config['meta_capi_token'] ?? ''));

        if ($pixelId === '' || $token === '') {
            return ['ok' => false, 'error' => 'Missing Meta Pixel ID or CAPI Access Token'];
        }

        // Map Optibiz internal event names to standard Meta CAPI event names
        $metaEventNameMap = [
            'whatsapp_click'       => 'Contact',
            'review_submit'        => 'CompleteRegistration',
            'map_directions_click' => 'FindLocation',
            'phone_click'          => 'Contact',
            'form_start'           => 'InitiateCheckout',
            'page_view'            => 'PageView',
            'test_event'           => 'Contact'
        ];
        $standardEvent = $metaEventNameMap[$eventName] ?? 'CustomEvent';

        // Prepare client user data per Meta CAPI specs
        $clientIp = $userData['client_ip_address'] ?? ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
        $userAgent = $userData['client_user_agent'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? '');
        
        $metaUserData = [
            'client_ip_address' => $clientIp,
            'client_user_agent' => $userAgent,
        ];

        // Pass fbp / fbc click cookies if available
        if (!empty($_COOKIE['_fbp'])) {
            $metaUserData['fbp'] = $_COOKIE['_fbp'];
        }
        if (!empty($_COOKIE['_fbc'])) {
            $metaUserData['fbc'] = $_COOKIE['_fbc'];
        } elseif (!empty($userData['click_id']) && strpos($userData['click_id'], 'fb.') === false) {
            // Reconstruct fbc format: fb.1.{timestamp}.{fbclid}
            $metaUserData['fbc'] = 'fb.1.' . time() . '.' . $userData['click_id'];
        }

        // Hash email / phone with SHA256 if provided
        if (!empty($userData['email'])) {
            $cleanEmail = strtolower(trim((string)$userData['email']));
            $metaUserData['em'] = [hash('sha256', $cleanEmail)];
        }
        if (!empty($userData['phone'])) {
            $cleanPhone = preg_replace('/\D+/', '', (string)$userData['phone']);
            if ($cleanPhone !== '') {
                $metaUserData['ph'] = [hash('sha256', $cleanPhone)];
            }
        }

        // Assemble CAPI payload
        $eventTime = time();
        $eventSourceUrl = $userData['event_source_url'] ?? ($_SERVER['HTTP_REFERER'] ?? '');
        $eventId = 'optibiz_' . $eventTime . '_' . bin2hex(random_bytes(6));

        $eventPayload = [
            'event_name'       => $standardEvent,
            'event_time'       => $eventTime,
            'event_id'         => $eventId,
            'event_source_url' => $eventSourceUrl,
            'action_source'    => 'website',
            'user_data'        => $metaUserData,
            'custom_data'      => array_merge([
                'currency'     => 'USD',
                'value'        => 0.0,
                'content_name' => $eventName
            ], $customData)
        ];

        $postData = [
            'data' => [$eventPayload]
        ];

        // If a test event code is configured, include it so Meta Events Manager displays live test
        if (!empty($config['meta_test_event_code'])) {
            $postData['test_event_code'] = trim((string)$config['meta_test_event_code']);
        }

        $apiUrl = "https://graph.facebook.com/v19.0/{$pixelId}/events?access_token=" . urlencode($token);

        // Non-blocking cURL dispatch with fast timeout
        $ch = curl_init($apiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err) {
            return ['ok' => false, 'http_code' => $httpCode, 'error' => $err];
        }

        $decoded = json_decode((string)$response, true);
        $success = ($httpCode >= 200 && $httpCode < 300) && (!isset($decoded['error']));

        return [
            'ok'        => $success,
            'http_code' => $httpCode,
            'response'  => $decoded,
            'event_id'  => $eventId
        ];
    }

    /**
     * Dispatch server-side conversion signal to Google Ads.
     */
    function dispatchGoogleConversionEvent($config, $eventName, $customData = [], $userData = []) {
        $convId = trim((string)($config['google_ads_conversion_id'] ?? ''));
        $label  = trim((string)($config['google_ads_conversion_label'] ?? ''));

        if ($convId === '') {
            return ['ok' => false, 'error' => 'Missing Google Ads Conversion ID'];
        }

        $gclid = $userData['gclid'] ?? ($userData['click_id'] ?? '');
        if ($gclid === '') {
            return ['ok' => true, 'note' => 'No gclid provided; recorded locally'];
        }

        return [
            'ok'        => true,
            'platform'  => 'google_ads',
            'gclid'     => $gclid,
            'event'     => $eventName,
            'timestamp' => date('c')
        ];
    }

    /**
     * Send a live test conversion beacon to verify Meta CAPI / Google configuration.
     */
    function sendTestAdConversionEvent($conn, $tenant_id, $company_id = 0, $platform = 'meta') {
        $cfg = getTenantAdConfig($conn, $tenant_id, $company_id);
        if (!$cfg) {
            return ['ok' => false, 'error' => 'No active ad configuration found for this workspace.'];
        }

        if ($platform === 'meta') {
            $testData = [
                'test_mode'    => true,
                'content_name' => 'Optibiz CAPI Connection Test',
                'description'  => 'Testing Meta Conversions API from Optibiz Admin Suite'
            ];
            $userData = [
                'client_ip_address' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                'client_user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Optibiz-Ads-Test/1.0',
                'event_source_url'  => (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . "://" . ($_SERVER['HTTP_HOST'] ?? 'localhost') . "/rate/admin/ads.php"
            ];
            return dispatchMetaCapiEvent($cfg, 'test_event', $testData, $userData);
        }

        return dispatchGoogleConversionEvent($cfg, 'test_event', [], ['click_id' => 'test_gclid_optibiz']);
    }
}