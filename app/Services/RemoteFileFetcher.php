<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

/**
 * Downloads a single broadcast file from an external location.
 *
 * The Laravel HTTP client (Guzzle) only speaks http/https and rejects anything else
 * with "The scheme 'ftp' is not supported". FTP therefore goes through cURL, which
 * handles it natively. Both branches return the raw body, so callers stay identical.
 */
class RemoteFileFetcher
{
    /** Schemes a station may enter for a kind=url source. The form validates against this. */
    public const SUPPORTED_SCHEMES = ['http', 'https', 'ftp', 'ftps'];

    /** Separate, shorter budget for establishing the connection. */
    private const CONNECT_TIMEOUT_SECONDS = 15;

    /**
     * Fetches the file and returns its body.
     *
     * Credentials are optional and apply to both branches: HTTP basic auth for
     * http/https, the FTP login otherwise. They stay out of the URL so they are not
     * written to proxy or access logs.
     *
     * @throws RemoteFetchException on an unsupported scheme, a transport error or an empty body
     */
    public function fetch(string $url, ?string $username = null, ?string $password = null, int $timeoutSeconds = 60): string
    {
        $url = self::normalizeUrl($url);
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        $body = match (true) {
            in_array($scheme, ['http', 'https'], true) => $this->fetchOverHttp($url, $username, $password, $timeoutSeconds),
            in_array($scheme, ['ftp', 'ftps'], true) => $this->fetchOverFtp($url, $scheme, $username, $password, $timeoutSeconds),
            default => throw new RemoteFetchException(__('Unsupported address: :scheme is not one of http, https, ftp, ftps.', ['scheme' => $scheme !== '' ? $scheme : '?'])),
        };

        if ($body === '') {
            throw new RemoteFetchException(__('The download is empty.'));
        }

        return $body;
    }

    /**
     * Percent-encodes what an address may not carry literally, above all the spaces that
     * FTP filenames are full of ("Show - Stafford 1A.mp3"): those make the address fail
     * validation and, once stored, fail the transfer.
     *
     * Only the part after the host is touched, so a login in the authority survives
     * untouched. Sequences that are already encoded are left as they are, and the
     * delimiters ? and # keep their meaning, so running this twice changes nothing.
     */
    public static function normalizeUrl(string $url): string
    {
        $url = trim($url);
        $scheme = strpos($url, '://');

        if ($scheme === false) {
            return $url;
        }

        $pathStart = strpos($url, '/', $scheme + 3);

        if ($pathStart === false) {
            return $url;
        }

        $encoded = preg_replace_callback(
            '/%[0-9A-Fa-f]{2}|[^A-Za-z0-9\-._~!$&\'()*+,;=:@\/?#]/',
            fn (array $match): string => str_starts_with($match[0], '%') && strlen($match[0]) === 3
                ? $match[0]
                : rawurlencode($match[0]),
            substr($url, $pathStart),
        );

        return substr($url, 0, $pathStart).$encoded;
    }

    /**
     * @throws RemoteFetchException
     */
    private function fetchOverHttp(string $url, ?string $username, ?string $password, int $timeoutSeconds): string
    {
        $request = Http::timeout($timeoutSeconds)->connectTimeout(self::CONNECT_TIMEOUT_SECONDS);

        if ((string) $username !== '') {
            $request = $request->withBasicAuth($username, (string) $password);
        }

        try {
            $response = $request->get($url);
        } catch (\Throwable $e) {
            throw new RemoteFetchException(__('Download failed: :error', ['error' => $e->getMessage()]), previous: $e);
        }

        if (! $response->successful()) {
            throw new RemoteFetchException(__('Download failed (HTTP :status).', ['status' => $response->status()]));
        }

        $body = $response->body();

        $this->assertComplete(
            strlen($body),
            $response->header('Content-Length'),
            // A compressed response announces the compressed length while the client hands
            // us the decoded body, so the two are not comparable and the check is skipped.
            $response->header('Content-Encoding') === '',
        );

        return $body;
    }

    /**
     * Guards against a transfer that ended early.
     *
     * A connection that dies mid-body yields a short response without an error: the HTTP
     * client does not verify that as many bytes arrived as were announced. The truncated
     * file used to be stored, trimmed, measured and aired as if it were complete, and its
     * shortened duration was written back to the source, where it went on to distort the
     * timing of every hour planned from it.
     *
     * @throws RemoteFetchException
     */
    private function assertComplete(int $received, string $announcedLength, bool $comparable): void
    {
        if (! $comparable || ! ctype_digit($announcedLength)) {
            return;
        }

        $announced = (int) $announcedLength;

        if ($announced <= 0 || $received >= $announced) {
            return;
        }

        throw new RemoteFetchException(__('The download broke off: :received of :announced bytes arrived.', [
            'received' => number_format($received),
            'announced' => number_format($announced),
        ]));
    }

    /**
     * FTP via cURL.
     *
     * Scheme semantics, chosen to match what servers actually offer today:
     * - ftp://  plain FTP, no encryption.
     * - ftps:// FTP with mandatory TLS. Explicit TLS (AUTH TLS on the regular port) is
     *   the common case, so the address is handed to cURL as ftp:// with CURLUSESSL_ALL.
     *   Port 990 stays implicit FTPS, which is what that port means.
     *
     * @throws RemoteFetchException
     */
    private function fetchOverFtp(string $url, string $scheme, ?string $username, ?string $password, int $timeoutSeconds): string
    {
        if (! function_exists('curl_init')) {
            throw new RemoteFetchException(__('FTP is unavailable: the cURL extension is missing on the server.'));
        }

        $implicit = $scheme === 'ftps' && (int) parse_url($url, PHP_URL_PORT) === 990;

        $options = [
            CURLOPT_URL => $scheme === 'ftps' && ! $implicit ? preg_replace('#^ftps://#i', 'ftp://', $url) : $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_FAILONERROR => true,
            // Passive mode: the server opens no connection back to us, which is the
            // only thing that works from inside a container behind NAT.
            CURLOPT_FTP_USE_EPSV => true,
        ];

        if ($scheme === 'ftps') {
            $options[CURLOPT_USE_SSL] = CURLUSESSL_ALL;
        }

        if ((string) $username !== '') {
            $options[CURLOPT_USERPWD] = $username.':'.(string) $password;
        }

        $handle = curl_init();
        curl_setopt_array($handle, $options);

        $body = curl_exec($handle);
        $error = curl_error($handle);
        $announced = (float) curl_getinfo($handle, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
        curl_close($handle);

        if ($body === false) {
            throw new RemoteFetchException(__('FTP download failed: :error', ['error' => $error !== '' ? $error : __('unknown error')]));
        }

        $body = (string) $body;

        $this->assertComplete(strlen($body), $announced > 0 ? (string) (int) $announced : '', true);

        return $body;
    }
}
