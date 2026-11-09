<?php

namespace Tests;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Http;

trait FakesRefersionApi
{
    protected const TOKEN_URL = 'auth.refersion.test/oauth/token';

    protected const API_URL = 'api.refersion.test';

    protected function configureRefersionApi(): void
    {
        config([
            'services.refersion.base_url' => 'https://'.self::API_URL,
            'services.refersion.oauth_token_url' => 'https://'.self::TOKEN_URL,
            'services.refersion.client_id' => 'client-id',
            'services.refersion.client_secret' => 'client-secret',
        ]);
    }

    protected function tokenResponse(string $accessToken = 'token', int $expiresIn = 3600): PromiseInterface
    {
        return Http::response(['access_token' => $accessToken, 'expires_in' => $expiresIn]);
    }

    /**
     * @return array{id: int, firstName: string, lastName: string, company: null, performance: array{total: array{commission: int}}}
     */
    protected function affiliateRecord(int $id, int $totalCommission): array
    {
        return [
            'id' => $id,
            'firstName' => "First{$id}",
            'lastName' => "Last{$id}",
            'company' => null,
            'performance' => ['total' => ['commission' => $totalCommission]],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $affiliates
     */
    protected function affiliatesPageResponse(array $affiliates): PromiseInterface
    {
        return Http::response(['data' => $affiliates]);
    }

    protected function customFieldsResponse(string $label, string $value): PromiseInterface
    {
        return Http::response(['data' => [
            ['id' => 10, 'label' => $label, 'value' => $value, 'required' => false, 'type' => 'text', 'hidden' => false],
        ]]);
    }

    protected function leaderboardFieldResponse(string $value): PromiseInterface
    {
        return $this->customFieldsResponse('Display in leaderboard', $value);
    }
}
