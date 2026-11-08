@php
    $u = auth()->user();
    $full = $u && (! $u->isAdmin() || ($u->mfa_enabled && session('mfa_ok')));
    $loc = app()->getLocale();
    $icons = [
        'orders' => '<path d="M6 4h12l1 4H5zM5 8v11a1 1 0 001 1h12a1 1 0 001-1V8M9 12h6"/>',
        'payments' => '<rect x="3" y="6" width="18" height="12" rx="2"/><path d="M3 10h18M7 15h3"/>',
        'products' => '<path d="M21 8l-9-5-9 5 9 5zM3 8v8l9 5 9-5V8M12 13v8"/>',
        'bulk' => '<path d="M4 6h16M4 12h16M4 18h10M17 17l3 3 3-3M20 14v6" transform="translate(-2 -1)"/>',
        'categories' => '<path d="M4 4h7v7H4zM13 4h7v7h-7zM4 13h7v7H4zM13 13h7v7h-7z"/>',
        'outlets' => '<path d="M3 10l2-6h14l2 6M3 10v10h18V10M3 10c0 2 3 2 3 0 0 2 3 2 3 0 0 2 3 2 3 0 0 2 3 2 3 0 0 2 3 2 3 0M9 20v-6h6v6"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19 12a7 7 0 00-.1-1.2l2-1.5-2-3.4-2.3 1a7 7 0 00-2-1.2L14.2 3h-4l-.4 2.7a7 7 0 00-2 1.2l-2.3-1-2 3.4 2 1.5a7 7 0 000 2.4l-2 1.5 2 3.4 2.3-1a7 7 0 002 1.2l.4 2.7h4l.4-2.7a7 7 0 002-1.2l2.3 1 2-3.4-2-1.5c.1-.4.1-.8.1-1.2z"/>',
        'pages' => '<path d="M6 3h9l4 4v14H6zM14 3v5h5M9 13h7M9 17h7"/>',
        'staff' => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c0-3.5 3-6 6.5-6s6.5 2.5 6.5 6M16 5a3.5 3.5 0 010 7M18 14c2 .7 3.5 2.6 3.5 5"/>',
        'audit' => '<path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6zM9 12l2 2 4-4"/>',
    ];
    $groups = [
        [['admin.orders', 'orders'], ['admin.payments', 'payments'], ['admin.products', 'products']],
        [['admin.bulk', 'bulk', true], ['admin.categories', 'categories', true], ['admin.outlets', 'outlets', true], ['admin.settings', 'settings', true], ['admin.pages', 'pages', true]],
        [['admin.staff', 'staff', true], ['admin.audit', 'audit', true]],
    ];
