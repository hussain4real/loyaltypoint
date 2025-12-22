<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\PointBalanceResource;
use App\Http\Resources\Api\V1\PointTransactionResource;
use App\Models\Provider;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PointController extends Controller
{
    /**
     * Get the authenticated customer's point balance.
     * If provider slug is specified, returns balance for that provider only.
     * Otherwise, returns balances for all providers.
     */
    public function balance(Request $request): PointBalanceResource
    {
        $user = $request->user();

        if ($request->filled('provider')) {
            $provider = Provider::where('slug', $request->input('provider'))->firstOrFail();

            return (new PointBalanceResource($user))->forProvider($provider);
        }

        return new PointBalanceResource($user);
    }

    /**
     * Get the authenticated customer's transaction history.
     * Can be filtered by provider using ?provider=slug.
     */
    public function transactions(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();

        $query = $user->pointTransactions()
            ->with('provider')
            ->orderByDesc('created_at');

        // Filter by provider if specified
        if ($request->filled('provider')) {
            $provider = Provider::where('slug', $request->input('provider'))->firstOrFail();
            $query->where('provider_id', $provider->id);
        }

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
