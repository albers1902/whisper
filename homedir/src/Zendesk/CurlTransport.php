<?php
declare(strict_types=1);

namespace Whisper\Zendesk;

/**
 * HTTP med curl-udvidelsen (spec: ingen Composer). Kun https, ingen redirects (Client afgør dem), certifikater tjekkes.
 * En netværksfejl bliver til en kort ZendeskError med curl's fejlnummer — aldrig curl's tekst, som kan indeholde URL'en.
 */
final class CurlTransport implements HttpTransport
{
    public const MAX_DOWNLOAD = 500 * 1024 * 1024;

    public static function available(): bool
    {
        return extension_loaded('curl');
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null, ?string $saveTo = null): HttpResult
    {
        if (!self::available()) {
            throw new ZendeskError('PHP-udvidelsen curl mangler');
        }
        $ch = curl_init();
        if ($ch === false) {
            throw new ZendeskError('curl kunne ikke starte');
        }
        $lines = [];
        foreach ($headers as $k => $v) {
            $lines[] = $k . ': ' . $v;
        }
        $respHeaders = [];
        $file = null;
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $lines,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT        => $saveTo !== null ? 1800 : 60,
            CURLOPT_USERAGENT      => 'whisper-sync/1',
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$respHeaders): int {
                $p = strpos($line, ':');
                if ($p !== false) {
                    $respHeaders[strtolower(trim(substr($line, 0, $p)))] = trim(substr($line, $p + 1));
                } elseif (str_starts_with($line, 'HTTP/')) {
                    $respHeaders = [];   // ny statuslinje (fx efter 100 Continue): kun det sidste svars headere
                }
                return strlen($line);
            },
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        if ($saveTo !== null) {
            $file = fopen($saveTo, 'wb');
            if ($file === false) {
                curl_close($ch);
                throw new ZendeskError('kunne ikke åbne midlertidig fil');
            }
            curl_setopt($ch, CURLOPT_FILE, $file);
            // Loft over en optagelse: Content-Length over loftet afvises straks, og uden Content-Length stopper
            // fremdriftstjekket hentningen, når loftet passeres (curl 42), så en fejl hos Zendesk ikke fylder disken
            curl_setopt($ch, CURLOPT_MAXFILESIZE_LARGE, self::MAX_DOWNLOAD);
            curl_setopt($ch, CURLOPT_NOPROGRESS, false);
            curl_setopt($ch, CURLOPT_XFERINFOFUNCTION, static fn($ch, int $dlTotal, int $dlNow): int => $dlNow > self::MAX_DOWNLOAD ? 1 : 0);
        } else {
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        }
        $out = curl_exec($ch);
        $errno = curl_errno($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($file !== null) {
            fclose($file);
        }
        if (in_array($errno, [42, 63], true)) {   // afbrudt af fremdriftstjekket / CURLE_FILESIZE_EXCEEDED
            throw new ZendeskError('optagelsen er over loftet på 500 MB', 413);
        }
        if ($errno !== 0 || $out === false) {
            throw new ZendeskError('netværksfejl (curl ' . $errno . ')');
        }
        return new HttpResult($status, $respHeaders, is_string($out) ? $out : '');
    }
}
