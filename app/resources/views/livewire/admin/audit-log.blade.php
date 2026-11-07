<div>
  <h2>{{ __('admin.nav.audit') }}</h2>
  <div class="card"><input wire:model.live.debounce.400ms="q" placeholder="{{ __('admin.audit_filter') }}"></div>
  <div class="card" style="overflow-x:auto"><table>
    <thead><tr><th>{{ __('admin.audit_when') }}</th><th>{{ __('admin.audit_actor') }}</th><th>{{ __('admin.audit_action') }}</th><th>{{ __('admin.audit_entity') }}</th><th>{{ __('admin.audit_before') }}</th><th>{{ __('admin.audit_after') }}</th></tr></thead>
    <tbody>@foreach($logs as $l)
      <tr><td class="muted">{{ $l->created_at->timezone('Asia/Dhaka')->format('d M H:i') }}</td><td>{{ $l->actor?->name ?? $l->actor_role }}</td><td><code>{{ $l->action }}</code></td><td>{{ $l->entity_type }} {{ $l->entity_id }}</td>
        <td><small>{{ $l->before ? json_encode($l->before, JSON_UNESCAPED_UNICODE) : '' }}</small></td><td><small>{{ $l->after ? json_encode($l->after, JSON_UNESCAPED_UNICODE) : '' }}</small></td></tr>
    @endforeach</tbody></table>{{ $logs->links() }}</div>
</div>
