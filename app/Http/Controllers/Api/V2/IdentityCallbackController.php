<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Services\ConsumerIdentityFailure;
use App\Services\ConsumerIdentityService;
use App\Services\ConsumerVerificationGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IdentityCallbackController extends Controller
{
    public function __invoke(Request $request, string $source, ConsumerVerificationGateway $gateway, ConsumerIdentityService $service): JsonResponse
    {
        if (! hash_equals($gateway->source(), $source)) {
            throw new ConsumerIdentityFailure('verification_provider_unavailable', 503);
        }
        $decision = $gateway->verifyCallback($request->getContent(), $request->header('X-Callback-Timestamp', ''), $request->header('X-Callback-Signature', ''));
        $receipt = $service->applyProviderDecision($decision);

        return response()->json(['reference' => $receipt->reference, 'status' => 'accepted'], 202);
    }
}
