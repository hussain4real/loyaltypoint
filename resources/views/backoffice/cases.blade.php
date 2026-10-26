@extends('backoffice.layout')
@section('title', 'Operational cases')
@section('content')
<p>References are masked operational identifiers. Sensitive source payloads are excluded.</p>
@forelse($cases as $case)<section><h2>{{ $case->reference }}</h2><p>{{ $case->category }} · {{ $case->state }} · Due {{ $case->due_at->toIso8601String() }}{{ $case->isOverdue() ? ' · Overdue' : '' }}</p>
@if($canManage)<form method="post" action="{{ route('backoffice.cases.transition', $case) }}">@csrf<label>Next state <select name="state">@foreach(['assigned','in_progress','awaiting_external','resolved','closed'] as $state)<option value="{{ $state }}">{{ str_replace('_', ' ', $state) }}</option>@endforeach</select></label><label>Owner user ID (for assignment) <input name="owner_id" type="number" min="1"></label><label>Reason code <input name="reason" pattern="[a-z][a-z0-9_]*" required></label><label>Evidence reference (required to resolve/close) <input name="evidence_reference" maxlength="128"></label><button>Record transition</button></form>@endif</section>@empty<section>No cases in your scope.</section>@endforelse
{{ $cases->links() }}
@endsection
