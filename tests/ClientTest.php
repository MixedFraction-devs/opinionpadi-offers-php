<?php

declare(strict_types=1);

namespace OpinionPadi\Offers\Tests;

use DateTimeImmutable;
use DateTimeZone;
use OpinionPadi\Offers\Http\TransportException;
use OpinionPadi\Offers\OpinionPadi;
use OpinionPadi\Offers\OpinionPadiException;
use PHPUnit\Framework\TestCase;

final class ClientTest extends TestCase
{
    private const TEST_KEY = 'op_sk_test_abc123';

    private const LIVE_KEY = 'op_sk_live_abc123';

    private FakeTransport $transport;

    /** @var list<float> */
    private array $sleeps = [];

    protected function setUp(): void
    {
        $this->transport = new FakeTransport();
        $this->sleeps = [];
        putenv('OPINIONPADI_SECRET_KEY');
        putenv('OPINIONPADI_BASE_URL');
        unset($_ENV['OPINIONPADI_SECRET_KEY'], $_SERVER['OPINIONPADI_SECRET_KEY'], $_ENV['OPINIONPADI_BASE_URL'], $_SERVER['OPINIONPADI_BASE_URL']);
    }

    /** @param array<string, mixed> $options */
    private function client(array $options = []): OpinionPadi
    {
        return new OpinionPadi($options + [
            'api_key' => self::TEST_KEY,
            'base_url' => 'https://api.example.test/api/v1/offers/',
            'transport' => $this->transport,
            'sleep' => function (float $s): void {
                $this->sleeps[] = $s;
            },
        ]);
    }

    private static function event(array $overrides = []): array
    {
        return $overrides + [
            'id' => 'evt_1',
            'object' => 'event',
            'event_id' => 'order_1',
            'type' => 'purchase',
            'mode' => 'test',
            'outcome' => 'converted',
        ];
    }

    // Construction and mode

    public function test_mode_comes_from_the_key_prefix(): void
    {
        $test = $this->client();
        $this->assertSame('test', $test->mode());
        $this->assertTrue($test->isTestMode());

        $live = $this->client(['api_key' => self::LIVE_KEY]);
        $this->assertSame('live', $live->mode());
        $this->assertFalse($live->isTestMode());
    }

    public function test_missing_key_throws(): void
    {
        try {
            new OpinionPadi(['transport' => $this->transport]);
            $this->fail('Expected an exception');
        } catch (OpinionPadiException $e) {
            $this->assertSame('missing_api_key', $e->getCode());
            $this->assertNull($e->status);
        }
    }

    public function test_key_without_offers_prefix_throws(): void
    {
        $this->expectException(OpinionPadiException::class);
        $this->expectExceptionMessage('op_sk_live_ or op_sk_test_');
        new OpinionPadi(['api_key' => 'sk_live_nope', 'transport' => $this->transport]);
    }

    public function test_key_and_base_url_fall_back_to_the_environment(): void
    {
        putenv('OPINIONPADI_SECRET_KEY='.self::LIVE_KEY);
        putenv('OPINIONPADI_BASE_URL=http://127.0.0.1:9999/api/v1/offers/');

        try {
            $op = new OpinionPadi(['transport' => $this->transport]);
            $this->assertSame('live', $op->mode());
            $this->assertSame('http://127.0.0.1:9999/api/v1/offers', $op->baseUrl());
        } finally {
            putenv('OPINIONPADI_SECRET_KEY');
            putenv('OPINIONPADI_BASE_URL');
        }
    }

    public function test_default_base_url(): void
    {
        $op = new OpinionPadi(['api_key' => self::TEST_KEY, 'transport' => $this->transport]);
        $this->assertSame('https://api.opinionpadi.com/api/v1/offers', $op->baseUrl());
    }

    // Request shape

