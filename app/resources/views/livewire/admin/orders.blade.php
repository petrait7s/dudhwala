<div>
  <div class="row" style="justify-content:space-between"><h2>{{ __('admin.orders') }}</h2>
    @if($canExport)<a class="btn alt" href="{{ route('admin.export', array_filter(['status' => $status])) }}">{{ __('admin.export_csv') }}</a>@endif</div>
  <div class="stats">
    <div class="stat"><div class="l">{{ __('admin.stat_today') }}</div><div class="n">{{ $stats['today'] }}</div></div>
    <div class="stat {{ $stats['new'] ? 'hot' : '' }}"><div class="l">{{ __('admin.stat_new') }}</div><div class="n">{{ $stats['new'] }}</div></div>
    <div class="stat {{ $stats['verify'] ? 'hot' : '' }}"><div class="l">{{ __('admin.stat_verify') }}</div><div class="n">{{ $stats['verify'] }}</div></div>
    <div class="stat"><div class="l">{{ __('admin.stat_cod') }}</div><div class="n">{{ $stats['cod_due'] }}</div></div>
  </div>
  <div class="card row">
    <div class="grow"><input wire:model.live.debounce.400ms="q" placeholder="{{ __('admin.search') }}"></div>
    <div><select wire:model.live="status"><option value="">{{ __('admin.filter_status') }}: {{ __('admin.all') }}</option>
      @foreach($statuses as $s)<option value="{{ $s }}">{{ __('admin.s.'.$s) }}</option>@endforeach</select></div>
  </div>
  <div class="card" style="overflow-x:auto">
    @if($orders->isEmpty())<p class="empty">{{ __('admin.no_orders') }}</p>@else
    <table>
      <thead><tr><th>{{ __('admin.order') }}</th><th>{{ __('admin.customer') }}</th><th>{{ __('admin.method') }}</th><th>{{ __('admin.status') }}</th><th>{{ __('admin.payment') }}</th><th>{{ __('admin.total') }}</th><th>{{ __('admin.placed_at') }}</th></tr></thead>
      <tbody>
      @foreach($orders as $o)
        <tr>
          <td><a href="{{ route('admin.order', $o) }}"><strong>{{ $o->order_number }}</strong></a></td>
          <td>{{ $o->contact_name }}<br><span class="muted">{{ $o->contact_phone }}</span></td>
          <td>{{ __('admin.m.'.$o->method) }}@if($o->zone)<br><span class="muted">{{ __('admin.z.'.$o->zone) }}</span>@endif</td>
          <td><span class="pill {{ $o->status === 'CANCELLED' ? 'bad' : '' }}">{{ __('admin.s.'.$o->status) }}</span></td>
          <td><span class="pill {{ $summary[$o->id] === 'PAID' ? 'ok' : ($summary[$o->id] === 'PENDING_VERIFICATION' ? 'warn' : '') }}">{{ __('admin.p.'.$summary[$o->id]) }}</span></td>
          <td>৳{{ number_format($o->total_poisha / 100, $o->total_poisha % 100 ? 2 : 0) }}</td>
          <td class="muted">{{ $o->created_at->timezone('Asia/Dhaka')->format('d M, H:i') }}</td>
        </tr>
      @endforeach
      </tbody>
    </table>
    {{ $orders->links() }}
    @endif
  </div>
</div>
