<?php

declare(strict_types=1);

namespace Quolle\Resource;

use Quolle\Client;
use Quolle\QuolleException;

/**
 * Verify incoming webhooks, and manage webhook endpoints (`$quolle->webhooks`).
 * `verify()` makes no API call; the management methods need a full-access key.
 */
class Webhooks
{
    private const DEFAULT_TOLERANCE = 300; // seconds

    private ?Client $client;

    public function __construct(?Client $client = null)
    {
        $this->client = $client;
    }

    /** @return array<int,array<string,mixed>> The events a webhook can subscribe to. */
    public function events(): array
    {
        return $this->req('GET', '/v1/webhooks/events')['events'] ?? [];
    }

    /** @return array<int,array<string,mixed>> */
    public function list(): array
    {
        return $this->req('GET', '/v1/webhooks')['webhooks'] ?? [];
    }

    /**
     * Add an endpoint. The returned `secret` (whsec_…) is shown once; store it.
     *
     * @param array<int,string> $events
     * @return array<string,mixed>
     */
    public function create(string $url, array $events): array
    {
        return $this->req('POST', '/v1/webhooks', ['url' => $url, 'events' => array_values($events)]);
    }

    /** @return array<string,mixed> */
    public function get(string $id): array
    {
        return $this->req('GET', '/v1/webhooks/' . rawurlencode($id));
    }

    /**
     * Change the URL or events. The signing secret stays the same.
     *
     * @param array{url?:string,events?:array<int,string>} $params
     * @return array<string,mixed>
     */
    public function update(string $id, array $params): array
    {
        return $this->req('PATCH', '/v1/webhooks/' . rawurlencode($id), $params);
    }

    /** @return array<string,mixed> */
    public function remove(string $id): array
    {
        return $this->req('DELETE', '/v1/webhooks/' . rawurlencode($id));
    }

    /** Send a signed `webhook.test` event now and report how the endpoint answered. @return array<string,mixed> */
    public function test(string $id): array
    {
        return $this->req('POST', '/v1/webhooks/' . rawurlencode($id) . '/test');
    }

    /**
     * Deliveries from the last 30 days, newest first.
     *
     * @param array{status?:string,limit?:int} $filter `status`: succeeded, pending or failed.
     * @return array<int,array<string,mixed>>
     */
    public function deliveries(string $id, array $filter = []): array
    {
        $qs = $filter ? '?' . http_build_query($filter) : '';
        return $this->req('GET', '/v1/webhooks/' . rawurlencode($id) . '/deliveries' . $qs)['deliveries'] ?? [];
    }

    /** Send a delivery again, with the same body and `eventId`. @return array<string,mixed> */
    public function redeliver(string $id, string $deliveryId): array
    {
        return $this->req(
            'POST',
            '/v1/webhooks/' . rawurlencode($id) . '/deliveries/' . rawurlencode($deliveryId) . '/redeliver'
        );
    }

    /** Replace the signing secret. Takes effect now; the new `secret` is returned once. @return array<string,mixed> */
    public function rotateSecret(string $id): array
    {
        return $this->req('POST', '/v1/webhooks/' . rawurlencode($id) . '/rotate-secret');
    }

    /**
     * @param array<string,mixed>|null $body
     * @return array<string,mixed>
     */
    private function req(string $method, string $path, ?array $body = null): array
    {
        if ($this->client === null) {
            throw new QuolleException('Webhooks was created without a client; use $quolle->webhooks');
        }
        return $this->client->request($method, $path, $body);
    }

    /**
     * Verify a webhook signature and return the decoded event.
     *
     * @param string $payload         The RAW request body.
     * @param string $signatureHeader The value of the `Quolle-Signature` header.
     * @param string $secret          Your webhook signing secret (whsec_…).
     * @param int    $tolerance       Max timestamp age in seconds (replay window).
     *
     * @return array<string,mixed> The decoded event payload.
     * @throws QuolleException if the header is malformed, the timestamp is stale,
     *                         or the signature does not match.
     */
    public function verify(
        string $payload,
        string $signatureHeader,
        string $secret,
        int $tolerance = self::DEFAULT_TOLERANCE
    ): array {
        $t = null;
        $v1 = null;
        foreach (explode(',', $signatureHeader) as $part) {
            $kv = explode('=', trim($part), 2);
            if (count($kv) !== 2) {
                continue;
            }
            if ($kv[0] === 't') {
                $t = $kv[1];
            } elseif ($kv[0] === 'v1') {
                $v1 = $kv[1];
            }
        }
        if ($t === null || $v1 === null || $t === '' || $v1 === '') {
            throw new QuolleException('Invalid Quolle-Signature header');
        }

        $ts = (int) $t;
        if (abs(time() - $ts) > $tolerance) {
            throw new QuolleException('Webhook timestamp outside the tolerance window (possible replay)');
        }

        $expected = hash_hmac('sha256', "$ts.$payload", $secret);
        if (!hash_equals($expected, $v1)) {
            throw new QuolleException('Webhook signature verification failed');
        }

        $decoded = json_decode($payload, true);
        return is_array($decoded) ? $decoded : [];
    }
}
