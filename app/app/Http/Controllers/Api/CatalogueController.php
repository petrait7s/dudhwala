<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\InventoryService;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Storage;

class CatalogueController extends Controller
{
    /** Public read model. Customers see availability (in_stock), never stock counts. Hidden products are not listed. */
    public function index(InventoryService $inventory, SettingsService $settings)
    {
        $products = Product::with('variants')->where('active', true)
            ->whereHas('category', fn ($q) => $q->where('active', true))->orderBy('id')->get();
        $variantIds = $products->flatMap(fn ($p) => $p->variants->pluck('id'))->all();
        $avail = $variantIds ? $inventory->availableAt($variantIds) : [];

        // stock that matters is the outlet that will fulfil: dispatch (home delivery) or any active outlet (pickup/showroom)
        $out = $products->map(fn ($p) => [
            'slug' => $p->slug,
            'image_url' => $p->image_path ? Storage::disk('public')->url($p->image_path).'?v='.$p->image_version : null,
            'variants' => $p->variants->sortBy('sort_order')->values()->map(fn ($v) => [
                'id' => $v->id, 'label_bn' => $v->label_bn, 'label_en' => $v->label_en,
                'price_poisha' => $v->price_poisha, 'max_qty' => $v->max_qty,
                'in_stock' => ! $v->track_stock || (isset($avail[$v->id]) && max($avail[$v->id]) > 0),
            ])->all(),
        ])->all();

        return response()->json(['products' => $out])->header('Cache-Control', 'public, max-age=30');
    }

    public function config(SettingsService $s)
    {
        $b = $s->get('bulk.rule');
        $collection = $s->get('bulk.collection_outlet_id');
        $dispatch = $s->get('fulfilment.dispatch_outlet_id');
        return response()->json([
            'hours' => $s->get('ordering.hours'),
            'bulk' => ['min_total_quantity' => $b['min_total_quantity'], 'min_total_poisha' => $b['min_total_poisha'], 'approved' => $b['approved']],
            'fees' => $s->get('delivery.fees'),
            'payment_numbers' => $s->get('payment.numbers'),
            'bulk_prepaid' => (bool) $s->get('bulk.prepaid'),
            'maintenance' => (bool) $s->get('site.maintenance'),
            'outlets' => \App\Models\Outlet::with('hours')->where('status', 'ACTIVE')->orderBy('id')->get()->map(fn ($o) => [
                'id' => $o->id, 'code' => $o->code, 'name_bn' => $o->name_bn, 'name_en' => $o->name_en, 'address_line' => $o->address_line, 'phone' => $o->phone,
                'hours' => $o->hours->map(fn ($h) => ['weekday' => $h->weekday, 'opens' => substr($h->opens_at, 0, 5), 'closes' => substr($h->closes_at, 0, 5)])->values()->all(),
                'is_collection' => $o->id === $collection, 'is_dispatch' => $o->id === $dispatch,
            ])->all(),
        ])->header('Cache-Control', 'public, max-age=30');
    }

    /** Owner-written content pages (About, FAQ, policies). */
    public function pages(SettingsService $s)
    {
        return response()->json(['pages' => array_values($s->get('content.pages'))])->header('Cache-Control', 'public, max-age=60');
    }
}
