<div class="card" style="max-width:420px;margin:2rem auto">
  <h2>{{ __('admin.mfa_title') }}</h2>
  @if($error)<div class="note bad">{{ $error }}</div>@endif
  <form wire:submit="submit">
    <p><label>{{ __('admin.mfa_code') }}<input wire:model="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6"></label></p>
    <p class="muted">{{ __('admin.mfa_recovery') }}</p>
    <p><input wire:model="recovery" autocomplete="off"></p>
    <button class="btn" type="submit">{{ __('admin.verify') }}</button>
  </form>
</div>
