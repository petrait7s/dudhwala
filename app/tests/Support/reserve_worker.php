<?php

// Child process for the parallel-order test: php reserve_worker.php <outletId> <variantId> <orderId> <qty> <startAt(float)>
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

[, $outlet, $variant, $order, $qty, $startAt] = $argv;
while (microtime(true) < (float) $startAt) usleep(200); // release all workers together

try {
    app(App\Services\InventoryService::class)->reserve((int) $outlet, [['variant_id' => (int) $variant, 'qty' => (int) $qty]], (int) $order, ['system' => 'worker']);
    echo "OK\n";
} catch (App\Exceptions\ShopException $e) {
    echo $e->errorCode."\n";
}
