<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\PointTransactionResource;
use App\Models\Provider;
use App\Services\VendorExchangeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VendorExchangeController extends Controller
{
    public function __construct(
        protected VendorExchangeService $exchangeService
    ) {}

    /**
     * Preview a vendor cross-account exchange.
     */
    public function preview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'vendor_email' => ['required', 'string', 'email'],
            'from_provider' => ['required', 'string', 'exists:providers,slug'],
            'to_provider' => ['required', 'string', 'exists:providers,slug', 'different:from_provider'],
            'points' => ['required', 'integer', 'min:1'],
        ]);

        $fromProvider = Provider::where('slug', $validated['from_provider'])->firstOrFail();
        $toProvider = Provider::where('slug', $validated['to_provider'])->firstOrFail();

        try {
            $preview = $this->exchangeService->preview(
                $validated['vendor_email'],
                $fromProvider,
                $toProvider,
                $validated['points']
            );

            return response()->json(['data' => $preview]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * Execute a vendor cross-account exchange.
     */
    public function exchange(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'vendor_email' => ['required', 'string', 'email'],
            'from_provider' => ['required', 'string', 'exists:providers,slug'],
            'to_provider' => ['required', 'string', 'exists:providers,slug', 'different:from_provider'],
            'points' => ['required', 'integer', 'min:1'],
        ]);

        $fromProvider = Provider::where('slug', $validated['from_provider'])->firstOrFail();
        $toProvider = Provider::where('slug', $validated['to_provider'])->firstOrFail();

        try {
            $result = $this->exchangeService->exchange(
                $validated['vendor_email'],
                $fromProvider,
                $toProvider,
                $validated['points']
            );

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
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
