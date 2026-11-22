<?php

declare(strict_types=1);

namespace App\Core;

use App\Providers\ProviderException;

/** Minimal cURL wrapper used by providers and the updater. */
final class Http
{
    /** @var null|callable(string,string,array):array  Test hook: replaces real network calls. */
    public static $fake = null;

    /**
     * @param array{headers?:string[],json?:mixed,form?:array,multipart?:array,body?:string,timeout?:int,sink?:string,max_bytes?:int,public_only?:bool,infile?:string,infile_offset?:int,infile_length?:int} $opts
     * @return array{status:int,body:string,json:mixed,headers:array}
     */
    public static function request(string $method, string $url, array $opts = []): array
    {
        if (self::$fake !== null) {
            return (self::$fake)($method, $url, $opts);
        }
        if (!preg_match('#^https?://#i', $url)) {
            throw new ProviderException('Invalid URL.');
        }
        $pin = null;
        if (!empty($opts['public_only'])) {
            // User-supplied host: resolve once, verify it's public, and pin curl to that IP (prevents DNS rebinding).
            $ip = self::assertPublicUrl($url);
            $p = parse_url($url);
            $port = (int) ($p['port'] ?? (strtolower((string) $p['scheme']) === 'https' ? 443 : 80));
            $pin = [$p['host'] . ':' . $port . ':' . (str_contains($ip, ':') ? '[' . $ip . ']' : $ip)];
            $opts['follow'] = false;
        }
        $ch = curl_init($url);
        if ($pin) {
            curl_setopt($ch, CURLOPT_RESOLVE, $pin);
        }
        $headers = $opts['headers'] ?? [];
        $respHeaders = [];
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $opts['timeout'] ?? 60,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => $opts['follow'] ?? false,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_USERAGENT => 'NextaPost/' . APP_VERSION . ' (+https://nextawebsolution.com)',
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$respHeaders): int {
                $p = explode(':', $line, 2);
                if (count($p) === 2) {
                    $respHeaders[strtolower(trim($p[0]))] = trim($p[1]);
                }
                return strlen($line);
            },
        ]);
        if (isset($opts['json'])) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, (string) json_encode($opts['json'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } elseif (isset($opts['multipart'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $opts['multipart']);
        } elseif (isset($opts['form'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($opts['form']));
        } elseif (isset($opts['body'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $opts['body']);
        }
        $infile = null;
        if (!empty($opts['infile'])) {
            // Stream a file (or a byte range of it) as the request body without loading it into memory.
            $infile = fopen((string) $opts['infile'], 'rb');
            if ($infile === false) {
                throw new ProviderException('Could not read the file to upload.');
            }
            $offset = (int) ($opts['infile_offset'] ?? 0);
            $length = (int) ($opts['infile_length'] ?? (filesize((string) $opts['infile']) - $offset));
            fseek($infile, $offset);
            curl_setopt($ch, CURLOPT_UPLOAD, true);
            curl_setopt($ch, CURLOPT_INFILE, $infile);
            curl_setopt($ch, CURLOPT_INFILESIZE, $length);
            curl_setopt($ch, CURLOPT_TIMEOUT, $opts['timeout'] ?? 900);
        }
        if (!empty($opts['max_bytes'])) {
            // Abort downloads that grow past the limit (the server may not send Content-Length).
            $max = (int) $opts['max_bytes'];
            curl_setopt($ch, CURLOPT_NOPROGRESS, false);
            curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, static fn ($c, $dlTotal, $dl) => ($dlTotal > $max || $dl > $max) ? 1 : 0);
        }
        $sink = null;
        if (!empty($opts['sink'])) {
            $sink = fopen($opts['sink'], 'wb');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
            curl_setopt($ch, CURLOPT_FILE, $sink);
        }
        $headers[] = 'Accept: application/json, */*';
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $resp = curl_exec($ch);
        $err = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($infile) {
            fclose($infile);
        }
        if ($sink) {
            fclose($sink);
        }
        if ($resp === false) {
            throw new ProviderException('Network error: ' . $err, true);
        }
        $body = is_string($resp) ? $resp : '';
        return ['status' => $status, 'body' => $body, 'json' => json_decode($body, true), 'headers' => $respHeaders];
    }

    /**
     * Blocks URLs that point at private, loopback, link-local, CGNAT or metadata networks
     * (SSRF protection for user-supplied URLs). Returns the vetted IP to connect to.
     */
    public static function assertPublicUrl(string $url): string
    {
        $parts = parse_url($url);
        if (!$parts || !in_array(strtolower($parts['scheme'] ?? ''), ['https', 'http'], true) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            throw new ProviderException('Enter a valid https:// URL.');
        }
        if (self::$fake !== null) {
            return '93.184.216.34';
        }
        $host = trim($parts['host'], '[]');
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips = [$host];
        } else {
            $ips = gethostbynamel($host) ?: [];
            foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $r) {
                if (!empty($r['ipv6'])) {
                    $ips[] = $r['ipv6'];
                }
            }
        }
        if (!$ips) {
            throw new ProviderException('We couldn’t resolve ' . $host . '. Check the address.');
        }
        foreach ($ips as $ip) {
            if (!self::isPublicIp($ip)) {
                throw new ProviderException('That address points to a private network and can’t be used.');
            }
        }
        return $ips[0];
    }

    public static function isPublicIp(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $n = ip2long($ip);
            foreach ([['100.64.0.0', 10], ['192.0.0.0', 24], ['198.18.0.0', 15], ['0.0.0.0', 8]] as [$net, $bits]) {
                if (($n & (-1 << (32 - $bits))) === (ip2long($net) & (-1 << (32 - $bits)))) {
                    return false;
                }
            }
        } else {
            $lower = strtolower($ip);
            if (str_starts_with($lower, '::ffff:') || str_starts_with($lower, '64:ff9b:') || str_starts_with($lower, 'fd') || str_starts_with($lower, 'fc') || str_starts_with($lower, 'fe8')) {
                return false;
            }
        }
        return true;
    }
}
