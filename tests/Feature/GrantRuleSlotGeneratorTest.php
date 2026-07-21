<?php

declare(strict_types=1);

use App\Domain\Grants\GrantRuleSlotGenerator;
use App\Domain\Spells\DuplicateWarningDetector;
use App\Domain\Spells\SpellAccessBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function grantCharacter(string $name = 'Grant Test'): int
{
    return DB::table('characters')->insertGetId([
        'name' => $name,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function grantSpell(string $versionKey, string $name, int $level = 0, array $lists = [], array $tags = []): int
{
    $identityKey = Str::after($versionKey, ':');
    $identityId = DB::table('spell_identities')->where('content_key', $identityKey)->value('id');
    if ($identityId === null) {
        $identityId = DB::table('spell_identities')->insertGetId([
            'content_key' => $identityKey,
            'canonical_name' => $name,
            'normalized_name' => Str::lower($name),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    $versionId = DB::table('spell_versions')->insertGetId([
        'content_key' => $versionKey,
        'spell_identity_id' => $identityId,
        'display_name' => $name,
        'rules_edition' => Str::before($versionKey, ':'),
        'level' => $level,
        'school' => 'Conjuration',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    foreach ($lists as $list) {
        DB::table('spell_list_memberships')->insert([
            'spell_version_id' => $versionId,
            'spell_list_key' => $list,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
    foreach ($tags as $tag) {
        DB::table('spell_version_tags')->insert([
            'spell_version_id' => $versionId,
            'tag' => $tag,
        ]);
    }

    return $versionId;
}

/** @param list<array<string, mixed>> $rules */
function grantDefinition(string $table, string $contentKey, string $name, array $rules, bool $repeatable = false): int
{
    return DB::table($table)->insertGetId([
        'content_key' => $contentKey,
        'name' => $name,
        'rules_edition' => '2024',
        'repeatable' => $repeatable,
        'grant_rules' => json_encode($rules, JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/** @param array<string, mixed> $config */
function grantSource(int $characterId, string $sourceType, int $definitionId, array $config = [], ?int $parentId = null): int
{
    return DB::table('character_source_instances')->insertGetId([
        'character_id' => $characterId,
        'instance_uuid' => Str::uuid()->toString(),
        'parent_source_instance_id' => $parentId,
        'source_type' => $sourceType,
        'source_definition_id' => $definitionId,
        'display_name' => Str::headline($sourceType),
        'config' => json_encode($config, JSON_THROW_ON_ERROR),
        'state' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('materializes a fixed spell as one locked slot without a current selection', function () {
    $spellId = grantSpell('2024:mage-hand', 'Mage Hand');
    $featId = grantDefinition('feat_definitions', '2024:feat:gift', 'Arcane Gift', [[
        'kind' => 'fixed_spell',
        'rule_key' => 'gift-mage-hand',
        'bucket' => 'automatic',
        'spell_version_key' => '2024:mage-hand',
        'always_prepared' => true,
        'with_slots' => false,
    ]]);
    $sourceId = grantSource(grantCharacter(), 'feat', $featId);
    $source = DB::table('character_source_instances')->find($sourceId);

    app(GrantRuleSlotGenerator::class)->generateForSource($sourceId);

    $slot = DB::table('spell_selection_slots')->sole();
    expect(data_get($slot, 'slot_key'))->toBe(data_get($source, 'instance_uuid').':gift-mage-hand:1')
        ->and((int) data_get($slot, 'fixed_spell_version_id'))->toBe($spellId)
        ->and(data_get($slot, 'current_spell_version_id'))->toBeNull()
        ->and((bool) data_get($slot, 'is_locked'))->toBeTrue()
        ->and((bool) data_get($slot, 'always_prepared'))->toBeTrue()
        ->and((bool) data_get($slot, 'with_slots'))->toBeFalse();
});

it('materializes a counted list choice with one-based stable slot keys', function () {
    $featId = grantDefinition('feat_definitions', '2024:feat:list-choice', 'List Choice', [[
        'kind' => 'choice_from_list',
        'rule_key' => 'three-cantrips',
        'count' => 3,
        'bucket' => 'cantrip_known',
        'list' => 'Wizard',
        'level_min' => 0,
        'level_max' => 0,
        'with_slots' => false,
    ]]);
    $sourceId = grantSource(grantCharacter(), 'feat', $featId);
    $uuid = DB::table('character_source_instances')->where('id', $sourceId)->value('instance_uuid');

    app(GrantRuleSlotGenerator::class)->generateForSource($sourceId);

    $slots = DB::table('spell_selection_slots')->orderBy('ordinal')->get();
    expect($slots)->toHaveCount(3)
        ->and($slots->pluck('ordinal')->all())->toBe([1, 2, 3])
        ->and($slots->pluck('slot_key')->all())->toBe([
            "{$uuid}:three-cantrips:1",
            "{$uuid}:three-cantrips:2",
            "{$uuid}:three-cantrips:3",
        ])
        ->and(json_decode((string) data_get($slots->first(), 'allowed_spell_lists'), true))->toBe(['Wizard'])
        ->and((bool) data_get($slots->first(), 'is_locked'))->toBeFalse();
});

it('keeps slot keys and row ids stable when source configuration changes eligibility', function () {
    $mageHandId = grantSpell('2024:mage-hand', 'Mage Hand', 0, ['Wizard']);
    $featId = grantDefinition('feat_definitions', '2024:feat:magic-initiate', 'Magic Initiate', [[
        'kind' => 'choice_from_list',
        'rule_key' => 'initiate-cantrips',
        'count' => 2,
        'bucket' => 'cantrip_known',
        'list' => '$config.chosen_list',
        'level_min' => 0,
        'level_max' => 0,
        'with_slots' => false,
    ]], true);
    $sourceId = grantSource(grantCharacter(), 'feat', $featId, ['chosen_list' => 'Wizard']);
    $generator = app(GrantRuleSlotGenerator::class);
    $generator->generateForSource($sourceId);
    $before = DB::table('spell_selection_slots')->orderBy('ordinal')->get();
    DB::table('spell_selection_slots')->where('id', data_get($before->first(), 'id'))->update([
        'current_spell_version_id' => $mageHandId,
    ]);

    DB::table('character_source_instances')->where('id', $sourceId)->update([
        'config' => json_encode(['chosen_list' => 'Cleric'], JSON_THROW_ON_ERROR),
    ]);
    $generator->generateForSource($sourceId);

    $after = DB::table('spell_selection_slots')->orderBy('ordinal')->get();
    expect($after->pluck('id')->all())->toBe($before->pluck('id')->all())
        ->and($after->pluck('slot_key')->all())->toBe($before->pluck('slot_key')->all())
        ->and(json_decode((string) data_get($after->first(), 'allowed_spell_lists'), true))->toBe(['Cleric'])
        ->and((int) data_get($after->first(), 'current_spell_version_id'))->toBe($mageHandId);
});

it('is idempotent and orphans then reactivates surplus ordinals across level changes', function () {
    $characterId = grantCharacter();
    $wizardId = DB::table('class_definitions')->insertGetId([
        'content_key' => '2024:class:wizard',
        'name' => 'Wizard',
        'rules_edition' => '2024',
        'progression_type' => 'full',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $rule = fn (int $count): string => json_encode([[
        'kind' => 'choice_from_list',
        'rule_key' => 'wizard-cantrips',
        'count' => $count,
        'bucket' => 'cantrip_known',
        'list' => 'Wizard',
        'level_min' => 0,
        'level_max' => 0,
    ]], JSON_THROW_ON_ERROR);
    foreach ([1 => 2, 4 => 3] as $level => $count) {
        DB::table('class_progressions')->insert([
            'class_definition_id' => $wizardId,
            'class_level' => $level,
            'grant_rules' => $rule($count),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
    DB::table('character_class_levels')->insert([
        'character_id' => $characterId,
        'class_definition_id' => $wizardId,
        'level' => 4,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $sourceId = grantSource($characterId, 'class', $wizardId);
    $generator = app(GrantRuleSlotGenerator::class);

    $generator->generateForSource($sourceId);
    $first = DB::table('spell_selection_slots')->orderBy('ordinal')->get();
    $generator->generateForSource($sourceId);
    $second = DB::table('spell_selection_slots')->orderBy('ordinal')->get();
    expect($second->map(fn ($slot) => (array) $slot)->all())
        ->toBe($first->map(fn ($slot) => (array) $slot)->all());

    DB::table('character_class_levels')->where('character_id', $characterId)->update(['level' => 1]);
    $generator->generateForSource($sourceId);
    $orphan = DB::table('spell_selection_slots')->where('ordinal', 3)->first();
    expect(data_get($orphan, 'state'))->toBe('orphaned')
        ->and(data_get($orphan, 'orphan_reason_code'))->toBe('rule_count_reduced')
        ->and(data_get($orphan, 'orphaned_at'))->not->toBeNull();

    DB::table('character_class_levels')->where('character_id', $characterId)->update(['level' => 4]);
    $generator->generateForSource($sourceId);
    $reactivated = DB::table('spell_selection_slots')->where('ordinal', 3)->first();
    expect(data_get($reactivated, 'id'))->toBe(data_get($orphan, 'id'))
        ->and(data_get($reactivated, 'state'))->toBe('active')
        ->and(data_get($reactivated, 'orphaned_at'))->toBeNull();
});

it('materializes query choices with school tag and level predicates', function () {
    $featId = grantDefinition('feat_definitions', '2024:feat:ritual-scholar', 'Ritual Scholar', [[
        'kind' => 'choice_from_query',
        'rule_key' => 'divination-rituals',
        'count' => 2,
        'bucket' => 'known',
        'schools' => ['Divination'],
        'tags' => ['ritual'],
        'level_min' => 1,
        'level_max' => 3,
    ]]);
    $sourceId = grantSource(grantCharacter(), 'feat', $featId);

    app(GrantRuleSlotGenerator::class)->generateForSource($sourceId);

    $slots = DB::table('spell_selection_slots')->orderBy('ordinal')->get();
    expect($slots)->toHaveCount(2)
        ->and(data_get($slots->first(), 'eligibility_kind'))->toBe('choice_from_query')
        ->and(json_decode((string) data_get($slots->first(), 'allowed_schools'), true))->toBe(['Divination'])
        ->and(json_decode((string) data_get($slots->first(), 'allowed_tags'), true))->toBe(['ritual'])
        ->and((int) data_get($slots->first(), 'spell_level_min'))->toBe(1)
        ->and((int) data_get($slots->first(), 'spell_level_max'))->toBe(3);
});

it('produces a nested species to origin feat to spell chain through grant_source', function () {
    $featId = grantDefinition('feat_definitions', '2024:feat:magic-initiate', 'Magic Initiate', [[
        'kind' => 'choice_from_list',
        'rule_key' => 'initiate-cantrips',
        'count' => 2,
        'bucket' => 'cantrip_known',
        'list' => '$config.chosen_list',
        'level_min' => 0,
        'level_max' => 0,
    ]], true);
    $speciesId = grantDefinition('species_definitions', '2024:species:human', 'Human', [[
        'kind' => 'grant_source',
        'rule_key' => 'human-origin-feat',
        'source_type' => 'feat',
        'definition_key_config' => 'origin_feat_key',
        'child_config_config' => 'origin_feat_config',
    ]]);
    $characterId = grantCharacter();
    $humanSourceId = grantSource($characterId, 'species', $speciesId, [
        'origin_feat_key' => '2024:feat:magic-initiate',
        'origin_feat_config' => ['chosen_list' => 'Wizard'],
    ]);

    app(GrantRuleSlotGenerator::class)->generateForSource($humanSourceId);

    $child = DB::table('character_source_instances')
        ->where('parent_source_instance_id', $humanSourceId)
        ->sole();
    expect(data_get($child, 'source_type'))->toBe('feat')
        ->and((int) data_get($child, 'source_definition_id'))->toBe($featId)
        ->and(json_decode((string) data_get($child, 'config'), true))->toBe(['chosen_list' => 'Wizard'])
        ->and(data_get($child, 'notes'))->toBe('grant_rule:human-origin-feat:1')
        ->and(DB::table('spell_selection_slots')->where('source_instance_id', data_get($child, 'id'))->count())->toBe(2);
});

it('activates a rule below at and above its class-level unlock', function () {
    $characterId = grantCharacter();
    $classId = DB::table('class_definitions')->insertGetId([
        'content_key' => '2024:class:wizard', 'name' => 'Wizard', 'rules_edition' => '2024',
        'progression_type' => 'full', 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('class_progressions')->insert([
        'class_definition_id' => $classId,
        'class_level' => 1,
        'grant_rules' => json_encode([[
            'kind' => 'choice_from_list', 'rule_key' => 'level-three-spell', 'count' => 1,
            'bucket' => 'known', 'list' => 'Wizard', 'level_min' => 1, 'level_max' => 1,
            'active_from_class_level' => 3,
        ]], JSON_THROW_ON_ERROR),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('character_class_levels')->insert([
        'character_id' => $characterId, 'class_definition_id' => $classId, 'level' => 2,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $sourceId = grantSource($characterId, 'class', $classId);
    $generator = app(GrantRuleSlotGenerator::class);

    $generator->generateForSource($sourceId);
    expect(DB::table('spell_selection_slots')->count())->toBe(0);

    DB::table('character_class_levels')->where('character_id', $characterId)->update(['level' => 3]);
    $generator->generateForSource($sourceId);
    expect(DB::table('spell_selection_slots')->where('state', 'active')->count())->toBe(1);

    DB::table('character_class_levels')->where('character_id', $characterId)->update(['level' => 4]);
    $generator->generateForSource($sourceId);
    expect(DB::table('spell_selection_slots')->where('state', 'active')->count())->toBe(1);
});

it('enforces distinct repeatable source configuration by chosen list', function () {
    $characterId = grantCharacter();
    $featId = grantDefinition('feat_definitions', '2024:feat:magic-initiate', 'Magic Initiate', [[
        'kind' => 'choice_from_list', 'rule_key' => 'initiate-cantrips', 'count' => 2,
        'bucket' => 'cantrip_known', 'list' => '$config.chosen_list',
        'level_min' => 0, 'level_max' => 0, 'distinct_config_by' => 'chosen_list',
    ]], true);
    $generator = app(GrantRuleSlotGenerator::class);
    $wizard = grantSource($characterId, 'feat', $featId, ['chosen_list' => 'Wizard']);
    $generator->generateForSource($wizard);

    $duplicateWizard = grantSource($characterId, 'feat', $featId, ['chosen_list' => 'Wizard']);
    expect(fn () => $generator->generateForSource($duplicateWizard))->toThrow(
        InvalidArgumentException::class,
        "Magic Initiate already uses chosen_list 'Wizard' for this character.",
    );

    $druid = grantSource($characterId, 'feat', $featId, ['chosen_list' => 'Druid']);
    $generator->generateForSource($druid);
    expect(DB::table('spell_selection_slots')->where('source_instance_id', $druid)->count())->toBe(2);
});

it('records six starting wizard spells and a later copy with provenance', function () {
    $characterId = grantCharacter();
    $spellKeys = [
        '2024:detect-magic', '2024:feather-fall', '2024:mage-armor',
        '2024:magic-missile', '2024:sleep', '2024:thunderwave', '2024:find-familiar',
    ];
    foreach ($spellKeys as $key) {
        grantSpell($key, Str::headline(Str::after($key, ':')), 1, ['Wizard']);
    }
    $featId = grantDefinition('feat_definitions', '2024:feat:wizard-spellbook-test', 'Wizard Spellbook', [[
        'kind' => 'spellbook_acquisition',
        'rule_key' => 'wizard-spellbook',
        'bucket' => 'spellbook',
        'list' => 'Wizard',
        'acquisitions_config' => 'wizard_spellbook_acquisitions',
    ]]);
    $starting = array_map(fn (string $key): array => [
        'spell_version_key' => $key,
        'acquisition' => 'starting',
    ], array_slice($spellKeys, 0, 6));
    $sourceId = grantSource($characterId, 'feat', $featId, [
        'wizard_spellbook_acquisitions' => $starting,
    ]);
    $generator = app(GrantRuleSlotGenerator::class);

    $generator->generateForSource($sourceId);
    $beforeIds = DB::table('wizard_spellbook_entries')->orderBy('id')->pluck('id')->all();
    expect($beforeIds)->toHaveCount(6)
        ->and(DB::table('spell_selection_slots')->count())->toBe(0);

    $withCopy = [...$starting, [
        'spell_version_key' => '2024:find-familiar',
        'acquisition' => 'copied',
        'copy_cost_gp' => 50,
        'copy_time_hours' => 2,
        'notes' => 'Copied from Merla’s spellbook.',
    ]];
    DB::table('character_source_instances')->where('id', $sourceId)->update([
        'config' => json_encode(['wizard_spellbook_acquisitions' => $withCopy], JSON_THROW_ON_ERROR),
    ]);
    $generator->generateForSource($sourceId);

    $entries = DB::table('wizard_spellbook_entries')->orderBy('id')->get();
    $copy = $entries->last();
    expect($entries)->toHaveCount(7)
        ->and($entries->take(6)->pluck('id')->all())->toBe($beforeIds)
        ->and(data_get($copy, 'acquisition'))->toBe('copied')
        ->and((int) data_get($copy, 'copy_cost_gp'))->toBe(50)
        ->and((int) data_get($copy, 'copy_time_hours'))->toBe(2)
        ->and((int) data_get($copy, 'source_instance_id'))->toBe($sourceId)
        ->and(data_get($copy, 'notes'))->toBe('Copied from Merla’s spellbook.');
});

it('keeps capabilities out of slots and computes wizard ritual access from the live spellbook', function () {
    $characterId = grantCharacter();
    $preparedRitualId = grantSpell('2024:prepared-ritual', 'Prepared Ritual', 1, ['Wizard'], ['ritual']);
    $unpreparedRitualId = grantSpell('2024:unprepared-ritual', 'Unprepared Ritual', 1, ['Wizard'], ['ritual']);
    $ordinaryId = grantSpell('2024:ordinary-spell', 'Ordinary Spell', 1, ['Wizard']);
    $wizardFeatureId = grantDefinition('feat_definitions', '2024:feat:wizard-features', 'Wizard Features', [[
        'kind' => 'capability',
        'rule_key' => 'ritual-adept',
        'capability_key' => 'wizard-ritual-adept',
        'collection' => 'wizard_spellbook',
        'tags' => ['ritual'],
        'access_mode' => 'ritual_only',
    ]]);
    $sourceId = grantSource($characterId, 'feat', $wizardFeatureId, ['spellcasting_ability' => 'intelligence']);
    app(GrantRuleSlotGenerator::class)->generateForSource($sourceId);
    expect(DB::table('spell_selection_slots')->count())->toBe(0);

    $entryIds = [];
    foreach ([$preparedRitualId, $unpreparedRitualId, $ordinaryId] as $spellId) {
        $entryIds[] = DB::table('wizard_spellbook_entries')->insertGetId([
            'character_id' => $characterId,
            'spell_version_id' => $spellId,
            'acquisition' => 'starting',
            'source_instance_id' => $sourceId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
    DB::table('wizard_prepared_entries')->insert([
        'character_id' => $characterId,
        'wizard_spellbook_entry_id' => $entryIds[0],
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $routes = app(SpellAccessBuilder::class)->buildForCharacter($characterId);
    expect($routes)->toHaveCount(2)
        ->and(collect($routes)->pluck('spell_name')->all())->toBe(['Prepared Ritual', 'Unprepared Ritual']);
    $prepared = collect($routes)->firstWhere('spell_version_id', $preparedRitualId);
    $ritualOnly = collect($routes)->firstWhere('spell_version_id', $unpreparedRitualId);
    expect(data_get($prepared, 'casting_mode'))->toBe('with_slots')
        ->and(data_get($prepared, 'origin'))->toBe('wizard_prepared')
        ->and(data_get($ritualOnly, 'casting_mode'))->toBe('ritual_only')
        ->and(data_get($ritualOnly, 'origin'))->toBe('capability')
        ->and(data_get($ritualOnly, 'is_selection'))->toBeFalse()
        ->and(data_get($ritualOnly, 'counts_against_limit'))->toBeFalse();
});

it('never treats a ritual-only capability route as a wasteful duplicate selection', function () {
    $characterId = grantCharacter();
    $ritualId = grantSpell('2024:detect-magic', 'Detect Magic', 1, ['Wizard'], ['ritual']);
    $definitionId = grantDefinition('feat_definitions', '2024:feat:ritual-overlap', 'Ritual Overlap', [
        [
            'kind' => 'fixed_spell', 'rule_key' => 'fixed-detect-magic', 'bucket' => 'automatic',
            'spell_version_key' => '2024:detect-magic', 'counts_against_limit' => true,
        ],
        [
            'kind' => 'capability', 'rule_key' => 'ritual-adept',
            'capability_key' => 'wizard-ritual-adept', 'collection' => 'wizard_spellbook',
            'tags' => ['ritual'], 'access_mode' => 'ritual_only',
        ],
    ]);
    $sourceId = grantSource($characterId, 'feat', $definitionId, ['spellcasting_ability' => 'intelligence']);
    DB::table('wizard_spellbook_entries')->insert([
        'character_id' => $characterId, 'spell_version_id' => $ritualId,
        'acquisition' => 'starting', 'source_instance_id' => $sourceId,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    app(GrantRuleSlotGenerator::class)->generateForSource($sourceId);

    $assessment = app(DuplicateWarningDetector::class)->classify(
        app(SpellAccessBuilder::class)->buildForCharacter($characterId),
    );

    expect($assessment)->toHaveCount(1)
        ->and(data_get($assessment[0], 'category'))->toBe('none')
        ->and(data_get($assessment[0], 'selection_count'))->toBe(1);
});
