<?php

declare(strict_types=1);

namespace OpinionPadi\Offers;

use DateTimeInterface;
use OpinionPadi\Offers\Http\CurlTransport;
use OpinionPadi\Offers\Http\Response;
use OpinionPadi\Offers\Http\TransportException;
use OpinionPadi\Offers\Http\TransportInterface;
use OpinionPadi\Offers\Resources\Conversions;
use OpinionPadi\Offers\Resources\Events;
use OpinionPadi\Offers\Resources\Reconciliation;

/**
 * Client for the OpinionPadi Offers events API.
 *
 *     $op = new OpinionPadi(['api_key' => getenv('OPINIONPADI_SECRET_KEY')]);
 *     $op->events->track(['type' => 'purchase', 'event_id' => 'order_1', 'click_id' => $clickId]);
 */
final class OpinionPadi
{
    public const VERSION = '0.1.0';

    public const DEFAULT_BASE_URL = 'https://api.opinionpadi.com/api/v1/offers';

    public const DEFAULT_TIMEOUT = 10.0;

    public const DEFAULT_MAX_RETRIES = 2;

    public readonly Events $events;

    public readonly Conversions $conversions;

    public readonly Reconciliation $reconciliation;

    private readonly string $apiKey;

    private readonly string $baseUrl;

    private readonly float $timeout;

    private readonly int $maxRetries;

    private readonly string $mode;

    private readonly TransportInterface $transport;

    /** @var callable(float): void */
    private $sleep;

    /**
     * Options: `api_key` (or OPINIONPADI_SECRET_KEY), `base_url` (or
     * OPINIONPADI_BASE_URL), `timeout` in seconds (default 10), `max_retries`
     * (default 2). Two more for tests and custom HTTP: `transport`, a
     * TransportInterface, and `sleep`, a callable given the seconds to wait
     * between retries.
     *
     * @param  array{api_key?: ?string, base_url?: ?string, timeout?: int|float|null, max_retries?: ?int, transport?: ?TransportInterface, sleep?: ?callable}  $options
     *
     * @throws OpinionPadiException When the key is missing or is not an Offers key.
     */
    public function __construct(array $options = [])
    {
        $apiKey = $options['api_key'] ?? self::env('OPINIONPADI_SECRET_KEY');
        $apiKey = is_string($apiKey) ? trim($apiKey) : '';

        if ($apiKey === '') {
            throw new OpinionPadiException(
                'missing_api_key',
                'No API key. Pass api_key or set the OPINIONPADI_SECRET_KEY environment variable.',
                'api_key',
            );
        }

        if (str_starts_with($apiKey, 'op_sk_live_')) {
            $mode = 'live';
        } elseif (str_starts_with($apiKey, 'op_sk_test_')) {
            $mode = 'test';
        } else {
            throw new OpinionPadiException(
                'invalid_api_key',
                'That is not an OpinionPadi Offers secret key. Keys start with op_sk_live_ or op_sk_test_.',
                'api_key',
            );
        }

        $baseUrl = $options['base_url'] ?? self::env('OPINIONPADI_BASE_URL') ?? self::DEFAULT_BASE_URL;

        $this->apiKey = $apiKey;
        $this->mode = $mode;
        $this->baseUrl = rtrim((string) $baseUrl, '/');
        $this->timeout = (float) ($options['timeout'] ?? self::DEFAULT_TIMEOUT);
        $this->maxRetries = max(0, (int) ($options['max_retries'] ?? self::DEFAULT_MAX_RETRIES));
        $this->transport = $options['transport'] ?? new CurlTransport();
        $this->sleep = $options['sleep'] ?? static function (float $seconds): void {
            usleep((int) round($seconds * 1_000_000));
        };

        $this->events = new Events($this);
        $this->conversions = new Conversions($this);
        $this->reconciliation = new Reconciliation($this);
    }

    /** `live` or `test`, from the key prefix. */
    public function mode(): string
    {
        return $this->mode;
    }

