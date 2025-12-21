<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\RequestOtpRequest;
use App\Http\Requests\Api\V1\VerifyOtpRequest;
use App\Models\User;
use App\Services\OtpService;
use Illuminate\Http\JsonResponse;

class VendorAuthController extends Controller
{
    public function __construct(
        protected OtpService $otpService
    ) {}

    /**
     * Request an OTP to be sent to the user's email.
     */
    public function requestOtp(RequestOtpRequest $request): JsonResponse
    {
        $user = User::where('email', $request->validated('email'))->first();

        $this->otpService->sendToEmail($user, 'vendor_auth');

        return response()->json([
            'message' => 'Verification code sent to your email address.',
            'expires_in_minutes' => 10,
        ]);
    }

    /**
     * Verify the OTP and issue an access token.
     */
    public function verifyOtp(VerifyOtpRequest $request): JsonResponse
    {
        $user = User::where('email', $request->validated('email'))->first();

        $result = $this->otpService->verify(
            $user,
            $request->validated('code'),
            'vendor_auth'
        );

        if (! $result['success']) {
            return response()->json([
                'message' => $result['message'],
            ], 422);
        }

        // Create token with vendor-specific abilities
        $token = $user->createToken(
            $request->validated('device_name'),
            ['points:read', 'transactions:read']
        )->plainTextToken;

        return response()->json([
            'message' => 'Authentication successful.',
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'point_balance' => $user->point_balance,
                'loyalty_tier' => $user->loyalty_tier,
            ],
        ]);
    }

    /**
     * Resend the OTP to the user's email.
     */
    public function resendOtp(RequestOtpRequest $request): JsonResponse
    {
        $user = User::where('email', $request->validated('email'))->first();

        $this->otpService->sendToEmail($user, 'vendor_auth');

        return response()->json([
            'message' => 'A new verification code has been sent to your email address.',
            'expires_in_minutes' => 10,
        ]);
    }
}
