<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ExchangePointsRequest;
use App\Http\Resources\Api\V1\PointTransactionResource;
use App\Models\Provider;
use App\Services\PointExchangeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ExchangeController extends Controller
{
    public function __construct(
        private readonly PointExchangeService $exchangeService,
    ) {}

    /**
     * Preview an exchange without executing it.
     */
    public function preview(Request $request): JsonResponse
    {
        $request->validate([
            'from_provider' => ['required', 'string', 'exists:providers,slug'],
            'to_provider' => ['required', 'string', 'exists:providers,slug', 'different:from_provider'],
            'points' => ['required', 'integer', 'min:1'],
        ]);

        $fromProvider = Provider::where('slug', $request->input('from_provider'))->firstOrFail();
        $toProvider = Provider::where('slug', $request->input('to_provider'))->firstOrFail();

        try {
            $preview = $this->exchangeService->preview(
                user: $request->user(),
                fromProvider: $fromProvider,
                toProvider: $toProvider,
                points: $request->integer('points'),
            );
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages([
                'points' => [$e->getMessage()],
            ]);
        }

        return response()->json([
            'data' => $preview,
        ]);
    }

    /**
     * Execute a point exchange between providers.
     */
    public function exchange(ExchangePointsRequest $request): JsonResponse
    {
        $fromProvider = Provider::where('slug', $request->input('from_provider'))->firstOrFail();
        $toProvider = Provider::where('slug', $request->input('to_provider'))->firstOrFail();

        try {
            $result = $this->exchangeService->exchange(
                user: $request->user(),
                fromProvider: $fromProvider,
                toProvider: $toProvider,
                points: $request->integer('points'),
            );
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages([
                'points' => [$e->getMessage()],
            ]);
        }

        return response()->json([
            'data' => [
                'points_sent' => $result['points_sent'],
                'gross_value' => $result['gross_value'],
                'total_fee_percent' => $result['total_fee_percent'],
                'total_fee_value' => $result['total_fee_value'],
                'net_value' => $result['net_value'],
                'points_received' => $result['points_received'],
                'transfer_out' => new PointTransactionResource($result['transfer_out']),
                'transfer_in' => new PointTransactionResource($result['transfer_in']),
            ],
            'message' => 'Points exchanged successfully.',
        ], 201);
    }
}
