<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\RequestOtpRequest;
use App\Http\Requests\Api\V1\VerifyOtpRequest;
use App\Models\Provider;
use App\Models\User;
use App\Models\VendorUserLink;
use App\Services\OtpService;
use Illuminate\Http\JsonResponse;

class VendorAuthController extends Controller
{
    public function __construct(
        protected OtpService $otpService
    ) {}

    /**
     * Request an OTP to be sent to vendor_email (for linked accounts) or platform email (for new accounts).
     */
    public function requestOtp(RequestOtpRequest $request): JsonResponse
    {
        // Resolve user: by vendor_email (linked) or platform email (new)
        $vendorEmail = $request->validated('vendor_email');
        $platformEmail = $request->validated('email');

        if ($vendorEmail && ! $platformEmail) {
            // Look up user by vendor_email link (provider derived from link)
            $vendorLink = VendorUserLink::where('vendor_email', $vendorEmail)->first();

            if (! $vendorLink) {
                return response()->json([
                    'message' => 'No account linked to this vendor email.',
                ], 404);
            }

            $provider = $vendorLink->provider;
            $user = $vendorLink->user;
            $targetEmail = $vendorEmail;

            // Check if provider is active
            if (! $provider->is_active) {
                return response()->json([
                    'message' => 'The specified provider is not active.',
                ], 422);
            }
        } else {
            // Platform email flow - provider is required
            $provider = Provider::where('slug', $request->validated('provider'))->firstOrFail();

            // Verify provider is active
            if (! $provider->is_active) {
                return response()->json([
                    'message' => 'The specified provider is not active.',
                ], 422);
            }

            // Use platform email for new/unlinked accounts
            $user = User::where('email', $platformEmail)->first();

            if (! $user) {
                return response()->json([
                    'message' => 'No account found with this email address.',
                ], 404);
            }

            // Check if user has a linked vendor email for this provider
            $vendorLink = VendorUserLink::where('user_id', $user->id)
                ->where('provider_id', $provider->id)
                ->first();

            // For first-time linking, OTP goes to platform email
            // For linked accounts, OTP goes to vendor email
            $targetEmail = $vendorLink ? $vendorLink->vendor_email : $platformEmail;
        }

        $this->otpService->sendToEmail($user, 'vendor_auth', $targetEmail);

        return response()->json([
            'message' => 'Verification code sent to your email address.',
            'expires_in_minutes' => 10,
            'provider' => [
                'name' => $provider->name,
                'slug' => $provider->slug,
            ],
        ]);
    }

