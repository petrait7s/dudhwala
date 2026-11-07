<div>
  <h2>{{ __('admin.nav.staff') }}</h2>
  @if($flash)<div class="note ok">{{ $flash }}</div>@endif @if($error)<div class="note bad">{{ $error }}</div>@endif
  @if($shownPassword)<div class="note warn">{{ __('admin.temp_password') }}: <code style="font-size:1.1rem">{{ $shownPassword }}</code></div>@endif
  <div class="card" style="overflow-x:auto"><table>
    <thead><tr><th>{{ __('admin.name_bn') }}</th><th>{{ __('admin.email') }}</th><th>{{ __('admin.role') }}</th><th>{{ __('admin.status_label') }}</th><th>2FA</th><th></th></tr></thead><tbody>
    @foreach($staff as $u)
      <tr><td>{{ $u->name }}</td><td>{{ $u->email }}</td><td><span class="pill">{{ __('admin.roles.'.$u->role) }}</span></td>
        <td><span class="pill {{ $u->isActive() ? 'ok' : 'bad' }}">{{ $u->isActive() ? __('admin.st.ACTIVE') : __('admin.suspended') }}</span></td>
        <td>{{ $u->mfa_enabled ? '✓' : '—' }}</td>
        <td class="row">
          @if($u->id !== auth()->id())<button class="btn alt" wire:click="toggle({{ $u->id }})">{{ $u->isActive() ? __('admin.suspend') : __('admin.activate') }}</button>@endif
          <button class="btn alt" wire:click="resetPassword({{ $u->id }})" wire:confirm="?">{{ __('admin.reset_password') }}</button>
          @if($u->isAdmin())<button class="btn alt" wire:click="resetMfa({{ $u->id }})" wire:confirm="?">{{ __('admin.reset_2fa') }}</button>@endif
        </td></tr>
    @endforeach</tbody></table></div>
  <form class="card" wire:submit="create"><h3>{{ __('admin.new') }}</h3>
    <div class="grid2">
      <label>{{ __('admin.name_bn') }}<input wire:model="new.name"></label>
      <label>{{ __('admin.email') }}<input type="email" wire:model="new.email"></label>
      <label>{{ __('admin.role') }}<select wire:model="new.role"><option value="MANAGER">{{ __('admin.roles.MANAGER') }}</option><option value="ADMIN">{{ __('admin.roles.ADMIN') }}</option></select></label>
      <label>{{ __('admin.password') }}<div class="row"><input class="grow" wire:model="new.password" autocomplete="off"><button type="button" class="btn alt" wire:click="generate">{{ __('admin.generate') }}</button></div></label>
    </div>
    <p><button class="btn" type="submit">{{ __('admin.add') }}</button></p>
  </form>
</div>
