<?php

declare(strict_types=1);

namespace OpinionPadi\Offers\Http;

final class Response
{
    /** @var array<string, string> Header names in lower case. */
    public readonly array $headers;

    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public readonly int $status,
        array $headers = [],
        public readonly string $body = '',
    ) {
        $normalised = [];
        foreach ($headers as $name => $value) {
            $normalised[strtolower((string) $name)] = (string) $value;
        }
        $this->headers = $normalised;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
