<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\ConsumerIdentity;
use App\Models\User;
use App\Services\ConsumerIdentityFailure;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class RejectLegacyConsumerAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('sanctum');
        if ($user instanceof User) {
            $token = $user->currentAccessToken();
            $consumerToken = $token instanceof PersonalAccessToken
                && in_array('consumer:identity', (array) $token->abilities, true);

            if ($consumerToken || ConsumerIdentity::query()->where('user_id', $user->id)->exists()) {
                throw new ConsumerIdentityFailure('consumer_session_required', 403, 'use_v2_session');
            }
        }

        return $next($request);
    }
}
