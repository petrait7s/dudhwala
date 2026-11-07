<div class="card" style="max-width:520px;margin:2rem auto">
  <h2>{{ __('admin.sec_title') }}</h2>
  @if($recoveryCodes)
    <div class="note warn">{{ __('admin.sec_codes') }}</div>
    <pre style="font-size:1.1rem">@foreach($recoveryCodes as $c){{ $c }}
@endforeach</pre>
    <button class="btn" wire:click="done">{{ __('admin.sec_done') }}</button>
  @else
    <p>{{ __('admin.sec_intro') }}</p>
    @if($error)<div class="note bad">{{ $error }}</div>@endif
    <div>{!! $qr['svg'] !!}</div>
    <p class="muted">{{ __('admin.sec_secret') }}: <code>{{ $qr['secret'] }}</code></p>
    <form wire:submit="confirm">
      <p><label>{{ __('admin.mfa_code') }}<input wire:model="code" inputmode="numeric" maxlength="6" autocomplete="one-time-code"></label></p>
      <button class="btn" type="submit">{{ __('admin.sec_confirm') }}</button>
    </form>
  @endif
</div>
