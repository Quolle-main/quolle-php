<?php

declare(strict_types=1);

namespace Quolle;

use Quolle\Resource\Emails;
use Quolle\Resource\Webhooks;

/**
 * The Quolle API client.
 *
 * ```php
 * $quolle = new \Quolle\Client('qle_...');
 * $result = $quolle->emails->send([
 *     'from'    => 'hello@mail.yourdomain.com',
 *     'to'      => 'customer@example.com',
 *     'subject' => 'Welcome!',
 *     'html'    => '<h1>Thanks for signing up</h1>',
 * ]);
 * echo $result['id'];
 * ```
 */
class Client
{
    public const VERSION = '1.0.0';
    private const DEFAULT_BASE_URL = 'https://api.quolle.com';

    private string $apiKey;
    private string $baseUrl;
    private int $timeout;
    private int $maxRetries;

    /** Email operations (send, sendBatch, get, cancel). */
    public Emails $emails;

    /** Webhook signature verification. */
    public Webhooks $webhooks;

    /**
     * @param string|null $apiKey  Your API key (starts with `qle_`). Falls back to the
     *                             QUOLLE_API_KEY environment variable.
     * @param array{base_url?:string,timeout?:int,max_retries?:int} $options
     */
    public function __construct(?string $apiKey = null, array $options = [])
    {
        $key = $apiKey ?? (getenv('QUOLLE_API_KEY') ?: null);
        if (!$key) {
            throw new \InvalidArgumentException(
                'A Quolle API key is required. Pass it to the constructor or set QUOLLE_API_KEY.'
            );
        }
        $this->apiKey = $key;
        $this->baseUrl = rtrim($options['base_url'] ?? self::DEFAULT_BASE_URL, '/');
        $this->timeout = $options['timeout'] ?? 30;
        $this->maxRetries = $options['max_retries'] ?? 3;
        $this->emails = new Emails($this);
        $this->webhooks = new Webhooks();
    }

    /**
     * Perform an authenticated request and return the decoded JSON body.
     *
     * @param array<string,mixed>|null $body
     * @param array<string,string>     $headers
     * @return array<string,mixed>
     * @throws QuolleException
     */
    public function request(string $method, string $path, ?array $body = null, array $headers = []): array
    {
        $url = $this->baseUrl . $path;
        $method = strtoupper($method);

        $requestHeaders = [
            'Authorization: Bearer ' . $this->apiKey,
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: quolle-php/' . self::VERSION,
        ];
        foreach ($headers as $name => $value) {
            $requestHeaders[] = "$name: $value";
        }

        // Retry safety: a 429 (never processed) is always safe to retry. A
        // 5xx/network error is only retried for idempotent methods (GET/DELETE)
        // or when an Idempotency-Key is present, so a POST can't double-send.
        $idempotent = $method === 'GET' || $method === 'DELETE';
        $hasIdem = isset($headers['Idempotency-Key']);

        $lastException = null;
        for ($attempt = 0; $attempt <= $this->maxRetries; $attempt++) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER => true,
                CURLOPT_HTTPHEADER => $requestHeaders,
                CURLOPT_TIMEOUT => $this->timeout,
            ]);
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
            }

            $response = curl_exec($ch);
            if ($response === false) {
                $err = curl_error($ch);
                curl_close($ch);
                $lastException = new QuolleException("Network error: $err");
                if ($attempt < $this->maxRetries && ($idempotent || $hasIdem)) {
                    $this->sleepBackoff($attempt, null);
                    continue;
                }
                throw $lastException;
            }

            $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $rawHeaders = substr((string) $response, 0, $headerSize);
            $raw = substr((string) $response, $headerSize);
            curl_close($ch);

            $decoded = $raw === '' ? [] : json_decode($raw, true);
            if (!is_array($decoded)) {
                $decoded = [];
            }

            if ($status < 200 || $status >= 300) {
                $message = $decoded['error'] ?? "Request failed with status $status";
                unset($decoded['error']);
                $lastException = new QuolleException((string) $message, $status, $decoded);
                $retryable = $status === 429 || (($status >= 500 && $status <= 504) && ($idempotent || $hasIdem));
                if ($attempt < $this->maxRetries && $retryable) {
                    $this->sleepBackoff($attempt, $this->retryAfter($rawHeaders));
                    continue;
                }
                throw $lastException;
            }

            return $decoded;
        }

        throw $lastException ?? new QuolleException('Request failed');
    }

    /** Parse a Retry-After header (seconds) from a raw header block. */
    private function retryAfter(string $rawHeaders): ?int
    {
        if (preg_match('/^Retry-After:\s*(\d+)/mi', $rawHeaders, $m)) {
            return (int) $m[1];
        }
        return null;
    }

    /** Sleep before the next retry: Retry-After if given, else exponential
     *  backoff (0.5s, 1s, 2s, …) capped at 8s, with jitter. */
    private function sleepBackoff(int $attempt, ?int $retryAfter): void
    {
        if ($retryAfter !== null && $retryAfter >= 0) {
            sleep($retryAfter);
            return;
        }
        $ms = (int) min(500 * (2 ** $attempt), 8000) + random_int(0, 250);
        usleep($ms * 1000);
    }
}
