<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Sends email via SMTP (STARTTLS / SSL, AUTH LOGIN), PHP mail(), or a log file.
 * Configured from System → Email settings (stored in the `settings` table).
 */
final class Mailer
{
    public static function config(): array
    {
        $c = (array) Settings::get('mail', []);
        return $c + [
            'driver' => 'mail', 'host' => '', 'port' => 587, 'encryption' => 'tls',
            'username' => '', 'password_enc' => '', 'from_email' => '', 'from_name' => app_name(),
        ];
    }

    public static function send(string $to, string $subject, string $html, ?string $text = null): void
    {
        $c = self::config();
        $to = trim(str_replace(["\r", "\n"], '', $to));
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Invalid recipient address.');
        }
        $subject = trim(str_replace(["\r", "\n"], ' ', $subject));
        $text ??= trim(html_entity_decode(strip_tags((string) preg_replace('#<br\s*/?>|</p>|</h\d>#i', "\n", $html)), ENT_QUOTES, 'UTF-8'));
        $fromEmail = $c['from_email'] ?: ('no-reply@' . (parse_url((string) Env::get('APP_URL', 'http://localhost'), PHP_URL_HOST) ?: 'localhost'));
        $fromName = str_replace(['"', "\r", "\n"], '', (string) ($c['from_name'] ?: app_name()));

        $boundary = 'np_' . bin2hex(random_bytes(12));
        $headers = [
            'Date: ' . date(DATE_RFC2822),
            'From: "' . self::encodeHeader($fromName) . '" <' . $fromEmail . '>',
            'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . (explode('@', $fromEmail)[1] ?? 'localhost') . '>',
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        ];
        $body = "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($text)) . "--$boundary\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($html)) . "--$boundary--\r\n";

        switch ($c['driver']) {
            case 'log':
                @file_put_contents(APP_ROOT . '/storage/logs/mail.log', "=== " . gmdate('c') . " To: $to | Subject: $subject\n$text\n\n", FILE_APPEND | LOCK_EX);
                return;
            case 'smtp':
                self::smtp($c, $fromEmail, $to, 'Subject: ' . self::encodeHeader($subject) . "\r\nTo: <$to>\r\n" . implode("\r\n", $headers) . "\r\n\r\n" . $body);
                return;
            default:
                if (!@mail($to, self::encodeHeader($subject), $body, implode("\r\n", $headers), '-f' . $fromEmail)) {
                    throw new \RuntimeException('PHP mail() failed. Configure SMTP in System → Email.');
                }
        }
    }

    private static function encodeHeader(string $v): string
    {
        return preg_match('/[^\x20-\x7E]/', $v) ? '=?UTF-8?B?' . base64_encode($v) . '?=' : $v;
    }

    private static function smtp(array $c, string $from, string $to, string $message): void
    {
        $enc = $c['encryption'] ?? 'tls';
        $remote = ($enc === 'ssl' ? 'ssl://' : 'tcp://') . $c['host'] . ':' . (int) $c['port'];
        $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $fp = @stream_socket_client($remote, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
        if (!$fp) {
            throw new \RuntimeException("Could not connect to SMTP server ($errstr).");
        }
        stream_set_timeout($fp, 20);
        $read = static function () use ($fp): string {
            $data = '';
            while (($line = fgets($fp, 515)) !== false) {
                $data .= $line;
                if (strlen($line) < 4 || $line[3] === ' ') {
                    break;
                }
            }
            return $data;
        };
        $cmd = static function (?string $line, array $ok) use ($fp, $read): string {
            if ($line !== null) {
                fwrite($fp, $line . "\r\n");
            }
            $resp = $read();
            if (!in_array((int) substr($resp, 0, 3), $ok, true)) {
                throw new \RuntimeException('SMTP error: ' . trim($resp));
            }
            return $resp;
        };
        $host = parse_url((string) Env::get('APP_URL', 'http://localhost'), PHP_URL_HOST) ?: 'localhost';
        try {
            $cmd(null, [220]);
            $cmd('EHLO ' . $host, [250]);
            if ($enc === 'tls') {
                $cmd('STARTTLS', [220]);
                if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new \RuntimeException('STARTTLS negotiation failed.');
                }
                $cmd('EHLO ' . $host, [250]);
            }
            if (!empty($c['username'])) {
                $pass = !empty($c['password_enc']) ? Crypto::decrypt((string) $c['password_enc']) : '';
                $cmd('AUTH LOGIN', [334]);
                $cmd(base64_encode((string) $c['username']), [334]);
                $cmd(base64_encode($pass), [235]);
            }
            $cmd('MAIL FROM:<' . $from . '>', [250]);
            $cmd('RCPT TO:<' . $to . '>', [250, 251]);
            $cmd('DATA', [354]);
            $cmd((string) preg_replace('/^\./m', '..', $message) . "\r\n.", [250]);
            $cmd('QUIT', [221]);
        } finally {
            fclose($fp);
        }
    }
}
