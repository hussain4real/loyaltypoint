<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\ConsumerSecurityService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireConsumerSession
{
    public function __construct(private ConsumerSecurityService $security) {}

    public function handle(Request $request, Closure $next, string $mode = 'unlocked'): Response
    {
        $request->attributes->set('consumer_session', $this->security->currentSession($request, $mode === 'allow_locked'));
        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
