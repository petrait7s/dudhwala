<div>
  <div class="row" style="justify-content:space-between"><h2>{{ __('admin.nav.outlets') }}</h2><button class="btn alt" wire:click="newOutlet">{{ __('admin.new') }}</button></div>
  @if($flash)<div class="note ok">{{ $flash }}</div>@endif @if($error)<div class="note bad">{{ $error }}</div>@endif
  <div class="card">
    @foreach($outlets as $o)<div class="row" style="padding:.3rem 0"><strong class="grow">{{ $o->name_bn }} <span class="muted">({{ $o->code }})</span></strong><span class="pill {{ $o->status === 'ACTIVE' ? 'ok' : 'warn' }}">{{ __('admin.st.'.$o->status) }}</span><button class="btn alt" wire:click="edit({{ $o->id }})">{{ __('admin.edit') }}</button></div>@endforeach
  </div>
  <form class="card" wire:submit="save">
    <h3>{{ $editing ? __('admin.edit') : __('admin.new') }}</h3>
    <div class="grid2">
      <label>{{ __('admin.code') }}<input wire:model="form.code" style="text-transform:uppercase"></label>
      <label>{{ __('admin.name_bn') }}<input wire:model="form.name_bn"></label>
      <label>{{ __('admin.name_en') }}<input wire:model="form.name_en"></label>
      <label>{{ __('admin.address_line') }}<input wire:model="form.address_line"></label>
      <label>{{ __('admin.phone') }}<input wire:model="form.phone"></label>
      <label>{{ __('admin.status_label') }}<select wire:model="form.status">@foreach(['ACTIVE', 'PAUSED', 'CLOSED'] as $s)<option value="{{ $s }}">{{ __('admin.st.'.$s) }}</option>@endforeach</select></label>
    </div>
    <h4>{{ __('admin.hours') }}</h4>
    @foreach(__('admin.days') as $d => $label)
      <div class="row" style="margin:.2rem 0"><label style="min-width:130px"><input type="checkbox" style="width:auto" wire:model="hours.{{ $d }}.open"> {{ $label }}</label>
        @if($hours[$d]['open'])<input type="time" style="width:130px" wire:model="hours.{{ $d }}.opens"> – <input type="time" style="width:130px" wire:model="hours.{{ $d }}.closes">@else<span class="muted">{{ __('admin.closed') }}</span>@endif</div>
    @endforeach
    <p><button class="btn" type="submit">{{ __('admin.save') }}</button></p>
  </form>
</div>
