<?php
// Shared Mailer module for ResiHUnity RHU.
// Configured to use chedricbascoguin27@gmail.com as primary system email sender.

if (!function_exists('sendRHUEmail')) {
    function rhuLoadMailSettings(?PDO $connection): array {
        if (!$connection) return [];
        $settings = [];
        try {
            // Read SMTP-specific audit entries, not an unrelated latest update.
            if (function_exists('rhuTableExists') && rhuTableExists($connection, 'audit_logs')) {
                $rows = $connection->query("SELECT description FROM audit_logs
                    WHERE action = 'Settings Updated' AND module_name = 'System'
                      AND description LIKE '%\"smtp_%'
                    ORDER BY id ASC")->fetchAll(PDO::FETCH_COLUMN);
                foreach ($rows as $json) {
                    $values = json_decode((string)$json, true);
                    if (is_array($values)) foreach ($values as $key=>$value) {
                        if (str_starts_with((string)$key, 'smtp_')) $settings[$key] = $value;
                    }
                }
            }
            // The current persisted settings override historical audit snapshots.
            if (function_exists('rhuTableExists') && rhuTableExists($connection, 'portal_settings')) {
                $rows = $connection->query("SELECT setting_key, setting_value FROM portal_settings WHERE setting_key LIKE 'smtp_%'")->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as $row) $settings[$row['setting_key']] = $row['setting_value'];
            }
        } catch (Throwable $error) { error_log('Mailer settings could not be loaded.'); }
        return $settings;
    }
    function rhuEmailLogoUrl(): string {
        if (function_exists('rhuEnv')) {
            $configured = trim((string)rhuEnv('RHU_EMAIL_LOGO_URL', ''));
            if ($configured !== '') return $configured;
        }
        return 'https://ruralhealthunit.page.gd/resihunity_logo.jpg';
    }

    function rhuWrapEmailHtml(string $subject, string $htmlContent): string {
        $logoUrl = htmlspecialchars(rhuEmailLogoUrl(), ENT_QUOTES, 'UTF-8');
        $safeSubject = htmlspecialchars(trim($subject), ENT_QUOTES, 'UTF-8');

        return '<!doctype html><html><head><meta charset="UTF-8"></head>'
            . '<body style="margin:0;padding:0;background:#f1f5f9;font-family:Arial,Helvetica,sans-serif;color:#0f172a;">'
            . '<div style="max-width:640px;margin:0 auto;padding:24px 12px;">'
            . '<div style="background:#ffffff;border:1px solid #dbe7e1;border-radius:18px;overflow:hidden;box-shadow:0 16px 34px rgba(15,23,42,.08);">'
            . '<div style="padding:20px 24px;background:linear-gradient(135deg,#ecfdf5,#e0f2fe);border-bottom:1px solid #dbe7e1;">'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0"><tr>'
            . '<td style="width:58px;vertical-align:middle;"><img src="' . $logoUrl . '" alt="ResiHUnity RHU Logo" width="50" height="50" style="display:block;width:50px;height:50px;border-radius:14px;object-fit:cover;background:#ffffff;border:1px solid #b7ead7;"></td>'
            . '<td style="vertical-align:middle;padding-left:12px;"><div style="font-size:18px;font-weight:800;color:#064e3b;line-height:1.2;">ResiHUnity RHU</div><div style="font-size:12px;font-weight:700;color:#0f766e;margin-top:3px;">' . $safeSubject . '</div></td>'
            . '</tr></table></div>'
            . '<div style="padding:24px;line-height:1.6;font-size:14px;">' . $htmlContent . '</div>'
            . '<div style="padding:14px 24px;background:#f8fafc;border-top:1px solid #e2e8f0;font-size:11px;color:#64748b;">Official email notification from ResiHUnity RHU.</div>'
            . '</div></div></body></html>';
    }

    function sendRHUEmail(string $toEmail, string $subject, string $htmlContent, bool $requireSmtp = false): array {
        global $pdo;
        @include_once dirname(__DIR__) . '/db.php';

        $mailSettings = rhuLoadMailSettings(isset($pdo) && $pdo instanceof PDO ? $pdo : null);
        $smtpHost = trim((string)($mailSettings['smtp_host'] ?? '')) ?: (function_exists('rhuEnv') ? rhuEnv('SMTP_HOST', 'smtp.gmail.com') : 'smtp.gmail.com');
        $smtpPort = (int)(trim((string)($mailSettings['smtp_port'] ?? '')) ?: (function_exists('rhuEnv') ? rhuEnv('SMTP_PORT', '587') : 587));
        $smtpUser = trim((string)($mailSettings['smtp_user'] ?? '')) ?: (function_exists('rhuEnv') ? (rhuEnv('SMTP_USER', '') ?: 'chedricbascoguin27@gmail.com') : 'chedricbascoguin27@gmail.com');
        $smtpPass = function_exists('rhuEnv') ? (rhuEnv('SMTP_PASS', '') ?: rhuEnv('SMTP_PASSWORD', '')) : '';
        $smtpPass = str_replace(' ', '', $smtpPass);
        $smtpFrom = trim((string)($mailSettings['smtp_from'] ?? '')) ?: (function_exists('rhuEnv') ? (rhuEnv('SMTP_FROM', '') ?: ($smtpUser ?: 'chedricbascoguin27@gmail.com')) : 'chedricbascoguin27@gmail.com');
        $smtpName = 'ResiHUnity RHU';

        if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'method' => 'validation', 'error' => 'Invalid recipient email address.'];
        }

        $htmlContent = rhuWrapEmailHtml($subject, $htmlContent);
        $logPath = dirname(__DIR__) . '/scratch/mail-delivery.log';
        $safeSubject = trim(str_replace(["\r", "\n"], '', $subject));
        $logPrefix = "[" . date('Y-m-d H:i:s') . "] FROM: {$smtpFrom} | TO: {$toEmail} | SUBJECT: {$safeSubject} | ";

        $smtpError = 'SMTP credentials are missing.';
        if ($requireSmtp && ($smtpUser === '' || $smtpPass === '')) {
            error_log('sendRHUEmail: SMTP credentials are missing on this server.');
            return ['success'=>false, 'method'=>'SMTP', 'error'=>'SMTP credentials are missing on this server. Configure SMTP_USER and SMTP_PASS in the hosted environment.'];
        }
        if ($smtpUser !== '' && $smtpPass !== '') {
            try {
                $context = stream_context_create([
                    'ssl' => [
                        'verify_peer' => true,
                        'verify_peer_name' => true,
                        'allow_self_signed' => false,
                        'peer_name' => $smtpHost,
                    ]
                ]);

                $remote = ($smtpPort === 465) ? "ssl://{$smtpHost}:{$smtpPort}" : "tcp://{$smtpHost}:{$smtpPort}";
                $socket = @stream_socket_client($remote, $errno, $errstr, 12, STREAM_CLIENT_CONNECT, $context);

                if ($socket) {
                    stream_set_timeout($socket, 15);
                    $read = function($sock) {
                        $res = '';
                        while (($line = fgets($sock, 512)) !== false) {
                            $res .= $line;
                            if (substr($line, 3, 1) === ' ') break;
                        }
                        return $res;
                    };

                    $write = function($sock, $cmd, $expected = [250]) use ($read) {
                        fputs($sock, $cmd . "\r\n");
                        $reply = $read($sock);
                        if (!in_array((int) substr($reply, 0, 3), $expected, true)) {
                            throw new RuntimeException('SMTP server rejected a delivery step (response ' . (int) substr($reply, 0, 3) . ').');
                        }
                        return $reply;
                    };

                    $read($socket);
                    $write($socket, 'EHLO ' . gethostname());

                    if ($smtpPort !== 465) {
                        $write($socket, 'STARTTLS', [220]);
                        if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                            throw new RuntimeException('SMTP TLS negotiation failed.');
                        }
                        $write($socket, 'EHLO ' . gethostname());
                    }

                    $write($socket, 'AUTH LOGIN', [334]);
                    $write($socket, base64_encode($smtpUser), [334]);
                    $write($socket, base64_encode($smtpPass), [235]);

                    $write($socket, 'MAIL FROM: <' . $smtpUser . '>');
                    $write($socket, 'RCPT TO: <' . $toEmail . '>', [250, 251]);
                    $write($socket, 'DATA', [354]);

                    $messageIdDomain = substr(strrchr($smtpFrom, '@') ?: '@gmail.com', 1) ?: 'gmail.com';
                    $messageId = '<' . bin2hex(random_bytes(16)) . '@' . $messageIdDomain . '>';

                    $headers  = "MIME-Version: 1.0\r\n";
                    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
                    $headers .= "From: {$smtpName} <{$smtpFrom}>\r\n";
                    $headers .= "Reply-To: {$smtpFrom}\r\n";
                    $headers .= "To: {$toEmail}\r\n";
                    $headers .= "Subject: {$subject}\r\n";
                    $headers .= "Message-ID: {$messageId}\r\n";
                    $headers .= "Date: " . date('r') . "\r\n";
                    $headers .= "X-Mailer: ResiHUnity RHU Mailer\r\n";

                    $emailPayload = $headers . "\r\n" . $htmlContent . "\r\n.";
                    $write($socket, $emailPayload);

                    // DATA was accepted already. A disconnect on QUIT must not
                    // report failure or invalidate a verification email in flight.
                    @fwrite($socket, "QUIT\r\n");
                    fclose($socket);

                    @file_put_contents($logPath, $logPrefix . "SUCCESS (SMTP via {$smtpFrom})\n", FILE_APPEND);
                    return ['success' => true, 'method' => 'SMTP'];
                }
                $message = "SMTP connection failed ({$errno}: " . preg_replace('/[\r\n]+/', ' ', (string)$errstr) . ').';
                $smtpError = $message;
                @file_put_contents($logPath, $logPrefix . "FAILED ({$message})\n", FILE_APPEND);
            } catch (Throwable $e) {
                $smtpError = $e->getMessage();
                if (isset($socket) && is_resource($socket)) fclose($socket);
                error_log('sendRHUEmail SMTP Exception: ' . $e->getMessage());
                @file_put_contents($logPath, $logPrefix . "FAILED (" . $e->getMessage() . ")\n", FILE_APPEND);
            }
        }

        // A configured SMTP failure must remain retryable. A relay accepting a
        // form or a local mail queue does not establish delivery to the resident.
        if ($smtpUser !== '' && $smtpPass !== '') {
            return ['success' => false, 'method' => 'SMTP', 'error' => $smtpError];
        }

        // 2. HTTPS Web Mail API Relay for Instant Delivery
        if (function_exists('curl_init') && filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            try {
                $apiUrl = 'https://formsubmit.co/ajax/' . urlencode($toEmail);
                $linkedContent = preg_replace_callback('/<a\b[^>]*href=["\x27]([^"\x27]+)["\x27][^>]*>(.*?)<\/a>/is', static function ($match) {
                    return strip_tags($match[2]) . ': ' . html_entity_decode($match[1], ENT_QUOTES, 'UTF-8') . "\n";
                }, $htmlContent);
                $plainMessage = trim(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $linkedContent)));
                $postFields = [
                    '_subject' => $subject,
                    'System_Sender' => 'ResiHUnity RHU (' . $smtpFrom . ')',
                    'Sender_Email' => $smtpFrom,
                    'Recipient' => $toEmail,
                    'Message' => $plainMessage !== '' ? $plainMessage : $htmlContent,
                    '_template' => 'table'
                ];

                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $apiUrl);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postFields));
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 8);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                $relayResult = json_decode((string) $response, true);
                if ($httpCode >= 200 && $httpCode < 300 && is_array($relayResult)
                    && in_array($relayResult['success'] ?? false, [true, 'true'], true)) {
                    @file_put_contents($logPath, $logPrefix . "SUCCESS (HTTPS_API via {$smtpFrom})\n", FILE_APPEND);
                    return ['success' => true, 'method' => 'HTTPS_API'];
                }
            } catch (Exception $ex) {
                error_log('sendRHUEmail HTTP API Error: ' . $ex->getMessage());
            }
        }

        // 3. Native PHP mail() fallback
        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "Content-type: text/html; charset=UTF-8\r\n";
        $headers .= "From: {$smtpName} <{$smtpFrom}>\r\n";
        $headers .= "Reply-To: {$smtpFrom}\r\n";
        $headers .= "X-Mailer: PHP/" . phpversion();

        $sent = @mail($toEmail, $subject, $htmlContent, $headers);
        @file_put_contents($logPath, $logPrefix . ($sent ? "ACCEPTED (PHP Mail)" : "FAILED (PHP Mail)") . "\n", FILE_APPEND);
        return ['success' => $sent, 'method' => 'php_mail',
            'error' => $sent ? '' : $smtpError . ' Fallback delivery also failed.'];
    }
}
