<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\FoundationEnvironment;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BackOfficeHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('pull2spend.backoffice_enabled', false), 404);
        if (! app()->environment('local', 'testing') && (new FoundationEnvironment)->problems() !== []) {
            abort(503, 'Staff configuration requires operational review.');
        }
        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('Content-Security-Policy', "default-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'none'; frame-ancestors 'none'; form-action 'self'; base-uri 'none'");

        return $response;
    }
}
