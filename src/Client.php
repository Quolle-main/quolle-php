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

    /**
     * 429s that retrying can't fix: the limit resets at a fixed time (midnight
     * UTC, the next billing period), not in seconds. Thrown at once so the
     * caller can queue the send instead of blocking in a retry loop.
     */
    private const NO_RETRY_CODES = ['daily_quota_exceeded', 'warmup_limit_exceeded', 'monthly_quota_exceeded'];

    /** A Retry-After longer than this means "not soon": throw instead of sleeping. */
    private const MAX_RETRY_WAIT_SECONDS = 60;

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
                curl_setopt($ch, CURLOPT_POSTFIELDS, self::encode($body));
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
            $isJson = $raw === '' || is_array($decoded);
            if (!is_array($decoded)) {
                $decoded = [];
            }

            if ($status < 200 || $status >= 300) {
                $message = $decoded['error'] ?? "Request failed with status $status";
                $code = isset($decoded['code']) && is_string($decoded['code']) ? $decoded['code'] : null;
                unset($decoded['error'], $decoded['code']);
                $lastException = new QuolleException((string) $message, $status, $decoded, $code);
                $retryable = ($status === 429 && !in_array($code, self::NO_RETRY_CODES, true))
                    || (($status >= 500 && $status <= 504) && ($idempotent || $hasIdem));
                $retryAfter = $this->retryAfter($rawHeaders);
                $tooLong = $retryAfter !== null && $retryAfter > self::MAX_RETRY_WAIT_SECONDS;
                if ($attempt < $this->maxRetries && $retryable && !$tooLong) {
                    $this->sleepBackoff($attempt, $retryAfter);
                    continue;
                }
                throw $lastException;
            }

            if (!$isJson) {
                // A 2xx that isn't JSON (a proxy page, a truncated body) is an
                // error, not an empty success.
                throw new QuolleException(
                    'Unexpected non-JSON response from the Quolle API',
                    $status,
                    ['body' => substr($raw, 0, 500)]
                );
            }
            return $decoded;
        }

        throw $lastException ?? new QuolleException('Request failed');
    }

    /** Fields the API expects as JSON objects, which PHP encodes `[]` when empty. */
    private const OBJECT_FIELDS = ['variables', 'metadata'];

    /**
     * JSON-encode a request body. Empty maps go out as `{}` (an empty PHP array
     * would encode as `[]`, which the API rejects), and an encoding failure
     * (invalid UTF-8, say) raises instead of silently sending `false`.
     *
     * @param mixed $body
     */
    private static function encode($body): string
    {
        $normalise = static function (array $email): array {
            foreach (self::OBJECT_FIELDS as $field) {
                if (array_key_exists($field, $email) && $email[$field] === []) {
                    $email[$field] = new \stdClass();
                }
            }
            return $email;
        };
        if (is_array($body)) {
            $body = $normalise($body);
            if (isset($body['emails']) && is_array($body['emails'])) {
                $body['emails'] = array_map(
                    static fn ($e) => is_array($e) ? $normalise($e) : $e,
                    $body['emails']
                );
            }
        }
        try {
            return json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (\JsonException $e) {
            throw new QuolleException('Could not encode the request as JSON: ' . $e->getMessage());
        }
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
