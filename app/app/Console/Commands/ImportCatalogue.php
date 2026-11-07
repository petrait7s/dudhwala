<?php

namespace App\Console\Commands;

use App\Services\CatalogueImporter;
use Illuminate\Console\Command;

class ImportCatalogue extends Command
{
    protected $signature = 'dudhwala:import-catalogue {--file= : catalogue JSON (default database/data/catalogue.json)} {--activate : make new products visible}';
    protected $description = 'Idempotent catalogue import (names and Outer Sylhet marks only; no prices)';

    public function handle(CatalogueImporter $imp): int
    {
        $file = $this->option('file') ?: database_path('data/catalogue.json');
        if (! is_file($file)) { $this->error("File not found: $file"); return self::FAILURE; }
        $data = json_decode(file_get_contents($file), true);
        if (! is_array($data)) { $this->error('Invalid JSON'); return self::FAILURE; }
        try {
            $r = $imp->import($data, (bool) $this->option('activate'));
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }
        $this->info("Catalogue import: {$r['categories_created']} categories and {$r['products_created']} products created, {$r['products_skipped']} already present.");
        return self::SUCCESS;
    }
}
