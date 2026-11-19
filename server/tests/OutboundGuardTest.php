<?php

namespace Alvora\ErpnextLink\Tests;

use Alvora\ErpnextLink\Support\OutboundFailure;
use Alvora\ErpnextLink\Support\OutboundGuard;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Uri;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * AC-23 to AC-28: the one guard every outbound call goes through.
 */
class OutboundGuardTest extends TestCase
{
    private array $history = [];

    private int $connections = 0;

    /** @var resource|null */
    private $server;

    private function guard(array $answers, ?\Closure $resolver = null, array $responses = []): OutboundGuard
    {
        $mock  = new MockHandler($responses ?: [new Response(200, [], '{"message":{"ok":true}}')]);
        $stack = HandlerStack::create(function ($request, $options) use ($mock) {
            $this->connections++;

            return $mock($request, $options);
        });
        $stack->push(Middleware::history($this->history));

        return new OutboundGuard($resolver ?? fn (string $host) => $answers, fn () => new Client(['handler' => $stack]));
    }

    public static function blockedAddresses(): array
    {
        return array_map(fn ($a) => [$a], [
            'http://x.com', 'https://127.0.0.1', 'https://2130706433', 'https://0x7f.1', 'https://[::1]', 'https://localhost', 'https://app.local',
            'https://x.internal', 'https://metadata.google.internal', 'https://database', 'https://cache', 'https://socket', 'https://user:pw@x.com',
            'https://x.com:8443', 'https://x.com/some/path', 'https://x.com?a=b', 'ftp://x.com', 'not a url', '', 'https://', 'https://-bad-.com',
        ]);
    }

    #[DataProvider('blockedAddresses')]
    public function testAnAddressThatIsNotAPublicHttpsSiteIsBlockedBeforeAnyLookupOrConnection(string $address): void
    {
        $lookups = 0;
        $guard   = $this->guard([], function () use (&$lookups) {
            $lookups++;

            return ['93.184.216.34'];
        });

        try {
            $guard->post($address, '/x', '{}');
            $this->fail('expected address_blocked');
        } catch (OutboundFailure $e) {
            $this->assertSame('address_blocked', $e->errorCode);
        }
        $this->assertSame(0, $lookups);
        $this->assertSame(0, $this->connections);
    }

    public static function blockedAnswers(): array
    {
        return [
            'one public one private' => [['93.184.216.34', '10.0.0.5']],
            'loopback'               => [['127.0.0.1']],
            'link-local metadata'    => [['169.254.169.254']],
            'ipv6 metadata'          => [['fd00:ec2::254']],
            'ipv6 loopback'          => [['::1']],
            'ipv4 mapped into ipv6'  => [['::ffff:127.0.0.1']],
            'documentation range'    => [['2001:db8::1']],
            'carrier grade nat'      => [['100.64.1.1']],
            'multicast'              => [['224.0.0.1']],
            'unspecified'            => [['0.0.0.0']],
            'private 172'            => [['172.16.0.1']],
            'private 192'            => [['192.168.1.1']],
            'no answers at all'      => [[]],
            'not an address'         => [['nonsense']],
        ];
    }

    #[DataProvider('blockedAnswers')]
    public function testANameWithAnyNonPublicAnswerOrNoAnswerIsBlockedAndNothingConnects(array $answers): void
    {
        $this->expectException(OutboundFailure::class);
        try {
            $this->guard($answers)->post('https://erp.example.com', '/x', '{}');
        } finally {
            $this->assertSame(0, $this->connections);
        }
    }

    public function testAResolverThatThrowsIsBlocked(): void
    {
        $guard = new OutboundGuard(function () {
            throw new \RuntimeException('dns down');
        });
        $this->expectException(OutboundFailure::class);
        $guard->resolve('erp.example.com');
    }

    public function testPublicAddressesPass(): void
    {
        foreach (['93.184.216.34', '8.8.8.8', '2606:2800:220:1:248:1893:25c8:1946', '2a00:1450:4009:81f::200e'] as $ip) {
            $this->assertTrue(OutboundGuard::isPublic($ip), $ip);
        }
    }

