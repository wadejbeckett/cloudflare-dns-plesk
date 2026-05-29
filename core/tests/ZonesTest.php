<?php

declare(strict_types=1);

namespace Noiz\CloudflareDns\Tests;

use Noiz\CloudflareDns\Cloudflare\Client;
use Noiz\CloudflareDns\Cloudflare\Zones;
use Noiz\CloudflareDns\Tests\Support\FakeTransport;
use PHPUnit\Framework\TestCase;

/**
 * Tests for zone lookup and the create-if-missing path, using a fake transport.
 */
final class ZonesTest extends TestCase
{
    public function testFindIdReturnsTheZoneId(): void
    {
        $transport = new FakeTransport();
        $transport->queue(200, ['success' => true, 'result' => [['id' => 'zone-abc', 'name' => 'example.com']]]);

        $zones = new Zones(new Client('test-token', $transport));

        self::assertSame('zone-abc', $zones->findId('example.com'));
    }

    public function testFindIdReturnsNullWhenZoneDoesNotExist(): void
    {
        $transport = new FakeTransport();
        $transport->queue(200, ['success' => true, 'result' => []]);

        $zones = new Zones(new Client('test-token', $transport));

        self::assertNull($zones->findId('absent.example.com'));
    }

    public function testEnsureReturnsAnExistingZoneIdWithoutCreating(): void
    {
        $transport = new FakeTransport();
        $transport->queue(200, ['success' => true, 'result' => [['id' => 'zone-existing', 'name' => 'example.com']]]);

        $zones = new Zones(new Client('test-token', $transport));

        self::assertSame('zone-existing', $zones->ensure('example.com', 'account-1'));
        // Only the lookup ran — no zone was created.
        self::assertSame(1, $transport->requestCount());
    }

    public function testEnsureCreatesTheZoneWhenMissing(): void
    {
        $transport = new FakeTransport();
        $transport->queue(200, ['success' => true, 'result' => []]); // lookup: not found
        $transport->queue(200, ['success' => true, 'result' => ['id' => 'zone-new', 'name' => 'example.com']]);

        $zones = new Zones(new Client('test-token', $transport));

        self::assertSame('zone-new', $zones->ensure('example.com', 'account-1'));

        $create = $transport->lastRequest();
        self::assertSame('POST', $create['method']);
        self::assertStringEndsWith('/zones', $create['url']);
    }
}
