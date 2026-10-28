<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Jobs\DeliverConsumerContactCode;
use App\Models\ConsumerChallenge;
use App\Models\ConsumerOtpDelivery;
use App\Services\ConsumerContactService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class ConsumerContactController extends Controller
{
    public function __construct(private ConsumerContactService $contacts) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'display_name' => ['required', 'string', 'max:255'], 'contact' => ['required', 'array:kind,value'],
            'contact.kind' => ['required', 'in:email'], 'contact.value' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:12', 'max:1024', 'confirmed'], 'password_confirmation' => ['required', 'string', 'max:1024'],
            'terms_version' => ['required', 'string', 'max:180'], 'privacy_notice_version' => ['required', 'string', 'max:180'],
        ]);
        $challenge = $this->contacts->register($data);
        $this->dispatchCode($challenge);

        return $this->challengeResponse($challenge);
    }

    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate(['challenge_reference' => ['required', 'uuid'], 'method' => ['required', 'in:verified_channel_otp'], 'proof' => ['required', 'string', 'regex:/\A[0-9]{6}\z/']]);
        $identity = $this->contacts->verify($data['challenge_reference'], $data['proof']);

        return response()->json(['verified' => true, 'registration_reference' => $identity->reference, 'next_action' => 'sign_in'])->header('Cache-Control', 'no-store, private');
    }

    public function resend(string $reference): JsonResponse
    {
        $challenge = $this->contacts->resend($reference);
        $this->dispatchCode($challenge);

        return $this->challengeResponse($challenge);
    }

    private function dispatchCode(ConsumerChallenge $challenge): void
    {
        $delivery = ConsumerOtpDelivery::where('challenge_id', $challenge->id)->first();
        if ($delivery !== null) {
            try {
                DeliverConsumerContactCode::dispatch($delivery->id);
            } catch (Throwable) {
                // The durable delivery remains available to consumer:recover-contact-codes.
            }
        }
    }

    private function challengeResponse(ConsumerChallenge $challenge): JsonResponse
    {
        return response()->json(['reference' => $challenge->id, 'purpose' => 'contact_verification', 'methods' => ['verified_channel_otp'],
            'expires_at' => $challenge->expires_at->toIso8601String(), 'resend_after_seconds' => (int) config('pull2spend.consumer.contact_resend_seconds', 60), 'next_action' => 'verify_contact'])->header('Cache-Control', 'no-store, private');
    }
}
