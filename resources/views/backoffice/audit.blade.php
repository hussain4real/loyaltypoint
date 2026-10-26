@extends('backoffice.layout')
@section('title', 'Audit history')
@section('content')
<p>Append-only records show coded actions and reasons. Payloads, credentials and customer identity data are excluded.</p>
<div class="scroll"><table><thead><tr><th>Reference</th><th>Action</th><th>Reason</th><th>Time</th></tr></thead><tbody>@forelse($events as $event)<tr><td>{{ $event->reference }}</td><td>{{ $event->action }}</td><td>{{ $event->reason }}</td><td>{{ $event->created_at->toIso8601String() }}</td></tr>@empty<tr><td colspan="4">No audit records in your scope.</td></tr>@endforelse</tbody></table></div>{{ $events->links() }}
<section><h2>Export masked audit records</h2><p>Requires active export access independently granted for this scope.</p><form action="{{ route('backoffice.audit.export') }}" method="get"><label>Partner ID <input name="partner_id" type="number" min="1"></label><label>Customer ID <input name="customer_id" type="number" min="1"></label><button>Download CSV</button></form></section>
@endsection
