<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\StaffMembership;
use App\Services\StaffAccessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireStaffMfa
{
    public function __construct(private StaffAccessService $access) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('pull2spend.backoffice_enabled', false), 404);
        $user = $request->user('web');
        if ($user === null) {
            return redirect()->route('backoffice.login');
        }
        abort_unless(StaffMembership::query()->where('user_id', $user->id)->where('enabled', true)->exists(), 403);
        if (! $this->access->hasRecentMfa($user)) {
            return redirect()->route('backoffice.mfa');
        }
        $this->access->authorize($user, 'dashboard.read');
        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