    public function test_balance_sends_get_with_offer_filter(): void
    {
        $this->transport->push(200, ['object' => 'balance', 'wallet' => ['available' => 250000, 'pending' => 0]]);

        $balance = $this->client()->balance(['offer_id' => 42]);

        $this->assertSame(250000, $balance['wallet']['available']);
        $req = $this->transport->requests[0];
        $this->assertSame('GET', $req['method']);
        $this->assertSame('https://api.example.test/api/v1/offers/balance?offer_id=42', $req['url']);

        $this->transport->push(200, ['object' => 'balance']);
        $this->client()->balance();
        $this->assertSame('https://api.example.test/api/v1/offers/balance', $this->transport->requests[1]['url']);
    }

    public function test_account_sends_get_with_headers(): void
    {
        $this->transport->push(200, ['object' => 'account', 'key' => ['mode' => 'test']]);

        $account = $this->client(['timeout' => 4])->account();

        $this->assertSame('account', $account['object']);
        $req = $this->transport->requests[0];
        $this->assertSame('GET', $req['method']);
        $this->assertSame('https://api.example.test/api/v1/offers/account', $req['url']);
        $this->assertSame('Bearer '.self::TEST_KEY, $req['headers']['Authorization']);
        $this->assertSame('application/json', $req['headers']['Accept']);
        $this->assertArrayNotHasKey('Content-Type', $req['headers']);
        $this->assertMatchesRegularExpression('#^opinionpadi-offers-php/0\.1\.0 \(PHP \d+\.\d+#', $req['headers']['User-Agent']);
        $this->assertNull($req['body']);
        $this->assertSame(4.0, $req['timeout']);
    }

    public function test_track_posts_json_and_drops_nulls(): void
    {
        $this->transport->push(201, ['duplicate' => false, 'event' => self::event()]);

        $result = $this->client()->events->track([
            'type' => 'purchase',
            'event_id' => 'order_1',
            'click_id' => 'op_test_converted',
            'ref' => null,
            'offer_id' => 42,
            'amount' => 15000.5,
            'customer_id' => null,
            'metadata' => ['plan' => 'gold', 'first' => true],
        ]);

        $this->assertFalse($result['duplicate']);
        $this->assertSame('converted', $result['event']['outcome']);

        $req = $this->transport->requests[0];
        $this->assertSame('POST', $req['method']);
        $this->assertSame('https://api.example.test/api/v1/offers/events', $req['url']);
        $this->assertSame('application/json', $req['headers']['Content-Type']);
        $this->assertSame([
            'type' => 'purchase',
            'event_id' => 'order_1',
            'click_id' => 'op_test_converted',
            'offer_id' => 42,
            'amount' => 15000.5,
            'metadata' => ['plan' => 'gold', 'first' => true],
        ], $this->transport->lastJson());
    }

    public function test_occurred_at_accepts_a_datetime(): void
    {
        $this->transport->push(201, ['duplicate' => false, 'event' => self::event()]);

        $this->client()->events->track([
            'type' => 'signup',
            'event_id' => 'su_1',
            'occurred_at' => new DateTimeImmutable('2026-11-17 10:00:00', new DateTimeZone('Africa/Lagos')),
        ]);

        $this->assertSame('2026-11-17T10:00:00+01:00', $this->transport->lastJson()['occurred_at']);
    }

    public function test_refund_forces_type_refund(): void
    {
        $this->transport->push(201, ['duplicate' => false, 'event' => self::event(['type' => 'refund', 'outcome' => 'refunded'])]);

        $this->client()->events->refund(['type' => 'purchase', 'event_id' => 'refund_1', 'refund_of' => 'order_1']);

        $this->assertSame(['type' => 'refund', 'event_id' => 'refund_1', 'refund_of' => 'order_1'], $this->transport->lastJson());
    }

