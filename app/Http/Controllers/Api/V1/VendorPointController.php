<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\PointTransactionResource;
use App\Models\PointTransaction;
use App\Models\UserProviderBalance;
use App\Models\VendorUserLink;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class VendorPointController extends Controller
{
    /**
     * Get point balances for all accounts linked to the same vendor email.
     */
    public function balance(Request $request): JsonResponse
    {
        $user = $request->user();

        // First, find the vendor_email for this user
        $userLink = VendorUserLink::where('user_id', $user->id)->first();

        if (! $userLink) {
            return response()->json([
                'message' => 'No vendor account linked. Use the standard /points/balance endpoint with ?provider= parameter.',
            ], 400);
        }

        // Get ALL links with the same vendor_email (across all users)
        $allLinks = VendorUserLink::with(['provider', 'user'])
            ->where('vendor_email', $userLink->vendor_email)
            ->get();

        // Build balances for all linked providers across all users
        $balances = $allLinks->map(function ($link) {
            $balanceRecord = UserProviderBalance::where('user_id', $link->user_id)
                ->where('provider_id', $link->provider_id)
                ->first();

            return [
                'user' => [
                    'id' => $link->user->id,
                    'name' => $link->user->name,
                    'email' => $link->user->email,
                ],
                'provider' => [
                    'id' => $link->provider->id,
                    'name' => $link->provider->name,
                    'trade_name' => $link->provider->trade_name,
                    'slug' => $link->provider->slug,
                    'category' => $link->provider->category,
                    'description' => $link->provider->description,
                    'official_logo' => $link->provider->official_logo,
                    'web_link' => $link->provider->web_link,
                    'points_to_value_ratio' => (float) $link->provider->points_to_value_ratio,
                    'transfer_fee_percent' => (float) $link->provider->transfer_fee_percent,
                ],
                'points_balance' => $balanceRecord?->balance ?? 0,
            ];
        });

        return response()->json([
            'data' => $balances,
        ]);
    }

    /**
     * Get transaction history for all accounts linked to the same vendor email.
     */
    public function transactions(Request $request): JsonResponse|AnonymousResourceCollection
    {
        $user = $request->user();

        // First, find the vendor_email for this user
        $userLink = VendorUserLink::where('user_id', $user->id)->first();

        if (! $userLink) {
            return response()->json([
                'message' => 'No vendor account linked. Use the standard /points/transactions endpoint with ?provider= parameter.',
            ], 400);
        }

        // Get ALL links with the same vendor_email (across all users)
        $allLinks = VendorUserLink::where('vendor_email', $userLink->vendor_email)->get();

        // Build conditions for all user+provider combinations
        $query = PointTransaction::with(['provider', 'user'])
            ->where(function ($q) use ($allLinks) {
                foreach ($allLinks as $link) {
                    $q->orWhere(function ($subQ) use ($link) {
                        $subQ->where('user_id', $link->user_id)
                            ->where('provider_id', $link->provider_id);
                    });
                }
            })
            ->orderByDesc('created_at');

        // Apply date filters if provided
        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->input('from'));
        }

        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->input('to'));
        }

        $perPage = min((int) $request->input('per_page', 15), 100);

        return PointTransactionResource::collection(
            $query->paginate($perPage)
        );
    }
}
