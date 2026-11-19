<?php

namespace Alvora\ErpnextLink\Tests;

use Alvora\ErpnextLink\Support\OutboundFailure;
use Alvora\ErpnextLink\Support\OutboundGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * AC-23, AC-24 (test engineer, slice 001): hostile address forms and range edges that OutboundGuardTest does not
 * list. Blocked forms must fail before any lookup; public neighbours of blocked ranges must still pass (a guard
 * that blocks too much is a failed Connect for a real client).
 */
class OutboundGuardVectorsTest extends TestCase
{
    public static function hostileTypedAddresses(): array
    {
        return array_map(fn ($a) => [$a], [
            'https://LOCALHOST', 'https://localhost.', 'https://127.1', 'https://0177.0.0.1', 'https://[::ffff:127.0.0.1]',
            'https://[::ffff:7f00:1]', 'https://169.254.169.254', 'https://x.com#frag', "https://x.com\r\nHost: evil.test",
            'https://x.com:443@evil.com', 'https://x.com\\@evil.com', 'https://x.com:0', 'https://x.com:80', 'https://münchen.de',
            'https://x.example', 'https://x.test', 'https://x.corp', 'https://x.lan', 'https://x.home', 'https://x.invalid',
            'https://x.in-addr.arpa', 'https://a..com', 'https://.com', 'HTTPS://x.com@y.com', 'https:///x.com', 'https://x.com/%2e%2e/',
            'file:///etc/passwd', 'gopher://x.com', 'javascript:alert(1)', 'https://[2130706433]',
        ]);
    }

    #[DataProvider('hostileTypedAddresses')]
    public function testHostileAddressFormsAreBlockedBeforeAnyLookup(string $address): void
    {
        try {
            OutboundGuard::hostOf($address);
            $this->fail('expected address_blocked for ' . json_encode($address));
        } catch (OutboundFailure $e) {
            $this->assertSame('address_blocked', $e->errorCode);
        }
    }

    public function testPlainPublicFormsAreAcceptedAndNormalised(): void
    {
        $this->assertSame('erp.example.com', OutboundGuard::hostOf('https://ERP.Example.COM./'));
        $this->assertSame('erp.example.com', OutboundGuard::hostOf('https://erp.example.com:443'));
        $this->assertSame('erp.example.com', OutboundGuard::hostOf('  https://erp.example.com/  '));
    }

    public static function neverPublic(): array
    {
        return array_map(fn ($a) => [$a], [
            '64:ff9b::7f00:1', '::ffff:7f00:1', '2002:7f00:1::', 'fe80::1', 'fc00::1', 'fd00:ec2::254', 'ff02::1', '2001::1', '2001:db8::1', '::', '::2',
            '0.0.0.1', '127.255.255.255', '10.255.255.255', '100.64.0.0', '100.127.255.255', '169.254.169.254', '172.16.0.0', '172.31.255.255',
            '192.0.0.8', '192.168.255.255', '198.18.0.0', '198.19.255.255', '224.0.0.1', '240.0.0.1', '255.255.255.255',
        ]);
    }

    #[DataProvider('neverPublic')]
    public function testEveryNonPublicRangeEdgeIsRefused(string $ip): void
    {
        $this->assertFalse(OutboundGuard::isPublic($ip), $ip);
    }

    public static function publicNeighbours(): array
    {
        return array_map(fn ($a) => [$a], [
            '8.8.8.8', '1.1.1.1', '100.63.255.255', '100.128.0.0', '172.15.255.255', '172.32.0.0', '198.17.255.255', '198.20.0.0',
            '169.253.255.255', '169.255.0.0', '192.0.1.1', '223.255.255.255', '2606:4700:4700::1111', '2001:4860:4860::8888', '2a00:1450:4001::1',
        ]);
    }

    #[DataProvider('publicNeighbours')]
    public function testPublicNeighboursOfBlockedRangesStillPass(string $ip): void
    {
        $this->assertTrue(OutboundGuard::isPublic($ip), $ip);
    }

    public function testAnAnswerThatIsNotTheLiteralAddressFormIsRefused(): void
    {
        foreach (['2130706433', '0x7f000001', '127.1', '0177.0.0.1', '1.1.1.1/32', ' 8.8.8.8', '8.8.8.8 ', ''] as $weird) {
            $this->assertFalse(OutboundGuard::isPublic($weird), json_encode($weird));
        }
    }
}
