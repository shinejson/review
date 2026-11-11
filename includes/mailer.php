<?php
/**
 * ============================================================
 *  Optibiz Email & SMTP Engine
 * ============================================================
 *  Lightweight, standalone mailer supporting both authenticated
 *  SMTP (TLS/SSL/Plain) and PHP's native mail(), with zero external
 *  dependencies.
 */

if (!function_exists('sa_get_mail_config')) {
    /**
     * Retrieve all active email configuration settings.
     * Supports environment variables (Railway, Docker, .env) with database fallbacks.
     */
    function sa_get_mail_config($conn = null, $reload = false)
    {
        global $conn;
        $site_name = function_exists('sa_setting') && $conn ? sa_setting($conn, 'site_name', 'Optibiz', $reload) : 'Optibiz';
        $admin_mail = function_exists('sa_setting') && $conn ? sa_setting($conn, 'admin_email', 'admin@example.com', $reload) : 'admin@example.com';

        $readEnv = function ($keys, $default = '') {
            foreach ((array) $keys as $k) {
                if (function_exists('optibiz_env')) {
                    $v = optibiz_env($k, '');
                    if ($v !== '') return $v;
                }
                if (isset($_ENV[$k]) && $_ENV[$k] !== '') return $_ENV[$k];
                if (isset($_SERVER[$k]) && $_SERVER[$k] !== '') return $_SERVER[$k];
                if (function_exists('getenv')) {
                    $v = @getenv($k);
                    if ($v !== false && $v !== '') return $v;
                }
            }
            return $default;
        };

        // Environment variable values
        $envResendKey  = $readEnv(['RESEND_API_KEY', 'OPTIBIZ_RESEND_API_KEY']);
        $envBrevoKey   = $readEnv(['BREVO_API_KEY', 'OPTIBIZ_BREVO_API_KEY']);
        $envDriver     = $readEnv(['MAIL_DRIVER', 'OPTIBIZ_MAIL_DRIVER']);
        $envFromEmail  = $readEnv(['MAIL_FROM_EMAIL', 'OPTIBIZ_MAIL_FROM_EMAIL']);
        $envFromName   = $readEnv(['MAIL_FROM_NAME', 'OPTIBIZ_MAIL_FROM_NAME']);
        $envSmtpHost   = $readEnv(['SMTP_HOST', 'OPTIBIZ_SMTP_HOST']);
        $envSmtpPort   = $readEnv(['SMTP_PORT', 'OPTIBIZ_SMTP_PORT']);
        $envSmtpEnc    = $readEnv(['SMTP_ENCRYPTION', 'OPTIBIZ_SMTP_ENCRYPTION']);
        $envSmtpUser   = $readEnv(['SMTP_USERNAME', 'OPTIBIZ_SMTP_USERNAME']);
        $envSmtpPass   = $readEnv(['SMTP_PASSWORD', 'OPTIBIZ_SMTP_PASSWORD']);

        $defaults = [
            'mail_driver'     => $envDriver !== '' ? $envDriver : ($envResendKey !== '' ? 'resend' : 'smtp'),
            'mail_from_name'  => $envFromName !== '' ? $envFromName : $site_name,
            'mail_from_email' => $envFromEmail !== '' ? $envFromEmail : $admin_mail,
            'smtp_host'       => $envSmtpHost !== '' ? $envSmtpHost : 'smtp.gmail.com',
            'smtp_port'       => $envSmtpPort !== '' ? $envSmtpPort : '587',
            'smtp_encryption' => $envSmtpEnc !== '' ? $envSmtpEnc : 'tls',
            'smtp_username'   => $envSmtpUser !== '' ? $envSmtpUser : '',
            'smtp_password'   => $envSmtpPass !== '' ? $envSmtpPass : '',
            'smtp_timeout'    => 12,
            'resend_api_key'  => $envResendKey !== '' ? $envResendKey : '',
            'brevo_api_key'   => $envBrevoKey !== '' ? $envBrevoKey : '',
        ];

        if (!$conn) {
            $res = $defaults;
        } else {
            $res = [];
            foreach ($defaults as $k => $def) {
                $val = function_exists('sa_setting') ? sa_setting($conn, $k, '', $reload) : '';
                $res[$k] = ($val !== '') ? $val : $def;
            }

            // Environment overrides take priority if explicitly set
            if ($envResendKey !== '') $res['resend_api_key'] = $envResendKey;
            if ($envBrevoKey !== '')  $res['brevo_api_key']  = $envBrevoKey;
            if ($envDriver !== '')    $res['mail_driver']    = $envDriver;
            if ($envFromEmail !== '') $res['mail_from_email'] = $envFromEmail;
            if ($envFromName !== '')  $res['mail_from_name']  = $envFromName;
            if ($envSmtpHost !== '')  $res['smtp_host']       = $envSmtpHost;
            if ($envSmtpPort !== '')  $res['smtp_port']       = $envSmtpPort;
            if ($envSmtpEnc !== '')   $res['smtp_encryption'] = $envSmtpEnc;
            if ($envSmtpUser !== '')  $res['smtp_username']   = $envSmtpUser;
            if ($envSmtpPass !== '')  $res['smtp_password']   = $envSmtpPass;
        }

        // Auto-select Resend driver when an API key is available and driver is default/empty
        if (!empty($res['resend_api_key']) && (empty($res['mail_driver']) || $res['mail_driver'] === 'smtp' && empty($res['smtp_username']))) {
            $res['mail_driver'] = 'resend';
        }

        // Google app passwords are shown as 4 groups of 4 letters and are often
        // pasted with spaces. Gmail expects the 16 characters with no spaces,
        // so normalize that exact shape (leave every other password untouched).
        if (isset($res['smtp_password']) && preg_match('/^[A-Za-z]{4} [A-Za-z]{4} [A-Za-z]{4} [A-Za-z]{4}$/', $res['smtp_password'])) {
            $res['smtp_password'] = str_replace(' ', '', $res['smtp_password']);
        }

        // SMTP servers (Gmail in particular) reject MAIL FROM when the From
        // address is not the authenticated account. Fall back to the SMTP
        // username whenever the From address is empty or a placeholder.
        $fromEmail = strtolower(trim((string)($res['mail_from_email'] ?? '')));
        $username  = trim((string)($res['smtp_username'] ?? ''));
        if (in_array($fromEmail, ['', 'admin@example.com', 'noreply@localhost'], true)
            && filter_var($username, FILTER_VALIDATE_EMAIL)) {
            $res['mail_from_email'] = $username;
        }

        // Resend requires sending from onboarding@resend.dev or a custom verified domain.
        // If from address is a generic example/placeholder, fall back to onboarding@resend.dev
        if (($res['mail_driver'] ?? '') === 'resend') {
            $from = strtolower(trim((string)($res['mail_from_email'] ?? '')));
            if (empty($from) || in_array($from, ['admin@example.com', 'noreply@localhost'], true) || strpos($from, 'example.com') !== false) {
                $res['mail_from_email'] = 'onboarding@resend.dev';
            }
        }

        return $res;
    }
}

