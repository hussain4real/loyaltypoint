<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AwardPointsRequest;
use App\Http\Requests\Api\V1\DeductPointsRequest;
use App\Http\Resources\Api\V1\PointBalanceResource;
use App\Http\Resources\Api\V1\PointTransactionResource;
use App\Models\User;
use App\Services\PointService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class CustomerPointController extends Controller
{
    public function __construct(
        private readonly PointService $pointService,
    ) {}

    /**
     * Get a customer's point balance.
     */
    public function show(User $customer): PointBalanceResource
    {
        return new PointBalanceResource($customer);
    }

    /**
     * Get a customer's transaction history.
     */
    public function transactions(Request $request, User $customer): AnonymousResourceCollection
    {
        $query = $customer->pointTransactions()
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

    /**
     * Award points to a customer.
     */
    public function award(AwardPointsRequest $request, User $customer): JsonResponse
    {
        $transaction = $this->pointService->awardBonusPoints(
            user: $customer,
            points: $request->integer('points'),
            description: $request->string('description')->toString(),
            metadata: $request->input('metadata'),
        );

        return response()->json([
            'data' => new PointTransactionResource($transaction),
            'message' => 'Points awarded successfully.',
        ], 201);
    }

    /**
     * Deduct points from a customer.
     */
    public function deduct(DeductPointsRequest $request, User $customer): JsonResponse
    {
        try {
            $transaction = $this->pointService->deductPoints(
                user: $customer,
                points: $request->integer('points'),
                description: $request->string('description')->toString(),
                metadata: $request->input('metadata'),
            );
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages([
                'points' => [$e->getMessage()],
            ]);
        }

        return response()->json([
            'data' => new PointTransactionResource($transaction),
            'message' => 'Points deducted successfully.',
        ], 201);
    }
}
