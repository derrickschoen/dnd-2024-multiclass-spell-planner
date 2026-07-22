<?php

namespace Database\Seeders;

use App\Domain\Catalog\CatalogImporter;
use App\Domain\Catalog\CatalogSource;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Catalog data has one ingress even during seeding.
        app(CatalogImporter::class)->importDirectory(CatalogSource::directory());

        $this->call([
            ClassProgressionSeeder::class,
            ContentDefinitionSeeder::class,
            SeedCharacterSeeder::class,
        ]);
    }
}
