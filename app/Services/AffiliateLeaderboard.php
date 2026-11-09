<?php

namespace App\Services;

use Illuminate\Support\Collection;

class AffiliateLeaderboard
{
    private const PAGE_SIZE = 100;

    public function __construct(
        private RefersionClient $refersionClient,
        private AffiliateCustomFields $affiliateCustomFields,
    ) {}

    /**
     * Ranks eligible affiliates by lifetime commission. Affiliates are sorted before eligibility is checked,
     * so custom fields are only requested until enough eligible affiliates are found.
     *
     * @return list<array{id: int, firstName: ?string, lastName: ?string, company: ?string, totalCommission: int|float}>
     */
    public function top(int $count = 5): array
    {
        return $this->allAffiliates()
            ->sortByDesc(fn (array $affiliate): int|float => $affiliate['performance']['total']['commission'] ?? 0)
            ->lazy()
            ->filter(fn (array $affiliate): bool => $this->affiliateCustomFields->isDisplayedInLeaderboard($affiliate['id']))
            ->take($count)
            ->map(fn (array $affiliate): array => [
                'id' => $affiliate['id'],
                'firstName' => $affiliate['firstName'] ?? null,
                'lastName' => $affiliate['lastName'] ?? null,
                'company' => $affiliate['company'] ?? null,
                'totalCommission' => $affiliate['performance']['total']['commission'] ?? 0,
            ])
            ->values()
            ->all();
    }

    /**
     * Walks the List Affiliates endpoint from page 1 until a page returns fewer records than requested.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function allAffiliates(): Collection
    {
        $affiliates = collect();
        $page = 1;

        do {
            $records = $this->refersionClient
                ->get('/affiliates', ['page' => $page, 'limit' => self::PAGE_SIZE])
                ->json('data', []);

            $affiliates->push(...$records);
            $page++;
        } while (count($records) === self::PAGE_SIZE);

        return $affiliates;
    }
}
