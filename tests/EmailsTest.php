<?php

declare(strict_types=1);

namespace Quolle\Tests;

use PHPUnit\Framework\TestCase;
use Quolle\Client;
use Quolle\QuolleException;
use Quolle\Resource\Emails;

/**
 * A Client subclass that records requests and returns a canned response
 * instead of hitting the network — lets us assert exactly what the SDK sends.
 */
class RecordingClient extends Client
{
    /** @var array<string,mixed> */
    public array $lastRequest = [];
    /** @var array<string,mixed> */
    public array $response = [];

    public function __construct()
    {
        parent::__construct('qle_test');
    }

    public function request(string $method, string $path, ?array $body = null, array $headers = []): array
    {
        $this->lastRequest = compact('method', 'path', 'body', 'headers');
        return $this->response;
    }
}

class EmailsTest extends TestCase
{
    public function testConstructorRequiresApiKey(): void
    {
        putenv('QUOLLE_API_KEY');
        $this->expectException(\InvalidArgumentException::class);
        new Client(null);
    }

    public function testSendPostsToCorrectPathWithBody(): void
    {
        $client = new RecordingClient();
        $client->response = ['id' => 'em_1', 'message' => 'Email queued successfully'];
        $emails = new Emails($client);

        $result = $emails->send([
            'from' => 'hello@mail.acme.com',
            'to' => 'user@example.com',
            'subject' => 'Hi',
            'html' => '<p>Hi</p>',
        ]);

        $this->assertSame('em_1', $result['id']);
        $this->assertSame('POST', $client->lastRequest['method']);
        $this->assertSame('/v1/emails/send', $client->lastRequest['path']);
        $this->assertSame('hello@mail.acme.com', $client->lastRequest['body']['from']);
    }

    public function testSendPassesIdempotencyKey(): void
    {
        $client = new RecordingClient();
        $emails = new Emails($client);
        $emails->send(['from' => 'a@b.com', 'to' => 'c@d.com', 'subject' => 's', 'html' => '<p>x</p>'], 'order_123');
        $this->assertSame('order_123', $client->lastRequest['headers']['Idempotency-Key']);
    }

    public function testSendBatchWrapsEmails(): void
    {
        $client = new RecordingClient();
        $client->response = ['queued' => 2, 'ids' => ['a', 'b']];
        $emails = new Emails($client);

        $out = $emails->sendBatch([
            ['from' => 'a@b.com', 'to' => 'x@y.com', 'subject' => '1', 'html' => '<p>1</p>'],
            ['from' => 'a@b.com', 'to' => 'z@y.com', 'subject' => '2', 'html' => '<p>2</p>'],
        ]);

        $this->assertSame(2, $out['queued']);
        $this->assertSame('/v1/emails/batch', $client->lastRequest['path']);
        $this->assertCount(2, $client->lastRequest['body']['emails']);
    }

    public function testGetUnwrapsEmail(): void
    {
        $client = new RecordingClient();
        $client->response = ['email' => ['id' => 'em_1', 'status' => 'delivered']];
        $emails = new Emails($client);

        $email = $emails->get('em_1');
        $this->assertSame('delivered', $email['status']);
        $this->assertSame('GET', $client->lastRequest['method']);
    }

    public function testCancelUsesDelete(): void
    {
        $client = new RecordingClient();
        $client->response = ['message' => 'Scheduled email cancelled'];
        $emails = new Emails($client);

        $out = $emails->cancel('em_1');
        $this->assertSame('Scheduled email cancelled', $out['message']);
        $this->assertSame('DELETE', $client->lastRequest['method']);
        $this->assertStringEndsWith('/cancel', $client->lastRequest['path']);
    }

    public function testExceptionCarriesStatusAndData(): void
    {
        $e = new QuolleException('Monthly limit reached', 402, ['limit' => 3000]);
        $this->assertSame(402, $e->statusCode);
        $this->assertSame(3000, $e->data['limit']);
        $this->assertSame('Monthly limit reached', $e->getMessage());
    }

    public function testEmptyMapsAreEncodedAsObjects(): void
    {
        $m = new \ReflectionMethod(Client::class, 'encode');
        $m->setAccessible(true);
        $json = $m->invoke(null, ['from' => 'a@b.co', 'variables' => [], 'metadata' => [],
            'emails' => [['metadata' => []]]]);
        $this->assertStringContainsString('"variables":{}', $json);
        $this->assertStringContainsString('"metadata":{}', $json);
        $this->assertStringNotContainsString('"metadata":[]', $json);
    }

    public function testUnencodableBodyThrowsInsteadOfSendingFalse(): void
    {
        $m = new \ReflectionMethod(Client::class, 'encode');
        $m->setAccessible(true);
        $this->expectException(QuolleException::class);
        $m->invoke(null, ['subject' => "\xB1\x31"]);
    }

    public function testExceptionCarriesTheApiCode(): void
    {
        $e = new QuolleException('Warm-up cap reached', 429, [], 'warmup_limit_exceeded');
        $this->assertSame('warmup_limit_exceeded', $e->errorCode);
        $this->assertSame(429, $e->statusCode);
    }
}
