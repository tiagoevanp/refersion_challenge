<?php

namespace App\Http\Controllers;

use App\Services\AffiliateLeaderboard;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class IndexPageController extends Controller
{
    public function index()
    {
        return inertia('welcome');
    }

    public function getAffiliates(AffiliateLeaderboard $affiliateLeaderboard): JsonResponse
    {
        try {
            $affiliates = $affiliateLeaderboard->top(5);
        } catch (HttpClientException|RuntimeException $exception) {
            Log::error('Error fetching affiliates: '.$exception->getMessage());

            return response()->json(['error' => 'Failed to fetch affiliates'], 500);
        }

        return response()->json($affiliates);
    }
}
