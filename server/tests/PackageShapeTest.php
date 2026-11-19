<?php

namespace Alvora\ErpnextLink\Tests;

use Alvora\ErpnextLink\Jobs\RunErpnextConnection;
use Alvora\ErpnextLink\Models\ErpnextConnection;
use PHPUnit\Framework\TestCase;

/**
 * Facts about the package that need no application: the job class (AC-22), the table name (AC-87) and the
 * status shape (AC-5).
 */
class PackageShapeTest extends TestCase
{
    public function testTheJobRunsOnceWithinTheWorkersHardKill(): void
    {
        $job = new RunErpnextConnection('c', 'r', 'connect', 'ref', 'u', '127.0.0.1');

        $this->assertSame(1, $job->tries);
        $this->assertLessThan(60, $job->timeout);
        $this->assertTrue($job->failOnTimeout);
        $this->assertFalse(method_exists($job, 'retryUntil'));
        $this->assertNull($job->queue, 'default queue');
    }

    public function testTheSerialisedJobHoldsNoSecret(): void
    {
        $payload = serialize(new RunErpnextConnection('uuid', 'run', 'connect', 'a-reference-of-48-characters', 'actor', '10.1.1.1'));

        $this->assertStringNotContainsString('ZZSENTINEL', $payload);
    }

    public function testTheGuardBuildsWithItsDefaultsAsTheQueueWorkerContainerDoes(): void
    {
        $guard = new \Alvora\ErpnextLink\Support\OutboundGuard();

        $this->expectExceptionObject(new \Alvora\ErpnextLink\Support\OutboundFailure('address_blocked'));
        $guard->resolve('localhost'); // the real resolver answers 127.0.0.1, which is refused
    }

    public function testTheEmptyStatusHasExactlyTheElevenSpecifiedKeys(): void
    {
        $keys = ['status', 'step', 'run_kind', 'host', 'error_code', 'connected_at', 'last_test_at', 'last_test_ok', 'last_test_ms', 'last_attempt_at', 'has_previous'];

        $this->assertSame($keys, array_keys(ErpnextConnection::emptyStatus()));
        $this->assertSame('alvora_erpnext_connections', (new ErpnextConnection())->getTable());
    }

    public function testTheWebhookListIsNeverEmptyAndHoldsOnlyTheTwoOrderEvents(): void
    {
        $this->assertSame(['order.completed', 'order.canceled'], RunErpnextConnection::EVENTS);
    }
}
