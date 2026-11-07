<?php

namespace App\Notifications;

use App\Contracts\OrderNotifier;
use App\Models\Order;
use Illuminate\Support\Facades\Log;

class LogOrderNotifier implements OrderNotifier
{
    public function orderPlaced(Order $order): void
    {
        Log::info('order.placed', ['order' => $order->order_number, 'method' => $order->method, 'total_poisha' => $order->total_poisha]);
    }
}
