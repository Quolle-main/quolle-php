<?php

declare(strict_types=1);

namespace Quolle\Resource;

use Quolle\Client;

/**
 * Sending domains (`$quolle->domains`). Needs a full-access API key.
 */
class Domains
{
    private Client $client;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    /** @return array<int,array<string,mixed>> Every sending domain, with verification and warm-up state. */
    public function list(): array
    {
        return $this->client->request('GET', '/v1/domains')['domains'] ?? [];
    }

    /**
     * Add a domain (e.g. "mail.example.com").
     *
     * @return array<string,mixed> `domain` and the `dnsRecords` to publish.
     */
    public function create(string $domain): array
    {
        return $this->client->request('POST', '/v1/domains', ['domain' => $domain]);
    }

    /** @return array<string,mixed> One domain. */
    public function get(string $id): array
    {
        return $this->client->request('GET', '/v1/domains/' . rawurlencode($id))['domain'] ?? [];
    }

    /** @return array<string,mixed> The DNS records to publish, and which are found. */
    public function records(string $id): array
    {
        return $this->client->request('GET', '/v1/domains/' . rawurlencode($id) . '/records');
    }

    /** Check DNS again now instead of waiting for the next automatic check. @return array<string,mixed> */
    public function verify(string $id): array
    {
        return $this->client->request('POST', '/v1/domains/' . rawurlencode($id) . '/recheck');
    }

    /** Remove a domain. Mail can no longer be sent from it. @return array<string,mixed> */
    public function remove(string $id): array
    {
        return $this->client->request('DELETE', '/v1/domains/' . rawurlencode($id));
    }
}
