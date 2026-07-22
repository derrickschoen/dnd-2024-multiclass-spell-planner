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
    expect(DB::table('spell_list_memberships')
        ->where('spell_version_id', $greenFlameBladeId)
        ->orderBy('spell_list_key')
        ->pluck('spell_list_key')->all())->toBe([
            'Artificer', 'Sorcerer (Optional)', 'Warlock (Optional)', 'Wizard (Optional)',
        ])
        ->and(DB::table('spell_list_memberships')
            ->where('spell_version_id', $greenFlameBladeId)
            ->where('spell_list_key', 'Sorcerer')->exists())->toBeFalse();

    $fastFriendsId = DB::table('spell_versions')->where('content_key', '2014:fast-friends')->value('id');
    expect(DB::table('spell_list_memberships')
        ->where('spell_version_id', $fastFriendsId)
        ->orderBy('spell_list_key')
        ->pluck('spell_list_key')->all())->toBe(['Bard', 'Cleric', 'Wizard'])
        ->and(DB::table('spell_list_memberships')
            ->where('spell_version_id', DB::table('spell_versions')
                ->where('content_key', '2014:encode-thoughts')->value('id'))
            ->count())->toBe(0)
        ->and(DB::table('spell_list_memberships')->where('spell_list_key', 'None')->count())->toBe(0)
        ->and(DB::table('spell_list_memberships')->where('spell_list_key', 'like', '% (Optional)')->count())->toBe(122)
        ->and(DB::table('spell_list_memberships')
            ->whereIn('spell_list_key', ['Wizard (Dunamancy)', 'Wizard (Graviturgy)'])->count())->toBe(6);
    $detectMagicId = DB::table('spell_versions')->where('content_key', '2024:detect-magic')->value('id');
    expect(DB::table('spell_version_tags')
        ->where('spell_version_id', $detectMagicId)
        ->pluck('tag')->all())->toContain('ritual');

    $versionIds = DB::table('spell_versions')->orderBy('content_key')->pluck('id', 'content_key')->all();
    $second = $importer->importDirectory(base_path('data/index'));
    expect($second)->toMatchArray(['created' => 0, 'updated' => 0, 'tombstoned' => 0])
        ->and(DB::table('spell_versions')->orderBy('content_key')->pluck('id', 'content_key')->all())->toBe($versionIds);
});

it('keeps referenced version metadata byte-identical while activity follows removal and reappearance', function () {
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
    $snapshot = (array) DB::table('spell_versions')->where('id', data_get($version, 'id'))->sole();
    $immutableSnapshot = array_diff_key($snapshot, array_flip(['is_active', 'updated_at']));

    file_put_contents($directory.'/catalog.json', json_encode([
        catalogRecord([
            'name' => 'Renamed Spell', 'school' => 'Illusion',
            'attackModes' => ['melee_spell'], 'spellLists' => ['Bard'],
        ]),
    ], JSON_THROW_ON_ERROR));
    $importer->importDirectory($directory);

    $preserved = DB::table('spell_versions')->where('id', data_get($version, 'id'))->sole();
    expect((array) $preserved)->toBe($snapshot)
        ->and(DB::table('spell_version_attack_modes')->pluck('attack_mode')->all())->toBe(['ranged_spell'])
        ->and(DB::table('spell_identity_aliases')->pluck('alias')->all())->toContain('Test Spell')
        ->and(DB::table('spell_identities')->value('canonical_name'))->toBe('Renamed Spell');

    file_put_contents($directory.'/catalog.json', '[]');
    $tombstone = $importer->importDirectory($directory);
    $removed = (array) DB::table('spell_versions')->where('id', data_get($version, 'id'))->sole();
    expect(data_get($tombstone, 'tombstoned'))->toBe(1)
        ->and((bool) data_get($removed, 'is_active'))->toBeFalse()
        ->and(array_diff_key($removed, array_flip(['is_active', 'updated_at'])))->toBe($immutableSnapshot);

    file_put_contents($directory.'/catalog.json', json_encode([
        catalogRecord(['name' => 'Returned Again', 'school' => 'Necromancy']),
    ], JSON_THROW_ON_ERROR));
    $returned = $importer->importDirectory($directory);
    $reactivated = (array) DB::table('spell_versions')->where('id', data_get($version, 'id'))->sole();
    expect(data_get($returned, 'updated'))->toBe(1)
        ->and((bool) data_get($reactivated, 'is_active'))->toBeTrue()
        ->and(array_diff_key($reactivated, array_flip(['is_active', 'updated_at'])))->toBe($immutableSnapshot);
});