    public function test_refund_requires_event_id_and_refund_of(): void
    {
        foreach ([['refund_of' => 'order_1'], ['event_id' => 'refund_1'], ['event_id' => 'refund_1', 'refund_of' => '  ']] as $params) {
            try {
                $this->client()->events->refund($params);
                $this->fail('Expected an exception');
            } catch (OpinionPadiException $e) {
                $this->assertSame('invalid_request', $e->getCode());
                $this->assertContains($e->param, ['event_id', 'refund_of']);
            }
        }
        $this->assertCount(0, $this->transport->requests);
    }

    public function test_batch_posts_events(): void
    {
        $this->transport->push(200, ['object' => 'batch', 'received' => 2, 'succeeded' => 2, 'failed' => 0, 'results' => []]);

        $result = $this->client()->events->batch([
            ['type' => 'purchase', 'event_id' => 'o1', 'click_id' => null],
            ['type' => 'purchase', 'event_id' => 'o2', 'amount' => 4000],
        ]);

        $this->assertSame('batch', $result['object']);
        $req = $this->transport->requests[0];
        $this->assertSame('https://api.example.test/api/v1/offers/events/batch', $req['url']);
        $this->assertSame(['events' => [
            ['type' => 'purchase', 'event_id' => 'o1'],
            ['type' => 'purchase', 'event_id' => 'o2', 'amount' => 4000],
        ]], $this->transport->lastJson());
    }

    public function test_batch_size_guard(): void
    {
        foreach ([0, 101] as $size) {
            $events = array_fill(0, $size, ['type' => 'purchase', 'event_id' => 'x']);
            try {
                $this->client()->events->batch($events);
                $this->fail('Expected an exception for '.$size.' events');
            } catch (OpinionPadiException $e) {
                $this->assertSame('invalid_request', $e->getCode());
                $this->assertSame('events', $e->param);
            }
        }
        $this->assertCount(0, $this->transport->requests);

        $this->transport->push(200, ['object' => 'batch']);
        $this->client()->events->batch(array_fill(0, 100, ['type' => 'purchase', 'event_id' => 'x']));
        $this->assertCount(1, $this->transport->requests);
    }

    public function test_get_url_encodes_the_event_id(): void
    {
        $this->transport->push(200, ['event' => self::event()]);

        $this->client()->events->get('order/10 23?x');

        $this->assertSame('https://api.example.test/api/v1/offers/events/order%2F10%2023%3Fx', $this->transport->requests[0]['url']);
    }

    public function test_list_builds_query_and_drops_nulls(): void
    {
        $this->transport->push(200, ['object' => 'list', 'data' => []]);
        $this->transport->push(200, ['object' => 'list', 'data' => []]);
        $this->transport->push(200, ['object' => 'reconciliation']);

        $op = $this->client();
        $op->events->list(['per_page' => 20, 'offer_id' => null, 'from' => new DateTimeImmutable('2026-11-01'), 'to' => '2026-11-17']);
        $op->conversions->list(['status' => 'pending', 'external_id' => 'order 1']);
        $op->reconciliation->get(['offer_id' => 42, 'to' => null]);

        $this->assertSame('https://api.example.test/api/v1/offers/events?per_page=20&from=2026-11-01&to=2026-11-17', $this->transport->requests[0]['url']);
        $this->assertSame('https://api.example.test/api/v1/offers/conversions?status=pending&external_id=order%201', $this->transport->requests[1]['url']);
        $this->assertSame('https://api.example.test/api/v1/offers/reconciliation?offer_id=42', $this->transport->requests[2]['url']);
    }

    public function test_list_without_params_has_no_query(): void
    {
        $this->transport->push(200, ['object' => 'list', 'data' => []]);
        $this->client()->events->list();
        $this->assertSame('https://api.example.test/api/v1/offers/events', $this->transport->requests[0]['url']);
    }

    // Errors

