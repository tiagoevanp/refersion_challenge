<?php

namespace App\Services;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;

class AffiliateCustomFields
{
    private const LEADERBOARD_FIELD_LABEL = 'Display in leaderboard';

    private const LEADERBOARD_FIELD_ENABLED_VALUE = 'TRUE';

    /**
     * Custom fields change rarely, so caching them keeps repeated page loads from spending the API read budget.
     */
    private const CACHE_TTL_MINUTES = 10;

    public function __construct(private RefersionClient $refersionClient) {}

    /**
     * @return list<array{id: int, label: string, value: ?string, required: bool, type: string, hidden: bool}>
     */
    public function forAffiliate(int $affiliateId): array
    {
        return Cache::remember(
            "refersion.affiliates.{$affiliateId}.custom-fields",
            now()->addMinutes(self::CACHE_TTL_MINUTES),
            function () use ($affiliateId): array {
                try {
                    return $this->refersionClient->get("/affiliates/{$affiliateId}/custom-fields")->json('data', []);
                } catch (RequestException $exception) {
                    if ($exception->response->notFound()) {
                        return [];
                    }

                    throw $exception;
                }
            },
        );
    }

    public function isDisplayedInLeaderboard(int $affiliateId): bool
    {
        return collect($this->forAffiliate($affiliateId))->contains(
            fn (array $field): bool => $field['label'] === self::LEADERBOARD_FIELD_LABEL
                && $field['value'] === self::LEADERBOARD_FIELD_ENABLED_VALUE,
        );
    }
}