if (!function_exists('sa_render_email_template')) {
    /**
     * Render a clean, modern HTML email wrapper matching the Optibiz theme.
     */
    function sa_render_email_template($title, $bodyHtml, $siteName = 'Optibiz', $buttonUrl = '', $buttonText = '')
    {
        $safeSite = htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8');
        $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $btnHtml = '';
        if ($buttonUrl && $buttonText) {
            $btnHtml = '
            <div style="margin: 28px 0; text-align: center;">
                <a href="' . htmlspecialchars($buttonUrl, ENT_QUOTES, 'UTF-8') . '" style="background: #a8e030; background: linear-gradient(135deg, #c2f542, #a8e030); color: #0a1a2a; text-decoration: none; padding: 13px 28px; border-radius: 99px; font-weight: 700; font-size: 14px; display: inline-block; letter-spacing: 0.3px;">
                    ' . htmlspecialchars($buttonText, ENT_QUOTES, 'UTF-8') . '
                </a>
            </div>';
        }

        return '<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>' . $safeTitle . '</title>
</head>
<body style="margin:0;padding:0;background-color:#081522;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;color:#eef2f7;-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#081522;padding:36px 16px;">
  <tr>
    <td align="center">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:580px;background:#0f2438;border:1px solid rgba(148,163,184,0.18);border-radius:18px;overflow:hidden;box-shadow:0 20px 45px rgba(2,8,20,0.5);">
        <!-- Brand Header -->
        <tr>
          <td style="padding:28px 32px 20px;border-bottom:1px solid rgba(148,163,184,0.14);background:linear-gradient(180deg,#12293d 0%,#0f2438 100%);">
            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
              <tr>
                <td style="vertical-align:middle;">
                  <span style="display:inline-block;width:34px;height:34px;border-radius:10px;background:#c2f542;color:#0a1a2a;text-align:center;line-height:34px;font-weight:900;font-size:18px;vertical-align:middle;">★</span>
                  <span style="vertical-align:middle;margin-left:10px;font-size:18px;font-weight:800;color:#ffffff;letter-spacing:-0.3px;">' . $safeSite . '</span>
                </td>
                <td align="right" style="vertical-align:middle;">
                  <span style="font-size:11px;font-weight:700;color:#c2f542;letter-spacing:1px;text-transform:uppercase;background:rgba(194,245,66,0.12);padding:4px 10px;border-radius:99px;border:1px solid rgba(194,245,66,0.25);">Notification</span>
                </td>
              </tr>
            </table>
          </td>
        </tr>
        <!-- Main Content -->
        <tr>
          <td style="padding:32px;color:#eef2f7;font-size:15px;line-height:1.65;">
            <h1 style="color:#ffffff;font-size:22px;font-weight:800;letter-spacing:-0.5px;margin:0 0 16px 0;">' . $safeTitle . '</h1>
            <div style="color:#cbd5e1;font-size:14.5px;line-height:1.65;">
              ' . $bodyHtml . '
            </div>
            ' . $btnHtml . '
          </td>
        </tr>
        <!-- Footer -->
        <tr>
          <td style="padding:20px 32px;background:#0a1a2a;border-top:1px solid rgba(148,163,184,0.12);text-align:center;color:#64748b;font-size:12px;line-height:1.5;">
            Sent by <strong>' . $safeSite . '</strong> Control Center.<br>
            If you did not expect this message, you can safely disregard it.
          </td>
        </tr>
      </table>
    </td>
  </tr>
</table>
</body>
</html>';
    }
}

