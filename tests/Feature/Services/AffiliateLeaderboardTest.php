<?php

namespace Tests\Feature\Services;

use App\Services\AffiliateLeaderboard;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\FakesRefersionApi;
use Tests\TestCase;

class AffiliateLeaderboardTest extends TestCase
{
    use FakesRefersionApi;

    private const FIRST_PAGE_URL = self::API_URL.'/affiliates?page=1&limit=100';

    private const SECOND_PAGE_URL = self::API_URL.'/affiliates?page=2&limit=100';

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureRefersionApi();
    }

    public function test_returns_top_five_eligible_affiliates_ranked_by_total_commission(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::TOKEN_URL => $this->tokenResponse(),
            self::API_URL.'/affiliates/7/custom-fields' => $this->leaderboardFieldResponse('FALSE'),
            self::API_URL.'/affiliates/*/custom-fields' => $this->leaderboardFieldResponse('TRUE'),
            self::FIRST_PAGE_URL => $this->affiliatesPageResponse([
                $this->affiliateRecord(1, 100),
                $this->affiliateRecord(2, 600),
                $this->affiliateRecord(3, 300),
                $this->affiliateRecord(4, 500),
                $this->affiliateRecord(5, 200),
                $this->affiliateRecord(6, 400),
                $this->affiliateRecord(7, 900),
            ]),
        ]);

        $leaderboard = app(AffiliateLeaderboard::class)->top();

        $this->assertSame([
            ['id' => 2, 'firstName' => 'First2', 'lastName' => 'Last2', 'company' => null, 'totalCommission' => 600],
            ['id' => 4, 'firstName' => 'First4', 'lastName' => 'Last4', 'company' => null, 'totalCommission' => 500],
            ['id' => 6, 'firstName' => 'First6', 'lastName' => 'Last6', 'company' => null, 'totalCommission' => 400],
            ['id' => 3, 'firstName' => 'First3', 'lastName' => 'Last3', 'company' => null, 'totalCommission' => 300],
            ['id' => 5, 'firstName' => 'First5', 'lastName' => 'Last5', 'company' => null, 'totalCommission' => 200],
        ], $leaderboard);
    }

    public function test_returns_fewer_affiliates_when_fewer_are_eligible(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::TOKEN_URL => $this->tokenResponse(),
            self::API_URL.'/affiliates/1/custom-fields' => $this->leaderboardFieldResponse('TRUE'),
            self::API_URL.'/affiliates/2/custom-fields' => $this->leaderboardFieldResponse('FALSE'),
            self::FIRST_PAGE_URL => $this->affiliatesPageResponse([
                $this->affiliateRecord(1, 100),
                $this->affiliateRecord(2, 200),
            ]),
        ]);

        $leaderboard = app(AffiliateLeaderboard::class)->top();

        $this->assertSame([1], array_column($leaderboard, 'id'));
    }

    public function test_returns_empty_leaderboard_when_account_has_no_affiliates(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::TOKEN_URL => $this->tokenResponse(),
            self::FIRST_PAGE_URL => $this->affiliatesPageResponse([]),
        ]);

        $leaderboard = app(AffiliateLeaderboard::class)->top();

        $this->assertSame([], $leaderboard);
    }

    public function test_ranks_affiliate_without_commission_data_as_zero(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::TOKEN_URL => $this->tokenResponse(),
            self::API_URL.'/affiliates/*/custom-fields' => $this->leaderboardFieldResponse('TRUE'),
            self::FIRST_PAGE_URL => $this->affiliatesPageResponse([
                ['id' => 1, 'firstName' => 'First1', 'lastName' => 'Last1', 'company' => null],
                $this->affiliateRecord(2, 100),
            ]),
        ]);

        $leaderboard = app(AffiliateLeaderboard::class)->top();

        $this->assertSame([2, 1], array_column($leaderboard, 'id'));
        $this->assertSame(0, $leaderboard[1]['totalCommission']);
    }

    public function test_reads_next_page_while_pages_are_full_and_stops_at_a_partial_page(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::TOKEN_URL => $this->tokenResponse(),
            self::API_URL.'/affiliates/*/custom-fields' => $this->leaderboardFieldResponse('TRUE'),
            self::FIRST_PAGE_URL => $this->affiliatesPageResponse(array_map(
                fn (int $id): array => $this->affiliateRecord($id, 1),
                range(1, 100),
            )),
            self::SECOND_PAGE_URL => $this->affiliatesPageResponse([$this->affiliateRecord(101, 999)]),
        ]);

        $leaderboard = app(AffiliateLeaderboard::class)->top();

        $this->assertSame(101, $leaderboard[0]['id']);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'page=3'));
    }

    public function test_does_not_request_custom_fields_of_affiliates_ranked_below_the_requested_count(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::TOKEN_URL => $this->tokenResponse(),
            self::API_URL.'/affiliates/*/custom-fields' => $this->leaderboardFieldResponse('TRUE'),
            self::FIRST_PAGE_URL => $this->affiliatesPageResponse([
                $this->affiliateRecord(1, 300),
                $this->affiliateRecord(2, 200),
                $this->affiliateRecord(3, 100),
            ]),
        ]);

        $leaderboard = app(AffiliateLeaderboard::class)->top(2);

        $this->assertSame([1, 2], array_column($leaderboard, 'id'));
        Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/affiliates/3/custom-fields'));
    }
}
