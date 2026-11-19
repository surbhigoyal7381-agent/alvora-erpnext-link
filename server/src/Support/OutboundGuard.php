<?php

namespace Alvora\ErpnextLink\Support;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;

/**
 * The only way this extension talks to a client's ERPNext. A call goes ahead only to the public
 * https address of a site: https, port 443, no user info, a dotted DNS name (not an IP, not a
 * private suffix) whose every answer is a public address. The connection is then pinned to the
 * address that was checked, so a second lookup cannot send it somewhere else. Redirects,
 * proxies and other protocols are refused; the certificate is always verified.
 */
class OutboundGuard
{
    public const CONNECT_TIMEOUT = 5;

    public const TIMEOUT = 10;

    public const MAX_BODY = 65536;

    /** The DNS lookup has no timeout of its own, so it runs in a child process that is killed after this many seconds. */
    public const LOOKUP_TIMEOUT = 5;

    private const PRIVATE_SUFFIXES = ['local', 'localhost', 'internal', 'localdomain', 'lan', 'home', 'corp', 'intranet', 'arpa', 'test', 'invalid', 'example'];

    /** @var \Closure(string): array<int, string> */
    private \Closure $resolver;

    /** @var \Closure(): Client */
    private \Closure $clientFactory;

    public function __construct(?\Closure $resolver = null, ?\Closure $clientFactory = null)
    {
        $this->resolver      = $resolver ?? self::lookup(...);
        $this->clientFactory = $clientFactory ?? fn () => new Client();
    }

    /**
     * The host a person typed, kept for display only: user info, port and path are dropped.
     */
    public static function displayHost(string $address): string
    {
        $host = parse_url(trim($address), PHP_URL_HOST);
        $host = is_string($host) ? strtolower(rtrim($host, '.')) : '';

        return preg_match('/^[a-z0-9.\-\[\]:]{1,255}$/', $host) ? $host : 'unknown';
    }

    /**
     * Validate the typed address and return its host name, or throw address_blocked.
     */
    public static function hostOf(string $address): string
    {
        $parts = parse_url(trim($address));
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new OutboundFailure('address_blocked');
        }
        if (isset($parts['port']) && $parts['port'] !== 443) {
            throw new OutboundFailure('address_blocked');
        }
        if (!in_array($parts['path'] ?? '', ['', '/'], true)) {
            throw new OutboundFailure('address_blocked');
        }

        $host = strtolower(rtrim($parts['host'] ?? '', '.'));
        $tld  = substr(strrchr($host, '.') ?: '', 1);
        if (!preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{1,62}$/', $host) || in_array($tld, self::PRIVATE_SUFFIXES, true)) {
            throw new OutboundFailure('address_blocked');
        }