if (!function_exists('sa_send_mail')) {
    /**
     * Main dispatch function: sends an email using the active configuration.
     *
     * @param string $to Recipient email address
     * @param string $subject Email subject line
     * @param string $htmlContent HTML body content
     * @param mysqli|null $conn Active database connection
     * @param array $override Optional configuration overrides
     * @return array ['success' => bool, 'message' => string, 'debug' => string]
     */
    function sa_send_mail($to, $subject, $htmlContent, $conn = null, $override = [])
    {
        $cfg = array_merge(sa_get_mail_config($conn), (array) $override);

        $to = trim($to);
        if (!$to || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'message' => 'Invalid recipient email address.', 'debug' => 'Invalid TO: ' . $to];
        }

        $driver = strtolower(trim($cfg['mail_driver'] ?? 'smtp'));

        if ($driver === 'resend') {
            return sa_resend_send($cfg, $to, $subject, $htmlContent);
        } elseif ($driver === 'brevo') {
            return sa_brevo_send($cfg, $to, $subject, $htmlContent);
        } elseif ($driver === 'smtp') {
            return sa_smtp_send($cfg, $to, $subject, $htmlContent);
        } else {
            return sa_native_mail_send($cfg, $to, $subject, $htmlContent);
        }
    }
}

if (!function_exists('sa_smtp_send')) {
    /**
     * Direct socket-level SMTP client with full STARTTLS / SSL support.
     */
    function sa_smtp_send($cfg, $to, $subject, $htmlContent)
    {
        $host       = trim($cfg['smtp_host'] ?? 'localhost');
        $port       = (int) ($cfg['smtp_port'] ?? 587);
        $encryption = strtolower(trim($cfg['smtp_encryption'] ?? 'tls'));
        $username   = trim($cfg['smtp_username'] ?? '');
        $password   = (string) ($cfg['smtp_password'] ?? '');
        $fromEmail  = trim($cfg['mail_from_email'] ?? 'noreply@localhost');
        $fromName   = trim($cfg['mail_from_name'] ?? 'Platform');
        $timeout    = max(5, (int) ($cfg['smtp_timeout'] ?? 12));

        $debugLog = [];
        $log = function ($msg) use (&$debugLog) {
            $debugLog[] = $msg;
        };

        // Determine socket target protocol
        $remoteTarget = ($encryption === 'ssl') ? 'ssl://' . $host . ':' . $port : 'tcp://' . $host . ':' . $port;
        $log("Connecting to $remoteTarget (timeout: {$timeout}s)...");

        $context = stream_context_create([
            'ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
            ]
        ]);

        $socket = @stream_socket_client(
            $remoteTarget,
            $errno,
            $errstr,
            $timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if (!$socket) {
            return [
                'success' => false,
                'message' => "Could not connect to SMTP server {$host}:{$port} ($errstr - code $errno)",
                'debug'   => implode("\n", $debugLog),
            ];
        }

        stream_set_timeout($socket, $timeout);

        $read = function () use ($socket, $log) {
            $response = '';
            while ($line = fgets($socket, 515)) {
                $response .= $line;
                if (substr($line, 3, 1) === ' ') {
                    break;
                }
            }
            $log("SERVER: " . trim($response));
            return $response;
        };

        $write = function ($cmd, $hideLog = false) use ($socket, $log) {
            $log("CLIENT: " . ($hideLog ? '********' : trim($cmd)));
            fputs($socket, $cmd . "\r\n");
        };

        $expect = function ($code) use ($read) {
            $resp = $read();
            $respCode = substr($resp, 0, 3);
            if ($respCode !== (string) $code) {
                return [false, $resp];
            }
            return [true, $resp];
        };

        // 1. Initial Greeting
        list($ok, $resp) = $expect(220);
        if (!$ok) {
            fclose($socket);
            return ['success' => false, 'message' => "SMTP greeting failed: " . trim($resp), 'debug' => implode("\n", $debugLog)];
        }

        // 2. EHLO
        $clientHost = gethostname() ?: 'localhost';
        $write("EHLO $clientHost");
        $ehloResp = $read();

        // 3. STARTTLS Upgrade if requested
        if ($encryption === 'tls') {
            $write("STARTTLS");
            list($ok, $resp) = $expect(220);
            if (!$ok) {
                fclose($socket);
                return ['success' => false, 'message' => "STARTTLS not supported or rejected: " . trim($resp), 'debug' => implode("\n", $debugLog)];
            }

            $crypto = @stream_socket_enable_crypto(
                $socket,
                true,
                STREAM_CRYPTO_METHOD_TLS_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT
            );

            if (!$crypto) {
                fclose($socket);
                return ['success' => false, 'message' => "TLS handshake failed with {$host}.", 'debug' => implode("\n", $debugLog)];
            }

            $log("TLS connection secured.");
            // Re-send EHLO after TLS negotiation
            $write("EHLO $clientHost");
            $read();
        }

        // 4. Authenticate if username is provided
        if ($username !== '') {
            $write("AUTH LOGIN");
            list($ok, $resp) = $expect(334);
            if (!$ok) {
                fclose($socket);
                return ['success' => false, 'message' => "AUTH LOGIN command rejected: " . trim($resp), 'debug' => implode("\n", $debugLog)];
            }

            $write(base64_encode($username));
            list($ok, $resp) = $expect(334);
            if (!$ok) {
                fclose($socket);
                return ['success' => false, 'message' => "SMTP Username rejected: " . trim($resp), 'debug' => implode("\n", $debugLog)];
            }

            $write(base64_encode($password), true);
            list($ok, $resp) = $expect(235);
            if (!$ok) {
                fclose($socket);
                return ['success' => false, 'message' => "SMTP Authentication failed (check your username and password): " . trim($resp), 'debug' => implode("\n", $debugLog)];
            }
            $log("SMTP authenticated successfully.");
        }

        // 5. MAIL FROM
        $write("MAIL FROM:<$fromEmail>");
        list($ok, $resp) = $expect(250);
        if (!$ok) {
            fclose($socket);
            return ['success' => false, 'message' => "MAIL FROM rejected for <$fromEmail>: " . trim($resp), 'debug' => implode("\n", $debugLog)];
        }

        // 6. RCPT TO
        $write("RCPT TO:<$to>");
        list($ok, $resp) = $expect(250);
        if (!$ok) {
            // Check for 251 forwarding
            if (substr($resp, 0, 3) !== '251') {
                fclose($socket);
                return ['success' => false, 'message' => "Recipient <$to> was rejected by the server: " . trim($resp), 'debug' => implode("\n", $debugLog)];
            }
        }

        // 7. DATA
        $write("DATA");
        list($ok, $resp) = $expect(354);
        if (!$ok) {
            fclose($socket);
            return ['success' => false, 'message' => "DATA command rejected: " . trim($resp), 'debug' => implode("\n", $debugLog)];
        }

        // 8. Headers & Body
        $msgDate = date('r');
        $msgId   = '<' . md5(uniqid(microtime(true), true)) . '@' . $clientHost . '>';
        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $encodedFromName = '=?UTF-8?B?' . base64_encode($fromName) . '?=';

        $headers  = "Date: $msgDate\r\n";
        $headers .= "Message-ID: $msgId\r\n";
        $headers .= "From: $encodedFromName <$fromEmail>\r\n";
        $headers .= "To: <$to>\r\n";
        $headers .= "Subject: $encodedSubject\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $headers .= "Content-Transfer-Encoding: 8bit\r\n";
        $headers .= "X-Mailer: Optibiz Mailer Engine\r\n";

        // Dot-stuffing for SMTP lines starting with a period
        $safeBody = preg_replace('/^\./m', '..', str_replace("\r\n", "\n", $htmlContent));
        $safeBody = str_replace("\n", "\r\n", $safeBody);

        $payload = $headers . "\r\n" . $safeBody . "\r\n.";
        fputs($socket, $payload . "\r\n");

        list($ok, $resp) = $expect(250);
        if (!$ok) {
            fclose($socket);
            return ['success' => false, 'message' => "Message body rejected by server: " . trim($resp), 'debug' => implode("\n", $debugLog)];
        }

        // 9. QUIT
        $write("QUIT");
        fclose($socket);

        return [
            'success' => true,
            'message' => "Email delivered successfully via SMTP ($host:$port).",
            'debug'   => implode("\n", $debugLog),
        ];
    }
}

