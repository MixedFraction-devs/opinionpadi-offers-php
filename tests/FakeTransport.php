<?php

declare(strict_types=1);

namespace OpinionPadi\Offers\Tests;

use OpinionPadi\Offers\Http\Response;
use OpinionPadi\Offers\Http\TransportException;
use OpinionPadi\Offers\Http\TransportInterface;
use RuntimeException;

/**
 * Plays queued responses and records every request.
 */
final class FakeTransport implements TransportInterface
{
    /** @var list<array{method: string, url: string, headers: array<string, string>, body: ?string, timeout: float}> */
    public array $requests = [];

    /** @var list<Response|TransportException> */
    private array $queue = [];

    /**
     * @param  array<string, mixed>|string|null  $body
     * @param  array<string, string>  $headers
     */
    public function push(int $status, array|string|null $body = null, array $headers = []): self
    {
        $raw = is_array($body) ? json_encode($body) : (string) $body;
        $this->queue[] = new Response($status, $headers + ['Content-Type' => 'application/json'], $raw);

        return $this;
    }

    public function pushFailure(TransportException $e): self
    {
        $this->queue[] = $e;

        return $this;
    }

    public function send(string $method, string $url, array $headers, ?string $body, float $timeout): Response
    {
        $this->requests[] = compact('method', 'url', 'headers', 'body', 'timeout');

        $next = array_shift($this->queue);
        if ($next === null) {
            throw new RuntimeException('FakeTransport has no response queued for '.$method.' '.$url);
        }
        if ($next instanceof TransportException) {
            throw $next;
        }

        return $next;
    }

    /** @return array<string, mixed> */
    public function lastJson(): array
    {
        $last = end($this->requests);

        return json_decode((string) $last['body'], true);
    }
}
