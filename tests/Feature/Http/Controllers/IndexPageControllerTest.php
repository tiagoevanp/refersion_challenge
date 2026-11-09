<?php

namespace Tests\Feature\Http\Controllers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Inertia\Testing\AssertableInertia;
use Tests\FakesRefersionApi;
use Tests\TestCase;

class IndexPageControllerTest extends TestCase
{
    use FakesRefersionApi;

    private const FIRST_PAGE_URL = self::API_URL.'/affiliates?page=1&limit=100';

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureRefersionApi();
    }

    public function test_home_renders_welcome_page(): void
    {
        $response = $this->get(route('home'));

        $response->assertOk();
        $response->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->component('welcome'));
    }

    public function test_returns_leaderboard_as_json(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::TOKEN_URL => $this->tokenResponse(),
            self::API_URL.'/affiliates/1/custom-fields' => $this->leaderboardFieldResponse('TRUE'),
            self::FIRST_PAGE_URL => $this->affiliatesPageResponse([$this->affiliateRecord(1, 100)]),
        ]);

        $response = $this->getJson(route('affiliate'));

        $response->assertOk();
        $response->assertExactJson([
            ['id' => 1, 'firstName' => 'First1', 'lastName' => 'Last1', 'company' => null, 'totalCommission' => 100],
        ]);
    }

    public function test_returns_500_and_logs_when_refersion_api_fails(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::TOKEN_URL => $this->tokenResponse(),
            self::FIRST_PAGE_URL => Http::response(['error' => 'Server error'], 500),
        ]);
        Log::shouldReceive('error')->once()->withArgs(
            fn (string $message): bool => str_starts_with($message, 'Error fetching affiliates: '),
        );

        $response = $this->getJson(route('affiliate'));

        $response->assertInternalServerError();
        $response->assertExactJson(['error' => 'Failed to fetch affiliates']);
    }

    public function test_returns_500_when_access_token_cannot_be_obtained(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::TOKEN_URL => Http::response(['error' => 'invalid_client'], 401),
        ]);
        Log::shouldReceive('error')->once();

        $response = $this->getJson(route('affiliate'));

        $response->assertInternalServerError();
        $response->assertExactJson(['error' => 'Failed to fetch affiliates']);
    }
}
