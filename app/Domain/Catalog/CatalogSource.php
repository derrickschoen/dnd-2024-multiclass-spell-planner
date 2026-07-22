<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

/**
 * Decides which catalog directory to import from.
 *
 * `data/index/` is the full scraped catalog — roughly 943 spell versions across
 * about twenty books, most of which are NOT Creative Commons. It is gitignored
 * and built locally with `npm run scrape`.
 *
 * `data/srd/` is the 339-record SRD 5.2.1 subset, which is CC-BY-4.0 and is the
 * only spell data this repository redistributes.
 *
 * Preferring the full catalog when present means a contributor who has run the
 * scraper gets everything, while a fresh clone still boots with working data.
 */
final class CatalogSource
{
    public static function directory(): string
    {
        $scraped = base_path('data/index');

        return is_dir($scraped) && glob($scraped.'/*.json') !== []
            ? $scraped
            : base_path('data/srd');
    }

    /** True when only the redistributable SRD subset is available. */
    public static function isSrdOnly(): bool
    {
        return self::directory() === base_path('data/srd');
    }
}
