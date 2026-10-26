@extends('backoffice.layout')
@section('title', 'Identity review queue')
@section('content')
<p>Provider fixtures do not establish live identity verification. Review unresolved checks and protected evidence before a decision.</p>
<form method="get"><label>Enrollment state <select name="state">@foreach(['all', 'not_started', 'pending', 'under_review', 'verified', 'rejected', 'reverification_required'] as $filter)<option value="{{ $filter }}" @selected($state === $filter)>{{ $filter }}</option>@endforeach</select></label><button>Filter owned reviews</button></form>
@forelse($records as $record)
<section><h2>Enrollment {{ $record->reference }}</h2><p>Customer {{ $record->customer_id }} · Revision {{ $record->revision }} · {{ $record->state->value }}</p>
<p><a href="{{ route('backoffice.identity.evidence', ['revision' => $record->reference]) }}">Review protected evidence references</a></p>
<form method="post" action="{{ route('backoffice.identity.review', ['revision' => $record->reference]) }}">@csrf
<input type="hidden" name="expected_revision" value="{{ $record->revision }}">
<label>Decision <select name="decision"><option value="rejected">Reject with remediation</option><option value="reverification_required">Require reverification</option><option value="verified">Approve resolved checks</option></select></label>
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
