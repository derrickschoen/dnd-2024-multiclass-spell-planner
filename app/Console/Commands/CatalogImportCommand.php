<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Catalog\CatalogImporter;
use Illuminate\Console\Command;

final class CatalogImportCommand extends Command
{
    protected $signature = 'catalog:import {--dry-run : Print the diff without writing}';

    protected $description = 'Import the normalized spell catalog from data/index JSON files';

    public function handle(CatalogImporter $importer): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $summary = $importer->importDirectory(base_path('data/index'), $dryRun);

        if ($dryRun) {
            $this->warn('DRY RUN — transaction rolled back');
        }
        $this->line('Created versions: '.data_get($summary, 'created'));
        $this->line('Updated versions: '.data_get($summary, 'updated'));
        $this->line('Tombstoned versions: '.data_get($summary, 'tombstoned'));
        $this->line('Created identities: '.data_get($summary, 'identities_created'));
        $this->line('Updated identities: '.data_get($summary, 'identities_updated'));

        return self::SUCCESS;
    }
}
