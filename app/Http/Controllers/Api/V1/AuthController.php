<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\LoginRequest;
use App\Http\Requests\Api\V1\RegisterRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Register a new user and issue an API token.
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = User::create([
            'name' => $request->string('name')->toString(),
            'email' => $request->string('email')->toString(),
            'password' => Hash::make($request->string('password')->toString()),
        ]);

        $token = $user->createToken(
            $request->string('device_name')->toString(),
            $this->getDefaultAbilities()
        )->plainTextToken;

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
            'token' => $token,
            'message' => 'Registration successful.',
        ], 201);
    }

    /**
     * Authenticate user and issue an API token.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->string('email')->toString())->first();

        if (! $user || ! Hash::check($request->string('password')->toString(), $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        $token = $user->createToken(
            $request->string('device_name')->toString(),
            $this->getDefaultAbilities()
        )->plainTextToken;

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'points_balance' => $user->point_balance,
                'tier' => $user->loyalty_tier,
            ],
            'token' => $token,
            'message' => 'Login successful.',
        ]);
    }

    /**
     * Revoke the current access token.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logged out successfully.',
        ]);
    }

    /**
     * Revoke all access tokens for the user.
     */
    public function logoutAll(Request $request): JsonResponse
    {
        $request->user()->tokens()->delete();

        return response()->json([
            'message' => 'All devices logged out successfully.',
        ]);
    }

    /**
     * Get the authenticated user profile.
     */
    public function user(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'points_balance' => $user->point_balance,
            'tier' => $user->loyalty_tier,
        ]);
    }

    /**
     * Get default token abilities for customers.
     *
     * @return array<int, string>
     */
    private function getDefaultAbilities(): array
    {
        return [
            'points:read',
            'transactions:read',
        ];
    }
}
