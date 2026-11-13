<?php

namespace App\Console\Commands;

use App\Services\PriceListImporter;
use Illuminate\Console\Command;

class ImportPrices extends Command
{
    protected $signature = 'dudhwala:import-prices {--file= : price list JSON (default database/data/price-list.json)}';
    protected $description = 'Apply the showroom price list: products, sizes, prices, publish, stock in at all branches';

    public function handle(PriceListImporter $imp): int
    {
        $file = $this->option('file') ?: database_path('data/price-list.json');
        $data = is_file($file) ? json_decode(file_get_contents($file), true) : null;
        if (! is_array($data)) { $this->error("Cannot read price list: $file"); return self::FAILURE; }
        try { $r = $imp->apply($data); } catch (\InvalidArgumentException $e) { $this->error($e->getMessage()); return self::FAILURE; }
        $this->info(sprintf('Prices: %d products created, %d sizes created, %d sizes priced, %d published, %d hidden, %d stock rows added.', $r['products_created'], $r['sizes_created'], $r['sizes_priced'], $r['published'], $r['hidden'], $r['stocked']));
        return self::SUCCESS;
    }
}
