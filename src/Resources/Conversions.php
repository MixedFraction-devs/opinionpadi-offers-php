<?php

declare(strict_types=1);

namespace OpinionPadi\Offers\Resources;

use OpinionPadi\Offers\OpinionPadi;
use OpinionPadi\Offers\OpinionPadiException;

/**
 * Conversions recorded on your offers, whatever reported them.
 */
final class Conversions
{
    public function __construct(private readonly OpinionPadi $client) {}

    /**
     * GET /conversions. Filters: `offer_id`, `status`, `source`,
     * `external_id`, `from`, `to` (Y-m-d or DateTimeInterface), `page`,
     * `per_page`. Test keys get an empty list.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     *
     * @throws OpinionPadiException
     */
    public function list(array $params = []): array
    {
        return $this->client->request('GET', '/conversions', $params);
    }
}
