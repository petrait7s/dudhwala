<form wire:submit="save">
  <h2>{{ __('admin.nav.settings') }}</h2>
  @if($flash)<div class="note ok">{{ $flash }}</div>@endif @if($error)<div class="note bad">{{ $error }}</div>@endif
  <div class="card"><h3>{{ __('admin.set_ordering_hours') }}</h3>
    <div class="grid2"><label>{{ __('admin.open_at') }}<input type="text" inputmode="numeric" placeholder="10:00" wire:model="f.open"></label><label>{{ __('admin.close_at') }}<input type="text" inputmode="numeric" placeholder="22:00" wire:model="f.close"></label></div></div>
  <div class="card"><h3>{{ __('admin.bulk_rule') }} <span class="pill {{ $bulkApproved ? 'ok' : 'warn' }}">{{ $bulkApproved ? __('admin.bulk_state_ok') : __('admin.bulk_state_unapproved') }}</span></h3>
    <div class="grid2"><label>{{ __('admin.bulk_qty') }}<input type="number" wire:model="f.bulk_qty"></label><label>{{ __('admin.bulk_amount') }}<input wire:model="f.bulk_taka" inputmode="decimal"></label></div>
    <p class="muted">{{ __('admin.bulk_note') }}</p>
    <label><input type="checkbox" style="width:auto" wire:model="f.prepaid"> {{ __('admin.bulk_prepaid') }}</label></div>
  <div class="card"><h3>{{ __('admin.fees') }}</h3>
    <div class="grid2"><label>{{ __('admin.fee_in') }}<input wire:model="f.fee_in" inputmode="decimal"></label><label>{{ __('admin.fee_out') }}<input wire:model="f.fee_out" inputmode="decimal"></label></div>
    <p class="muted">{{ __('admin.fee_note') }}</p></div>
  <div class="card"><h3>{{ __('admin.outlet') }}</h3>
    <div class="grid2">
      <label>{{ __('admin.dispatch') }}<select wire:model="f.dispatch"><option value="">{{ __('admin.none') }}</option>@foreach($outlets as $o)<option value="{{ $o->id }}">{{ $o->name_bn }}</option>@endforeach</select></label>
      <label>{{ __('admin.collection') }}<select wire:model="f.collection"><option value="">{{ __('admin.none') }}</option>@foreach($outlets as $o)<option value="{{ $o->id }}">{{ $o->name_bn }}</option>@endforeach</select></label>
    </div></div>
  <div class="card"><h3>{{ __('admin.pay_numbers') }}</h3>
    <div class="grid2"><label>{{ __('admin.bkash') }}<input wire:model="f.bkash" inputmode="tel"></label><label>{{ __('admin.nagad') }}<input wire:model="f.nagad" inputmode="tel"></label></div></div>
  <div class="card"><h3>{{ __('admin.courier_track') }}</h3>
    <div class="grid2"><label>Steadfast<input wire:model="f.cur_steadfast" placeholder="https://…/{code}"></label><label>Pathao<input wire:model="f.cur_pathao" placeholder="https://…{code}…{phone}"></label></div>
    <p class="muted">{{ __('admin.courier_track_note') }}</p></div>
  <div class="card"><label><input type="checkbox" style="width:auto" wire:model="f.maintenance"> {{ __('admin.maintenance') }}</label></div>
  <p><button class="btn" type="submit">{{ __('admin.save') }}</button></p>
</form>
