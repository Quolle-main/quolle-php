<?php

declare(strict_types=1);

namespace Quolle\Resource;

use Quolle\QuolleException;

/**
 * Verify that an incoming webhook really came from Quolle.
 */
class Webhooks
{
    private const DEFAULT_TOLERANCE = 300; // seconds

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
