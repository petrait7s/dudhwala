<?php

namespace Tests\Support;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductVariant;

final class TestData
{
    private static int $n = 0;

    public static function outlet(string $code = null): Outlet
    {
        $code ??= 'T-'.++self::$n;
        return Outlet::create(['code' => $code, 'name_bn' => 'x', 'address_line' => 'y']);
    }

    public static function variant(int $price = 10000, string $cat = 'bakery', bool $active = true, ?int $max = null): ProductVariant
    {
        $c = Category::firstOrCreate(['slug' => $cat], ['name_bn' => $cat, 'name_en' => $cat]);
        $p = Product::create(['category_id' => $c->id, 'slug' => 'p-'.++self::$n, 'name_bn' => 'পণ্য', 'name_en' => 'Item', 'active' => $active]);
        return $p->variants()->create(['label_bn' => '১ কেজি', 'price_poisha' => $price, 'max_qty' => $max ?? 20]);
    }

    public static function order(Outlet $o, string $method = 'HOME_DELIVERY'): Order
    {
        $c = Customer::firstOrCreate(['phone' => '01712345678'], ['name' => 'N']);
        return Order::create(['order_number' => 'DW-'.++self::$n.'-'.bin2hex(random_bytes(3)), 'customer_id' => $c->id, 'outlet_id' => $o->id, 'method' => $method, 'zone' => 'IN_CITY', 'status' => 'PLACED', 'contact_name' => 'N', 'contact_phone' => '01712345678', 'subtotal_poisha' => 100, 'total_poisha' => 100, 'idempotency_key' => bin2hex(random_bytes(8))]);
    }
}
