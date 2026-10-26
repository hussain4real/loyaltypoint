@extends('backoffice.layout')
@section('title', 'Verify authenticator')
@section('content')
@if(!$enrolled && $secret === null)
<section><h2>Set up your authenticator</h2><p>Enter the one-time invitation token delivered by your administrator.</p><form action="{{ route('backoffice.mfa.enroll') }}" method="post">@csrf<label>Invitation token <input name="token" type="password" autocomplete="off" required></label><button>Start setup</button></form></section>
@endif
@if($secret !== null)<section><h2>Add an authenticator account</h2><p>In your authenticator, add Pull2Spend with the setup key below. Use time based codes, six digits and a 30 second interval. This key is shown once; keep it private.</p><code>{{ $secret }}</code></section>@endif
<section><form action="{{ route('backoffice.mfa.verify') }}" method="post">@csrf<label>Six digit code <input name="code" inputmode="numeric" pattern="[0-9]{6}" autocomplete="one-time-code" required></label><button>Verify</button></form></section>
@endsection
