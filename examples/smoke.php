<?php

/**
 * Smoke test against a real server with a test key.
 *
 *     OPINIONPADI_SECRET_KEY=op_sk_test_... \
 *     OPINIONPADI_BASE_URL=http://127.0.0.1:8000/api/v1/offers \
 *     php examples/smoke.php
 *
 * Optional: OPINIONPADI_OFFER_ID (default 1) for the offer the test
 * conversion is simulated against. Exits non-zero on any unexpected result.
 */

declare(strict_types=1);

use OpinionPadi\Offers\OpinionPadi;
use OpinionPadi\Offers\OpinionPadiException;

require __DIR__.'/../vendor/autoload.php';

$failures = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $failures;
    if (! $ok) {
        $failures++;
    }
    printf("%s  %s%s\n", $ok ? 'ok  ' : 'FAIL', $label, $detail !== '' ? '  ('.$detail.')' : '');
}

function step(string $label, callable $fn): mixed
{
    try {
        return $fn();
    } catch (OpinionPadiException $e) {
        check($label, false, sprintf('%s %s: %s', $e->status ?? '-', $e->getCode(), $e->getMessage()));

        return null;
    }
}

$op = new OpinionPadi();

if (! $op->isTestMode()) {
    fwrite(STDERR, "Use a test key (op_sk_test_...) for the smoke test.\n");
    exit(2);
}

printf("Base URL %s, mode %s\n\n", $op->baseUrl(), $op->mode());

$offerId = (int) (getenv('OPINIONPADI_OFFER_ID') ?: 1);
$run = 'smoke-php-'.date('YmdHis').'-'.bin2hex(random_bytes(3));
$orderId = $run.'-order';

// 1. Account
$account = step('account', fn () => $op->account());
if ($account !== null) {
    check('account', ($account['object'] ?? null) === 'account' && ($account['key']['mode'] ?? null) === 'test',
        sprintf('business "%s", %d offers', $account['business']['name'] ?? '?', count($account['offers'] ?? [])));
    check('account lists test values', in_array('op_test_converted', $account['test_values'] ?? [], true));
}

// 2. A converted test event
$first = step('events.track converted', fn () => $op->events->track([
    'type' => 'purchase',
    'event_id' => $orderId,
    'click_id' => 'op_test_converted',
    'offer_id' => $offerId,
    'amount' => 15000.50,
    'customer_id' => 'smoke_customer',
    'occurred_at' => new DateTimeImmutable('-1 minute'),
    'metadata' => ['source' => 'smoke.php', 'first_order' => true],
]));
if ($first !== null) {
    $event = $first['event'];
    check('events.track converted',
        $first['duplicate'] === false
        && $event['outcome'] === 'converted'
        && ($event['conversion']['status'] ?? null) === 'pending'
        && ($event['conversion']['simulated'] ?? null) === true,
        sprintf('%s outcome=%s conversion=%s', $event['id'], $event['outcome'], $event['conversion']['status'] ?? 'null'));
}

// 3. The same event again
$again = step('events.track duplicate', fn () => $op->events->track([
    'type' => 'purchase',
    'event_id' => $orderId,
    'click_id' => 'op_test_converted',
    'offer_id' => $offerId,
    'amount' => 15000.50,
]));
if ($again !== null && $first !== null) {
    check('events.track duplicate', $again['duplicate'] === true && $again['event']['id'] === $first['event']['id'],
        'duplicate='.json_encode($again['duplicate']));
}

// 4. Fetch it back
$fetched = step('events.get', fn () => $op->events->get($orderId));
if ($fetched !== null) {
    check('events.get', ($fetched['event']['event_id'] ?? null) === $orderId, 'outcome='.($fetched['event']['outcome'] ?? '?'));
}

// 5. Refund it
$refund = step('events.refund', fn () => $op->events->refund([
    'event_id' => $run.'-refund',
    'refund_of' => $orderId,
]));
if ($refund !== null) {
    check('events.refund', $refund['event']['type'] === 'refund' && $refund['event']['outcome'] === 'refunded',
        'outcome='.$refund['event']['outcome']);
}

