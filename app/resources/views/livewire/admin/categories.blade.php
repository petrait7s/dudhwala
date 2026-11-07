<div>
  <h2>{{ __('admin.nav.categories') }}</h2>
  @if($flash)<div class="note ok">{{ $flash }}</div>@endif @if($error)<div class="note bad">{{ $error }}</div>@endif
  <div class="card">
    @foreach($rows as $id => $r)
      <div class="row" style="padding:.4rem 0;border-bottom:1px solid var(--line)">
        <input style="width:150px" wire:model="rows.{{ $id }}.slug" aria-label="{{ __('admin.slug') }}">
        <input style="width:180px" wire:model="rows.{{ $id }}.name_bn" aria-label="{{ __('admin.name_bn') }}">
        <input style="width:180px" wire:model="rows.{{ $id }}.name_en" aria-label="{{ __('admin.name_en') }}">
        <input style="width:80px" type="number" wire:model="rows.{{ $id }}.sort_order" aria-label="{{ __('admin.sort') }}">
        <label><input type="checkbox" style="width:auto" wire:model="rows.{{ $id }}.active"> {{ __('admin.active') }}</label>
        <button class="btn" wire:click="save({{ $id }})">{{ __('admin.save') }}</button>
        <button class="btn alt" wire:click="remove({{ $id }})" wire:confirm="?">{{ __('admin.delete') }}</button>
      </div>
    @endforeach
  </div>
  <div class="card"><h3>{{ __('admin.new') }}</h3>
    <div class="row">
      <input style="width:150px" wire:model="new.slug" placeholder="{{ __('admin.slug') }}"><input style="width:180px" wire:model="new.name_bn" placeholder="{{ __('admin.name_bn') }}">
      <input style="width:180px" wire:model="new.name_en" placeholder="{{ __('admin.name_en') }}"><button class="btn" wire:click="create">{{ __('admin.add') }}</button>
    </div>
  </div>
</div>
