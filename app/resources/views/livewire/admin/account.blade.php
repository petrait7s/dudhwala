<form class="card" wire:submit="save" style="max-width:480px">
  <h2>{{ __('admin.account') }}</h2>
  @if($flash)<div class="note ok">{{ $flash }}</div>@endif @if($error)<div class="note bad">{{ $error }}</div>@endif
  <p class="muted">{{ auth()->user()->name }} · {{ auth()->user()->email }} · {{ __('admin.roles.'.auth()->user()->role) }}</p>
  <p><label>{{ __('admin.current_password') }}<input type="password" wire:model="current" autocomplete="current-password"></label></p>
  <p><label>{{ __('admin.new_password') }}<input type="password" wire:model="new" autocomplete="new-password"></label></p>
  <button class="btn" type="submit">{{ __('admin.save') }}</button>
</form>
