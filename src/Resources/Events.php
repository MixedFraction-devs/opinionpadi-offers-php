<?php

declare(strict_types=1);

namespace OpinionPadi\Offers\Resources;

use DateTimeInterface;
use OpinionPadi\Offers\OpinionPadi;
use OpinionPadi\Offers\OpinionPadiException;

/**
 * Purchases, signups, refunds and other events your servers send.
 */
final class Events
{
    public const MAX_BATCH = 100;

    public function __construct(private readonly OpinionPadi $client) {}

    /**
     * POST /events. Returns `['duplicate' => bool, 'event' => [...]]`.
     *
     * Sending the same `event_id` again returns the first result and changes
     * nothing, so it is safe to retry.
     *
     * @param  array<string, mixed>  $params  `type`, `event_id`, `click_id`, `ref`, `offer_id`, `amount`,
     *                                        `currency`, `occurred_at`, `customer_id`, `customer_ip`,
     *                                        `refund_of`, `metadata`.
     * @return array<string, mixed>
     *
     * @throws OpinionPadiException
     */
    public function track(array $params): array
    {
        return $this->client->request('POST', '/events', [], self::prepare($params));
    }

    /**
     * A refund: POST /events with `type` set to `refund`. Needs its own
     * `event_id` and `refund_of`, the purchase's `event_id`.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     *
     * @throws OpinionPadiException
     */
    public function refund(array $params): array
    {
        foreach (['event_id', 'refund_of'] as $field) {
            if (! isset($params[$field]) || trim((string) $params[$field]) === '') {
                throw new OpinionPadiException(
                    'invalid_request',
                    $field === 'event_id'
                        ? 'A refund needs its own event_id.'
                        : 'A refund needs refund_of: the event_id of the purchase being refunded.',
                    $field,
                );
            }
        }

        $params['type'] = 'refund';

        return $this->client->request('POST', '/events', [], self::prepare($params));
    }

    /**
     * POST /events/batch with up to 100 events, each handled on its own.
     *
     * @param  list<array<string, mixed>>  $events
     * @return array<string, mixed>
     *
     * @throws OpinionPadiException
     */
    public function batch(array $events): array
    {
        $count = count($events);
        if ($count === 0 || $count > self::MAX_BATCH) {
            throw new OpinionPadiException(
                'invalid_request',
                sprintf('A batch holds 1 to %d events; got %d.', self::MAX_BATCH, $count),
                'events',
            );
        }

        $prepared = [];
        foreach (array_values($events) as $event) {
            $prepared[] = is_array($event) ? self::prepare($event) : $event;
        }

        return $this->client->request('POST', '/events/batch', [], ['events' => $prepared]);
    }

    /**
     * GET /events/{event_id}. Returns `['event' => [...]]` with the
     * conversion's current status.
     *
     * @return array<string, mixed>
     *
     * @throws OpinionPadiException
     */
    public function get(string $eventId): array
    {
        return $this->client->request('GET', '/events/'.rawurlencode($eventId));
    }

    /**
     * GET /events, newest first. Filters: `offer_id`, `type`, `outcome`,
     * `from`, `to` (Y-m-d or DateTimeInterface), `page`, `per_page`.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     *
     * @throws OpinionPadiException
     */
    public function list(array $params = []): array
    {
        return $this->client->request('GET', '/events', $params);
    }

    /**
     * Drop nulls and turn dates into ISO 8601.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private static function prepare(array $params): array
    {
        $out = [];
        foreach ($params as $key => $value) {
            if ($value === null) {
                continue;
            }
            if ($value instanceof DateTimeInterface) {
                $value = $value->format(DateTimeInterface::ATOM);
            }
            $out[$key] = $value;
        }

        return $out;
    }
}
