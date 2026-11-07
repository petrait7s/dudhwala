<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\PaymentService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OrderExportController extends Controller
{
    /** Spreadsheet formulas in customer-typed text must not run when the CSV is opened. */
    public static function safe(mixed $v): string
    {
        $s = (string) $v;
        return $s !== '' && in_array($s[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$s : $s;
    }

    /** CSV of orders (admin only). Filters: status, from, to (Dhaka dates, YYYY-MM-DD). */
    public function __invoke(Request $r, PaymentService $payments): StreamedResponse
    {
        $q = Order::with(['outlet', 'items'])->orderBy('id');
        if ($s = $r->query('status')) $q->where('status', $s);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $r->query('from'))) $q->where('created_at', '>=', \Carbon\CarbonImmutable::parse($r->query('from'), 'Asia/Dhaka')->startOfDay()->utc());
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $r->query('to'))) $q->where('created_at', '<=', \Carbon\CarbonImmutable::parse($r->query('to'), 'Asia/Dhaka')->endOfDay()->utc());

        return response()->streamDownload(function () use ($q, $payments) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM so Excel reads Bangla
            fputcsv($out, ['order_number', 'placed_at_dhaka', 'status', 'method', 'zone', 'branch', 'customer', 'phone', 'address', 'items', 'subtotal_taka', 'delivery_fee_taka', 'total_taka', 'payment', 'due_taka', 'courier', 'tracking_code']);
            $q->chunkById(500, function ($orders) use ($out, $payments) {
                foreach ($orders as $o) {
                    fputcsv($out, array_map([self::class, 'safe'], [
                        $o->order_number, $o->created_at->timezone('Asia/Dhaka')->format('Y-m-d H:i'), $o->status, $o->method, $o->zone, $o->outlet->name_en ?? $o->outlet->name_bn,
                        $o->contact_name, $o->contact_phone, trim($o->address_line1.' '.$o->address_landmark),
                        $o->items->map(fn ($i) => $i->product_name_bn.' x'.$i->quantity)->implode('; '),
                        number_format($o->subtotal_poisha / 100, 2, '.', ''), number_format($o->delivery_fee_poisha / 100, 2, '.', ''), number_format($o->total_poisha / 100, 2, '.', ''),
                        $payments->summary($o), number_format($payments->due($o) / 100, 2, '.', ''), $o->courier, $o->courier_ref,
                    ]));
                }
            });
            fclose($out);
        }, 'dudhwala-orders-'.now('Asia/Dhaka')->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
