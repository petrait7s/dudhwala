@php($tk = fn ($p) => '৳'.number_format($p / 100, $p % 100 ? 2 : 0))
<div>
  <h2>{{ __('admin.pay_queue') }}</h2>
  <div class="note">{{ __('admin.pay_howto') }}</div>
  @if(! $canManage)<div class="note">{{ __('admin.view_only') }}</div>@endif
  @if($flash)<div class="note ok">{{ $flash }}</div>@endif
  @if($error)<div class="note bad">{{ $error }}</div>@endif

  @if($rows->isEmpty())
    <div class="card"><p class="empty">{{ __('admin.pay_none') }}</p></div>
  @endif

  @foreach($rows as $p)
    @php($o = $p->order)
    @php($same = \App\Services\Phone::normalize($p->sender_number) === $o->contact_phone)
    <div class="card">
      <div class="row" style="justify-content:space-between;align-items:flex-start">
        <div>
          <span class="pill warn">{{ $p->method }}</span>
          <a href="{{ route('admin.order', $o) }}"><strong>{{ $o->order_number }}</strong></a>
          <span class="muted">· {{ $o->created_at->timezone('Asia/Dhaka')->format('d M, H:i') }}</span>
        </div>
        <div style="font-size:1.5rem;font-weight:700;color:var(--navy)">{{ $tk($p->amount_poisha) }}</div>
      </div>
      <table style="margin:.6rem 0">
        <tr><th style="width:9rem">{{ __('admin.trx') }}</th><td><code style="font-size:1.05rem">{{ $p->trx_id }}</code></td></tr>
        <tr><th>{{ __('admin.sender') }}</th><td><code style="font-size:1.05rem">{{ $p->sender_number }}</code>
          <span class="pill {{ $same ? 'ok' : 'warn' }}">{{ $same ? __('admin.pay_same_phone') : __('admin.pay_other_phone') }}</span></td></tr>
        <tr><th>{{ __('admin.customer') }}</th><td>{{ $o->contact_name }} · {{ $o->contact_phone }}</td></tr>
      </table>
      <p class="muted" style="margin:0 0 .6rem">{{ __('admin.pay_check', ['amount' => $tk($p->amount_poisha)]) }}</p>
      @if($canManage)
        <div class="row">
          <button class="btn" wire:click="verify({{ $p->id }})" wire:loading.attr="disabled">✓ {{ __('admin.pay_match') }}</button>
          <button class="btn alt" wire:click="$set('rejecting', {{ $p->id }})">✗ {{ __('admin.pay_nomatch') }}</button>
        </div>
        @if($rejecting === $p->id)
          <form wire:submit="reject" class="row" style="margin-top:.6rem">
            <div class="grow"><input wire:model="reason" list="reasons" placeholder="{{ __('admin.void_reason') }}"></div>
            <button class="btn bad">{{ __('admin.void_payment') }}</button>
          </form>
        @endif
      @endif
    </div>
  @endforeach
  <datalist id="reasons">@foreach(__('admin.pay_reasons') as $r)<option value="{{ $r }}">@endforeach</datalist>
</div>