    public function test_api_errors_are_mapped(): void
    {
        $body = ['error' => ['code' => 'unknown_click', 'message' => 'No click with this click_id on your offers.', 'param' => 'click_id']];
        $this->transport->push(422, $body);

        try {
            $this->client()->events->track(['type' => 'purchase', 'event_id' => 'o', 'click_id' => 'op_test_unknown']);
            $this->fail('Expected an exception');
        } catch (OpinionPadiException $e) {
            $this->assertSame('unknown_click', $e->getCode());
            $this->assertSame('unknown_click', $e->errorCode);
            $this->assertSame('No click with this click_id on your offers.', $e->getMessage());
            $this->assertSame('click_id', $e->param);
            $this->assertSame(422, $e->status);
            $this->assertSame($body, $e->body);
            $this->assertNull($e->retryAfter);
        }
        $this->assertCount(1, $this->transport->requests, '422 is not retried');
    }

    public function test_401_and_404_are_not_retried(): void
    {
        $this->transport->push(401, ['error' => ['code' => 'invalid_api_key', 'message' => 'This API key does not exist.', 'param' => null]]);
        try {
            $this->client()->account();
            $this->fail('Expected an exception');
        } catch (OpinionPadiException $e) {
            $this->assertSame('invalid_api_key', $e->getCode());
            $this->assertSame(401, $e->status);
            $this->assertNull($e->param);
        }

        $this->transport->push(404, ['error' => ['code' => 'event_not_found', 'message' => 'No event.', 'param' => 'event_id']]);
        try {
            $this->client()->events->get('nope');
            $this->fail('Expected an exception');
        } catch (OpinionPadiException $e) {
            $this->assertSame('event_not_found', $e->getCode());
            $this->assertSame(404, $e->status);
        }

        $this->assertCount(2, $this->transport->requests);
        $this->assertSame([], $this->sleeps);
    }

    public function test_non_json_error_body(): void
    {
        $this->transport->push(502, '<html>Bad gateway</html>', ['Content-Type' => 'text/html']);

        try {
            $this->client(['max_retries' => 0])->account();
            $this->fail('Expected an exception');
        } catch (OpinionPadiException $e) {
            $this->assertSame('server_error', $e->getCode());
            $this->assertSame(502, $e->status);
            $this->assertSame('<html>Bad gateway</html>', $e->body);
        }
    }

    public function test_invalid_json_on_success_is_invalid_response(): void
    {
        $this->transport->push(200, 'not json');

        try {
            $this->client()->account();
            $this->fail('Expected an exception');
        } catch (OpinionPadiException $e) {
            $this->assertSame('invalid_response', $e->getCode());
            $this->assertSame(200, $e->status);
        }
    }

    // Retries

    public function test_retries_5xx_then_succeeds(): void
    {
        $this->transport->push(500, ['message' => 'Server Error']);
        $this->transport->push(503, '');
        $this->transport->push(200, ['object' => 'account']);

        $account = $this->client()->account();

        $this->assertSame('account', $account['object']);
        $this->assertCount(3, $this->transport->requests);
        $this->assertCount(2, $this->sleeps);
        $this->assertGreaterThanOrEqual(0.5, $this->sleeps[0]);
        $this->assertLessThanOrEqual(0.625, $this->sleeps[0]);
        $this->assertGreaterThanOrEqual(1.0, $this->sleeps[1]);
        $this->assertLessThanOrEqual(1.25, $this->sleeps[1]);
    }

    public function test_gives_up_after_max_retries(): void
    {
        $this->transport->push(500, ['message' => 'Server Error']);
        $this->transport->push(500, ['message' => 'Server Error']);
        $this->transport->push(500, ['message' => 'Server Error']);

        try {
            $this->client()->account();
            $this->fail('Expected an exception');
        } catch (OpinionPadiException $e) {
            $this->assertSame('server_error', $e->getCode());
            $this->assertSame('Server Error', $e->getMessage());
            $this->assertSame(500, $e->status);
        }
        $this->assertCount(3, $this->transport->requests);
    }

