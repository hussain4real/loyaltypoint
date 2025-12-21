<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\PointBalanceResource;
use App\Http\Resources\Api\V1\PointTransactionResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PointController extends Controller
{
    /**
     * Get the authenticated customer's point balance.
     */
    public function balance(Request $request): PointBalanceResource
    {
        return new PointBalanceResource($request->user());
    }

    /**
     * Get the authenticated customer's transaction history.
     */
    public function transactions(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();

        $query = $user->pointTransactions()
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