    /**
     * Verify the OTP and issue an access token.
     */
    public function verifyOtp(VerifyOtpRequest $request): JsonResponse
    {
        // Resolve user: by vendor_email (linked) or platform email (new)
        $vendorEmail = $request->validated('vendor_email');
        $platformEmail = $request->validated('email');

        if ($vendorEmail && ! $platformEmail) {
            // Look up user by vendor_email link (provider derived from link)
            $vendorLink = VendorUserLink::where('vendor_email', $vendorEmail)->first();

            if (! $vendorLink) {
                return response()->json([
                    'message' => 'No account linked to this vendor email.',
                ], 404);
            }

            $provider = $vendorLink->provider;
            $user = $vendorLink->user;

            // Verify provider is active
            if (! $provider->is_active) {
                return response()->json([
                    'message' => 'The specified provider is not active.',
                ], 422);
            }
        } else {
            // Platform email flow - provider is required
            $provider = Provider::where('slug', $request->validated('provider'))->firstOrFail();

            // Verify provider is active
            if (! $provider->is_active) {
                return response()->json([
                    'message' => 'The specified provider is not active.',
                ], 422);
            }

            // Use platform email (for new linking or existing users)
            $user = User::where('email', $platformEmail)->first();

            if (! $user) {
                return response()->json([
                    'message' => 'No account found with this email address.',
                ], 404);
            }

            // Check if user is linked to this provider (unless they're providing vendor_email for new linking)
            if (! $vendorEmail) {
                $existingLink = VendorUserLink::where('user_id', $user->id)
                    ->where('provider_id', $provider->id)
                    ->first();

                if (! $existingLink) {
                    return response()->json([
                        'message' => 'You are not linked to this provider. Please provide vendor_email to link your account.',
                        'requires_linking' => true,
                    ], 422);
                }
            }
        }

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

        $responseData = [];

        // Handle vendor email linking (only when platform email is provided with vendor_email)
        if ($vendorEmail && $platformEmail) {
            // Check if this vendor email is already linked to a different user for this provider
            $existingLink = VendorUserLink::where('vendor_email', $vendorEmail)
                ->where('provider_id', $provider->id)
                ->first();

            if ($existingLink && $existingLink->user_id !== $user->id) {
                return response()->json([
                    'message' => 'This vendor email is already linked to another account for this provider.',
                ], 422);
            }

            // Create or update the link
            VendorUserLink::updateOrCreate(
                [
                    'user_id' => $user->id,
                    'provider_id' => $provider->id,
                ],
                [
                    'vendor_email' => $vendorEmail,
                    'linked_at' => now(),
                ]
            );

            $responseData['vendor_email'] = $vendorEmail;
        } elseif ($vendorEmail) {
            // Already linked, include vendor_email in response
            $responseData['vendor_email'] = $vendorEmail;
        }

        // Create token with vendor-specific abilities
        $token = $user->createToken(
            $request->validated('device_name'),
            ['points:read', 'transactions:read']
        )->plainTextToken;

        return response()->json(array_merge([
            'message' => 'Authentication successful.',
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
            'provider' => [
                'id' => $provider->id,
                'name' => $provider->name,
                'slug' => $provider->slug,
            ],
            'points_balance' => $user->getBalanceForProvider($provider),
        ], $responseData));
    }

    /**
     * Resend the OTP to vendor_email (for linked accounts) or platform email (for new accounts).
     */
    public function resendOtp(RequestOtpRequest $request): JsonResponse
    {
        // Resolve user: by vendor_email (linked) or platform email (new)
        $vendorEmail = $request->validated('vendor_email');
        $platformEmail = $request->validated('email');

        if ($vendorEmail && ! $platformEmail) {
            // Look up user by vendor_email link (provider derived from link)
            $vendorLink = VendorUserLink::where('vendor_email', $vendorEmail)->first();

            if (! $vendorLink) {
                return response()->json([
                    'message' => 'No account linked to this vendor email.',
                ], 404);
            }

            $provider = $vendorLink->provider;
            $user = $vendorLink->user;
            $targetEmail = $vendorEmail;

            // Check if provider is active
            if (! $provider->is_active) {
                return response()->json([
                    'message' => 'The specified provider is not active.',
                ], 422);
            }
        } else {
            // Platform email flow - provider is required
            $provider = Provider::where('slug', $request->validated('provider'))->firstOrFail();

            // Verify provider is active
            if (! $provider->is_active) {
                return response()->json([
                    'message' => 'The specified provider is not active.',
                ], 422);
            }

            // Use platform email for new/unlinked accounts
            $user = User::where('email', $platformEmail)->first();

            if (! $user) {
                return response()->json([
                    'message' => 'No account found with this email address.',
                ], 404);
            }

            // Check if user has a linked vendor email for this provider
            $existingLink = VendorUserLink::where('user_id', $user->id)
                ->where('provider_id', $provider->id)
                ->first();

            // For first-time linking, OTP goes to platform email
            // For linked accounts, OTP goes to vendor email
            $targetEmail = $existingLink ? $existingLink->vendor_email : $platformEmail;
        }

        $this->otpService->sendToEmail($user, 'vendor_auth', $targetEmail);

        return response()->json([
            'message' => 'A new verification code has been sent to your email address.',
            'expires_in_minutes' => 10,
            'provider' => [
                'name' => $provider->name,
                'slug' => $provider->slug,
            ],
        ]);
    }
}