// 6. A batch: one paid conversion, one unattributed sale, one unknown ref
$batch = step('events.batch', fn () => $op->events->batch([
    ['type' => 'purchase', 'event_id' => $run.'-b1', 'click_id' => 'op_test_converted_paid', 'offer_id' => $offerId, 'amount' => 9000],
    ['type' => 'purchase', 'event_id' => $run.'-b2', 'amount' => 4000, 'offer_id' => $offerId],
    ['type' => 'purchase', 'event_id' => $run.'-b3', 'ref' => 'op_test_unknown'],
]));
if ($batch !== null) {
    $r = $batch['results'];
    check('events.batch',
        $batch['received'] === 3 && $batch['succeeded'] === 2 && $batch['failed'] === 1
        && $r[0]['event']['outcome'] === 'converted' && ($r[0]['event']['conversion']['status'] ?? null) === 'approved'
        && $r[1]['event']['outcome'] === 'unattributed'
        && $r[2]['ok'] === false && $r[2]['error']['code'] === 'unknown_ref',
        sprintf('succeeded=%d failed=%d [%s, %s, %s]', $batch['succeeded'], $batch['failed'],
            $r[0]['event']['outcome'] ?? '?', $r[1]['event']['outcome'] ?? '?', $r[2]['error']['code'] ?? '?'));
}

// 7. List events
$list = step('events.list', fn () => $op->events->list(['per_page' => 5]));
if ($list !== null) {
    $ids = array_column($list['data'], 'event_id');
    check('events.list', $list['object'] === 'list' && $list['per_page'] === 5 && in_array($run.'-b3', $ids, true) === false && count($list['data']) > 0,
        sprintf('%d of %d shown, newest %s', count($list['data']), $list['total'], $ids[0] ?? '-'));
}

// 8. Conversions (a test key never creates any)
$conversions = step('conversions.list', fn () => $op->conversions->list(['per_page' => 5]));
if ($conversions !== null) {
    check('conversions.list', $conversions['object'] === 'list' && $conversions['data'] === [], 'total='.$conversions['total']);
}

// 9. Reconciliation for today
$today = new DateTimeImmutable('now', new DateTimeZone('Africa/Lagos'));
$recon = step('reconciliation.get', fn () => $op->reconciliation->get(['from' => $today->modify('-1 day'), 'to' => $today]));
if ($recon !== null) {
    check('reconciliation.get', $recon['object'] === 'reconciliation' && $recon['mode'] === 'test' && $recon['events']['total'] >= 4,
        sprintf('%s to %s, %d events, converted=%d refunded=%d', $recon['range']['from'], $recon['range']['to'],
            $recon['events']['total'], $recon['events']['by_outcome']['converted'] ?? 0, $recon['events']['by_outcome']['refunded'] ?? 0));
}

// 9b. Wallet and offer budgets
$balance = step('balance', fn () => $op->balance());
if ($balance !== null) {
    check('balance', $balance['object'] === 'balance' && isset($balance['wallet']['available']) && is_array($balance['by_offer']),
        sprintf('wallet %s %d, %d offers holding money, %d slots left', $balance['currency'], $balance['wallet']['available'], count($balance['by_offer']), $balance['offers']['remaining_slots']));
}

// 10. op_test_unknown is an error
try {
    $op->events->track(['type' => 'purchase', 'event_id' => $run.'-unknown', 'click_id' => 'op_test_unknown']);
    check('op_test_unknown error', false, 'no exception');
} catch (OpinionPadiException $e) {
    check('op_test_unknown error', $e->getCode() === 'unknown_click' && $e->status === 422 && $e->param === 'click_id',
        sprintf('%d %s param=%s', $e->status, $e->getCode(), $e->param));
}

// 11. A missing event is a 404
try {
    $op->events->get($run.'-does-not-exist');
    check('events.get 404', false, 'no exception');
} catch (OpinionPadiException $e) {
    check('events.get 404', $e->getCode() === 'event_not_found' && $e->status === 404, sprintf('%d %s', $e->status, $e->getCode()));
}

// 12. A bad key is a 401, not retried
$bad = new OpinionPadi(['api_key' => 'op_sk_test_'.str_repeat('0', 40), 'base_url' => $op->baseUrl()]);
try {
    $bad->account();
    check('invalid key error', false, 'no exception');
} catch (OpinionPadiException $e) {
    check('invalid key error', $e->getCode() === 'invalid_api_key' && $e->status === 401, sprintf('%d %s', $e->status, $e->getCode()));
}

echo "\n".($failures === 0 ? 'All checks passed.' : $failures.' check(s) failed.')."\n";
exit($failures === 0 ? 0 : 1);
