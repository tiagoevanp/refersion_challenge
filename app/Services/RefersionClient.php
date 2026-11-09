<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Sleep;
use RuntimeException;

class RefersionClient
{
    private const CACHE_KEY = 'refersion.access_token';

    private const READ_RATE_LIMITER_KEY = 'refersion.reads';

    private const MAX_READS_PER_MINUTE = 280;

    private const MAX_RATE_LIMITED_RETRIES = 3;

    public function get(string $path, array $query = []): Response
    {
        $response = $this->sendRead($path, $query);

        if ($response->status() === 401) {
            Cache::forget(self::CACHE_KEY);
            $response = $this->sendRead($path, $query);
        }

        return $response->throw();
    }

    private function sendRead(string $path, array $query): Response
    {
        $attempt = 0;

        while (true) {
            $this->waitForReadSlot();

            $response = $this->authorized()->get($path, $query);

            if (! $response->tooManyRequests() || $attempt >= self::MAX_RATE_LIMITED_RETRIES) {
                return $response;
            }

            $attempt++;

            Sleep::for(max((int) $response->header('Retry-After'), 1))->seconds();
        }
    }

    private function waitForReadSlot(): void
    {
        while (RateLimiter::tooManyAttempts(self::READ_RATE_LIMITER_KEY, self::MAX_READS_PER_MINUTE)) {
            Sleep::for(max(RateLimiter::availableIn(self::READ_RATE_LIMITER_KEY), 1))->seconds();
        }

        RateLimiter::hit(self::READ_RATE_LIMITER_KEY, 60);
    }

    private function authorized(): PendingRequest
    {
        return Http::baseUrl(config('services.refersion.base_url'))
            ->withToken($this->accessToken())
            ->acceptJson();
    }

    private function accessToken(): string
    {
        if ($token = Cache::get(self::CACHE_KEY)) {
            return $token;
        }

        $response = Http::asForm()->post(config('services.refersion.oauth_token_url'), [
            'client_id' => config('services.refersion.client_id'),
            'client_secret' => config('services.refersion.client_secret'),
        ]);

        $token = $response->json('access_token');

        if ($response->failed() || ! is_string($token) || $token === '') {
            throw new RuntimeException('Failed to retrieve access token from Refersion API.');
        }

        $expiresIn = (int) $response->json('expires_in', 3600);
        Cache::put(self::CACHE_KEY, $token, now()->addSeconds(max($expiresIn - 60, 60)));

        return $token;
    }
}
