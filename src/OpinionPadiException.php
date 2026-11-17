<?php

declare(strict_types=1);

namespace OpinionPadi\Offers;

use RuntimeException;
use Throwable;

/**
 * Every failure the SDK reports, from the API or from the client.
 *
 * `getCode()` returns the API error code as a string (`unknown_click`,
 * `invalid_request`, ...), or `connection_error`, `timeout` or
 * `invalid_response` when no usable response arrived. The same value is in
 * the `errorCode` property.
 */
class OpinionPadiException extends RuntimeException
{
    /** The API error code, or a client-side code. */
    public readonly string $errorCode;

    /** The request field the error is about, when the API names one. */
    public readonly ?string $param;

    /** The HTTP status, or null when no response arrived. */
    public readonly ?int $status;

    /** The decoded response body (or the raw string when it was not JSON). */
    public readonly mixed $body;

    /** Seconds to wait before retrying, from Retry-After on a 429. */
    public readonly ?int $retryAfter;

    public function __construct(
        string $errorCode,
        string $message,
        ?string $param = null,
        ?int $status = null,
        mixed $body = null,
        ?int $retryAfter = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);

        // Exception::$code is untyped, so it can hold the string code, the
        // same way PDOException does. getCode() then returns it.
        $this->code = $errorCode;
        $this->errorCode = $errorCode;
        $this->param = $param;
        $this->status = $status;
        $this->body = $body;
        $this->retryAfter = $retryAfter;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getParam(): ?string
    {
        return $this->param;
    }

    public function getStatus(): ?int
    {
        return $this->status;
    }

    public function getBody(): mixed
    {
        return $this->body;
    }

    public function getRetryAfter(): ?int
    {
        return $this->retryAfter;
    }
}
