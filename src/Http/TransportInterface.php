<?php

declare(strict_types=1);

namespace OpinionPadi\Offers\Http;

/**
 * Sends one HTTP request and returns the response, whatever its status.
 *
 * The client uses CurlTransport by default. Pass your own implementation as
 * the `transport` option to route requests through another HTTP client, or
 * to fake responses in tests.
 */
interface TransportInterface
{
    /**
     * @param  array<string, string>  $headers  Header name => value.
     * @param  float  $timeout  Seconds.
     *
     * @throws TransportException When no response arrived (connection failure or timeout).
     */
    public function send(string $method, string $url, array $headers, ?string $body, float $timeout): Response;
}
