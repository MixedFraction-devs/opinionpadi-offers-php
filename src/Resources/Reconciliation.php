<?php

declare(strict_types=1);

namespace OpinionPadi\Offers\Resources;

use OpinionPadi\Offers\OpinionPadi;
use OpinionPadi\Offers\OpinionPadiException;

/**
 * The events you sent lined up against the conversions we recorded.
 */
final class Reconciliation
{
    public function __construct(private readonly OpinionPadi $client) {}

    /**
     * GET /reconciliation. Filters: `offer_id`, `from`, `to` (Y-m-d or
     * DateTimeInterface, Africa/Lagos days; default the last 30 days).
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     *
     * @throws OpinionPadiException
     */
    public function get(array $params = []): array
    {
        return $this->client->request('GET', '/reconciliation', $params);
    }
}
