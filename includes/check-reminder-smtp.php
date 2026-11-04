<?php
// Authentication-only diagnostic: never issues MAIL FROM, RCPT TO or DATA.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/includes/portal.php';
$settings = portalSettings($pdo);
$host = trim((string)($settings['smtp_host'] ?? '')) ?: rhuEnv('SMTP_HOST', 'smtp.gmail.com');
$port = (int)(trim((string)($settings['smtp_port'] ?? '')) ?: rhuEnv('SMTP_PORT', '587'));
$user = trim((string)($settings['smtp_user'] ?? '')) ?: rhuEnv('SMTP_USER', '');
$password = str_replace(' ', '', rhuEnv('SMTP_PASS', '') ?: rhuEnv('SMTP_PASSWORD', ''));
if ($user === '' || $password === '') { fwrite(STDERR, "SMTP credentials are missing.\n"); exit(1); }
$socket = null;
try {
    $context = stream_context_create(['ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'allow_self_signed'=>false,'peer_name'=>$host]]);
    $socket = @stream_socket_client(($port === 465 ? 'ssl://' : 'tcp://').$host.':'.$port, $errno, $errstr, 12, STREAM_CLIENT_CONNECT, $context);
    if (!$socket) throw new RuntimeException('SMTP connection could not be established (code '.(int)$errno.').');
    stream_set_timeout($socket, 15);
    $read = static function () use ($socket): int {
        $reply = '';
        while (($line=fgets($socket,512)) !== false) { $reply .= $line; if (substr($line,3,1)===' ') break; }
        return (int)substr($reply,0,3);
    };
    $command = static function (string $line, int $expected) use ($socket,$read): void {
        fwrite($socket,$line."\r\n"); $code=$read();
        if ($code !== $expected) throw new RuntimeException('SMTP authentication check rejected (response '.$code.').');
    };
    if ($read() !== 220) throw new RuntimeException('SMTP server did not send a greeting.');
    $command('EHLO '.gethostname(),250);
    if ($port !== 465) {
        $command('STARTTLS',220);
        if (!stream_socket_enable_crypto($socket,true,STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) throw new RuntimeException('SMTP TLS negotiation failed.');
        $command('EHLO '.gethostname(),250);
    }
    $command('AUTH LOGIN',334);
    $command(base64_encode($user),334);
    $command(base64_encode($password),235);
    fwrite($socket,"QUIT\r\n");
    echo "SMTP TLS connection and authentication passed. No email sent.\n";
} catch (Throwable $error) {
    fwrite(STDERR,$error->getMessage()."\n"); exit(1);
} finally { if (is_resource($socket)) fclose($socket); }
