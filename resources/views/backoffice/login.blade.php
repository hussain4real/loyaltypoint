@extends('backoffice.layout')
@section('title', 'Staff sign in')
@section('content')
<section><p>Use your provisioned staff account and authenticator.</p><form action="{{ route('backoffice.login') }}" method="post">@csrf
<label>Email <input name="email" type="email" autocomplete="username" value="{{ old('email') }}" required></label>
<label>Password <input name="password" type="password" autocomplete="current-password" required></label><button>Continue</button></form></section>
@endsection
