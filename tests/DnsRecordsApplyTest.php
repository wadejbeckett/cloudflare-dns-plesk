<?php

declare(strict_types=1);

namespace Noiz\CloudflareDns\Tests;

use Noiz\CloudflareDns\Cloudflare\ApiException;
use Noiz\CloudflareDns\Cloudflare\Client;
use Noiz\CloudflareDns\Cloudflare\DnsRecords;
use Noiz\CloudflareDns\Cloudflare\Record;
use Noiz\CloudflareDns\Cloudflare\RecordUpdate;
use Noiz\CloudflareDns\Cloudflare\SyncPlan;
use Noiz\CloudflareDns\Tests\Support\FakeTransport;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the executor and the HTTP client, using a fake transport.
 *
 * The first test is the project's core guarantee asserted directly: updates
 * go out as HTTP PATCH and never carry a `proxied` field.
 */
final class DnsRecordsApplyTest extends TestCase
{
    public function testUpdateUsesPatchAndNeverSendsProxied(): void
    {
        $transport = new FakeTransport();
        $transport->queue(200, [
            'success' => true,
            'result' => [
                'id' => 'r1',
                'type' => 'A',
                'name' => 'www.example.com',
                'content' => '9.9.9.9',
                'ttl' => 1,
                'proxied' => true,
            ],
        ]);

        $records = new DnsRecords(new Client('test-token', $transport), 'zone123');

        $existing = new Record('A', 'www.example.com', '1.2.3.4', 1, null, 'r1', true, true);
        $desired = new Record('A', 'www.example.com', '9.9.9.9', 3600);
        $plan = new SyncPlan([], [new RecordUpdate($existing, $desired)], []);

        $report = $records->apply($plan);

        self::assertSame(1, $report->updated);
        self::assertFalse($report->hasErrors());

        $request = $transport->lastRequest();
        // The fix, asserted: PATCH (partial update), not PUT (full overwrite).
        self::assertSame('PATCH', $request['method']);
        self::assertStringEndsWith('/zones/zone123/dns_records/r1', $request['url']);

        $body = json_decode((string) $request['body'], true);
        self::assertIsArray($body);
        self::assertArrayNotHasKey('proxied', $body);
        self::assertSame('9.9.9.9', $body['content']);
    }

    public function testCreateSendsPostWithoutProxied(): void
    {
        $transport = new FakeTransport();
        $transport->queue(200, [
            'success' => true,
            'result' => ['id' => 'new1', 'type' => 'A', 'name' => 'new.example.com', 'content' => '1.2.3.4', 'ttl' => 3600],
        ]);

        $records = new DnsRecords(new Client('test-token', $transport), 'zone123');
        $plan = new SyncPlan([new Record('A', 'new.example.com', '1.2.3.4', 3600)], [], []);

        $report = $records->apply($plan);

        self::assertSame(1, $report->created);
        self::assertCount(1, $report->createdRecords);
        self::assertSame('new1', $report->createdRecords[0]->id);

        $request = $transport->lastRequest();
        self::assertSame('POST', $request['method']);

        $body = json_decode((string) $request['body'], true);
        self::assertIsArray($body);
        self::assertArrayNotHasKey('proxied', $body);
    }

    public function testAuthorizationHeaderCarriesBearerToken(): void
    {
        $transport = new FakeTransport();
        $client = new Client('secret-token', $transport);

        $client->request('GET', 'zones');

        self::assertSame('Bearer secret-token', $transport->lastRequest()['headers']['Authorization']);
    }

    public function testFailedApiResponseRaisesApiException(): void
    {
        $transport = new FakeTransport();
        $transport->queue(403, [
            'success' => false,
            'errors' => [['code' => 9109, 'message' => 'Unauthorized to access requested resource']],
        ]);

        $client = new Client('test-token', $transport);

        $this->expectException(ApiException::class);
        $client->request('GET', 'zones');
    }

    public function testEmptyTokenIsRejected(): void
    {
        $this->expectException(ApiException::class);
        new Client('   ');
    }

    public function testApplyContinuesAfterAFailedRecord(): void
    {
        $transport = new FakeTransport();
        // First update fails, second succeeds.
        $transport->queue(400, ['success' => false, 'errors' => [['code' => 1004, 'message' => 'bad record']]]);
        $transport->queue(200, ['success' => true, 'result' => ['id' => 'r2', 'type' => 'A', 'name' => 'b.example.com', 'content' => '2.2.2.2', 'ttl' => 1]]);

        $records = new DnsRecords(new Client('test-token', $transport), 'zone123');

        $plan = new SyncPlan([], [
            new RecordUpdate(
                new Record('A', 'a.example.com', '1.1.1.1', 1, null, 'r1', false, true),
                new Record('A', 'a.example.com', '8.8.8.8', 3600)
            ),
            new RecordUpdate(
                new Record('A', 'b.example.com', '2.2.2.2', 1, null, 'r2', false, true),
                new Record('A', 'b.example.com', '9.9.9.9', 3600)
            ),
        ], []);

        $report = $records->apply($plan);

        self::assertSame(1, $report->updated);
        self::assertTrue($report->hasErrors());
        self::assertCount(1, $report->errors);
        self::assertSame('update', $report->errors[0]['action']);
    }
}
