<?php

declare(strict_types=1);

namespace Quolle\Resource;

use Quolle\Client;

/**
 * Send and manage emails.
 */
class Emails
{
    private Client $client;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    /**
     * Queue an email for immediate or scheduled delivery.
     *
     * Provide either `html` (with `subject`) or a `template` slug (with optional
     * `variables`). Pass `$idempotencyKey` to make retries safe.
     *
     * `type` is "transactional" (the default) or "marketing". Marketing mail
     * gets one-click unsubscribe headers and a footer link, and honours
     * unsubscribes; transactional mail never does.
     *
     * @param array<string,mixed> $params  from, to, subject, html, text, template,
     *                                      variables, replyTo, scheduledAt, metadata,
     *                                      attachments, type
     * @return array<string,mixed> at least `id` and `message`
     * @throws \Quolle\QuolleException
     */
    public function send(array $params, ?string $idempotencyKey = null): array
    {
        $headers = $idempotencyKey ? ['Idempotency-Key' => $idempotencyKey] : [];
        return $this->client->request('POST', '/v1/emails/send', $params, $headers);
    }

    /**
     * Send up to 100 emails in one all-or-nothing request.
     *
     * @param array<int,array<string,mixed>> $emails
     * @return array<string,mixed> `{ queued: int, ids: string[] }`
     * @throws \Quolle\QuolleException
     */
    public function sendBatch(array $emails, ?string $idempotencyKey = null): array
    {
        $headers = $idempotencyKey ? ['Idempotency-Key' => $idempotencyKey] : [];
        return $this->client->request('POST', '/v1/emails/batch', ['emails' => $emails], $headers);
    }

    /**
     * Retrieve a single email by ID.
     *
     * @return array<string,mixed>
     * @throws \Quolle\QuolleException
     */
    public function get(string $id): array
    {
        $resp = $this->client->request('GET', '/v1/emails/' . rawurlencode($id));
        return $resp['email'] ?? $resp;
    }

    /**
     * Cancel a scheduled email that has not yet been sent.
     *
     * @return array<string,mixed>
     * @throws \Quolle\QuolleException
     */
    public function cancel(string $id): array
    {
        return $this->client->request('DELETE', '/v1/emails/' . rawurlencode($id) . '/cancel');
    }
}
