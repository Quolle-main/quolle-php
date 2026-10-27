<?php

declare(strict_types=1);

namespace Quolle;

/**
 * Thrown when the Quolle API returns a non-2xx response, or a request could
 * not be completed (network/timeout).
 */
class QuolleException extends \Exception
{
    /** @var int|null HTTP status code, or null for transport-level failures. */
    public ?int $statusCode;

    /** @var array<string,mixed> Extra fields returned alongside `error`. */
    public array $data;

    /**
     * @var string|null The API's stable, machine-readable error code (e.g.
     *      "validation_error", "rate_limit_exceeded"). Branch on this, not on
     *      the message. Null for transport-level failures.
     */
    public ?string $errorCode;

    /**
     * @param array<string,mixed> $data
     */
    public function __construct(string $message, ?int $statusCode = null, array $data = [], ?string $errorCode = null)
    {
        parent::__construct($message, $statusCode ?? 0);
        $this->statusCode = $statusCode;
        $this->data = $data;
        $this->errorCode = $errorCode;
    }
}
