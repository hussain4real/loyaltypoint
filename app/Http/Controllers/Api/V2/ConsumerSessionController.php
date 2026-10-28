<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Models\ConsumerChallenge;
use App\Models\ConsumerDevice;
use App\Services\ConsumerSecurityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ConsumerSessionController extends Controller
{
    public function __construct(private ConsumerSecurityService $security) {}

    public function startLogin(Request $request): JsonResponse
    {
        $data = $request->validate(['contact' => ['required', 'array:kind,value'], 'contact.kind' => ['required', 'in:email'],
            'contact.value' => ['required', 'string', 'email', 'max:255'], 'device_reference' => ['nullable', 'uuid'],
            'method' => ['required', 'in:password,device_signature']]);
        $challenge = $this->security->startLogin($data['contact'], $data['device_reference'] ?? null, $data['method']);

        return response()->json($this->challengeWire($challenge), 201)->header('Cache-Control', 'no-store, private');
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['challenge_reference' => ['required', 'uuid'], 'method' => ['required', 'in:password,device_signature'], 'proof' => ['required', 'string', 'max:2048']]);

        return response()->json($this->security->authenticate($data['challenge_reference'], $data['method'], $data['proof']), 201)->header('Cache-Control', 'no-store, private');
    }

    public function show(Request $request): JsonResponse
    {
        return response()->json($this->security->sessionWire($this->security->currentSession($request, true)))->header('Cache-Control', 'no-store, private');
    }

    public function lock(Request $request): Response
    {
        $this->security->lock($this->security->currentSession($request, true));

        return response()->noContent();
    }

    public function unlock(Request $request): JsonResponse
    {
        $data = $request->validate(['password' => ['required', 'string', 'max:1024']]);
        $session = $this->security->unlockWithPassword($this->security->currentSession($request, true), $data['password']);

        return response()->json($this->security->sessionWire($session))->header('Cache-Control', 'no-store, private');
    }

    public function destroy(Request $request): Response
    {
        $this->security->logout($this->security->currentSession($request, true));

        return response()->noContent();
    }

    public function beginDeviceEnrollment(Request $request): JsonResponse
    {
        $data = $request->validate(['public_key' => ['required', 'string', 'max:1024']]);

        return response()->json($this->challengeWire($this->security->beginDeviceEnrollment($this->security->currentSession($request), $data['public_key'])), 201);
    }

    public function enrollDevice(Request $request): JsonResponse
    {
        $data = $request->validate(['challenge_reference' => ['required', 'uuid'], 'proof' => ['required', 'string', 'max:512']]);
        $device = $this->security->enrollDevice($this->security->currentSession($request), $data['challenge_reference'], $data['proof']);

        return response()->json(['reference' => $device->reference, 'assurance' => $device->assurance], 201);
    }

    public function devices(Request $request): JsonResponse
    {
        $session = $this->security->currentSession($request);
        $devices = ConsumerDevice::query()->where('consumer_identity_id', $session->consumer_identity_id)->orderBy('id')->get()
            ->map(fn (ConsumerDevice $device): array => ['reference' => $device->reference, 'assurance' => $device->assurance,
                'revoked' => $device->revoked_at !== null, 'current' => $device->id === $session->device_id]);

        return response()->json(['devices' => $devices])->header('Cache-Control', 'no-store, private');
    }

    public function revokeDevice(Request $request, string $reference): Response
    {
        $this->security->revokeDevice($this->security->currentSession($request), $reference);

        return response()->noContent();
    }

    public function startStepUp(Request $request): JsonResponse
    {
        $data = $request->validate(['action_digest' => ['required', 'regex:/\A[a-f0-9]{64}\z/'], 'method' => ['required', 'in:password,device_signature']]);

        return response()->json($this->challengeWire($this->security->startStepUp($this->security->currentSession($request), $data['action_digest'], $data['method'])), 201);
    }

    public function verifyStepUp(Request $request): JsonResponse
    {
        $data = $request->validate(['challenge_reference' => ['required', 'uuid'], 'method' => ['required', 'in:password,device_signature'], 'proof' => ['required', 'string', 'max:2048']]);
        $stepUp = $this->security->verifyStepUp($this->security->currentSession($request), $data['challenge_reference'], $data['method'], $data['proof']);

        return response()->json(['reference' => $stepUp->reference, 'expires_at' => $stepUp->expires_at->toIso8601String(), 'action_digest' => $stepUp->action_digest], 201);
    }

    /** @return array<string, mixed> */
    private function challengeWire(ConsumerChallenge $challenge): array
    {
        return ['reference' => $challenge->id, 'purpose' => $challenge->purpose, 'methods' => [$challenge->method], 'next_action' => 'complete_challenge',
            'expires_at' => $challenge->expires_at->toIso8601String(), 'payload' => ['message' => $challenge->payload['message']]];
    }
}