if (!function_exists('sa_native_mail_send')) {
    /**
     * Fallback sending via PHP's native mail() function.
     */
    function sa_native_mail_send($cfg, $to, $subject, $htmlContent)
    {
        $fromEmail  = trim($cfg['mail_from_email'] ?? 'noreply@localhost');
        $fromName   = trim($cfg['mail_from_name'] ?? 'Platform');
        $encodedFromName = '=?UTF-8?B?' . base64_encode($fromName) . '?=';
        $encodedSubject  = '=?UTF-8?B?' . base64_encode($subject) . '?=';

        $headers  = "From: $encodedFromName <$fromEmail>\r\n";
        $headers .= "Reply-To: <$fromEmail>\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $headers .= "X-Mailer: Optibiz Native Mailer\r\n";

        $extraParam = '-f' . escapeshellarg($fromEmail);

        // Suppress warning and capture error
        $sent = @mail($to, $encodedSubject, $htmlContent, $headers, $extraParam);
        if (!$sent) {
            $sent = @mail($to, $encodedSubject, $htmlContent, $headers);
        }

        if ($sent) {
            return [
                'success' => true,
                'message' => "Email sent successfully via PHP mail() function.",
                'debug'   => "Sent to $to using PHP mail()",
            ];
        }

        $lastErr = error_get_last();
        $errMsg  = isset($lastErr['message']) ? $lastErr['message'] : 'PHP mail() function returned false. Ensure sendmail or SMTP is configured in php.ini.';

        return [
            'success' => false,
            'message' => "PHP mail() failed: $errMsg",
            'debug'   => $errMsg,
        ];
    }
}

