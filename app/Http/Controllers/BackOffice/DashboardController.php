<?php

declare(strict_types=1);

namespace App\Http\Controllers\BackOffice;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\OperationalCase;
use App\Models\StaffElevation;
use App\Services\AuditService;
use App\Services\CaseService;
use App\Services\StaffAccessService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DashboardController extends Controller
{
    public function __construct(private StaffAccessService $access, private AuditService $audit) {}

    public function index(Request $request): View
    {
        $staff = $this->access->authorize($request->user('web'), 'dashboard.read');
        $cases = $this->access->scopeQuery(OperationalCase::query(), $request->user('web'))->orderBy('due_at')->orderBy('id')->limit(50)->get();
        $elevations = in_array('elevations.manage', $staff->role->permissions(), true)
            ? $this->access->scopeQuery(StaffElevation::query(), $request->user('web'), 'elevations.manage')->whereNull('revoked_at')->where('expires_at', '>', now())->orderBy('expires_at')->limit(50)->get()
            : collect();
        $this->audit->record($request->user('web')->id, 'staff.dashboard_viewed', 'backoffice', 'operational_review');

        return view('backoffice.dashboard', ['staff' => $staff, 'cases' => $cases, 'elevations' => $elevations]);
    }

    public function cases(Request $request): View
    {
        $cases = $this->access->scopeQuery(OperationalCase::query(), $request->user('web'))->orderBy('due_at')->orderBy('id')->paginate(30);
        $this->audit->record($request->user('web')->id, 'case.list_viewed', 'cases', 'operational_review');

        return view('backoffice.cases', ['cases' => $cases, 'canManage' => in_array('cases.manage', $this->access->authorize($request->user('web'), 'dashboard.read')->role->permissions(), true)]);
    }

    public function transition(Request $request, OperationalCase $case, CaseService $cases): RedirectResponse
    {
        $case = $this->access->scopeQuery(OperationalCase::query(), $request->user('web'))->findOrFail($case->id);
        $data = $request->validate([
            'state' => ['required', 'in:open,assigned,in_progress,awaiting_external,resolved,closed'],
            'reason' => ['required', 'regex:/\A[a-z][a-z0-9_]{0,63}\z/'],
            'evidence_reference' => ['nullable', 'string', 'max:128', 'regex:/\A[a-zA-Z0-9_:\/-]+\z/'],
            'owner_id' => ['nullable', 'integer', 'min:1'],
        ]);
        try {
            $cases->transition($request->user('web'), $case, $data['state'], $data['reason'], $data['evidence_reference'] ?? null, isset($data['owner_id']) ? (int) $data['owner_id'] : null);
        } catch (InvalidArgumentException $exception) {
            [$field, $message] = match ($exception->getMessage()) {
                'The case state transition is not permitted.' => ['state', 'This case cannot move to the selected state.'],
                'Resolution and closure require an evidence reference.' => ['evidence_reference', 'Add an evidence reference before resolving or closing this case.'],
                'A named eligible owner is required.',
                'The case owner is inactive, unpermitted or outside its scope.' => ['owner_id', 'Choose an eligible owner within this case scope.'],
                'Reassign the case before changing its workflow state.' => ['owner_id', 'Assign the new owner before changing the case state.'],
                default => ['case', 'Review the case details before trying again.'],
            };
            throw ValidationException::withMessages([$field => $message]);
        }

        return redirect()->route('backoffice.cases');
    }

    public function audits(Request $request): View
    {
        $events = $this->access->scopeQuery(AuditEvent::query(), $request->user('web'), 'audit.read')->orderByDesc('id')->paginate(30);
        $this->audit->record($request->user('web')->id, 'audit.list_viewed', 'audit', 'operational_review');

        return view('backoffice.audit', ['events' => $events]);
    }

    public function export(Request $request): StreamedResponse
    {
        $data = $request->validate(['partner_id' => ['nullable', 'integer', 'min:1'], 'customer_id' => ['nullable', 'integer', 'min:1']]);
        $partnerId = isset($data['partner_id']) ? (int) $data['partner_id'] : null;
        $customerId = isset($data['customer_id']) ? (int) $data['customer_id'] : null;
        $this->access->authorize($request->user('web'), 'audit.export', $partnerId, $customerId);
        $query = AuditEvent::query();
        if ($partnerId !== null) {
            $query->where('partner_id', $partnerId);
        }
        if ($customerId !== null) {
            $query->where('customer_id', $customerId);
        }
        $this->audit->record($request->user('web')->id, 'audit.exported', 'audit', 'operational_review', $partnerId, $customerId);

        return response()->streamDownload(function () use ($query): void {
            $stream = fopen('php://output', 'w');
            fputcsv($stream, ['Reference', 'Action', 'Reason', 'Time'], escape: '');
            foreach ($query->orderBy('id')->cursor() as $event) {
                fputcsv($stream, [(string) $event->id, $event->action, $event->reason, $event->created_at->toIso8601String()], escape: '');
            }
            fclose($stream);
        }, 'pull2spend-audit.csv', ['Content-Type' => 'text/csv', 'Cache-Control' => 'no-store, private']);
    }
}
