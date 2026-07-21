<?php

namespace Database\Seeders;

use App\Domain\Catalog\CatalogImporter;
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
        app(CatalogImporter::class)->importDirectory(base_path('data/index'));

        $this->call([
            ClassProgressionSeeder::class,
            ContentDefinitionSeeder::class,
            SeedCharacterSeeder::class,
        ]);
    }
}