it('tombstones and reactivates unreferenced versions with an accurate dry-run diff', function () {
    $directory = writeCatalogFixture([catalogRecord()]);
    $importer = app(CatalogImporter::class);
    $importer->importDirectory($directory);
    $versionId = (int) DB::table('spell_versions')->value('id');

    file_put_contents($directory.'/catalog.json', '[]');
    $tombstone = $importer->importDirectory($directory);
    expect(data_get($tombstone, 'tombstoned'))->toBe(1)
        ->and((bool) DB::table('spell_versions')->where('id', $versionId)->value('is_active'))->toBeFalse();

    file_put_contents($directory.'/catalog.json', json_encode([catalogRecord()], JSON_THROW_ON_ERROR));
    $dryRun = $importer->importDirectory($directory, true);
    expect(data_get($dryRun, 'updated'))->toBe(1)
        ->and((bool) DB::table('spell_versions')->where('id', $versionId)->value('is_active'))->toBeFalse();
    $importer->importDirectory($directory);
    expect((bool) DB::table('spell_versions')->where('id', $versionId)->value('is_active'))->toBeTrue();
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

it('rejects spell levels outside the zero through nine catalog boundary', function (int $level) {
    $directory = writeCatalogFixture([catalogRecord(['level' => $level])]);

    expect(fn () => app(CatalogImporter::class)->importDirectory($directory))
        ->toThrow(InvalidArgumentException::class, "Catalog field 'level' must be an integer from 0 through 9.");
    expect(DB::table('spell_versions')->count())->toBe(0);
})->with([-1, 10]);

it('rejects every malformed catalog container and required field shape', function (Closure $arrange, string $message) {
    $directory = writeCatalogFixture([catalogRecord()]);
    $arrange($directory);

    expect(fn () => app(CatalogImporter::class)->importDirectory($directory))
        ->toThrow(InvalidArgumentException::class, $message);
    expect(DB::table('spell_versions')->count())->toBe(0)
        ->and(DB::table('spell_identities')->count())->toBe(0);
})->with([
    'invalid json' => [
        static fn (string $directory) => file_put_contents($directory.'/catalog.json', '{'),
        'Invalid catalog JSON',
    ],
    'object root' => [
        static fn (string $directory) => file_put_contents($directory.'/catalog.json', '{"record":{}}'),
        'must contain a JSON list.',
    ],
    'scalar record' => [
        static fn (string $directory) => file_put_contents($directory.'/catalog.json', '[1]'),
        'contains a non-object record.',
    ],
    'blank identity key' => [
        static fn (string $directory) => file_put_contents($directory.'/catalog.json', json_encode([catalogRecord(['identityKey' => '  '])], JSON_THROW_ON_ERROR)),
        "Catalog field 'identityKey' must be a non-empty string.",
    ],
    'missing version key' => [
        static fn (string $directory) => file_put_contents($directory.'/catalog.json', json_encode([catalogRecord(['versionKey' => null])], JSON_THROW_ON_ERROR)),
        "Catalog field 'versionKey' must be a non-empty string.",
    ],
    'numeric edition' => [
        static fn (string $directory) => file_put_contents($directory.'/catalog.json', json_encode([catalogRecord(['edition' => 2024])], JSON_THROW_ON_ERROR)),
        "Catalog field 'edition' must be a non-empty string.",
    ],
    'blank school' => [
        static fn (string $directory) => file_put_contents($directory.'/catalog.json', json_encode([catalogRecord(['school' => "\t"])], JSON_THROW_ON_ERROR)),
        "Catalog field 'school' must be a non-empty string.",
    ],
    'concentration scalar' => [
        static fn (string $directory) => file_put_contents($directory.'/catalog.json', json_encode([catalogRecord(['concentration' => 0])], JSON_THROW_ON_ERROR)),
        "Catalog field 'concentration' must be boolean.",
    ],
    'ritual scalar' => [
        static fn (string $directory) => file_put_contents($directory.'/catalog.json', json_encode([catalogRecord(['ritual' => 'false'])], JSON_THROW_ON_ERROR)),
        "Catalog field 'ritual' must be boolean.",
    ],
    'attack modes scalar' => [
        static fn (string $directory) => file_put_contents($directory.'/catalog.json', json_encode([catalogRecord(['attackModes' => 'ranged_spell'])], JSON_THROW_ON_ERROR)),
        "Catalog field 'attackModes' must be a list.",
    ],
    'save abilities scalar' => [
        static fn (string $directory) => file_put_contents($directory.'/catalog.json', json_encode([catalogRecord(['saveAbilities' => null])], JSON_THROW_ON_ERROR)),
        "Catalog field 'saveAbilities' must be a list.",
    ],
    'spell lists scalar' => [
        static fn (string $directory) => file_put_contents($directory.'/catalog.json', json_encode([catalogRecord(['spellLists' => 'Wizard'])], JSON_THROW_ON_ERROR)),
        "Catalog field 'spellLists' must be a list.",
    ],
    'source books scalar' => [
        static fn (string $directory) => file_put_contents($directory.'/catalog.json', json_encode([catalogRecord(['sourceBooks' => false])], JSON_THROW_ON_ERROR)),
        "Catalog field 'sourceBooks' must be a list.",
    ],
    'attack modes blank item' => [
        static fn (string $directory) => file_put_contents($directory.'/catalog.json', json_encode([catalogRecord(['attackModes' => [' ']])], JSON_THROW_ON_ERROR)),
        "Catalog field 'attackModes' must contain non-empty strings.",
    ],
    'save abilities non-string item' => [
        static fn (string $directory) => file_put_contents($directory.'/catalog.json', json_encode([catalogRecord(['saveAbilities' => [1]])], JSON_THROW_ON_ERROR)),
        "Catalog field 'saveAbilities' must contain non-empty strings.",
    ],
    'spell lists blank item' => [
        static fn (string $directory) => file_put_contents($directory.'/catalog.json', json_encode([catalogRecord(['spellLists' => ['']])], JSON_THROW_ON_ERROR)),
        "Catalog field 'spellLists' must contain non-empty strings.",
    ],
    'source books non-string item' => [
        static fn (string $directory) => file_put_contents($directory.'/catalog.json', json_encode([catalogRecord(['sourceBooks' => [null]])], JSON_THROW_ON_ERROR)),
        "Catalog field 'sourceBooks' must contain non-empty strings.",
    ],
]);

it('imports and synchronizes the complete version and pivot contract', function () {
    $directory = writeCatalogFixture([catalogRecord([
        'identityKey' => 'arcane-echo',
        'versionKey' => '2024:arcane-echo',
        'name' => 'Arcane Echo',
        'school' => 'Illusion',
        'castingTime' => 'Action or R',
        'duration' => 'C, up to 1 minute',
        'components' => 'V, S, M',
        'attackModes' => ['ranged_spell', 'melee_spell', 'ranged_spell'],
        'saveAbilities' => ['wisdom', 'charisma', 'wisdom'],
        'spellLists' => ['Wizard', 'Bard', 'Wizard'],
        'sourceBooks' => ['Book B', 'Book A'],
        'sourcePage' => 99,
        'sourceSlug' => 'arcane-echo',
        'tags' => ['utility', 'utility'],
        'healing' => true,
        'effectReliabilityCategory' => 'saving_throw',
    ])]);

    $summary = app(CatalogImporter::class)->importDirectory($directory);
    expect($summary)->toBe([
        'created' => 1,
        'updated' => 0,
        'tombstoned' => 0,
        'identities_created' => 1,
        'identities_updated' => 0,
        'publications_created' => 2,
        'memberships_created' => 2,
        'tags_created' => 3,
        'attack_modes_created' => 2,
        'save_abilities_created' => 2,
    ]);

    $version = DB::table('spell_versions')->where('content_key', '2024:arcane-echo')->sole();
    expect(collect((array) $version)->only([
        'content_key', 'display_name', 'rules_edition', 'level', 'school', 'ritual', 'concentration',
        'casting_time', 'range', 'duration', 'components', 'healing', 'effect_reliability_category',
        'provenance', 'is_active',
    ])->all())->toBe([
        'content_key' => '2024:arcane-echo',
        'display_name' => 'Arcane Echo',
        'rules_edition' => '2024',
        'level' => 1,
        'school' => 'Illusion',
        'ritual' => 0,
        'concentration' => 0,
        'casting_time' => 'Action or R',
        'range' => '60 feet',
        'duration' => 'C, up to 1 minute',
        'components' => 'V, S, M',
        'healing' => 1,
        'effect_reliability_category' => 'saving_throw',
        'provenance' => 'import',
        'is_active' => 1,
    ])->and(data_get($version, 'created_at'))->not->toBeNull()
        ->and(data_get($version, 'updated_at'))->not->toBeNull()
        ->and(DB::table('spell_identities')->where('id', data_get($version, 'spell_identity_id'))->first())
        ->canonical_name->toBe('Arcane Echo')
        ->and(DB::table('spell_version_publications')->where('spell_version_id', data_get($version, 'id'))
            ->orderBy('source_book')->get(['source_book', 'source_page', 'source_reference'])
            ->map(static fn (object $row): array => (array) $row)->all())->toBe([
                ['source_book' => 'Book A', 'source_page' => 99, 'source_reference' => 'arcane-echo'],
                ['source_book' => 'Book B', 'source_page' => 99, 'source_reference' => 'arcane-echo'],
            ])
        ->and(DB::table('spell_list_memberships')->where('spell_version_id', data_get($version, 'id'))
            ->orderBy('spell_list_key')->pluck('spell_list_key')->all())->toBe(['Bard', 'Wizard'])
        ->and(DB::table('spell_list_memberships')->where('spell_version_id', data_get($version, 'id'))
            ->whereNull('created_at')->count())->toBe(0)
        ->and(DB::table('spell_version_tags')->where('spell_version_id', data_get($version, 'id'))
            ->orderBy('tag')->pluck('tag')->all())->toBe(['concentration', 'ritual', 'utility'])
        ->and(DB::table('spell_version_attack_modes')->where('spell_version_id', data_get($version, 'id'))
            ->orderBy('attack_mode')->pluck('attack_mode')->all())->toBe(['melee_spell', 'ranged_spell'])
        ->and(DB::table('spell_version_save_abilities')->where('spell_version_id', data_get($version, 'id'))
            ->orderBy('save_ability')->pluck('save_ability')->all())->toBe(['charisma', 'wisdom']);
});

it('merges split version records and chooses the highest-edition canonical name deterministically', function () {
    $directory = writeCatalogFixture([catalogRecord([
        'identityKey' => 'split-spell', 'versionKey' => '2014:split-spell',
        'name' => 'Legacy Split', 'edition' => '2014', 'spellLists' => ['Wizard'],
        'sourceBooks' => ['Legacy Book'],
    ])]);
    file_put_contents($directory.'/b-expanded.json', json_encode([catalogRecord([
        'identityKey' => 'split-spell', 'versionKey' => 'expanded:split-spell',
        'name' => 'Expanded Split', 'edition' => 'expanded', 'spellLists' => ['Bard'],
        'sourceBooks' => ['Expanded Book'],
    ])], JSON_THROW_ON_ERROR));
    file_put_contents($directory.'/c-modern.json', json_encode([catalogRecord([
        'identityKey' => 'split-spell', 'versionKey' => '2024:split-spell',
        'name' => 'Modern Split', 'edition' => '2024',
        'spellLists' => ['Wizard'], 'attackModes' => ['ranged_spell'],
        'saveAbilities' => [], 'sourceBooks' => ['Modern A'], 'tags' => ['alpha'],
    ])], JSON_THROW_ON_ERROR));
    file_put_contents($directory.'/d-modern-extra.json', json_encode([catalogRecord([
        'identityKey' => 'split-spell', 'versionKey' => '2024:split-spell',
        'name' => 'Modern Split', 'edition' => '2024',
        'spellLists' => ['Wizard', 'Cleric'], 'attackModes' => ['melee_spell'],
        'saveAbilities' => ['wisdom'], 'sourceBooks' => ['Modern B'], 'tags' => ['beta'],
    ])], JSON_THROW_ON_ERROR));

    app(CatalogImporter::class)->importDirectory($directory);

    $identity = DB::table('spell_identities')->where('content_key', 'split-spell')->sole();
    $modernId = (int) DB::table('spell_versions')->where('content_key', '2024:split-spell')->value('id');
    expect(data_get($identity, 'canonical_name'))->toBe('Modern Split')
        ->and((bool) DB::table('spell_versions')->where('id', $modernId)->value('healing'))->toBeFalse()
        ->and(DB::table('spell_versions')->where('spell_identity_id', data_get($identity, 'id'))->count())->toBe(3)
        ->and(DB::table('spell_list_memberships')->where('spell_version_id', $modernId)
            ->orderBy('spell_list_key')->pluck('spell_list_key')->all())->toBe(['Cleric', 'Wizard'])
        ->and(DB::table('spell_version_attack_modes')->where('spell_version_id', $modernId)
            ->orderBy('attack_mode')->pluck('attack_mode')->all())->toBe(['melee_spell', 'ranged_spell'])
        ->and(DB::table('spell_version_save_abilities')->where('spell_version_id', $modernId)
            ->pluck('save_ability')->all())->toBe(['wisdom'])
        ->and(DB::table('spell_version_tags')->where('spell_version_id', $modernId)
            ->orderBy('tag')->pluck('tag')->all())->toBe(['alpha', 'beta'])
        ->and(DB::table('spell_version_publications')->where('spell_version_id', $modernId)
            ->orderBy('source_book')->pluck('source_book')->all())->toBe(['Modern A', 'Modern B']);
});

it('updates every mutable version field and synchronizes publication and pivot removals', function () {
    $directory = writeCatalogFixture([catalogRecord([
        'sourceBooks' => ['Old Book', 'Retained Book'],
        'spellLists' => ['Wizard', 'Bard'], 'attackModes' => ['ranged_spell'],
        'saveAbilities' => ['wisdom'], 'tags' => ['old'],
    ])]);
    $importer = app(CatalogImporter::class);
    $importer->importDirectory($directory);

    file_put_contents($directory.'/catalog.json', json_encode([catalogRecord([
        'name' => 'Updated Spell', 'edition' => 'expanded', 'level' => 2, 'school' => 'Illusion',
        'castingTime' => 'Bonus Action or r', 'range' => 'Self', 'components' => 'V',
        'duration' => 'c, up to 1 minute', 'concentration' => false, 'ritual' => false,
        'attackModes' => ['melee_spell'], 'saveAbilities' => ['charisma'],
        'effectReliabilityCategory' => 'saving_throw', 'spellLists' => ['Cleric'],
        'sourceBooks' => ['Retained Book', 'New Book'], 'sourcePage' => 77,
        'sourceSlug' => 'updated-reference', 'tags' => ['new'], 'healing' => true,
    ])], JSON_THROW_ON_ERROR));
    $summary = $importer->importDirectory($directory);

    $version = DB::table('spell_versions')->where('content_key', '2024:test-spell')->sole();
    expect(data_get($summary, 'updated'))->toBe(1)
        ->and(collect((array) $version)->only([
            'display_name', 'rules_edition', 'level', 'school', 'ritual', 'concentration',
            'casting_time', 'range', 'duration', 'components', 'healing', 'effect_reliability_category',
        ])->all())->toBe([
            'display_name' => 'Updated Spell', 'rules_edition' => 'expanded', 'level' => 2,
            'school' => 'Illusion', 'ritual' => 0, 'concentration' => 0,
            'casting_time' => 'Bonus Action or r', 'range' => 'Self',
            'duration' => 'c, up to 1 minute', 'components' => 'V', 'healing' => 1,
            'effect_reliability_category' => 'saving_throw',
        ])->and(DB::table('spell_version_publications')->where('spell_version_id', data_get($version, 'id'))
        ->orderBy('source_book')->get(['source_book', 'source_page', 'source_reference'])
        ->map(static fn (object $row): array => (array) $row)->all())->toBe([
            ['source_book' => 'New Book', 'source_page' => 77, 'source_reference' => 'updated-reference'],
            ['source_book' => 'Retained Book', 'source_page' => 77, 'source_reference' => 'updated-reference'],
        ])
        ->and(DB::table('spell_list_memberships')->where('spell_version_id', data_get($version, 'id'))
            ->pluck('spell_list_key')->all())->toBe(['Cleric'])
        ->and(DB::table('spell_version_tags')->where('spell_version_id', data_get($version, 'id'))
            ->orderBy('tag')->pluck('tag')->all())->toBe(['concentration', 'new', 'ritual'])
        ->and(DB::table('spell_version_attack_modes')->where('spell_version_id', data_get($version, 'id'))
            ->pluck('attack_mode')->all())->toBe(['melee_spell'])
        ->and(DB::table('spell_version_save_abilities')->where('spell_version_id', data_get($version, 'id'))
            ->pluck('save_ability')->all())->toBe(['charisma']);

    $pivotOnly = catalogRecord([
        'name' => 'Updated Spell', 'edition' => 'expanded', 'level' => 2, 'school' => 'Illusion',
        'castingTime' => 'Bonus Action or r', 'range' => 'Self', 'components' => 'V',
        'duration' => 'c, up to 1 minute', 'concentration' => false, 'ritual' => false,
        'attackModes' => ['melee_spell'], 'saveAbilities' => ['charisma'],
        'effectReliabilityCategory' => 'saving_throw', 'spellLists' => ['Cleric', 'Druid'],
        'sourceBooks' => ['Retained Book', 'New Book'], 'sourcePage' => 88,
        'sourceSlug' => 'updated-reference', 'tags' => ['new'], 'healing' => true,
    ]);
    file_put_contents($directory.'/catalog.json', json_encode([$pivotOnly], JSON_THROW_ON_ERROR));
    $pivotSummary = $importer->importDirectory($directory);
    expect(data_get($pivotSummary, 'updated'))->toBe(1)
        ->and(DB::table('spell_list_memberships')->where('spell_version_id', data_get($version, 'id'))
            ->orderBy('spell_list_key')->pluck('spell_list_key')->all())->toBe(['Cleric', 'Druid'])
        ->and(DB::table('spell_version_publications')->where('spell_version_id', data_get($version, 'id'))
            ->pluck('source_page')->unique()->all())->toBe([88]);
});

it('normalizes surrounding and repeated whitespace when resolving an existing identity', function () {
    $directory = writeCatalogFixture([
        catalogRecord([
            'identityKey' => 'spaced-a', 'versionKey' => '2014:spaced-a',
            'name' => '  Shared   Name  ', 'edition' => '2014',
        ]),
        catalogRecord(['identityKey' => 'spaced-b', 'versionKey' => '2024:spaced-b', 'name' => 'shared name']),
    ]);

    app(CatalogImporter::class)->importDirectory($directory);

    expect(DB::table('spell_identities')->count())->toBe(1)
        ->and(DB::table('spell_versions')->pluck('spell_identity_id')->unique()->count())->toBe(1);
});

it('uses expanded names over legacy names', function () {
    $directory = writeCatalogFixture([
        catalogRecord([
            'identityKey' => 'priority-spell', 'versionKey' => '2014:priority-spell',
            'name' => 'Legacy Priority', 'edition' => '2014',
        ]),
        catalogRecord([
            'identityKey' => 'priority-spell', 'versionKey' => 'expanded:priority-spell',
            'name' => 'Expanded Priority', 'edition' => 'expanded',
        ]),
    ]);

    app(CatalogImporter::class)->importDirectory($directory);

    expect(DB::table('spell_identities')->where('content_key', 'priority-spell')->value('canonical_name'))
        ->toBe('Expanded Priority');
});

it('does not infer concentration from a non-leading duration marker', function () {
    $directory = writeCatalogFixture([catalogRecord([
        'duration' => 'Instantaneous C, after the effect', 'concentration' => false, 'tags' => [],
    ])]);

    app(CatalogImporter::class)->importDirectory($directory);

    expect(DB::table('spell_version_tags')->pluck('tag')->all())->not->toContain('concentration');
});

it('resolves aliases and reports canonical identity updates accurately', function () {
    $directory = writeCatalogFixture([catalogRecord(['name' => 'Original Alias Name'])]);
    $importer = app(CatalogImporter::class);
    $importer->importDirectory($directory);
    file_put_contents($directory.'/catalog.json', json_encode([
        catalogRecord(['name' => 'Renamed Canonical']),
    ], JSON_THROW_ON_ERROR));
    $rename = $importer->importDirectory($directory);
    file_put_contents($directory.'/catalog.json', json_encode([
        catalogRecord([
            'identityKey' => 'alias-target', 'versionKey' => 'expanded:alias-target',
            'name' => 'Original Alias Name', 'edition' => 'expanded',
        ]),
    ], JSON_THROW_ON_ERROR));
    $importer->importDirectory($directory);

    expect(data_get($rename, 'identities_updated'))->toBe(1)
        ->and(DB::table('spell_identities')->count())->toBe(1)
        ->and(DB::table('spell_versions')->pluck('spell_identity_id')->unique()->count())->toBe(1);
});

it('reports isolated publication and pivot changes and ignores pivot row order', function () {
    $directory = writeCatalogFixture([catalogRecord(['sourceBooks' => ['Book A']])]);
    $importer = app(CatalogImporter::class);
    $importer->importDirectory($directory);

    file_put_contents($directory.'/catalog.json', json_encode([
        catalogRecord(['sourceBooks' => ['Book A', 'Book B']]),
    ], JSON_THROW_ON_ERROR));
    expect(data_get($importer->importDirectory($directory), 'updated'))->toBe(1);

    file_put_contents($directory.'/catalog.json', json_encode([
        catalogRecord(['sourceBooks' => ['Book A', 'Book B'], 'sourcePage' => 77]),
    ], JSON_THROW_ON_ERROR));
    expect(data_get($importer->importDirectory($directory), 'updated'))->toBe(1);

    file_put_contents($directory.'/catalog.json', json_encode([
        catalogRecord(['sourceBooks' => ['Book A'], 'sourcePage' => 77]),
    ], JSON_THROW_ON_ERROR));
    expect(data_get($importer->importDirectory($directory), 'updated'))->toBe(1);

    file_put_contents($directory.'/catalog.json', json_encode([
        catalogRecord(['sourceBooks' => ['Book A'], 'sourcePage' => 77, 'spellLists' => ['Wizard', 'Cleric']]),
    ], JSON_THROW_ON_ERROR));
    expect(data_get($importer->importDirectory($directory), 'updated'))->toBe(1);

    $versionId = (int) DB::table('spell_versions')->where('content_key', '2024:test-spell')->value('id');
    DB::table('spell_list_memberships')->where('spell_version_id', $versionId)->delete();
    foreach (['Wizard', 'Cleric'] as $list) {
        DB::table('spell_list_memberships')->insert([
            'spell_version_id' => $versionId, 'spell_list_key' => $list,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    expect(data_get($importer->importDirectory($directory), 'updated'))->toBe(0);
});

it('preserves the wrapped JSON error as the previous exception with code zero', function () {
    $directory = writeCatalogFixture([catalogRecord()]);
    file_put_contents($directory.'/catalog.json', '{');

    try {
        app(CatalogImporter::class)->importDirectory($directory);
        $this->fail('The invalid catalog JSON should have thrown.');
    } catch (InvalidArgumentException $exception) {
        expect($exception->getCode())->toBe(0)
            ->and($exception->getPrevious())->toBeInstanceOf(JsonException::class);
    }
});

it('preserves imported metadata for every non-slot reference surface', function (string $reference): void {
    $directory = writeCatalogFixture([catalogRecord()]);
    $importer = app(CatalogImporter::class);
    $importer->importDirectory($directory);
    $version = DB::table('spell_versions')->where('content_key', '2024:test-spell')->sole();
    $characterId = (int) DB::table('characters')->insertGetId([
        'name' => 'Reference '.$reference, 'created_at' => now(), 'updated_at' => now(),
    ]);
    if ($reference === 'spellbook') {
        DB::table('wizard_spellbook_entries')->insert([
            'character_id' => $characterId, 'spell_version_id' => data_get($version, 'id'),
            'acquisition' => 'copied', 'created_at' => now(), 'updated_at' => now(),
        ]);
    } elseif ($reference === 'loadout') {
        $loadoutId = DB::table('spell_loadouts')->insertGetId([
            'character_id' => $characterId, 'name' => 'Reference',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('spell_loadout_entries')->insert([
            'spell_loadout_id' => $loadoutId, 'spell_version_id' => data_get($version, 'id'),
            'role' => 'prepared', 'created_at' => now(), 'updated_at' => now(),
        ]);
    } else {
        DB::table('character_spell_preferences')->insert([
            'character_id' => $characterId, 'spell_version_id' => data_get($version, 'id'),
            'favourite' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    file_put_contents($directory.'/catalog.json', json_encode([
        catalogRecord(['name' => 'Must Not Replace', 'school' => 'Necromancy']),
    ], JSON_THROW_ON_ERROR));
    $importer->importDirectory($directory);

    $preserved = DB::table('spell_versions')->where('id', data_get($version, 'id'))->sole();
    expect(data_get($preserved, 'display_name'))->toBe('Test Spell')
        ->and(data_get($preserved, 'school'))->toBe('Evocation');
})->with(['spellbook', 'loadout', 'preference']);

it('exposes catalog import as a dry-run artisan command without writing', function () {
    $this->artisan('catalog:import', ['--dry-run' => true])
        ->expectsOutputToContain('DRY RUN')
        ->expectsOutputToContain('Created versions: 943')
        ->assertSuccessful();

    expect(DB::table('spell_versions')->count())->toBe(0);
});
