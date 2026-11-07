<div>
  <div class="row" style="justify-content:space-between"><h2>{{ __('admin.nav.products') }}</h2>
    @if($canManage)<a class="btn" href="{{ route('admin.product.new') }}">{{ __('admin.new') }}</a>@endif</div>
  @if($flash)<div class="note ok">{{ $flash }}</div>@endif @if($error)<div class="note bad">{{ $error }}</div>@endif
  <div class="card row">
    <div class="grow"><input wire:model.live.debounce.400ms="q" placeholder="{{ __('admin.search_products') }}"></div>
    <select wire:model.live="category" style="width:auto"><option value="">{{ __('admin.all_categories') }}</option>@foreach($categories as $c)<option value="{{ $c->id }}">{{ $c->name_bn }}</option>@endforeach</select>
    <select wire:model.live="vis" style="width:auto"><option value="">{{ __('admin.all') }}</option><option value="1">{{ __('admin.active') }}</option><option value="0">{{ __('admin.hidden') }}</option></select>
  </div>
  <div class="card" style="overflow-x:auto">
    @if($products->isEmpty())<p class="muted">{{ __('admin.no_results') }}</p>@else
    <table><thead><tr><th>{{ __('admin.name_bn') }}</th><th>{{ __('admin.category') }}</th><th>{{ __('admin.price') }}</th><th>{{ __('admin.outer_city') }}</th><th>{{ __('admin.status_label') }}</th><th></th></tr></thead><tbody>
    @foreach($products as $p)
      @php($prices = $p->variants->pluck('price_poisha')->filter(fn ($x) => $x !== null))
      <tr>
        <td><a href="{{ route('admin.product', $p) }}"><strong>{{ $p->name_bn }}</strong></a><br><span class="muted">{{ $p->name_en }}</span></td>
        <td>{{ $p->category->name_bn }}</td>
        <td>@if($prices->isEmpty())<span class="pill warn">{{ __('admin.unpriced') }}</span>@else ৳{{ number_format($prices->min() / 100, $prices->min() % 100 ? 2 : 0) }}@endif</td>
        <td>{{ $p->outer_city_status ? __('admin.outer.'.$p->outer_city_status) : __('admin.outer_default') }}</td>
        <td><span class="pill {{ $p->active ? 'ok' : '' }}">{{ $p->active ? __('admin.active') : __('admin.hidden') }}</span></td>
        <td>@if($canManage)<button class="btn alt" wire:click="toggle({{ $p->id }})">{{ $p->active ? __('admin.hide') : __('admin.publish') }}</button>@endif</td>
      </tr>
    @endforeach</tbody></table>{{ $products->links() }}@endif
  </div>
</div>