    public function test_max_retries_zero_does_not_retry(): void
    {
        $this->transport->push(409, ['error' => ['code' => 'conflict', 'message' => 'Retry.', 'param' => 'event_id']]);

        try {
            $this->client(['max_retries' => 0])->events->track(['type' => 'purchase', 'event_id' => 'o']);
            $this->fail('Expected an exception');
        } catch (OpinionPadiException $e) {
            $this->assertSame('conflict', $e->getCode());
        }
        $this->assertCount(1, $this->transport->requests);
    }

    public function test_409_is_retried_with_the_same_body(): void
    {
        $this->transport->push(409, ['error' => ['code' => 'conflict', 'message' => 'Retry.', 'param' => 'event_id']]);
        $this->transport->push(200, ['duplicate' => true, 'event' => self::event()]);

        $result = $this->client()->events->track(['type' => 'purchase', 'event_id' => 'order_1']);

        $this->assertTrue($result['duplicate']);
        $this->assertCount(2, $this->transport->requests);
        $this->assertSame($this->transport->requests[0]['body'], $this->transport->requests[1]['body']);
    }

    public function test_429_honours_retry_after(): void
    {
        $this->transport->push(429, ['message' => 'Too Many Attempts.'], ['Retry-After' => '7']);
        $this->transport->push(200, ['object' => 'account']);

        $this->client()->account();

        $this->assertSame([7.0], $this->sleeps);
    }

    public function test_429_exposes_retry_after_when_retries_run_out(): void
    {
        $this->transport->push(429, ['message' => 'Too Many Attempts.'], ['retry-after' => '30']);

        try {
            $this->client(['max_retries' => 0])->account();
            $this->fail('Expected an exception');
        } catch (OpinionPadiException $e) {
            $this->assertSame('rate_limited', $e->getCode());
            $this->assertSame(429, $e->status);
            $this->assertSame(30, $e->retryAfter);
            $this->assertSame(30, $e->getRetryAfter());
        }
    }

    public function test_429_without_retry_after_uses_backoff(): void
    {
        $this->transport->push(429, ['message' => 'Too Many Attempts.']);
        $this->transport->push(200, ['object' => 'account']);

        $this->client()->account();

        $this->assertCount(1, $this->sleeps);
        $this->assertGreaterThanOrEqual(0.5, $this->sleeps[0]);
        $this->assertLessThanOrEqual(0.625, $this->sleeps[0]);
    }

    public function test_backoff_is_capped_at_eight_seconds(): void
    {
        for ($i = 0; $i < 7; $i++) {
            $this->transport->push(503, '');
        }
        $this->transport->push(200, ['object' => 'account']);

        $this->client(['max_retries' => 7])->account();

        $this->assertCount(7, $this->sleeps);
        foreach ($this->sleeps as $s) {
            $this->assertLessThanOrEqual(8.0, $s);
        }
        $this->assertSame(8.0, $this->sleeps[6]);
    }

    public function test_connection_errors_are_retried_then_reported(): void
    {
        $this->transport->pushFailure(new TransportException('Connection refused'));
        $this->transport->pushFailure(new TransportException('Connection refused'));
        $this->transport->pushFailure(new TransportException('Connection refused'));

        try {
            $this->client()->account();
            $this->fail('Expected an exception');
        } catch (OpinionPadiException $e) {
            $this->assertSame('connection_error', $e->getCode());
            $this->assertNull($e->status);
            $this->assertStringContainsString('Connection refused', $e->getMessage());
        }
        $this->assertCount(3, $this->transport->requests);
    }

    public function test_timeouts_are_retried_then_reported(): void
    {
        $this->transport->pushFailure(new TransportException('Operation timed out', true));
        $this->transport->push(200, ['object' => 'account']);
        $this->assertSame('account', $this->client()->account()['object']);

        $this->transport->pushFailure(new TransportException('Operation timed out', true));
        try {
            $this->client(['max_retries' => 0])->account();
            $this->fail('Expected an exception');
        } catch (OpinionPadiException $e) {
            $this->assertSame('timeout', $e->getCode());
            $this->assertNull($e->status);
        }
    }
}
