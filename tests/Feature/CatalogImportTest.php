<?php

declare(strict_types=1);

use App\Domain\Catalog\CatalogImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/** @param list<array<string, mixed>> $records */
function writeCatalogFixture(array $records): string
{
    $directory = sys_get_temp_dir().'/catalog-import-'.Str::uuid();
    mkdir($directory, 0777, true);
    file_put_contents(
        $directory.'/catalog.json',
        json_encode($records, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT),
    );

    return $directory;
}

/** @return array<string, mixed> */
function catalogRecord(array $overrides = []): array
{
    return array_merge([
        'identityKey' => 'test-spell',
        'versionKey' => '2024:test-spell',
        'name' => 'Test Spell',
        'edition' => '2024',
        'level' => 1,
        'school' => 'Evocation',
        'castingTime' => 'Action',
        'range' => '60 feet',
        'components' => 'V, S',
        'duration' => 'Instantaneous',
        'concentration' => false,
        'ritual' => false,
        'attackModes' => ['ranged_spell'],
        'saveAbilities' => [],
        'effectReliabilityCategory' => 'attack_roll',
        'spellLists' => ['Wizard'],
        'sourceBooks' => ['Test Book'],
        'sourcePage' => 42,
        'sourceSlug' => 'test-spell',
    ], $overrides);
}

it('imports the real index into identities versions publications and normalized pivots idempotently', function () {
    $importer = app(CatalogImporter::class);

    $first = $importer->importDirectory(base_path('data/index'));
    expect(data_get($first, 'created'))->toBe(943)
        ->and(data_get($first, 'updated'))->toBe(0)
        ->and(data_get($first, 'tombstoned'))->toBe(0)
        ->and(DB::table('spell_versions')->count())->toBe(943)
        ->and(DB::table('spell_identities')->count())->toBe(564);

    $chillTouch = DB::table('spell_versions')
        ->whereIn('content_key', ['2014:chill-touch', '2024:chill-touch'])
        ->orderBy('rules_edition')
        ->get();
    expect($chillTouch)->toHaveCount(2)
        ->and($chillTouch->pluck('spell_identity_id')->unique()->count())->toBe(1)
        ->and(DB::table('spell_version_attack_modes')
            ->where('spell_version_id', data_get($chillTouch->firstWhere('rules_edition', '2014'), 'id'))
            ->pluck('attack_mode')->all())->toBe(['ranged_spell'])
        ->and(DB::table('spell_version_attack_modes')
            ->where('spell_version_id', data_get($chillTouch->firstWhere('rules_edition', '2024'), 'id'))
            ->pluck('attack_mode')->all())->toBe(['melee_spell']);

    $greenFlameBladeId = DB::table('spell_versions')
        ->where('content_key', '2014:green-flame-blade')
        ->value('id');
    expect(DB::table('spell_version_publications')
        ->where('spell_version_id', $greenFlameBladeId)
        ->orderBy('source_book')
        ->pluck('source_book')->all())->toBe([
            "Sword Coast Adventurer's Guide",
            "Tasha's Cauldron of Everything",
        ]);
    $detectMagicId = DB::table('spell_versions')->where('content_key', '2024:detect-magic')->value('id');
    expect(DB::table('spell_version_tags')
        ->where('spell_version_id', $detectMagicId)
        ->pluck('tag')->all())->toContain('ritual');

    $versionIds = DB::table('spell_versions')->orderBy('content_key')->pluck('id', 'content_key')->all();
    $second = $importer->importDirectory(base_path('data/index'));
    expect($second)->toMatchArray(['created' => 0, 'updated' => 0, 'tombstoned' => 0])
        ->and(DB::table('spell_versions')->orderBy('content_key')->pluck('id', 'content_key')->all())->toBe($versionIds);
});

it('preserves referenced version rules while tracking aliases tombstones and dry-run diffs', function () {
    $directory = writeCatalogFixture([catalogRecord()]);
    $importer = app(CatalogImporter::class);
    $importer->importDirectory($directory);
    $version = DB::table('spell_versions')->where('content_key', '2024:test-spell')->first();
    $characterId = DB::table('characters')->insertGetId([
        'name' => 'Catalog Reference', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $sourceId = DB::table('character_source_instances')->insertGetId([
        'character_id' => $characterId, 'instance_uuid' => Str::uuid()->toString(),
        'source_type' => 'feat', 'display_name' => 'Reference', 'state' => 'active',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('spell_selection_slots')->insert([
        'character_id' => $characterId, 'source_instance_id' => $sourceId,
        'slot_key' => 'catalog:reference:1', 'rule_key' => 'catalog-reference', 'ordinal' => 1,
        'bucket' => 'automatic', 'eligibility_kind' => 'fixed_spell',
        'fixed_spell_version_id' => data_get($version, 'id'),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    file_put_contents($directory.'/catalog.json', json_encode([
        catalogRecord([
            'name' => 'Renamed Spell', 'school' => 'Illusion',
            'attackModes' => ['melee_spell'], 'spellLists' => ['Bard'],
        ]),
    ], JSON_THROW_ON_ERROR));
    $importer->importDirectory($directory);

    $preserved = DB::table('spell_versions')->where('id', data_get($version, 'id'))->first();
    expect(data_get($preserved, 'display_name'))->toBe('Test Spell')
        ->and(data_get($preserved, 'school'))->toBe('Evocation')
        ->and(DB::table('spell_version_attack_modes')->pluck('attack_mode')->all())->toBe(['ranged_spell'])
        ->and(DB::table('spell_identity_aliases')->pluck('alias')->all())->toContain('Test Spell')
        ->and(DB::table('spell_identities')->value('canonical_name'))->toBe('Renamed Spell');

    file_put_contents($directory.'/catalog.json', '[]');
    $tombstone = $importer->importDirectory($directory);
    expect(data_get($tombstone, 'tombstoned'))->toBe(1)
        ->and((bool) DB::table('spell_versions')->value('is_active'))->toBeFalse();

    file_put_contents($directory.'/catalog.json', json_encode([catalogRecord(['name' => 'Renamed Spell'])], JSON_THROW_ON_ERROR));
    $dryRun = $importer->importDirectory($directory, true);
    expect(data_get($dryRun, 'updated'))->toBe(1)
        ->and((bool) DB::table('spell_versions')->value('is_active'))->toBeFalse();
    $importer->importDirectory($directory);
    expect((bool) DB::table('spell_versions')->value('is_active'))->toBeTrue();
});

it('rolls back the whole import when any record is invalid', function () {
    $directory = writeCatalogFixture([
        catalogRecord(),
        catalogRecord(['identityKey' => 'broken', 'versionKey' => '2024:broken', 'name' => null]),
    ]);

    expect(fn () => app(CatalogImporter::class)->importDirectory($directory))
        ->toThrow(InvalidArgumentException::class, "Catalog field 'name' must be a non-empty string.");
    expect(DB::table('spell_versions')->count())->toBe(0)
        ->and(DB::table('spell_identities')->count())->toBe(0);
});

it('exposes catalog import as a dry-run artisan command without writing', function () {
    $this->artisan('catalog:import', ['--dry-run' => true])
        ->expectsOutputToContain('DRY RUN')
        ->expectsOutputToContain('Created versions: 943')
        ->assertSuccessful();

    expect(DB::table('spell_versions')->count())->toBe(0);
});