if (!function_exists('sa_resend_send')) {
    /**
     * Send email via Resend HTTPS REST API (Port 443 — works on all cloud hosts, never blocked).
     */
    function sa_resend_send($cfg, $to, $subject, $htmlContent)
    {
        $apiKey = trim($cfg['resend_api_key'] ?? '');
        if (!$apiKey) {
            return ['success' => false, 'message' => 'Resend API key is missing. Please set RESEND_API_KEY or enter your key in Superadmin Settings.'];
        }

        $fromEmail = trim($cfg['mail_from_email'] ?? '');
        if (empty($fromEmail) || in_array(strtolower($fromEmail), ['admin@example.com', 'noreply@localhost'], true) || strpos($fromEmail, 'example.com') !== false) {
            $fromEmail = 'onboarding@resend.dev';
        }
        $fromName  = trim($cfg['mail_from_name'] ?? 'Optibiz');

        $recipients = is_array($to) ? array_values(array_filter($to)) : [trim($to)];

        $payload = [
            'from'    => $fromName . ' <' . $fromEmail . '>',
            'to'      => $recipients,
            'subject' => $subject,
            'html'    => $htmlContent,
        ];

        $postData = json_encode($payload);

        if (function_exists('curl_init')) {
            $ch = curl_init('https://api.resend.com/emails');
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => [
                    'Authorization: Bearer ' . $apiKey,
                    'Content-Type: application/json',
                ],
                CURLOPT_POSTFIELDS     => $postData,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);

            $response = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr  = curl_error($ch);
            curl_close($ch);

            if ($curlErr) {
                return ['success' => false, 'message' => 'Resend API connection error: ' . $curlErr, 'debug' => $curlErr];
            }
        } else {
            $ctx = stream_context_create([
                'http' => [
                    'method'        => 'POST',
                    'header'        => "Authorization: Bearer {$apiKey}\r\nContent-Type: application/json\r\n",
                    'content'       => $postData,
                    'timeout'       => 15,
                    'ignore_errors' => true,
                ],
                'ssl' => [
                    'verify_peer' => true,
                ],
            ]);
            $response = @file_get_contents('https://api.resend.com/emails', false, $ctx);
            $httpCode = 200;
            if (isset($http_response_header) && is_array($http_response_header)) {
                if (preg_match('#HTTP/\S+\s+(\d+)#', $http_response_header[0], $m)) {
                    $httpCode = (int) $m[1];
                }
            }
        }

        $json = json_decode($response, true);
        if ($httpCode >= 200 && $httpCode < 300) {
            $id = $json['id'] ?? 'sent';
            return ['success' => true, 'message' => "Email sent successfully via Resend API (ID: {$id}).", 'debug' => $response, 'id' => $id];
        }

        $errMsg = $json['message'] ?? ($json['name'] ?? ('HTTP ' . $httpCode . ': ' . $response));
        return ['success' => false, 'message' => 'Resend API error: ' . $errMsg, 'debug' => $response];
    }
}

