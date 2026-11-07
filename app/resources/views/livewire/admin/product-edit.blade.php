<div>
  <p><a href="{{ route('admin.products') }}">← {{ __('admin.nav.products') }}</a></p>
  <h2>{{ $product ? $product->name_bn : __('admin.new') }}</h2>
  @if(! $canManage)<div class="note">{{ __('admin.view_only') }}</div>@endif
  @if($flash)<div class="note ok">{{ $flash }}</div>@endif @if($error)<div class="note bad">{{ $error }}</div>@endif

  <form class="card" wire:submit="saveProduct">
    <fieldset @disabled(! $canManage) style="border:0;padding:0;margin:0">
    <div class="grid2">
      <label>{{ __('admin.category') }}<select wire:model="form.category_id">@foreach($categories as $c)<option value="{{ $c->id }}">{{ $c->name_bn }}</option>@endforeach</select></label>
      <label>{{ __('admin.slug') }}<input wire:model="form.slug">@if(in_array('slug', $badFields))<span class="err">!</span>@endif</label>
      <label>{{ __('admin.name_bn') }}<input wire:model="form.name_bn"></label>
      <label>{{ __('admin.name_en') }}<input wire:model="form.name_en"></label>
      <label>{{ __('admin.outer_city') }}<select wire:model="form.outer_city_status"><option value="">{{ __('admin.outer_default') }}</option>@foreach($outerOptions as $o)<option value="{{ $o }}">{{ __('admin.outer.'.$o) }}</option>@endforeach</select></label>
      <label style="align-self:end"><input type="checkbox" wire:model="form.name_verified" style="width:auto"> {{ __('admin.name_verified') }}</label>
    </div>
    <div class="grid2" style="margin-top:.7rem">
      @foreach(['description_bn' => 'description', 'ingredients_bn' => 'ingredients', 'allergens_bn' => 'allergens', 'storage_bn' => 'storage', 'shelf_life_bn' => 'shelf_life'] as $f => $l)
        <label>{{ __('admin.'.$l) }}<textarea rows="2" wire:model="form.{{ $f }}"></textarea></label>
      @endforeach
    </div>
    @if($canManage)<p><button class="btn" type="submit">{{ __('admin.save') }}</button></p>@endif
    </fieldset>
  </form>

  @if($product)
  <div class="card">
    <h3>{{ __('admin.photo') }}</h3>
    <div class="row">
      @if($product->image_path)<img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($product->image_path) }}?v={{ $product->image_version }}" alt="" style="width:120px;height:120px;object-fit:cover;border-radius:10px">@endif
      @if($canManage)<label>{{ __('admin.upload') }}<input type="file" wire:model="photo" accept="image/jpeg,image/png,image/webp"></label>@endif
    </div>
    <h4>{{ __('admin.gallery') }}</h4>
    <div class="row">
      @foreach($gallery as $g)
        <div><img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($g->path) }}" alt="" style="width:90px;height:90px;object-fit:cover;border-radius:8px;display:block">
          @if($canManage)<button class="btn alt" style="margin-top:.2rem;padding:.15rem .6rem" wire:click="removeExtra({{ $g->id }})" wire:confirm="?">{{ __('admin.remove') }}</button>@endif</div>
      @endforeach
      @if($canManage && $gallery->count() < 5)<label>{{ __('admin.gallery_add') }}<input type="file" wire:model="extra" accept="image/jpeg,image/png,image/webp"></label>@endif
    </div>
  </div>

  <div class="card">
    <h3>{{ __('admin.variants') }}</h3>
    @foreach($variants as $id => $v)
      <div style="border-bottom:1px solid var(--line);padding:.7rem 0">
        <div class="grid2">
          <label>{{ __('admin.label_bn') }}<input wire:model="variants.{{ $id }}.label_bn" @disabled(! $canManage)></label>
          <label>{{ __('admin.label_en') }}<input wire:model="variants.{{ $id }}.label_en" @disabled(! $canManage)></label>
          <label>{{ __('admin.price') }}<input wire:model="variants.{{ $id }}.price" inputmode="decimal" @disabled(! $canManage)></label>
          <label>{{ __('admin.compare_at') }}<input wire:model="variants.{{ $id }}.compare_at" inputmode="decimal" @disabled(! $canManage)></label>
          <label>{{ __('admin.max_qty') }}<input type="number" wire:model="variants.{{ $id }}.max_qty" @disabled(! $canManage)></label>
          <label style="align-self:end"><input type="checkbox" wire:model="variants.{{ $id }}.track_stock" style="width:auto" @disabled(! $canManage)> {{ __('admin.track_stock') }}</label>
        </div>
        @if($v['price'] === '')<span class="pill warn">{{ __('admin.unpriced') }}</span>@endif
        @if($canManage)<div class="row" style="margin-top:.4rem"><button class="btn" wire:click="saveVariant({{ $id }})">{{ __('admin.save') }}</button><button class="btn alt" wire:click="deleteVariant({{ $id }})" wire:confirm="?">{{ __('admin.delete') }}</button></div>@endif

        @if($v['track_stock'])
        <div style="margin-top:.5rem"><strong>{{ __('admin.stock') }}</strong>
          @foreach($outlets as $o)
            <div class="row" style="margin:.25rem 0"><span style="min-width:160px">{{ $o->name_bn }}</span>
              <input style="width:100px" wire:model="stock.{{ $id }}-{{ $o->id }}" inputmode="numeric" @disabled(! $canManage)>
              @if($canManage)<button class="btn alt" wire:click="saveStock({{ $id }}, {{ $o->id }})">{{ __('admin.set_stock') }}</button>@endif</div>
          @endforeach
        </div>
        @endif
      </div>
    @endforeach
    @if($canManage && count($variants))<p style="margin-top:.7rem"><label>{{ __('admin.stock_reason') }}<input wire:model="stockReason"></label></p>@endif

    @if($canManage)
    <h4>{{ __('admin.add') }}</h4>
    <div class="grid2">
      <label>{{ __('admin.label_bn') }}<input wire:model="newVariant.label_bn"></label>
      <label>{{ __('admin.label_en') }}<input wire:model="newVariant.label_en"></label>
      <label>{{ __('admin.price') }}<input wire:model="newVariant.price" inputmode="decimal"></label>
      <label>{{ __('admin.max_qty') }}<input type="number" wire:model="newVariant.max_qty"></label>
    </div>
    <p><button class="btn" wire:click="addVariant">{{ __('admin.add') }}</button></p>
    @endif
  </div>
  @endif
</div>