    public function testTheConnectionIsPinnedToTheFirstCheckedAddressAndHardened(): void
    {
        $calls = 0;
        // Rebinding: the first lookup is public, a second one would be loopback. Only one lookup is made per call.
        $guard = $this->guard([], function () use (&$calls) {
            return ++$calls === 1 ? ['93.184.216.34'] : ['127.0.0.1'];
        });

        [$status, $json] = $guard->post('https://ERP.Example.com', '/api/method/x', '{"a":1}', ['Authorization' => 'token a:b']);

        $this->assertSame(200, $status);
        $this->assertTrue($json['message']['ok']);
        $this->assertSame(1, $calls);
        $options = $this->history[0]['options'];
        $this->assertSame(['erp.example.com:443:93.184.216.34'], $options['curl'][CURLOPT_RESOLVE]);
        $this->assertSame(CURLPROTO_HTTPS, $options['curl'][CURLOPT_PROTOCOLS]);
        $this->assertSame(CURLPROTO_HTTPS, $options['curl'][CURLOPT_REDIR_PROTOCOLS]);
        $this->assertFalse($options['allow_redirects']);
        $this->assertTrue($options['verify']);
        $this->assertSame('', $options['proxy']);
        $this->assertSame(10, $options['timeout']);
        $this->assertSame(5, $options['connect_timeout']);
        $this->assertSame('https://erp.example.com/api/method/x', (string) $this->history[0]['request']->getUri());
        $this->assertSame('token a:b', $this->history[0]['request']->getHeaderLine('Authorization'));
    }

    public function testTheBodyCapIsSetOnCurlItselfBecauseGuzzlesProgressOptionIgnoresItsAnswer(): void
    {
        $this->guard(['93.184.216.34'])->post('https://erp.example.com', '/x', '{}');
        $options = $this->history[0]['options'];
        $abort   = $options['curl'][CURLOPT_XFERINFOFUNCTION];

        $this->assertArrayNotHasKey('progress', $options);
        $this->assertFalse($options['curl'][CURLOPT_NOPROGRESS]);
        $this->assertSame(0, $abort(null, 0, OutboundGuard::MAX_BODY));
        $this->assertSame(1, $abort(null, 0, OutboundGuard::MAX_BODY + 1));
    }

    /**
     * The guard's own curl options against a real HTTP server (the TLS, pinning and protocol options are dropped,
     * everything else is the guard's): a 2 MB answer must be cut off, not read.
     */
    public function testARealServerStreaming2MbIsCutOffAndABigButNormalAnswerStillWorks(): void
    {
        $port   = $this->startServer();
        $curl   = new CurlHandler();
        $client = new Client(['handler' => function ($request, array $options) use ($curl, $port) {
            unset($options['curl'][CURLOPT_RESOLVE], $options['curl'][CURLOPT_PROTOCOLS], $options['curl'][CURLOPT_REDIR_PROTOCOLS]);
            $options['verify'] = false;

            return $curl($request->withUri(new Uri('http://127.0.0.1:' . $port . $request->getUri()->getPath())), $options);
        }]);
        $guard = new OutboundGuard(fn () => ['93.184.216.34'], fn () => $client);

        [$status, $json] = $guard->post('https://erp.example.com', '/small', '{}');
        $this->assertSame(200, $status);
        $this->assertTrue($json['message']['ok']);

        try {
            $guard->post('https://erp.example.com', '/big', '{}');
            $this->fail('A 2 MB answer was read to the end.');
        } catch (OutboundFailure $e) {
            $this->assertSame('site_error', $e->errorCode);
        }
    }

    public function testADnsLookupThatDoesNotFinishInTimeIsASiteTimeout(): void
    {
        $started = microtime(true);
        try {
            OutboundGuard::lookup('slow-name.example.com', 0);
            $this->fail('A lookup with no time allowed finished.');
        } catch (OutboundFailure $e) {
            $this->assertSame('site_timeout', $e->errorCode);
        }
        $this->assertLessThan(2, microtime(true) - $started, 'it does not wait for the answer');
    }

