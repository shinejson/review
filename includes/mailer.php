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
     */
    function sa_get_mail_config($conn = null)
    {
        global $conn;
        $site_name = function_exists('sa_setting') && $conn ? sa_setting($conn, 'site_name', 'Optibiz') : 'Optibiz';
        $admin_mail = function_exists('sa_setting') && $conn ? sa_setting($conn, 'admin_email', 'admin@example.com') : 'admin@example.com';

        $defaults = [
            'mail_driver'     => 'smtp',
            'mail_from_name'  => $site_name,
            'mail_from_email' => $admin_mail,
            'smtp_host'       => 'smtp.gmail.com',
            'smtp_port'       => '587',
            'smtp_encryption' => 'tls',
            'smtp_username'   => '',
            'smtp_password'   => '',
            'smtp_timeout'    => 12,
        ];

        if (!$conn) {
            return $defaults;
        }

        $res = [];
        foreach ($defaults as $k => $def) {
            $val = function_exists('sa_setting') ? sa_setting($conn, $k, '') : '';
            $res[$k] = ($val !== '') ? $val : $def;
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

        if ($driver === 'smtp') {
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
