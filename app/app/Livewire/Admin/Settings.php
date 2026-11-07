<?php

namespace App\Livewire\Admin;

use App\Livewire\Admin\Concerns\HandlesShopErrors;
use App\Models\Outlet;
use App\Services\AdminCatalogueService;
use App\Services\Money;
use App\Services\OrderingRules;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.admin')]
class Settings extends Component
{
    use HandlesShopErrors;

    public array $f = [];
    public bool $bulkApproved = false;

    public function mount(SettingsService $s): void
    {
        $h = $s->get('ordering.hours'); $b = $s->get('bulk.rule'); $fees = $s->get('delivery.fees'); $pn = $s->get('payment.numbers');
        $this->bulkApproved = $b['approved'];
        $this->f = [
            'open' => OrderingRules::minutesToHHMM($h['open_minute']), 'close' => OrderingRules::minutesToHHMM($h['close_minute']),
            'bulk_qty' => $b['min_total_quantity'], 'bulk_taka' => rtrim(rtrim(number_format($b['min_total_poisha'] / 100, 2, '.', ''), '0'), '.'),
            'fee_in' => rtrim(rtrim(number_format($fees['IN_CITY'] / 100, 2, '.', ''), '0'), '.') ?: '0', 'fee_out' => rtrim(rtrim(number_format($fees['OUTER_CITY'] / 100, 2, '.', ''), '0'), '.') ?: '0',
            'dispatch' => (string) ($s->get('fulfilment.dispatch_outlet_id') ?? ''), 'collection' => (string) ($s->get('bulk.collection_outlet_id') ?? ''),
            'bkash' => $pn['bkash'] ?? '', 'nagad' => $pn['nagad'] ?? '',
            'cur_steadfast' => $s->get('courier.tracking_urls')['STEADFAST'] ?? '', 'cur_pathao' => $s->get('courier.tracking_urls')['PATHAO'] ?? '',
            'prepaid' => (bool) $s->get('bulk.prepaid'), 'maintenance' => (bool) $s->get('site.maintenance'),
        ];
    }

    public function save(AdminCatalogueService $svc, SettingsService $s): void
    {
        $this->attempt(function () use ($svc) {
            $f = $this->f;
            $open = OrderingRules::hhmmToMinutes($f['open']); $close = OrderingRules::hhmmToMinutes($f['close']);
            if ($open === null || $close === null) throw new \App\Exceptions\ShopException('VALIDATION_FAILED', 'Hours must look like 10:00');
            try { $bulk = Money::parseTaka((string) $f['bulk_taka']); $in = Money::parseTaka((string) $f['fee_in']); $out = Money::parseTaka((string) $f['fee_out']); }
            catch (\InvalidArgumentException) { throw new \App\Exceptions\ShopException('VALIDATION_FAILED', 'Amounts must be numbers such as 60 or 60.50'); }
            $svc->saveSettings(Auth::user(), [
                'ordering.hours' => ['open_minute' => $open, 'close_minute' => $close],
                // saving the limit here is the owner's approval of it
                'bulk.rule' => ['min_total_quantity' => (int) $f['bulk_qty'], 'min_total_poisha' => $bulk, 'approved' => true],
                'delivery.fees' => ['IN_CITY' => $in, 'OUTER_CITY' => $out],
                'fulfilment.dispatch_outlet_id' => $f['dispatch'] === '' ? null : (int) $f['dispatch'],
                'bulk.collection_outlet_id' => $f['collection'] === '' ? null : (int) $f['collection'],
                'payment.numbers' => ['bkash' => $f['bkash'] === '' ? null : $f['bkash'], 'nagad' => $f['nagad'] === '' ? null : $f['nagad']],
                'courier.tracking_urls' => ['STEADFAST' => trim($f['cur_steadfast']) ?: null, 'PATHAO' => trim($f['cur_pathao']) ?: null],
                'bulk.prepaid' => (bool) $f['prepaid'], 'site.maintenance' => (bool) $f['maintenance'],
            ]);
            $this->bulkApproved = true;
        });
    }

    public function render()
    {
        return view('livewire.admin.settings', ['outlets' => Outlet::where('status', 'ACTIVE')->orderBy('id')->get()]);
    }
}