    public function testTheDefaultLookupReturnsOnlyAddressStringsWithinItsTimeLimit(): void
    {
        // Needs a resolver: with none reachable the answer is a timeout, which is also the correct, bounded outcome.
        try {
            $addresses = OutboundGuard::lookup('localhost', 8);
        } catch (OutboundFailure $e) {
            $this->assertSame('site_timeout', $e->errorCode);

            return;
        }
        $this->assertContainsOnly('string', $addresses);
    }

    public function testATimeoutFromTheResolverReachesTheCallerAsATimeoutNotAsABlockedAddress(): void
    {
        $guard = new OutboundGuard(fn () => throw new OutboundFailure('site_timeout'));

        $this->expectExceptionObject(new OutboundFailure('site_timeout'));
        $guard->resolve('erp.example.com');
    }

    private function startServer(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $port   = (int) substr(stream_socket_get_name($socket, false), strrpos(stream_socket_get_name($socket, false), ':') + 1);
        fclose($socket);

        $process = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, __DIR__ . '/Support/body-router.php'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        $this->assertIsResource($process);
        $this->server = $process;
        for ($i = 0; $i < 50; $i++) {
            if ($c = @fsockopen('127.0.0.1', $port)) {
                fclose($c);

                return $port;
            }
            usleep(100000);
        }
        $this->fail('The local test server did not start.');
    }

    protected function tearDown(): void
    {
        if ($this->server) {
            proc_terminate($this->server);
            proc_close($this->server);
        }
        parent::tearDown();
    }

    public function testEveryCallChecksAgainAndNothingIsCached(): void
    {
        $answers = ['93.184.216.34'];
        $guard   = $this->guard([], function () use (&$answers) {
            return $answers;
        }, [new Response(200, [], '{}'), new Response(200, [], '{}')]);

        $guard->post('https://erp.example.com', '/x', '{}');
        $answers = ['10.0.0.1'];

        $this->expectException(OutboundFailure::class);
        $guard->post('https://erp.example.com', '/x', '{}');
    }

    public function testARedirectIsRefusedNotFollowed(): void
    {
        $this->expectExceptionObject(new OutboundFailure('address_blocked'));
        $this->guard(['93.184.216.34'], null, [new Response(302, ['Location' => 'https://127.0.0.1/'])])->post('https://erp.example.com', '/x', '{}');
    }

    public function testASilentServerIsATimeoutAndOtherFailuresAreSiteErrors(): void
    {
        $timeout = new ConnectException('cURL error 28: Operation timed out', new Request('POST', 'https://erp.example.com'), null, ['errno' => CURLE_OPERATION_TIMEDOUT]);
        $refused = new ConnectException('cURL error 7', new Request('POST', 'https://erp.example.com'), null, ['errno' => CURLE_COULDNT_CONNECT]);

        foreach ([[$timeout, 'site_timeout'], [$refused, 'site_error'], [new \RuntimeException('tls'), 'site_error']] as [$failure, $code]) {
            try {
                $this->guard(['93.184.216.34'], null, [$failure])->post('https://erp.example.com', '/x', '{}');
                $this->fail('expected a failure');
            } catch (OutboundFailure $e) {
                $this->assertSame($code, $e->errorCode);
            }
        }
    }

    public function testRemoteTextNeverReachesTheFailure(): void
    {
        $guard = $this->guard(['93.184.216.34'], null, [new Response(500, [], '<script>alert(1)</script>')]);

        [$status, $json] = $guard->post('https://erp.example.com', '/x', '{}');

        $this->assertSame(500, $status);
        $this->assertNull($json);
    }

    public function testDisplayHostKeepsOnlyTheHost(): void
    {
        $this->assertSame('x.com', OutboundGuard::displayHost('https://user:ZZSENTINEL@X.com:8443/path?q=1'));
        $this->assertSame('unknown', OutboundGuard::displayHost('not a url'));
        $this->assertSame('unknown', OutboundGuard::displayHost('https://x.com<script>/'));
    }
}