    public function isTestMode(): bool
    {
        return $this->mode === 'test';
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * GET /account: confirms the key works and lists your offers.
     *
     * @return array<string, mixed>
     */
    public function account(): array
    {
        return $this->request('GET', '/account');
    }

    /**
     * GET /balance: the business wallet and what is left on each offer's
     * budget (remaining, reserved for conversions in hold, unreserved,
     * slots left). Read-only; test keys see the real figures too.
     *
     * @param  array{offer_id?: int|null}  $params
     * @return array<string, mixed>
     *
     * @throws OpinionPadiException
     */
    public function balance(array $params = []): array
    {
        return $this->request('GET', '/balance', $params);
    }

    /**
     * Send a request and return the decoded JSON body.
     *
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>|null  $body
     * @return array<string, mixed>
     *
     * @throws OpinionPadiException
     *
     * @internal Used by the resource classes.
     */
    public function request(string $method, string $path, array $query = [], ?array $body = null): array
    {
        $url = $this->baseUrl.$path;
        $query = self::prepareQuery($query);
        if ($query !== []) {
            $url .= '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        $headers = [
            'Authorization' => 'Bearer '.$this->apiKey,
            'Accept' => 'application/json',
            'User-Agent' => sprintf('opinionpadi-offers-php/%s (PHP %s)', self::VERSION, PHP_VERSION),
        ];

        $payload = null;
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
            $payload = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
            if ($payload === false) {
                throw new OpinionPadiException('invalid_request', 'The request body could not be encoded as JSON: '.json_last_error_msg());
            }
        }

        // Retrying is safe for every endpoint, POST /events included: the API
        // is idempotent on event_id, so a repeated event returns the first
        // result and changes nothing.
        $attempt = 0;
        while (true) {
            try {
                $response = $this->transport->send($method, $url, $headers, $payload, $this->timeout);
            } catch (TransportException $e) {
                if ($attempt < $this->maxRetries) {
                    ($this->sleep)($this->backoff($attempt));
                    $attempt++;

                    continue;
                }

                throw new OpinionPadiException(
                    $e->timedOut ? 'timeout' : 'connection_error',
                    $e->timedOut
                        ? sprintf('The request timed out after %s seconds.', self::formatSeconds($this->timeout))
                        : 'Could not reach OpinionPadi: '.$e->getMessage(),
                    previous: $e,
                );
            }

            if ($response->status >= 200 && $response->status < 300) {
                return $this->decodeSuccess($response);
            }

            $error = $this->errorFrom($response);

            if ($attempt < $this->maxRetries && self::retryable($response->status)) {
                $wait = $error->retryAfter !== null ? (float) $error->retryAfter : $this->backoff($attempt);
                ($this->sleep)($wait);
                $attempt++;

                continue;
            }

            throw $error;
        }
    }

    /** Seconds to wait before retry number $attempt + 1. */
    private function backoff(int $attempt): float
    {
        $base = 0.5 * (2 ** $attempt);
        $jitter = $base * 0.25 * (mt_rand() / mt_getrandmax());

        return min(8.0, $base + $jitter);
    }

    private static function retryable(int $status): bool
    {
        return $status === 409 || $status === 429 || $status >= 500;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeSuccess(Response $response): array
    {
        if (trim($response->body) === '') {
            return [];
        }

        $decoded = json_decode($response->body, true);
        if (! is_array($decoded)) {
            throw new OpinionPadiException(
                'invalid_response',
                sprintf('The API answered %d with a body that is not a JSON object.', $response->status),
                status: $response->status,
                body: $response->body,
            );
        }

        return $decoded;
    }

    private function errorFrom(Response $response): OpinionPadiException
    {
        $decoded = json_decode($response->body, true);
        $body = $decoded ?? ($response->body === '' ? null : $response->body);

        $retryAfter = null;
        if ($response->status === 429) {
            $header = $response->header('Retry-After');
            if ($header !== null && is_numeric(trim($header))) {
                $retryAfter = max(0, (int) ceil((float) trim($header)));
            }
        }

        if (is_array($decoded) && isset($decoded['error']) && is_array($decoded['error']) && isset($decoded['error']['code'])) {
            $err = $decoded['error'];

            return new OpinionPadiException(
                (string) $err['code'],
                isset($err['message']) ? (string) $err['message'] : 'The API returned an error.',
                isset($err['param']) ? (string) $err['param'] : null,
                $response->status,
                $body,
                $retryAfter,
            );
        }

        // No error object: a rate limit or a server error from in front of the API.
        $message = is_array($decoded) && isset($decoded['message']) && is_string($decoded['message'])
            ? $decoded['message']
            : sprintf('The API answered with HTTP %d.', $response->status);

        $code = match (true) {
            $response->status === 429 => 'rate_limited',
            $response->status >= 500 => 'server_error',
            default => 'api_error',
        };

        return new OpinionPadiException($code, $message, null, $response->status, $body, $retryAfter);
    }

    /**
     * Drop nulls and format values for a query string.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, string|int|float>
     */
    private static function prepareQuery(array $query): array
    {
        $out = [];
        foreach ($query as $key => $value) {
            if ($value === null) {
                continue;
            }
            if ($value instanceof DateTimeInterface) {
                $value = $value->format('Y-m-d');
            } elseif (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            }
            $out[$key] = $value;
        }

        return $out;
    }

    private static function env(string $name): ?string
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function formatSeconds(float $seconds): string
    {
        return rtrim(rtrim(number_format($seconds, 3, '.', ''), '0'), '.');
    }
}
