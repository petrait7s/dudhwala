<div>
  <h2>{{ __('admin.nav.bulk') }}</h2>
  @if($flash)<div class="note ok">{{ $flash }}</div>@endif @if($error)<div class="note bad">{{ $error }}</div>@endif

  <div class="card">
    <h3>{{ __('admin.bulk_csv_title') }}</h3>
    <ol class="muted" style="margin-top:0">
      <li>{{ __('admin.bulk_step1') }}</li><li>{{ __('admin.bulk_step2') }}</li><li>{{ __('admin.bulk_step3') }}</li>
    </ol>
    <div class="row">
      <button class="btn alt" wire:click="download">{{ __('admin.bulk_download') }}</button>
      <input type="file" wire:model="csv" accept=".csv,text/csv" style="width:auto">
      <button class="btn" wire:click="import" wire:loading.attr="disabled">{{ __('admin.bulk_upload') }}</button>
    </div>
    @if($result)
      @if($result['errors'])
        <div class="note bad" style="margin-top:.8rem"><strong>{{ __('admin.bulk_nothing_saved') }}</strong><ul>@foreach(array_slice($result['errors'], 0, 50) as $e)<li>{{ $e }}</li>@endforeach</ul></div>
      @else
        <div class="note ok" style="margin-top:.8rem">{{ __('admin.bulk_done', ['rows' => $result['rows'], 'v' => $result['changed_variants'], 's' => $result['stock_set'], 'p' => $result['published'], 'h' => $result['hidden']]) }}</div>
      @endif
    @endif
  </div>

  <div class="card">
    <h3>{{ __('admin.bulk_photos_title') }}</h3>
    <p class="muted">{{ __('admin.bulk_photos_help') }}</p>
    <div class="row">
      <input type="file" wire:model="photos" multiple accept="image/jpeg,image/png,image/webp" style="width:auto">
      <button class="btn" wire:click="uploadPhotos" wire:loading.attr="disabled">{{ __('admin.bulk_photos_upload') }}</button>
    </div>
    @if($photoResult)
      <div class="note {{ $photoResult['errors'] ? 'warn' : 'ok' }}" style="margin-top:.8rem">{{ __('admin.bulk_photos_done', ['c' => $photoResult['covers'], 'e' => $photoResult['extras']]) }}
        @if($photoResult['errors'])<ul>@foreach($photoResult['errors'] as $e)<li>{{ $e }}</li>@endforeach</ul>@endif</div>
    @endif
  </div>
</div>