if (!function_exists('sa_brevo_send')) {
    /**
     * Send email via Brevo HTTPS REST API (Port 443 — works on all cloud hosts, never blocked).
     */
    function sa_brevo_send($cfg, $to, $subject, $htmlContent)
    {
        $apiKey = trim($cfg['brevo_api_key'] ?? '');
        if (!$apiKey) {
            return ['success' => false, 'message' => 'Brevo API key is missing. Please enter your Brevo API key in Superadmin Settings.'];
        }

        $fromEmail = trim($cfg['mail_from_email'] ?? '');
        $fromName  = trim($cfg['mail_from_name'] ?? 'Optibiz');
        if (!$fromEmail) {
            return ['success' => false, 'message' => 'Sender email is required for Brevo.'];
        }

        $payload = [
            'sender'      => ['name' => $fromName, 'email' => $fromEmail],
            'to'          => [['email' => $to]],
            'subject'     => $subject,
            'htmlContent' => $htmlContent,
        ];

        $ch = curl_init('https://api.brevo.com/v3/smtp/email');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'api-key: ' . $apiKey,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            return ['success' => false, 'message' => 'Brevo API connection error: ' . $curlErr, 'debug' => $curlErr];
        }

        $json = json_decode($response, true);
        if ($httpCode >= 200 && $httpCode < 300) {
            $msgId = $json['messageId'] ?? 'sent';
            return ['success' => true, 'message' => "Email sent successfully via Brevo API (ID: {$msgId}).", 'debug' => $response];
        }

        $errMsg = $json['message'] ?? ('HTTP ' . $httpCode . ': ' . $response);
        return ['success' => false, 'message' => 'Brevo API error: ' . $errMsg, 'debug' => $response];
    }
}
