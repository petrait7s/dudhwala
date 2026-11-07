<?php

namespace App\Contracts;

use App\Models\Order;

/** Staff alert channel for new orders. Default: log. A WhatsApp implementation can be bound later. */
interface OrderNotifier
{
    public function orderPlaced(Order $order): void;
}
