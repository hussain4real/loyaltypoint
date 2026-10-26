<?php

declare(strict_types=1);

namespace App\Http\Controllers\BackOffice;

use App\Enums\AccountRestriction;
use App\Enums\KycState;
use App\Http\Controllers\Controller;
use App\Models\ConsumerVerificationRevision;
use App\Services\AuditService;
use App\Services\ConsumerIdentityFailure;
use App\Services\ConsumerIdentityService;
use App\Services\StaffAccessService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ConsumerReviewController extends Controller
{
    public function index(Request $request, StaffAccessService $access): View
    {
        $state = $request->query('state', 'under_review');
        abort_unless(in_array($state, ['all', ...array_column(KycState::cases(), 'value')], true), 422);
        $query = $access->scopeQuery(ConsumerVerificationRevision::query(), $request->user('web'), 'kyc.review')
            ->with('identity')->whereHas('identity', fn (Builder $identity): Builder => $identity->whereColumn('consumer_identities.verification_revision', 'consumer_verification_revisions.revision'));
        if ($state !== 'all') {
            $query->where('state', $state);
        }
        $records = $query->orderBy('id')->paginate(25)->withQueryString();

        return view('backoffice.consumer-reviews', ['records' => $records, 'state' => $state]);
    }

    public function review(Request $request, string $revision, ConsumerIdentityService $service): RedirectResponse
    {
        $data = $request->validate(['expected_revision' => ['required', 'integer', 'min:1'],
            'decision' => ['required', Rule::in(['verified', 'rejected', 'reverification_required'])],
            'reason' => ['required', 'string', 'regex:/\A[a-z][a-z0-9_.-]{0,99}\z/'],
            'evidence_reference' => ['required', 'string', 'regex:/\A[A-Za-z0-9][A-Za-z0-9_.:\/-]{0,179}\z/'],
            'resolved_checks' => ['sometimes', 'array'], 'resolved_checks.*' => ['string']]);
        try {
            $service->review($request->user('web'), $revision, (int) $data['expected_revision'], KycState::from($data['decision']), $data['reason'], $data['evidence_reference'], $data['resolved_checks'] ?? []);
        } catch (ConsumerIdentityFailure $exception) {
            return back()->withErrors(['review' => $exception->errorCode]);
        }

        return back()->with('status', 'Identity review recorded.');
    }

    public function evidence(Request $request, string $revision, StaffAccessService $access, AuditService $audit): JsonResponse
    {
        $record = $access->scopeQuery(ConsumerVerificationRevision::query(), $request->user('web'), 'kyc.evidence.read')
            ->where('reference', $revision)->firstOrFail();
        $access->authorize($request->user('web'), 'kyc.evidence.read', customerId: $record->customer_id);
        $audit->record($request->user('web')->id, 'identity.evidence_viewed', $record->reference, 'compliance_review', customerId: $record->customer_id);

        return response()->json(['reference' => $record->reference, 'checks' => $record->checks, 'unresolved_checks' => $record->unresolved_checks,
            'evidence_references' => $record->evidence, 'viewer_available' => false,
            'notice' => 'Protected provider viewer requires a selected identity service.'], headers: ['Cache-Control' => 'no-store, private']);
    }

    public function restrict(Request $request, string $identity, ConsumerIdentityService $service): RedirectResponse
    {
        $data = $request->validate(['restriction' => ['required', Rule::enum(AccountRestriction::class)],
            'reason' => ['required', 'string', 'regex:/\A[a-z][a-z0-9_.-]{0,99}\z/'],
            'evidence_reference' => ['required', 'string', 'regex:/\A[A-Za-z0-9][A-Za-z0-9_.:\/-]{0,179}\z/']]);
        $service->restrict($request->user('web'), $identity, AccountRestriction::from($data['restriction']), $data['reason'], $data['evidence_reference']);

        return back()->with('status', 'Account restriction recorded.');
    }
}