@endphp
<!doctype html>
<html lang="{{ $loc }}">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ __('admin.title') }}</title>
<meta name="robots" content="noindex">
<link rel="icon" href="/favicon.ico">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&family=Tiro+Bangla&display=swap">
<style>
:root{--navy:#0C1D35;--navy2:#26354B;--teal:#038D91;--teal-deep:#066B75;--teal-soft:#DCEFF0;--bg:#EEF1F6;--card:#fff;--ink:#0C1D35;--muted:#6E7989;--line:#DDE2EA;--ok:#0b7a55;--okbg:#DFF3EA;--warn:#8a5a00;--warnbg:#FFF1D2;--bad:#B3261E;--badbg:#FCE9E7;--r:14px;--shadow:0 1px 2px rgba(12,29,53,.06),0 8px 24px -14px rgba(12,29,53,.28)}
*{box-sizing:border-box}
[x-cloak]{display:none!important}
html{-webkit-text-size-adjust:100%}
body{margin:0;font-family:Montserrat,"Tiro Bangla",system-ui,sans-serif;background:var(--bg);color:var(--ink);line-height:1.6;font-size:15px}
a{color:var(--teal-deep)}
h2{font-size:1.45rem;margin:0 0 1rem;letter-spacing:-.01em}h3{font-size:1.08rem;margin:.2rem 0 .8rem}h4{margin:1.1rem 0 .5rem}
code{background:#EEF1F6;padding:.05rem .4rem;border-radius:6px;font-size:.9em}
.app{display:flex;min-height:100vh}
.side{width:248px;flex:none;background:var(--navy);color:#fff;display:flex;flex-direction:column;position:sticky;top:0;height:100vh;overflow-y:auto}
.brand{display:flex;align-items:center;gap:.7rem;padding:1.1rem 1.2rem 1rem}
.brand img{height:40px;width:auto;background:#fff;border-radius:9px;padding:3px}
.brand b{font-size:.95rem;letter-spacing:.04em}.brand small{display:block;color:#A9B0BB;font-weight:500;font-size:.72rem;letter-spacing:.02em}
.nav{padding:.3rem .8rem 1rem;display:grid;gap:.15rem}
.nav hr{border:0;border-top:1px solid rgba(255,255,255,.1);margin:.55rem .3rem}
.nav a{display:flex;align-items:center;gap:.75rem;padding:.62rem .8rem;border-radius:11px;color:#C9D0DA;text-decoration:none;font-weight:600;font-size:.92rem;position:relative}
.nav a svg{width:20px;height:20px;stroke:currentColor;fill:none;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round;flex:none}
.nav a:hover{background:rgba(255,255,255,.07);color:#fff}
.nav a.on{background:var(--navy2);color:#fff}
.nav a.on::before{content:"";position:absolute;left:-.8rem;top:.55rem;bottom:.55rem;width:4px;border-radius:0 4px 4px 0;background:var(--teal)}
.petra{display:flex;align-items:center;gap:.6rem}.petra img{width:30px;height:30px;border-radius:8px}.petra span{display:grid;line-height:1.25;font-size:.75rem}.petra b{font-size:.68rem;letter-spacing:.14em;text-transform:uppercase;color:#4cc3c6}
.side .foot{margin-top:auto;padding:1rem 1.2rem;color:#8f99a8;font-size:.75rem}
.main{flex:1;min-width:0;display:flex;flex-direction:column}
.top{display:flex;align-items:center;gap:.8rem;padding:.7rem 1.4rem;background:var(--card);border-bottom:1px solid var(--line);position:sticky;top:0;z-index:20}
.top .sp{flex:1}
.burger{display:none;border:1px solid var(--line);background:#fff;border-radius:10px;width:42px;height:42px;cursor:pointer}
.burger svg{width:22px;height:22px;stroke:var(--navy);fill:none;stroke-width:2;stroke-linecap:round}
.seg{display:inline-flex;background:#EEF1F6;border-radius:999px;padding:3px}
.seg button{border:0;background:transparent;font:inherit;font-weight:700;font-size:.8rem;color:var(--muted);padding:.28rem .75rem;border-radius:999px;cursor:pointer}
.seg button.on{background:var(--navy);color:#fff}
.user{display:flex;align-items:center;gap:.6rem;text-decoration:none;color:var(--ink)}
.avatar{width:38px;height:38px;border-radius:50%;background:var(--teal);color:#fff;display:grid;place-items:center;font-weight:700}
.user small{display:block;color:var(--muted);font-size:.72rem;line-height:1.2}.user b{display:block;font-size:.88rem;line-height:1.25}
.content{padding:1.5rem 1.4rem 3rem;width:100%;max-width:1240px;margin:0 auto}
.card{background:var(--card);border:1px solid var(--line);border-radius:var(--r);padding:1.15rem 1.25rem;margin-bottom:1rem;box-shadow:var(--shadow)}
.card>h3:first-child{margin-top:0}
table{width:100%;border-collapse:collapse}
th{font-size:.74rem;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);text-align:left;padding:.55rem .6rem;border-bottom:2px solid var(--line);white-space:nowrap}
td{padding:.7rem .6rem;border-bottom:1px solid #EDF0F4;vertical-align:top}
tbody tr:last-child td{border-bottom:0}
tbody tr:hover td{background:#F7F9FB}
td a{font-weight:600}
label{display:block;font-weight:600;font-size:.83rem;color:#3b4a60;margin-bottom:.1rem}
input,select,textarea{font:inherit;width:100%;padding:.58rem .75rem;border:1.5px solid var(--line);border-radius:10px;background:#fff;color:var(--ink);transition:border-color .15s,box-shadow .15s;margin-top:.25rem}
input:focus,select:focus,textarea:focus{outline:0;border-color:var(--teal);box-shadow:0 0 0 3px rgba(3,141,145,.18)}
input[type=checkbox]{width:auto;margin:0 .35rem 0 0;accent-color:var(--teal)}
input[type=file]{padding:.45rem}
fieldset[disabled] input,fieldset[disabled] select,fieldset[disabled] textarea,input:disabled,select:disabled{background:#F4F6F9;color:var(--muted)}
.btn{font:inherit;font-weight:700;font-size:.88rem;border:1px solid var(--teal-deep);background:var(--teal);color:#fff;border-radius:10px;padding:.5rem 1.05rem;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:.4rem;transition:transform .12s,filter .15s,box-shadow .15s;box-shadow:0 2px 0 var(--teal-deep)}
.btn:hover{filter:brightness(1.07)}.btn:active{transform:translateY(1px);box-shadow:none}
.btn.alt{background:#fff;color:var(--ink);border-color:var(--line);box-shadow:0 1px 0 var(--line)}
.btn.alt:hover{background:#F4F6F9;filter:none}
.btn.bad{background:var(--bad);border-color:#8d1d17;box-shadow:0 2px 0 #8d1d17}
.btn:disabled{opacity:.5;cursor:not-allowed}
.pill{display:inline-block;padding:.12rem .7rem;border-radius:999px;background:#E3E8F0;color:#33435a;font-size:.78rem;font-weight:700;white-space:nowrap}
.pill.ok{background:var(--okbg);color:var(--ok)}.pill.warn{background:var(--warnbg);color:var(--warn)}.pill.bad{background:var(--badbg);color:var(--bad)}
.note{padding:.7rem 1rem;border-radius:12px;margin-bottom:.9rem;background:#E3F1F8;color:#0f4c6b;border:1px solid #cfe5f1}
.note.warn{background:var(--warnbg);color:var(--warn);border-color:#f0dca8}.note.bad{background:var(--badbg);color:var(--bad);border-color:#f3c8c4}.note.ok{background:var(--okbg);color:var(--ok);border-color:#bde3d2}
.err{color:var(--bad);font-size:.82rem}
.row{display:flex;gap:.6rem;flex-wrap:wrap;align-items:center}.grow{flex:1}.muted{color:var(--muted)}
.grid2{display:grid;gap:.85rem;grid-template-columns:repeat(auto-fit,minmax(230px,1fr))}
.nav .cnt{margin-left:auto;background:#e0245e;color:#fff;border-radius:99px;font-size:.72rem;font-weight:700;padding:1px 8px}
.stats{display:grid;gap:.9rem;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));margin-bottom:1.1rem}
.stat{background:var(--card);border:1px solid var(--line);border-radius:var(--r);padding:.95rem 1.1rem;box-shadow:var(--shadow);border-left:5px solid var(--teal)}
.stat .l{color:var(--muted);font-size:.78rem;font-weight:600}.stat .n{font-size:1.9rem;font-weight:700;line-height:1.2;color:var(--navy)}
.stat.hot{border-left-color:#E0A100}.stat.hot .n{color:var(--warn)}
.pager{display:flex;align-items:center;gap:.8rem;justify-content:center;padding:.9rem 0 .2rem;color:var(--muted);font-size:.85rem}
.empty{text-align:center;color:var(--muted);padding:2.2rem 1rem}
.auth{min-height:100vh;display:grid;place-items:center;padding:1.5rem;background:radial-gradient(60rem 30rem at 85% -10%,rgba(3,141,145,.28),transparent 60%),linear-gradient(160deg,var(--navy2),var(--navy))}
.auth .panel{width:100%;max-width:440px}
.auth .logo{display:flex;justify-content:center;margin-bottom:1.2rem}
.auth .logo img{height:64px;background:#fff;border-radius:14px;padding:6px}
.auth .card{padding:1.6rem 1.7rem}
.auth .lang{display:flex;justify-content:center;margin-top:1rem}
.auth .seg{background:rgba(255,255,255,.12)}.auth .seg button{color:#cfd6e1}.auth .seg button.on{background:#fff;color:var(--navy)}
@media (max-width:900px){
  .side{position:fixed;left:0;top:0;z-index:40;transform:translateX(-100%);transition:transform .25s;box-shadow:0 0 40px rgba(0,0,0,.4)}
  .side.open{transform:none}
  .scrim{position:fixed;inset:0;background:rgba(12,29,53,.5);z-index:30}
  .burger{display:grid;place-items:center}
  .content{padding:1rem .9rem 2.5rem}.top{padding:.6rem .9rem}.user div{display:none}
  .card{padding:1rem}
}
@media (prefers-reduced-motion:reduce){*{transition:none!important}}
</style>
@livewireStyles
</head>
<body>
@if($full)
<div class="app" x-data="{open:false}">
  <aside class="side" :class="{open:open}">
    <a class="brand" href="{{ route('admin.orders') }}" style="text-decoration:none;color:#fff"><img src="/images/logo.png" alt="Dudhwala"><span><b>{{ __('admin.title') }}</b><small>Dudhwala Food Products</small></span></a>
    <nav class="nav">
      @foreach($groups as $gi => $group)
        @php($visible = array_values(array_filter($group, fn ($i) => empty($i[2]) || $u->isAdmin())))
        @if($visible)
          @if($gi > 0)<hr>@endif
          @foreach($visible as [$route, $key])
            <a href="{{ route($route) }}" class="{{ request()->routeIs($route.'*') || ($key === 'orders' && request()->routeIs('admin.order')) || ($key === 'products' && request()->routeIs('admin.product*')) ? 'on' : '' }}"><svg viewBox="0 0 24 24" aria-hidden="true">{!! $icons[$key] !!}</svg>{{ __('admin.nav.'.$key) }}@if($key === 'payments' && ($pendingPay = \App\Models\Payment::where('status', 'PENDING_VERIFICATION')->whereHas('order', fn ($o) => $o->where('status', '!=', 'CANCELLED'))->count()))<span class="cnt">{{ $pendingPay }}</span>@endif</a>
          @endforeach
        @endif
      @endforeach
    </nav>
    <div class="foot petra"><img src="/images/petra-logo.png" alt="PETRA"><span>Developed &amp; designed by PETRA<b>Built On Trust</b></span></div>
  </aside>
  <div class="scrim" x-show="open" x-cloak @click="open=false"></div>
  <div class="main">
    <header class="top">
      <button class="burger" @click="open=!open" aria-label="Menu"><svg viewBox="0 0 24 24"><path d="M4 7h16M4 12h16M4 17h16"/></svg></button>
      <div class="sp"></div>
      <form method="post" action="{{ route('admin.locale', $loc === 'bn' ? 'en' : 'bn') }}" class="seg" aria-label="Language">@csrf
        <button type="submit" class="{{ $loc === 'bn' ? 'on' : '' }}" formaction="{{ route('admin.locale', 'bn') }}">বাংলা</button>
        <button type="submit" class="{{ $loc === 'en' ? 'on' : '' }}" formaction="{{ route('admin.locale', 'en') }}">EN</button>
      </form>
      <a class="user" href="{{ route('admin.account') }}"><span class="avatar">{{ mb_strtoupper(mb_substr($u->name, 0, 1)) }}</span><div><b>{{ $u->name }}</b><small>{{ __('admin.roles.'.$u->role) }}</small></div></a>
      <form method="post" action="{{ route('admin.logout') }}">@csrf<button class="btn alt" type="submit">{{ __('admin.logout') }}</button></form>
    </header>
    <main class="content">{{ $slot }}</main>
  </div>
</div>
@else
<div class="auth"><div class="panel">
  <div class="logo"><img src="/images/logo.png" alt="Dudhwala"></div>
  {{ $slot }}
  <form method="post" class="lang">@csrf
    <span class="seg"><button type="submit" formaction="{{ route('admin.locale', 'bn') }}" class="{{ $loc === 'bn' ? 'on' : '' }}">বাংলা</button><button type="submit" formaction="{{ route('admin.locale', 'en') }}" class="{{ $loc === 'en' ? 'on' : '' }}">EN</button></span>
  </form>
  <div class="petra" style="justify-content:center;margin-top:1rem"><img src="/images/petra-logo.png" alt="PETRA"><span>Developed &amp; designed by PETRA<b>Built On Trust</b></span></div>
  @auth<form method="post" action="{{ route('admin.logout') }}" style="text-align:center;margin-top:.8rem">@csrf<button class="btn alt" type="submit">{{ __('admin.logout') }}</button></form>@endauth
</div></div>
@endif
@livewireScripts
</body></html>
