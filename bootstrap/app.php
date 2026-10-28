<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trimStrings(except: ['proof']);
        $middleware->redirectGuestsTo(fn (Request $request): ?string => $request->is('api/v2/*') ? null : route('login'));
        $middleware->alias([
            'abilities' => CheckAbilities::class,
            'ability' => CheckForAnyAbility::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['token', 'code', 'proof', 'password_confirmation']);
        $exceptions->shouldRenderJsonWhen(fn (Request $request, Throwable $exception): bool => $request->is('api/v2/*') || $request->expectsJson());
        $exceptions->render(function (AuthenticationException $exception, Request $request): ?JsonResponse {
            return $request->is('api/v2/*')
                ? response()->json(['error' => ['code' => 'unauthenticated', 'next_action' => 'sign_in']], 401)
                : null;
        });
        $exceptions->render(function (HttpExceptionInterface $exception, Request $request): ?JsonResponse {
            $code = match ($exception->getStatusCode()) {
                401 => 'unauthenticated', 403 => 'forbidden', 404 => 'not_found', 429 => 'rate_limited', default => null
            };

            return $request->is('api/v2/*') && $code !== null
                ? response()->json(['error' => ['code' => $code, 'next_action' => $code === 'rate_limited' ? 'retry_later' : 'review_request']], $exception->getStatusCode(), $exception->getHeaders())
                : null;
        });
    })->create();
