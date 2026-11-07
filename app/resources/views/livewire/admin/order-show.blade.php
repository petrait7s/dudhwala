@php($tk = fn ($p) => '৳'.number_format($p / 100, $p % 100 ? 2 : 0))
<div>
  <p><a href="{{ route('admin.orders') }}">← {{ __('admin.back') }}</a></p>
  <h2>{{ $o->order_number }} <span class="pill {{ $o->status === 'CANCELLED' ? 'bad' : '' }}">{{ __('admin.s.'.$o->status) }}</span></h2>

  @if(! $canManage)<div class="note">{{ __('admin.view_only') }}</div>@endif
  @if($flash)<div class="note ok">{{ $flash }}</div>@endif
  @if($error)<div class="note bad">{{ $error }}</div>@endif
  @if($priorCancels > 0)<div class="note warn">{{ __('admin.prior_cancels', ['n' => $priorCancels]) }}</div>@endif
  @if($refundFlag)<div class="note bad">{{ __('admin.refund_flag') }}</div>@endif
  @if($o->bulk_rule_snapshot && ! ($o->bulk_rule_snapshot['approved'] ?? true))<div class="note warn">{{ __('admin.bulk_unapproved') }}</div>@endif

  <div class="card">
    <table>
      <tr><th>{{ __('admin.customer') }}</th><td>{{ $o->contact_name }} · {{ $o->contact_phone }}</td></tr>
      <tr><th>{{ __('admin.method') }}</th><td>{{ __('admin.m.'.$o->method) }}@if($o->zone) · {{ __('admin.z.'.$o->zone) }}@endif</td></tr>
      @if($o->address_line1)<tr><th>{{ __('admin.address') }}</th><td>{{ $o->address_line1 }}@if($o->address_landmark) ({{ $o->address_landmark }})@endif</td></tr>@endif
      @if($o->pickup_at)<tr><th>{{ __('admin.pickup_at') }}</th><td>{{ $o->pickup_at->timezone('Asia/Dhaka')->format('d M, H:i') }}</td></tr>@endif
      <tr><th>{{ __('admin.outlet') }}</th><td>{{ $o->outlet->name_bn }}</td></tr>
    </table>
  </div>

  <div class="card">
    <h3>{{ __('admin.items') }}</h3>
    <table>
      @foreach($o->items as $i)<tr><td>{{ $i->product_name_bn }}@if($i->variant_label_bn) · {{ $i->variant_label_bn }}@endif × {{ $i->quantity }}</td><td style="text-align:right">{{ $tk($i->line_total_poisha) }}</td></tr>@endforeach
      <tr><td>{{ __('admin.subtotal') }}</td><td style="text-align:right">{{ $tk($o->subtotal_poisha) }}</td></tr>
      <tr><td>{{ __('admin.fee') }}</td><td style="text-align:right">{{ $tk($o->delivery_fee_poisha) }}</td></tr>
      <tr><th>{{ __('admin.total') }}</th><th style="text-align:right">{{ $tk($o->total_poisha) }}</th></tr>
      <tr><td>{{ __('admin.due') }}</td><td style="text-align:right"><strong>{{ $tk($due) }}</strong></td></tr>
    </table>
  </div>

  <div class="card">
    <h3>{{ __('admin.payment') }}</h3>
    @foreach($o->payments as $p)
      <div class="row" style="padding:.4rem 0;border-bottom:1px solid var(--line)">
        <div class="grow"><strong>{{ $p->method }}</strong> {{ $tk($p->amount_poisha) }}
          @if($p->trx_id) · {{ __('admin.trx') }}: <code>{{ $p->trx_id }}</code>@endif
          @if($p->sender_number) · {{ __('admin.sender') }}: {{ $p->sender_number }}@endif
          <span class="pill {{ $p->status === 'PAID' ? 'ok' : ($p->status === 'PENDING_VERIFICATION' ? 'warn' : ($p->status === 'VOID' ? 'bad' : '')) }}">{{ __('admin.p.'.$p->status) }}</span>
          @if($p->void_reason)<span class="muted">({{ $p->void_reason }})</span>@endif
        </div>
        @if($canManage && $o->status !== 'CANCELLED')
          @if($p->status === 'PENDING_VERIFICATION')<button class="btn" wire:click="verify({{ $p->id }})">{{ __('admin.verify_payment') }}</button>@endif
          @if($p->status === 'UNPAID' && $p->method === 'COD')<button class="btn" wire:click="codCollected">{{ __('admin.cod_collected') }}</button>@endif
          @if($p->status !== 'VOID')<button class="btn alt" wire:click="$set('voidingPayment', {{ $p->id }})">{{ __('admin.void_payment') }}</button>@endif
        @endif
      </div>
      @if($voidingPayment === $p->id)
        <form wire:submit="confirmVoid" class="row" style="margin-top:.5rem"><div class="grow"><input wire:model="voidReason" placeholder="{{ __('admin.void_reason') }}"></div><button class="btn bad">{{ __('admin.void_payment') }}</button></form>
      @endif
    @endforeach
  </div>

  @if($o->method === 'HOME_DELIVERY')
  <div class="card">
    <h3>{{ __('admin.courier') }}</h3>
    @if($o->courier)<p class="muted">{{ __('admin.cr.'.$o->courier) }}@if($o->courier_ref) · <code>{{ $o->courier_ref }}</code>@endif @if($o->courier_note) · {{ $o->courier_note }}@endif @if($o->dispatched_at) · {{ $o->dispatched_at->timezone('Asia/Dhaka')->format('d M, H:i') }}@endif</p>@elseif(! $canManage)<p class="muted">—</p>@endif
    @if($canManage && $live)
      <form wire:submit="saveCourier" class="grid2">
        <label>{{ __('admin.courier') }}<select wire:model="courier"><option value="">—</option>@foreach(['STEADFAST', 'PATHAO', 'OWN_RIDER', 'OTHER'] as $c)<option value="{{ $c }}">{{ __('admin.cr.'.$c) }}</option>@endforeach</select></label>
        <label>{{ __('admin.courier_ref') }}<input wire:model="courierRef"></label>
        <label>{{ __('admin.courier_note') }}<input wire:model="courierNote"></label>
        <div style="align-self:end"><button class="btn" type="submit">{{ __('admin.save') }}</button></div>
      </form>
    @endif
  </div>
  @endif

  @if($canManage && $live)
  <div class="card">
    <div class="row">
      @foreach($next as $s)<button class="btn" wire:click="advance('{{ $s }}')">{{ __('admin.move_to') }}: {{ __('admin.s.'.$s) }}</button>@endforeach
      <button class="btn alt" wire:click="$toggle('cancelling')">{{ __('admin.cancel') }}</button>
    </div>
    @if($cancelling)
      <form wire:submit="confirmCancel" class="row" style="margin-top:.7rem"><div class="grow"><input wire:model="cancelReason" placeholder="{{ __('admin.cancel_reason') }}"></div><button class="btn bad">{{ __('admin.confirm_cancel') }}</button></form>
    @endif
  </div>
  @endif

  <div class="card">
    <h3>{{ __('admin.timeline') }}</h3>
    @foreach($o->events->sortBy('id') as $e)
      <div class="row muted"><span>{{ \Illuminate\Support\Carbon::parse($e->created_at)->timezone('Asia/Dhaka')->format('d M, H:i') }}</span><span>{{ $e->from_status ? __('admin.s.'.$e->from_status).' → ' : '' }}<strong>{{ __('admin.s.'.$e->to_status) }}</strong></span><span>{{ $e->actor_role }}</span>@if($e->reason)<span>“{{ $e->reason }}”</span>@endif</div>
    @endforeach
  </div>
</div>
