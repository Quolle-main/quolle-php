<?php

declare(strict_types=1);

namespace Quolle\Tests;

use PHPUnit\Framework\TestCase;
use Quolle\Client;

/** Records every request; returns one canned body for all of them. */
class CallLog extends Client
{
    /** @var array<int,array{0:string,1:string,2:array<string,mixed>|null}> */
    public array $calls = [];

    public function __construct()
    {
        parent::__construct('qle_test');
    }

    public function request(string $method, string $path, ?array $body = null, array $headers = []): array
    {
        $this->calls[] = [$method, $path, $body];
        return [
            'domains' => [['id' => 'd1']], 'domain' => ['id' => 'd1'], 'keys' => [['id' => 'k1']],
            'webhooks' => [], 'deliveries' => [], 'events' => [],
        ];
    }

    /** @return array<int,string> */
    public function routes(): array
    {
        return array_map(fn ($c) => $c[0] . ' ' . $c[1], $this->calls);
    }
}

/** Domains, API keys and webhook endpoints (K8): each method hits the right route. */
class ManagementTest extends TestCase
{
    public function testDomains(): void
    {
        $q = new CallLog();
        $this->assertSame('d1', $q->domains->list()[0]['id']);
        $q->domains->create('mail.acme.com');
        $this->assertSame('d1', $q->domains->get('d1')['id']);
        $q->domains->records('d1');
        $q->domains->verify('d1');
        $q->domains->remove('d1');
        $this->assertSame([
            'GET /v1/domains', 'POST /v1/domains', 'GET /v1/domains/d1', 'GET /v1/domains/d1/records',
            'POST /v1/domains/d1/recheck', 'DELETE /v1/domains/d1',
        ], $q->routes());
        $this->assertSame(['domain' => 'mail.acme.com'], $q->calls[1][2]);
    }

    public function testApiKeys(): void
    {
        $q = new CallLog();
        $this->assertSame('k1', $q->apiKeys->list()[0]['id']);
        $q->apiKeys->create(['name' => 'ci', 'scope' => 'sending']);
        $q->apiKeys->remove('k1');
        $this->assertSame(['GET /v1/keys', 'POST /v1/keys', 'DELETE /v1/keys/k1'], $q->routes());
        $this->assertSame(['name' => 'ci', 'scope' => 'sending'], $q->calls[1][2]);
    }

    public function testWebhookEndpoints(): void
    {
        $q = new CallLog();
        $q->webhooks->events();
        $q->webhooks->list();
        $q->webhooks->create('https://x.io/h', ['email.delivered']);
        $q->webhooks->get('w1');
        $q->webhooks->update('w1', ['events' => ['email.bounced']]);
        $q->webhooks->test('w1');
        $q->webhooks->deliveries('w1', ['status' => 'failed', 'limit' => 10]);
        $q->webhooks->redeliver('w1', 'dl1');
        $q->webhooks->rotateSecret('w1');
        $q->webhooks->remove('w1');
        $this->assertSame([
            'GET /v1/webhooks/events', 'GET /v1/webhooks', 'POST /v1/webhooks', 'GET /v1/webhooks/w1',
            'PATCH /v1/webhooks/w1', 'POST /v1/webhooks/w1/test', 'GET /v1/webhooks/w1/deliveries?status=failed&limit=10',
            'POST /v1/webhooks/w1/deliveries/dl1/redeliver', 'POST /v1/webhooks/w1/rotate-secret', 'DELETE /v1/webhooks/w1',
        ], $q->routes());
    }
}
