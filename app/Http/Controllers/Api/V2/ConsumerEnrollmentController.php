<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Models\ConsumerIdentity;
use App\Services\ConsumerIdentityFailure;
use App\Services\ConsumerIdentityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConsumerEnrollmentController extends Controller
{
    public function eligibility(Request $request, ConsumerIdentityService $service): JsonResponse
    {
        return response()->json($service->eligibility($request->attributes->get('consumer_session')));
    }

    public function current(Request $request, ConsumerIdentityService $service): JsonResponse
    {
        return response()->json($service->current($request->attributes->get('consumer_session')));
    }

    public function store(Request $request, ConsumerIdentityService $service): JsonResponse
    {
        return $this->submit($request, $service);
    }

    public function remediate(Request $request, string $revision, ConsumerIdentityService $service): JsonResponse
    {
        return $this->submit($request, $service, $revision);
    }

    public function upload(): never
    {
        throw new ConsumerIdentityFailure('verification_provider_unavailable', 503);
    }

    private function submit(Request $request, ConsumerIdentityService $service, ?string $revision = null): JsonResponse
    {
        $data = $request->validate(array_fill_keys(ConsumerIdentityService::EVIDENCE_FIELDS, ['required', 'string', 'max:180']));
        if (array_diff(array_keys($request->all()), ConsumerIdentityService::EVIDENCE_FIELDS) !== []) {
            throw new ConsumerIdentityFailure('invalid_identity_evidence', 422);
        }
        $session = $request->attributes->get('consumer_session');
        $record = $service->submit($session, $data, $request->header('Idempotency-Key', ''), $revision);
        $identity = ConsumerIdentity::findOrFail($record->consumer_identity_id);

        return response()->json($service->view($identity, $record), 202);
    }
}
