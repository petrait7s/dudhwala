<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ __('admin.title') }}</title>
<meta name="robots" content="noindex">
<style>
:root{--ink:#12173f;--muted:#484d78;--line:#d8dcf0;--brand:#2f35b6;--edge:#1a2086;--bg:#eef1ff;--ok:#14703d;--okbg:#e2f6ea;--warn:#854d00;--warnbg:#fff2cf;--bad:#b3261e;--badbg:#fdebe9}
*{box-sizing:border-box}body{margin:0;font-family:system-ui,'Tiro Bangla',sans-serif;background:var(--bg);color:var(--ink);line-height:1.6}
header{background:#1d2672;color:#fff;padding:.7rem 1rem;display:flex;gap:1rem;align-items:center;justify-content:space-between}
header a,header button{color:#fff}main{max-width:1000px;margin:0 auto;padding:1rem}
.card{background:#fff;border:1px solid var(--line);border-radius:12px;padding:1rem;margin-bottom:1rem}
table{width:100%;border-collapse:collapse}th,td{text-align:left;padding:.5rem;border-bottom:1px solid var(--line);vertical-align:top}
input,select,textarea{font:inherit;padding:.5rem .7rem;border:1px solid #b4bce0;border-radius:8px;width:100%}
.btn{font:inherit;font-weight:600;border:1px solid var(--edge);background:var(--brand);color:#fff;border-radius:8px;padding:.45rem 1rem;cursor:pointer}
.btn.alt{background:#fff;color:var(--ink);border-color:#b4bce0}.btn.bad{background:var(--bad);border-color:var(--bad)}
.pill{display:inline-block;padding:.05rem .6rem;border-radius:99px;background:#e2e6ff;font-size:.85rem}.pill.ok{background:var(--okbg);color:var(--ok)}.pill.warn{background:var(--warnbg);color:var(--warn)}.pill.bad{background:var(--badbg);color:var(--bad)}
.note{padding:.6rem .9rem;border-radius:8px;margin-bottom:.8rem;background:#ddf2fc}.note.warn{background:var(--warnbg);color:var(--warn)}.note.bad{background:var(--badbg);color:var(--bad)}.note.ok{background:var(--okbg);color:var(--ok)}
.row{display:flex;gap:.6rem;flex-wrap:wrap;align-items:center}.grow{flex:1}.muted{color:var(--muted)}
</style>
@livewireStyles
</head>
<body>
<header><strong>{{ __('admin.title') }}</strong>
@auth<div class="row"><span>{{ auth()->user()->name }} · {{ auth()->user()->role }}</span>
<form method="post" action="{{ route('admin.logout') }}">@csrf<button class="btn alt" style="color:#12173f">{{ __('admin.logout') }}</button></form></div>@endauth
</header>
<main>{{ $slot }}</main>
@livewireScripts
</body></html>
