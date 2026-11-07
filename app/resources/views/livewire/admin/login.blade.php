<div class="card" style="max-width:420px;margin:2rem auto">
  <h2>{{ __('admin.login') }}</h2>
  @if($error)<div class="note bad">{{ $error }}</div>@endif
  <form wire:submit="submit">
    <p><label>{{ __('admin.email') }}<input type="email" wire:model="email" autocomplete="username" required></label></p>
    <p><label>{{ __('admin.password') }}<input type="password" wire:model="password" autocomplete="current-password" required></label></p>
    <button class="btn" type="submit">{{ __('admin.login') }}</button>
  </form>
</div>
