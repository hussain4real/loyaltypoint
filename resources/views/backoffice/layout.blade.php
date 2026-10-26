<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>@yield('title', 'Operations') · Pull2Spend</title>
<style>body{margin:0;background:#f4f7fa;color:#172b42;font:1rem/1.6 system-ui,sans-serif}header{background:#163650;color:white;padding:1rem 5%}header a{color:white;margin-right:1rem}main{max-width:1100px;margin:2rem auto;padding:0 1rem}section{background:white;padding:1.4rem;margin:1rem 0;border:1px solid #c8d4de;border-radius:.5rem}label{display:block;margin:.7rem 0}input,select,button{font:inherit;padding:.5rem;border:1px solid #70879a;border-radius:.25rem}button{background:#164b69;color:white;cursor:pointer}input{max-width:100%}table{border-collapse:collapse;width:100%;background:white}td,th{padding:.7rem;text-align:left;border-bottom:1px solid #c8d4de}caption{text-align:left;font-weight:bold;padding:1rem}a{color:#174b71}:focus-visible{outline:3px solid #b76f00;outline-offset:3px}.errors{color:#8e1c24}.muted{color:#52677b}.scroll{overflow:auto}code{word-break:break-all}</style>
</head><body><header><strong>Pull2Spend operations</strong><nav aria-label="Operations">
@auth('web')<a href="{{ route('backoffice.dashboard') }}">Overview</a><a href="{{ route('backoffice.cases') }}">Cases</a><a href="{{ route('backoffice.audit') }}">Audit</a><form action="{{ route('backoffice.logout') }}" method="post" style="display:inline">@csrf<button>Sign out</button></form>@endauth
</nav></header><main><h1>@yield('title', 'Operations')</h1>
@if($errors->any())<section role="alert" class="errors"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></section>@endif
@if(session('status'))<p role="status">{{ session('status') }}</p>@endif
@yield('content')</main></body></html>
