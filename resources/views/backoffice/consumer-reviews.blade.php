@extends('backoffice.layout')
@section('title', 'Identity review queue')
@section('content')
<p>Provider fixtures do not establish live identity verification. An examiner recommends approval against the current evidence. A different Compliance checker records final approval. Review unresolved checks and protected evidence before a decision.</p>
<form method="get"><label>Enrollment state <select name="state">@foreach(['all', 'not_started', 'pending', 'under_review', 'verified', 'rejected', 'reverification_required'] as $filter)<option value="{{ $filter }}" @selected($state === $filter)>{{ $filter }}</option>@endforeach</select></label><button>Filter owned reviews</button></form>
@forelse($records as $record)
<section><h2>Enrollment {{ $record->reference }}</h2><p>Customer {{ $record->customer_id }} · Revision {{ $record->revision }} · {{ $record->state->value }}</p>
<p><a href="{{ route('backoffice.identity.evidence', ['revision' => $record->reference]) }}">Review protected evidence references</a></p>
@if($record->latestRecommendation)
<p>Latest examiner recommendation: {{ $record->latestRecommendation->reference }}. Examiner user {{ $record->latestRecommendation->actor_id }}. Evidence {{ $record->latestRecommendation->evidence_reference }}.</p>
<p>Assessed risk: {{ $record->latestRecommendation->review_context['risk_level'] }}. Document expires: {{ $record->latestRecommendation->review_context['document_expires_on'] }}. A changed observation requires a new recommendation.</p>
@else
<p>No examiner recommendation is recorded for this revision.</p>
@endif
<form method="post" action="{{ route('backoffice.identity.review', ['revision' => $record->reference]) }}">@csrf
<input type="hidden" name="expected_revision" value="{{ $record->revision }}">
<input type="hidden" name="recommendation_reference" value="{{ $record->latestRecommendation?->reference }}">
<label>Decision <select name="decision"><option value="rejected">Reject with remediation</option><option value="reverification_required">Require reverification</option><option value="recommend_verified">Examiner: recommend approval</option><option value="verified">Checker: approve recommendation</option></select></label>
<label>Risk level for examiner recommendation <select name="risk_level"><option value="standard">Standard</option><option value="elevated">Elevated</option></select></label>
<label>Document expiry from evidence, for examiner recommendation <input type="date" name="document_expires_on"></label>
<label>Reason code <input name="reason" required maxlength="100"></label>
<label>Review evidence reference <input name="evidence_reference" required maxlength="180"></label>
<fieldset><legend>Checks resolved with review evidence</legend>@foreach(['document', 'liveness', 'identity', 'sanctions', 'pep', 'adverse_media'] as $check)<label><input type="checkbox" name="resolved_checks[]" value="{{ $check }}">{{ $check }}</label>@endforeach</fieldset>
<button>Record reviewed decision</button></form>
<h3>Account restriction</h3><p>Current restriction: {{ $record->identity->restriction->value }}. Restriction remains separate from identity verification.</p>
<form method="post" action="{{ route('backoffice.identity.restrict', ['identity' => $record->identity->reference]) }}">@csrf
<label>Restriction <select name="restriction"><option value="suspended">Suspend financial initiation</option><option value="none">Clear restriction after review</option><option value="offboarded">Offboard</option></select></label>
<label>Reason code <input name="reason" required maxlength="100"></label><label>Evidence reference <input name="evidence_reference" required maxlength="180"></label><button>Record account restriction</button></form></section>
@empty<p>No identity reviews in your permitted scope.</p>@endforelse
{{ $records->links() }}
@endsection
