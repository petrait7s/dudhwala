<?php

namespace App\Livewire\Admin;

use App\Livewire\Admin\Concerns\HandlesShopErrors;
use App\Services\AdminBulkService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Layout('components.layouts.admin')]
class Bulk extends Component
{
    use HandlesShopErrors, WithFileUploads;

    public $csv = null;
    public array $photos = [];
    public ?array $result = null;
    public ?array $photoResult = null;

    public function download(AdminBulkService $svc)
    {
        return response()->streamDownload(fn () => print($svc->exportCsv()), 'dudhwala-products-'.now('Asia/Dhaka')->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function import(AdminBulkService $svc): void
    {
        $this->result = null;
        if (! $this->csv) { $this->error = __('admin.bulk_choose'); return; }
        $text = (string) file_get_contents($this->csv->getRealPath());
        $this->attempt(function () use ($svc, $text) {
            $this->result = $svc->importCsv(Auth::user(), $text);
            if ($this->result['errors']) throw new \App\Exceptions\ShopException('VALIDATION_FAILED', __('admin.bulk_nothing_saved'));
        });
        $this->csv = null;
    }

    public function uploadPhotos(AdminBulkService $svc): void
    {
        $this->photoResult = null;
        $files = array_map(fn ($f) => new UploadedFile($f->getRealPath(), $f->getClientOriginalName(), null, null, true), $this->photos);
        $this->attempt(function () use ($svc, $files) { $this->photoResult = $svc->importPhotos(Auth::user(), $files); });
        $this->photos = [];
    }

    public function render() { return view('livewire.admin.bulk'); }
}
