<form wire:submit="save">
  <div class="row" style="justify-content:space-between"><h2>{{ __('admin.nav.pages') }}</h2><button type="button" class="btn alt" wire:click="add">{{ __('admin.add_page') }}</button></div>
  @if($flash)<div class="note ok">{{ $flash }}</div>@endif @if($error)<div class="note bad">{{ $error }}</div>@endif
  <p class="muted">{{ __('admin.page_note') }}</p>
  @foreach($pages as $i => $p)
    <div class="card">
      <div class="grid2">
        <label>{{ __('admin.page_slug') }}<input wire:model="pages.{{ $i }}.slug"></label>
        <label>{{ __('admin.title_bn') }}<input wire:model="pages.{{ $i }}.title_bn"></label>
        <label>{{ __('admin.title_en') }}<input wire:model="pages.{{ $i }}.title_en"></label>
      </div>
      <label>{{ __('admin.body_bn') }}<textarea rows="6" wire:model="pages.{{ $i }}.body_bn"></textarea></label>
      <label>{{ __('admin.body_en') }}<textarea rows="6" wire:model="pages.{{ $i }}.body_en"></textarea></label>
      <p><button type="button" class="btn alt" wire:click="remove({{ $i }})">{{ __('admin.remove') }}</button></p>
    </div>
  @endforeach
  <p><button class="btn" type="submit">{{ __('admin.save') }}</button></p>
</form>
