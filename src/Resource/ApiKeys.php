<?php

declare(strict_types=1);

namespace Quolle\Resource;

use Quolle\Client;

/**
 * API keys (`$quolle->apiKeys`). Needs a full-access key; a sending-only key
 * gets 403 `restricted_api_key`.
 */
class ApiKeys
{
    private Client $client;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    /** @return array<int,array<string,mixed>> Active keys (only a `preview` of each key). */
    public function list(): array
    {
        return $this->client->request('GET', '/v1/keys')['keys'] ?? [];
    }

    /**
     * Create a key. The returned `key` (qle_…) is shown once; store it now.
     *
     * @param array{name:string,scope?:string,domains?:array<int,string>,expiresInDays?:int} $params
     *        `scope` "sending" makes a key that can only send email and read its status.
     * @return array<string,mixed>
     */
    public function create(array $params): array
    {
        return $this->client->request('POST', '/v1/keys', $params);
    }

    /** Revoke a key. Requests made with it fail from now on. @return array<string,mixed> */
    public function remove(string $id): array
    {
        return $this->client->request('DELETE', '/v1/keys/' . rawurlencode($id));
    }
}
