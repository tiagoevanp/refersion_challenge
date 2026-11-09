<?php

namespace Tests\Feature\Services;

use App\Services\AffiliateCustomFields;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\FakesRefersionApi;
use Tests\TestCase;

class AffiliateCustomFieldsTest extends TestCase
{
    use FakesRefersionApi;

    private const CUSTOM_FIELDS_URL = self::API_URL.'/affiliates/1/custom-fields';

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureRefersionApi();
    }

    public function test_returns_custom_fields_of_the_affiliate(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::TOKEN_URL => $this->tokenResponse(),
            self::CUSTOM_FIELDS_URL => $this->leaderboardFieldResponse('TRUE'),
        ]);

        $customFields = app(AffiliateCustomFields::class)->forAffiliate(1);

        $this->assertSame([
            ['id' => 10, 'label' => 'Display in leaderboard', 'value' => 'TRUE', 'required' => false, 'type' => 'text', 'hidden' => false],
        ], $customFields);
    }

    public function test_returns_no_custom_fields_when_affiliate_is_not_found(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::TOKEN_URL => $this->tokenResponse(),
            self::CUSTOM_FIELDS_URL => Http::response(['error' => 'Affiliate not found'], 404),
        ]);

        $customFields = app(AffiliateCustomFields::class)->forAffiliate(1);

        $this->assertSame([], $customFields);
    }

    public function test_throws_when_custom_fields_request_fails(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::TOKEN_URL => $this->tokenResponse(),
            self::CUSTOM_FIELDS_URL => Http::response(['error' => 'Server error'], 500),
        ]);

        $this->expectException(RequestException::class);

        app(AffiliateCustomFields::class)->forAffiliate(1);
    }

    public function test_reuses_cached_custom_fields_for_ten_minutes(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::TOKEN_URL => $this->tokenResponse(),
            self::CUSTOM_FIELDS_URL => $this->leaderboardFieldResponse('TRUE'),
        ]);
        $this->freezeTime();
        $affiliateCustomFields = app(AffiliateCustomFields::class);

        $affiliateCustomFields->forAffiliate(1);
        $this->travel(599)->seconds();
        $affiliateCustomFields->forAffiliate(1);
        $this->travel(1)->seconds();
        $affiliateCustomFields->forAffiliate(1);

        $this->assertCount(2, Http::recorded(fn (Request $request): bool => $request->url() === 'https://'.self::CUSTOM_FIELDS_URL));
    }

    /**
     * @return array<string, array{list<array{label: string, value: string}>, bool}>
     */
    public static function leaderboardEligibilityCases(): array
    {
        return [
            'display in leaderboard is TRUE' => [[['label' => 'Display in leaderboard', 'value' => 'TRUE']], true],
            'display in leaderboard is FALSE' => [[['label' => 'Display in leaderboard', 'value' => 'FALSE']], false],
            'display in leaderboard is lowercase true' => [[['label' => 'Display in leaderboard', 'value' => 'true']], false],
            'another field is TRUE' => [[['label' => 'Nickname', 'value' => 'TRUE']], false],
            'no custom fields' => [[], false],
        ];
    }

    /**
     * @param  list<array{label: string, value: string}>  $customFields
     */
    #[DataProvider('leaderboardEligibilityCases')]
    public function test_affiliate_is_displayed_in_leaderboard_only_when_field_is_exactly_true(array $customFields, bool $expected): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::TOKEN_URL => $this->tokenResponse(),
            self::CUSTOM_FIELDS_URL => Http::response(['data' => $customFields]),
        ]);

        $isDisplayed = app(AffiliateCustomFields::class)->isDisplayedInLeaderboard(1);

        $this->assertSame($expected, $isDisplayed);
    }
}
