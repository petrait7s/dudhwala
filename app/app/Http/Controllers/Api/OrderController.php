<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ShopException;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\Phone;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    private const STATUS = [
        'VALIDATION_FAILED' => 422, 'CHECKOUT_INVALID' => 422, 'UNPRICED' => 422, 'UNAVAILABLE' => 422, 'QTY_LIMIT' => 422,
        'TRX_DUPLICATE' => 422, 'OUT_OF_STOCK' => 409, 'CONFLICT' => 409, 'NOT_CONFIGURED' => 503, 'FORBIDDEN' => 403,
    ];

    public static function fail(ShopException $e): JsonResponse
    {
        return response()->json(['error' => ['code' => $e->errorCode, 'message' => $e->getMessage()] + $e->context], self::STATUS[$e->errorCode] ?? 400);
    }

    public function store(Request $r, OrderService $orders, SettingsService $settings): JsonResponse
    {
        // honeypot: real people never fill this
        if (filled($r->input('website'))) return response()->json(['error' => ['code' => 'VALIDATION_FAILED']], 422);
        if ($settings->get('site.maintenance')) return response()->json(['error' => ['code' => 'MAINTENANCE']], 503);

        try {
            ['order' => $o, 'replayed' => $replayed] = $orders->place($r->all());
        } catch (ShopException $e) {
            return self::fail($e);
        }
        return response()->json([
            'order_number' => $o->order_number, 'status' => $o->status, 'subtotal_poisha' => $o->subtotal_poisha,
            'delivery_fee_poisha' => $o->delivery_fee_poisha, 'total_poisha' => $o->total_poisha,
        ], $replayed ? 200 : 201);
    }

    /** Ownership check: order number AND phone must match. Mismatch is indistinguishable from "not found". */
    public function track(Request $r, PaymentService $payments, OrderService $orders): JsonResponse
    {
        $num = strtoupper(trim((string) $r->input('order_number')));
        $phone = Phone::normalize((string) $r->input('phone'));
        $o = ($num && $phone) ? Order::with('items')->where('order_number', $num)->where('contact_phone', $phone)->first() : null;
        if (! $o) return response()->json(['error' => ['code' => 'NOT_FOUND']], 404);

        return response()->json([
            'order_number' => $o->order_number, 'method' => $o->method, 'status' => $o->status, 'payment_status' => $payments->summary($o),
            'subtotal_poisha' => $o->subtotal_poisha, 'delivery_fee_poisha' => $o->delivery_fee_poisha, 'total_poisha' => $o->total_poisha,
            'courier' => $o->courier, 'courier_ref' => $o->courier_ref, 'courier_url' => $orders->courierUrl($o),
            'items' => $o->items->map(fn ($i) => ['name_bn' => $i->product_name_bn, 'variant_label_bn' => $i->variant_label_bn, 'quantity' => $i->quantity, 'line_total_poisha' => $i->line_total_poisha])->all(),
        ]);
    }
}