        return $host;
    }

    /**
     * Resolve the host once and return the address to connect to; every answer must be public.
     */
    public function resolve(string $host): string
    {
        try {
            $addresses = ($this->resolver)($host);
        } catch (OutboundFailure $e) {
            throw $e;
        } catch (\Throwable) {
            throw new OutboundFailure('address_blocked');
        }

        if (!is_array($addresses) || $addresses === []) {
            throw new OutboundFailure('address_blocked');
        }
        foreach ($addresses as $address) {
            if (!is_string($address) || !self::isPublic($address)) {
                throw new OutboundFailure('address_blocked');
            }
        }

        return $addresses[0];
    }

    /**
     * POST a JSON body to https://{host}{path} and return [status, decoded JSON or null].
     * Throws OutboundFailure: address_blocked, site_timeout or site_error.
     *
     * @return array{0: int, 1: array|null}
     */
    public function post(string $address, string $path, string $body, array $headers = []): array
    {
        $host = self::hostOf($address);
        $ip   = $this->resolve($host);

        $options = [
            'headers'         => $headers + ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
            'body'            => $body,
            'timeout'         => self::TIMEOUT,
            'connect_timeout' => self::CONNECT_TIMEOUT,
            'allow_redirects' => false,
            'http_errors'     => false,
            'verify'          => true,
            'proxy'           => '',
            'curl'            => [
                // Guzzle's own 'progress' option ignores the callback's answer, so the cap is set on curl itself:
                // returning 1 aborts the transfer (cURL error 42), which is mapped to site_error below.
                CURLOPT_NOPROGRESS       => false,
                CURLOPT_XFERINFOFUNCTION => fn ($handle, $downloadTotal, $downloaded) => $downloaded > self::MAX_BODY ? 1 : 0,
                CURLOPT_RESOLVE          => [$host . ':443:' . $ip],
                CURLOPT_PROTOCOLS        => CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS  => CURLPROTO_HTTPS,
            ],
        ];

        try {
            $response = ($this->clientFactory)()->post('https://' . $host . $path, $options);
        } catch (ConnectException $e) {
            throw new OutboundFailure(self::isTimeout($e) ? 'site_timeout' : 'site_error');
        } catch (\Throwable) {
            throw new OutboundFailure('site_error');
        }

        $status = $response->getStatusCode();
        if ($status >= 300 && $status < 400) {
            throw new OutboundFailure('address_blocked');
        }

        $json = json_decode(substr((string) $response->getBody(), 0, self::MAX_BODY), true);

        return [$status, is_array($json) ? $json : null];
    }

    public static function isPublic(string $address): bool
    {
        $packed = @inet_pton($address);
        if ($packed === false) {
            return false;
        }

        return strlen($packed) === 4 ? self::publicV4($packed) : self::publicV6($packed);
    }

    /**
     * Default resolver: every A and AAAA answer for the name. PHP's DNS call cannot be given a timeout, so it
     * runs in a child PHP process (the host is already validated and goes in as an argument, not through a
     * shell) that is killed after $timeout seconds: site_timeout. Used by the queue worker, which is the CLI.
     *
     * @return array<int, string>
     */
    public static function lookup(string $host, int $timeout = self::LOOKUP_TIMEOUT): array
    {
        $code    = 'echo json_encode(array_map(fn ($r) => $r["ip"] ?? $r["ipv6"] ?? null, @dns_get_record($argv[1], DNS_A | DNS_AAAA) ?: []));';
        $process = proc_open([PHP_BINARY, '-r', $code, '--', $host], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        // proc_open() returning false leaves $pipes empty: the next line fails, and resolve() turns any failure into address_blocked.
        $output   = '';
        $deadline = microtime(true) + $timeout;
        while (!feof($pipes[1])) {
            $left = $deadline - microtime(true);
            $read = [$pipes[1]];
            $none = null;
            if ($left <= 0 || !stream_select($read, $none, $none, (int) $left, (int) (fmod($left, 1) * 1e6))) {
                proc_terminate($process, 9);
                fclose($pipes[1]);
                proc_close($process);

                throw new OutboundFailure('site_timeout');
            }
            $output .= (string) fread($pipes[1], 8192);
        }
        fclose($pipes[1]);
        proc_close($process);

        $addresses = json_decode($output, true);

        return is_array($addresses) ? array_values(array_filter($addresses, 'is_string')) : [];
    }

    private static function publicV4(string $packed): bool
    {
        $ip = unpack('N', $packed)[1];
        // [network, prefix length]: every range that is not ordinary public unicast.
        foreach ([
            ['0.0.0.0', 8], ['10.0.0.0', 8], ['100.64.0.0', 10], ['127.0.0.0', 8], ['169.254.0.0', 16],
            ['172.16.0.0', 12], ['192.0.0.0', 24], ['192.0.2.0', 24], ['192.88.99.0', 24], ['192.168.0.0', 16],
            ['198.18.0.0', 15], ['198.51.100.0', 24], ['203.0.113.0', 24], ['224.0.0.0', 4], ['240.0.0.0', 4],
        ] as [$network, $prefix]) {
            $mask = $prefix === 0 ? 0 : (~0 << (32 - $prefix)) & 0xFFFFFFFF;
            if (($ip & $mask) === (ip2long($network) & $mask)) {
                return false;
            }
        }

        return true;
    }

    private static function publicV6(string $packed): bool
    {
        // Only global unicast 2000::/3 is allowed, minus documentation, Teredo and 6to4 ranges.
        // Loopback, unspecified, mapped (::ffff:0:0/96), NAT64, unique-local (fc00::/7, which holds
        // fd00:ec2::254), link-local and multicast all fall outside 2000::/3 and are refused.
        if ((ord($packed[0]) & 0xE0) !== 0x20) {
            return false;
        }
        $head = substr($packed, 0, 4);
        if ($head === "\x20\x01\x0d\xb8" || $head === "\x20\x01\x00\x00" || substr($packed, 0, 2) === "\x20\x02") {
            return false;
        }

        return true;
    }

    private static function isTimeout(ConnectException $e): bool
    {
        return ($e->getHandlerContext()['errno'] ?? null) === CURLE_OPERATION_TIMEDOUT;
    }
}
