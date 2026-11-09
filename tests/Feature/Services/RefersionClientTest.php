<?php

namespace Tests\Feature\Services;

use App\Services\RefersionClient;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\FakesRefersionApi;
use Tests\TestCase;

class RefersionClientTest extends TestCase
{
    use FakesRefersionApi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureRefersionApi();
    }

    public function test_sends_query_with_bearer_token_obtained_from_client_credentials(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::TOKEN_URL => $this->tokenResponse('access-token'),
            self::API_URL.'/affiliates?page=2&limit=10' => $this->affiliatesPageResponse([['id' => 1]]),
        ]);

        $response = app(RefersionClient::class)->get('/affiliates', ['page' => 2, 'limit' => 10]);

        $this->assertSame([['id' => 1]], $response->json('data'));
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://'.self::TOKEN_URL
            && $request['client_id'] === 'client-id'
            && $request['client_secret'] === 'client-secret');
        Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://'.self::API_URL)
            && $request->hasHeader('Authorization', 'Bearer access-token'));
    }

    public function test_reuses_cached_token_until_one_minute_before_it_expires(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::TOKEN_URL => Http::sequence()
                ->push(['access_token' => 'first-token', 'expires_in' => 3600])
                ->push(['access_token' => 'second-token', 'expires_in' => 3600]),
            self::API_URL.'/affiliates' => $this->affiliatesPageResponse([]),
        ]);
        $this->freezeTime();
        $refersionClient = app(RefersionClient::class);

        $refersionClient->get('/affiliates');
        $this->travel(3539)->seconds();
        $refersionClient->get('/affiliates');
        $this->travel(1)->seconds();
        $refersionClient->get('/affiliates');

        $this->assertCount(2, Http::recorded(fn (Request $request): bool => $request->url() === 'https://'.self::TOKEN_URL));
        $this->assertSame(
            ['Bearer first-token', 'Bearer first-token', 'Bearer second-token'],
            Http::recorded(fn (Request $request): bool => str_starts_with($request->url(), 'https://'.self::API_URL))
                ->map(fn (array $pair): string => $pair[0]->header('Authorization')[0])
                ->values()
                ->all(),
        );
    }

    public function test_caches_short_lived_token_for_at_least_60_seconds(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::TOKEN_URL => $this->tokenResponse('token', 30),
            self::API_URL.'/affiliates' => $this->affiliatesPageResponse([]),
        ]);
        $this->freezeTime();
        $refersionClient = app(RefersionClient::class);

        $refersionClient->get('/affiliates');
        $this->travel(59)->seconds();
        $refersionClient->get('/affiliates');

        $this->assertCount(1, Http::recorded(fn (Request $request): bool => $request->url() === 'https://'.self::TOKEN_URL));
    }

    /**
     * @return array<string, array{array<string, mixed>, int}>
     */
    public static function invalidTokenResponses(): array
    {
        return [
            'failed status' => [['error' => 'invalid_client'], 401],
            'missing access token' => [['expires_in' => 3600], 200],
            'empty access token' => [['access_token' => '', 'expires_in' => 3600], 200],
        ];
    }

    /**
     * @param  array<string, mixed>  $tokenBody
     */
    #[DataProvider('invalidTokenResponses')]
    public function test_throws_when_token_cannot_be_obtained(array $tokenBody, int $tokenStatus): void
    {
        Http::preventStrayRequests();
        Http::fake([self::TOKEN_URL => Http::response($tokenBody, $tokenStatus)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to retrieve access token from Refersion API.');

        app(RefersionClient::class)->get('/affiliates');
    }

    public function test_refreshes_token_and_retries_once_when_api_returns_401(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::TOKEN_URL => Http::sequence()
                ->push(['access_token' => 'expired-token', 'expires_in' => 3600])
                ->push(['access_token' => 'fresh-token', 'expires_in' => 3600]),
            self::API_URL.'/affiliates' => Http::sequence()
                ->push(['error' => 'Unauthenticated'], 401)
                ->push(['data' => [['id' => 1]]]),
        ]);

        $response = app(RefersionClient::class)->get('/affiliates');

        $this->assertSame([['id' => 1]], $response->json('data'));
        Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://'.self::API_URL)
            && $request->hasHeader('Authorization', 'Bearer fresh-token'));
    }

    public function test_throws_when_api_still_returns_401_after_refreshing_token(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::TOKEN_URL => $this->tokenResponse(),
            self::API_URL.'/affiliates' => Http::response(['error' => 'Unauthenticated'], 401),
        ]);

        $this->expectException(RequestException::class);

        try {
            app(RefersionClient::class)->get('/affiliates');
        } finally {
            $this->assertCount(2, Http::recorded(fn (Request $request): bool => str_starts_with($request->url(), 'https://'.self::API_URL)));
        }
    }

    public function test_throws_when_api_returns_server_error(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::TOKEN_URL => $this->tokenResponse(),
            self::API_URL.'/affiliates' => Http::response(['error' => 'Server error'], 500),
        ]);

        $this->expectException(RequestException::class);

        app(RefersionClient::class)->get('/affiliates');
    }

    public function test_retries_after_retry_after_seconds_when_api_returns_429(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::TOKEN_URL => $this->tokenResponse(),
            self::API_URL.'/affiliates' => Http::sequence()
                ->push(['error' => 'Rate limit exceeded', 'retryAfter' => 7], 429, ['Retry-After' => '7'])
                ->push(['data' => [['id' => 1]]]),
        ]);
        Sleep::fake();

        $response = app(RefersionClient::class)->get('/affiliates');

        $this->assertSame([['id' => 1]], $response->json('data'));
        Sleep::assertSequence([Sleep::for(7)->seconds()]);
    }

    public function test_waits_one_second_when_429_has_no_retry_after_header(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::TOKEN_URL => $this->tokenResponse(),
            self::API_URL.'/affiliates' => Http::sequence()
                ->push(['error' => 'Rate limit exceeded'], 429)
                ->push(['data' => []]),
        ]);
        Sleep::fake();

        app(RefersionClient::class)->get('/affiliates');

        Sleep::assertSequence([Sleep::for(1)->seconds()]);
    }

    public function test_throws_after_three_retries_when_api_keeps_returning_429(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::TOKEN_URL => $this->tokenResponse(),
            self::API_URL.'/affiliates' => Http::response(['error' => 'Rate limit exceeded'], 429, ['Retry-After' => '2']),
        ]);
        Sleep::fake();

        $this->expectException(RequestException::class);

        try {
            app(RefersionClient::class)->get('/affiliates');
        } finally {
            $this->assertCount(4, Http::recorded(fn (Request $request): bool => str_starts_with($request->url(), 'https://'.self::API_URL)));
            Sleep::assertSleptTimes(3);
        }
    }

    public function test_waits_for_the_next_minute_before_exceeding_280_reads(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::TOKEN_URL => $this->tokenResponse(),
            self::API_URL.'/affiliates' => $this->affiliatesPageResponse([]),
        ]);
        $this->freezeTime();
        $refersionClient = app(RefersionClient::class);
        for ($read = 0; $read < 280; $read++) {
            $refersionClient->get('/affiliates');
        }
        Sleep::fake(syncWithCarbon: true);

        $refersionClient->get('/affiliates');

        Sleep::assertSequence([Sleep::for(60)->seconds()]);
    }
}
