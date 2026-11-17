<?php

declare(strict_types=1);

namespace OpinionPadi\Offers\Http;

use RuntimeException;

/**
 * No response arrived. The client turns this into an OpinionPadiException
 * with the code `timeout` or `connection_error`, after retrying.
 */
class TransportException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $timedOut = false)
    {
        parent::__construct($message);
    }
}
