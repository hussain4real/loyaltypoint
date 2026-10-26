<?php

declare(strict_types=1);

namespace App\Http\Controllers\BackOffice;

use App\Http\Controllers\Controller;
use App\Models\StaffElevation;
use App\Models\StaffMembership;
use App\Services\StaffAccessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ElevationController extends Controller
{
    public function store(Request $request, StaffAccessService $access): RedirectResponse
    {
        $data = $request->validate([
            'staff_id' => ['required', 'integer', 'min:1'], 'minutes' => ['required', 'integer', 'min:1', 'max:30'],
            'reason' => ['required', 'regex:/\A[a-z][a-z0-9_]{0,63}\z/'],
            'partner_id' => ['nullable', 'integer', 'min:1'], 'customer_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $access->authorize($request->user('web'), 'elevations.manage',
            isset($data['partner_id']) ? (int) $data['partner_id'] : null, isset($data['customer_id']) ? (int) $data['customer_id'] : null);
        $access->grantElevation($request->user('web'), StaffMembership::query()->findOrFail($data['staff_id']), 'audit.export', (int) $data['minutes'], $data['reason'],
            isset($data['partner_id']) ? (int) $data['partner_id'] : null, isset($data['customer_id']) ? (int) $data['customer_id'] : null);

        return redirect()->route('backoffice.dashboard')->with('status', 'Temporary export access granted.');
    }

    public function destroy(Request $request, string $elevation, StaffAccessService $access): RedirectResponse
    {
        $elevation = $access->scopeQuery(StaffElevation::query(), $request->user('web'), 'elevations.manage')->findOrFail($elevation);
        $data = $request->validate(['reason' => ['required', 'regex:/\A[a-z][a-z0-9_]{0,63}\z/']]);
        $access->revokeElevation($request->user('web'), $elevation, $data['reason']);

        return redirect()->route('backoffice.dashboard')->with('status', 'Temporary access revoked.');
    }
}
