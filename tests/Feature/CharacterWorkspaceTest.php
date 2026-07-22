<?php

declare(strict_types=1);

use App\Domain\Characters\CharacterListBuilder;
use App\Domain\Characters\CharacterState;
use App\Domain\Characters\CharacterWorkspaceBuilder;
use App\Domain\Characters\Commands\CharacterCommandIntegrity;
use App\Domain\Characters\EligibleSpellSearch;
use App\Domain\Reports\BuildReportBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed();
});

function workspaceCharacterId(): int
{
    return (int) DB::table('characters')->where('notes', 'seed:a6')->value('id');
}

/** @param array<string, mixed> $command */
function mutateCharacter($test, int $characterId, int $revision, array $command, ?string $operation = null)
{
    return $test->postJson("/characters/{$characterId}/mutations", [
        'operation_uuid' => $operation ?? Str::uuid()->toString(),
        'expected_revision' => $revision,
        'command' => $command,
    ]);
}

/** @return array{character_id: int, source_id: int} */
function orderClassSource($test, string $className = 'Cleric'): array
{
    $characterId = (int) DB::table('characters')->insertGetId([
        'name' => "{$className} Order Authoring", 'created_at' => now(), 'updated_at' => now(),
    ]);
    $classId = (int) DB::table('class_definitions')->where('name', $className)->value('id');
    mutateCharacter($test, $characterId, 0, [
        'type' => 'add_source', 'source_type' => 'class',
        'source_definition_id' => $classId, 'config' => ['level' => 1],
    ])->assertOk()->assertJsonPath('revision', 1);

    return [
        'character_id' => $characterId,
        'source_id' => (int) DB::table('character_source_instances')
            ->where('character_id', $characterId)->where('source_type', 'class')->value('id'),
    ];
}

it('serves the seeded character list and editable workspace', function () {
    $id = workspaceCharacterId();
    $this->get('/')->assertOk()->assertInertia(fn ($page) => $page
        ->component('Characters/Index')
        ->where('characters.0.name', 'A6 Sixfold Spellcaster')
        ->where('characters.0.level', 6)
    );
    $this->get("/characters/{$id}")->assertOk()->assertInertia(fn ($page) => $page
        ->component('Characters/Workspace')
        ->where('workspace.report.caster.caster_level', 6)
        ->where('workspace.report.character.proficiency_bonus', 3)
        ->where('workspace.source_catalog.feat.0.configuration_kind', 'magic_initiate')
        ->where('workspace.source_catalog.species.0.configuration_kind', 'origin_feat_magic_initiate')
        ->where('workspace.source_catalog.background.0.configuration_kind', 'origin_feat_magic_initiate')
        ->has('workspace.order_sources', 2)
        ->has('workspace.removable_sources', 4)
        ->has('workspace.slots')
    );
});

it('builds the complete character list card contract in deterministic order', function () {
    $characterId = workspaceCharacterId();
    $muttId = (int) DB::table('characters')->where('notes', 'like', "seed:mutt\n%")->value('id');

    expect(app(CharacterListBuilder::class)->build())->toBe([
        [
            'id' => $characterId,
            'name' => 'A6 Sixfold Spellcaster',
            'level' => 6,
            'classes' => ['Bard 1', 'Cleric 1', 'Druid 1', 'Paladin 1', 'Sorcerer 1', 'Wizard 1'],
            'warning_count' => 1,
        ],
        [
            'id' => $muttId,
            'name' => 'Mutt (SRD)',
            'level' => 6,
            'classes' => ['Bard 1', 'Cleric 1', 'Druid 1', 'Paladin 1', 'Sorcerer 1', 'Wizard 1'],
            'warning_count' => 0,
        ],
    ]);
});

it('builds the complete workspace editing contract for the seeded character', function () {
    $characterId = workspaceCharacterId();
    $workspace = app(CharacterWorkspaceBuilder::class)->build($characterId);

    expect(array_keys($workspace))->toBe([
        'revision', 'report', 'classes', 'available_classes', 'allow_legacy',
        'configurable_sources', 'order_sources', 'source_catalog', 'removable_sources', 'spell_lists',
        'slots', 'save_points',
    ])->and(data_get($workspace, 'revision'))->toBe(0)
        ->and(data_get($workspace, 'allow_legacy'))->toBeFalse()
        ->and(data_get($workspace, 'spell_lists'))->toBe(['Cleric', 'Druid', 'Wizard'])
        ->and(data_get($workspace, 'save_points'))->toBe([])
        ->and(data_get($workspace, 'report.summary'))->toBe([
            'unique_spells' => 11, 'access_routes' => 12, 'warning_count' => 1,
        ]);

    $classes = collect(data_get($workspace, 'classes'));
    expect($classes->pluck('name')->all())->toBe(['Bard', 'Cleric', 'Druid', 'Paladin', 'Sorcerer', 'Wizard'])
        ->and($classes->pluck('id')->all())->toBe(
            DB::table('character_class_levels as level')
                ->join('class_definitions as class', 'class.id', '=', 'level.class_definition_id')
                ->where('level.character_id', $characterId)->orderBy('class.name')->pluck('level.id')->all(),
        )
        ->and($classes->map(static fn (array $class): array => array_keys($class))->unique()->values()->all())->toBe([[
            'id', 'class_definition_id', 'subclass_definition_id', 'level', 'name', 'subclass_name', 'subclasses',
        ]])
        ->and($classes->every(static fn (array $class): bool => data_get($class, 'level') === 1
            && data_get($class, 'subclass_definition_id') === null
            && data_get($class, 'subclass_name') === null
            && data_get($class, 'subclasses') === []))->toBeTrue();

    expect(collect(data_get($workspace, 'available_classes'))->pluck('name')->all())->toBe([
        'Barbarian', 'Bard', 'Cleric', 'Druid', 'Fighter', 'Monk',
        'Paladin', 'Ranger', 'Rogue', 'Sorcerer', 'Warlock', 'Wizard',
    ])->and(collect(data_get($workspace, 'available_classes'))->every(
        static fn (array $class): bool => array_keys($class) === ['id', 'name'] && is_int(data_get($class, 'id')),
    ))->toBeTrue();
    expect(collect(data_get($workspace, 'available_classes'))->pluck('id')->every(
        static fn (mixed $id): bool => is_int($id) && $id > 0,
    ))->toBeTrue();

    $configurable = collect(data_get($workspace, 'configurable_sources'))->map(
        static fn (array $source): array => collect($source)->except('id')->all(),
    )->all();
    expect($configurable)->toBe([
        ['display_name' => 'Magic Initiate: Wizard', 'chosen_list' => 'Wizard', 'spellcasting_ability' => 'intelligence'],
        ['display_name' => 'Magic Initiate: Druid', 'chosen_list' => 'Druid', 'spellcasting_ability' => 'wisdom'],
    ])->and(collect(data_get($workspace, 'source_catalog'))->map(
        static fn (array $definitions): array => collect($definitions)->map(
            static fn (array $definition): array => collect($definition)->except('id')->all(),
        )->all(),
    )->all())->toBe([
        'feat' => [[
            'content_key' => '2024:feat:magic-initiate', 'name' => 'Magic Initiate',
            'repeatable' => true, 'configuration_kind' => 'magic_initiate',
        ]],
        'species' => [[
            'content_key' => '2024:species:human', 'name' => 'Human',
            'repeatable' => false, 'configuration_kind' => 'origin_feat_magic_initiate',
        ]],
        'background' => [[
            'content_key' => '2024:background:custom', 'name' => 'Custom Background',
            'repeatable' => false, 'configuration_kind' => 'origin_feat_magic_initiate',
        ]],
    ]);
    expect(collect(data_get($workspace, 'configurable_sources'))->pluck('id')->every(
        static fn (mixed $id): bool => is_int($id) && $id > 0,
    ))->toBeTrue();

    $orderSources = collect(data_get($workspace, 'order_sources'));
    expect($orderSources->map(static fn (array $source): array => collect($source)->except('id')->all())->all())
        ->toBe([
            [
                'class_name' => 'Cleric', 'display_name' => 'Cleric 1',
                'order_name' => 'Divine Order', 'chosen_option' => null,
                'options' => ['Protector', 'Thaumaturge'], 'bonus_option' => 'Thaumaturge',
            ],
            [
                'class_name' => 'Druid', 'display_name' => 'Druid 1',
                'order_name' => 'Primal Order', 'chosen_option' => null,
                'options' => ['Warden', 'Magician'], 'bonus_option' => 'Magician',
            ],
        ])->and($orderSources->pluck('id')->every(
            static fn (mixed $id): bool => is_int($id) && $id > 0,
        ))->toBeTrue();

    $removable = collect(data_get($workspace, 'removable_sources'))->map(
        static fn (array $source): array => [
            'source_type' => data_get($source, 'source_type'),
            'display_name' => data_get($source, 'display_name'),
            'is_child' => data_get($source, 'parent_source_instance_id') !== null,
            'keys' => array_keys($source),
        ],
    )->all();
    expect($removable)->toBe([
        ['source_type' => 'background', 'display_name' => 'Custom Background', 'is_child' => false, 'keys' => ['id', 'parent_source_instance_id', 'source_type', 'source_definition_id', 'display_name']],
        ['source_type' => 'feat', 'display_name' => 'Magic Initiate: Druid', 'is_child' => true, 'keys' => ['id', 'parent_source_instance_id', 'source_type', 'source_definition_id', 'display_name']],
        ['source_type' => 'feat', 'display_name' => 'Magic Initiate: Wizard', 'is_child' => true, 'keys' => ['id', 'parent_source_instance_id', 'source_type', 'source_definition_id', 'display_name']],
        ['source_type' => 'species', 'display_name' => 'Human', 'is_child' => false, 'keys' => ['id', 'parent_source_instance_id', 'source_type', 'source_definition_id', 'display_name']],
    ])->and(collect(data_get($workspace, 'removable_sources'))->pluck('id')->every(
        static fn (mixed $id): bool => is_int($id) && $id > 0,
    ))->toBeTrue();

    $slots = collect(data_get($workspace, 'slots'));
    expect($slots)->toHaveCount(40)
        ->and($slots->map(static fn (array $slot): array => array_keys($slot))->unique()->values()->all())->toBe([[
            'id', 'slot_key', 'source', 'source_type', 'label', 'bucket', 'level_min', 'level_max',
            'spell_id', 'spell_name', 'spell_level', 'spell_edition', 'ability', 'attack_bonus', 'save_dc', 'ritual',
            'concentration', 'duplicate_status', 'state', 'eligibility', 'invalid_reason', 'orphan_reason',
            'override_note', 'locked',
        ]]);
    $bardCantrip = $slots->first(
        static fn (array $slot): bool => data_get($slot, 'source') === 'Bard 1'
            && data_get($slot, 'label') === 'Cantrip Known 1',
    );
    expect(collect($bardCantrip)->except(['id', 'slot_key'])->all())->toBe([
        'source' => 'Bard 1', 'source_type' => 'class', 'label' => 'Cantrip Known 1',
        'bucket' => 'cantrip_known', 'level_min' => 0, 'level_max' => 0,
        'spell_id' => null, 'spell_name' => null, 'spell_level' => null, 'spell_edition' => null,
        'ability' => 'charisma', 'attack_bonus' => null, 'save_dc' => null,
        'ritual' => false, 'concentration' => false, 'duplicate_status' => 'none',
        'state' => 'active', 'eligibility' => 'unselected', 'invalid_reason' => null,
        'orphan_reason' => null, 'override_note' => null, 'locked' => false,
    ]);
});

it('shows only mechanically relevant casting math from each selected spell source', function () {
    $characterId = (int) DB::table('characters')->where('notes', 'like', "seed:mutt\n%")->value('id');
    $slots = collect(data_get(app(CharacterWorkspaceBuilder::class)->build($characterId), 'slots'))
        ->keyBy('spell_name');

    expect($slots->get('Bane'))->toMatchArray([
        'ability' => 'charisma', 'attack_bonus' => null, 'save_dc' => 14,
        'concentration' => true, 'ritual' => false,
    ])->and($slots->get('Chromatic Orb'))->toMatchArray([
        'ability' => 'charisma', 'attack_bonus' => 6, 'save_dc' => null, 'spell_edition' => '2024',
    ])->and($slots->get('Mage Hand'))->toMatchArray([
        'ability' => 'intelligence', 'attack_bonus' => null, 'save_dc' => null,
    ])->and($slots->get('Find Familiar'))->toMatchArray([
        'ability' => 'intelligence', 'attack_bonus' => null, 'save_dc' => null,
        'concentration' => false, 'ritual' => true,
    ]);
});

it('counts invalid and override selections in both workspace and list warnings', function () {
    $characterId = workspaceCharacterId();
    $slotId = (int) DB::table('spell_selection_slots')->where('character_id', $characterId)
        ->where('rule_key', 'wizard-cantrips')->where('ordinal', 1)->value('id');
    DB::table('spell_selection_slots')->where('id', $slotId)->update([
        'state' => 'kept_override',
        'selection_eligibility' => 'invalid',
        'selection_invalid_reason' => 'Deliberate test override.',
        'override_note' => 'Accepted for this build.',
    ]);

    $workspace = app(CharacterWorkspaceBuilder::class)->build($characterId);
    expect(data_get($workspace, 'report.invalid_selections'))->toHaveCount(1)
        ->and(data_get($workspace, 'report.invalid_selections.0.id'))->toBe($slotId)
        ->and(data_get($workspace, 'report.summary'))->toBe([
            'unique_spells' => 11, 'access_routes' => 12, 'warning_count' => 2,
        ])->and(data_get(app(CharacterListBuilder::class)->build(), '0.warning_count'))->toBe(2);
});

it('returns exact save-point and subclass option identities', function () {
    $characterId = workspaceCharacterId();
    $wizardId = (int) DB::table('class_definitions')->where('name', 'Wizard')->value('id');
    $subclassId = (int) DB::table('subclass_definitions')->insertGetId([
        'content_key' => 'test:wizard:mutation-school',
        'class_definition_id' => $wizardId,
        'name' => 'Mutation School',
        'rules_edition' => '2024',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $createdAt = '2026-07-22 12:34:56';
    $pointId = (int) DB::table('character_save_points')->insertGetId([
        'character_id' => $characterId,
        'label' => 'Mutation checkpoint',
        'snapshot' => '{}',
        'schema_version' => 'a7-v1',
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);

    $workspace = app(CharacterWorkspaceBuilder::class)->build($characterId);
    $wizard = collect(data_get($workspace, 'classes'))->firstWhere('name', 'Wizard');
    expect(data_get($wizard, 'subclasses'))->toBe([[
        'id' => $subclassId, 'name' => 'Mutation School',
    ]])->and(data_get($workspace, 'save_points'))->toBe([[
        'id' => $pointId, 'label' => 'Mutation checkpoint', 'created_at' => $createdAt,
    ]]);
});

it('returns an exact eligible-spell DTO and treats wildcard characters literally', function () {
    $characterId = workspaceCharacterId();
    $slotId = (int) DB::table('spell_selection_slots')->where('character_id', $characterId)
        ->where('rule_key', 'wizard-cantrips')->where('ordinal', 1)->value('id');
    $mageHandId = (int) DB::table('spell_versions')->where('content_key', '2024:mage-hand')->value('id');
    $search = app(EligibleSpellSearch::class);

    expect($search->search($characterId, $slotId, 'Mage'))->toBe([[
        'id' => $mageHandId,
        'name' => 'Mage Hand',
        'level' => 0,
        'school' => 'Conjuration',
        'ritual' => false,
        'concentration' => false,
        'edition' => '2024',
    ]])->and($search->search($characterId, $slotId, 'Hand'))->toBe([[
        'id' => $mageHandId,
        'name' => 'Mage Hand',
        'level' => 0,
        'school' => 'Conjuration',
        'ritual' => false,
        'concentration' => false,
        'edition' => '2024',
    ]])->and($search->search($characterId, $slotId, '%'))->toBe([])
        ->and($search->search($characterId, $slotId, '_'))->toBe([]);
});

it('applies the legacy edition switch to eligible spell search', function () {
    $characterId = workspaceCharacterId();
    $slotId = (int) DB::table('spell_selection_slots')->where('character_id', $characterId)
        ->where('rule_key', 'wizard-cantrips')->where('ordinal', 1)->value('id');
    $search = app(EligibleSpellSearch::class);

    expect(collect($search->search($characterId, $slotId, 'Chill Touch'))->pluck('edition')->all())
        ->toBe(['2024']);
    DB::table('characters')->where('id', $characterId)->update(['allow_legacy' => true]);
    expect(collect($search->search($characterId, $slotId, 'Chill Touch'))->pluck('edition')->all())
        ->toBe(['2014', '2024']);
});

it('returns exactly the first fifty eligible search results in stable order', function () {
    $characterId = workspaceCharacterId();
    $slotId = (int) DB::table('spell_selection_slots')->where('character_id', $characterId)
        ->where('rule_key', 'wizard-cantrips')->where('ordinal', 1)->value('id');
    for ($index = 0; $index < 51; $index++) {
        $name = sprintf('Cap Probe %02d', $index);
        $identityId = (int) DB::table('spell_identities')->insertGetId([
            'content_key' => "cap-probe-{$index}",
            'canonical_name' => $name,
            'normalized_name' => strtolower($name),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $versionId = (int) DB::table('spell_versions')->insertGetId([
            'content_key' => "2024:cap-probe-{$index}",
            'spell_identity_id' => $identityId,
            'display_name' => $name,
            'rules_edition' => '2024',
            'level' => 0,
            'school' => 'Evocation',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('spell_list_memberships')->insert([
            'spell_version_id' => $versionId,
            'spell_list_key' => 'Wizard',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    $results = app(EligibleSpellSearch::class)->search($characterId, $slotId, 'Cap Probe');
    expect($results)->toHaveCount(50)
        ->and(collect($results)->pluck('name')->all())->toBe(array_map(
            static fn (int $index): string => sprintf('Cap Probe %02d', $index),
            range(0, 49),
        ));
});

it('applies list eligibility before the fifty-result search cap', function () {
    $characterId = workspaceCharacterId();
    $slotId = (int) DB::table('spell_selection_slots')->where('character_id', $characterId)
        ->where('rule_key', 'wizard-cantrips')->where('ordinal', 1)->value('id');

    for ($index = 0; $index < 50; $index++) {
        $name = sprintf('Crowding Cantrip %02d', $index);
        $identityId = (int) DB::table('spell_identities')->insertGetId([
            'content_key' => "crowding-cantrip-{$index}", 'canonical_name' => $name,
            'normalized_name' => strtolower($name), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('spell_versions')->insert([
            'content_key' => "2024:crowding-cantrip-{$index}", 'spell_identity_id' => $identityId,
            'display_name' => $name, 'rules_edition' => '2024', 'level' => 0,
            'school' => 'Evocation', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    $eligibleIdentityId = (int) DB::table('spell_identities')->insertGetId([
        'content_key' => 'crowding-cantrip-valid', 'canonical_name' => 'Crowding Cantrip Z Valid',
        'normalized_name' => 'crowding cantrip z valid', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $eligibleVersionId = (int) DB::table('spell_versions')->insertGetId([
        'content_key' => '2024:crowding-cantrip-valid', 'spell_identity_id' => $eligibleIdentityId,
        'display_name' => 'Crowding Cantrip Z Valid', 'rules_edition' => '2024', 'level' => 0,
        'school' => 'Evocation', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('spell_list_memberships')->insert([
        'spell_version_id' => $eligibleVersionId, 'spell_list_key' => 'Wizard',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(app(EligibleSpellSearch::class)->search($characterId, $slotId, 'Crowding Cantrip'))->toBe([[
        'id' => $eligibleVersionId, 'name' => 'Crowding Cantrip Z Valid', 'level' => 0,
        'school' => 'Evocation', 'ritual' => false, 'concentration' => false, 'edition' => '2024',
    ]]);

    DB::table('spell_selection_slots')->where('id', $slotId)->update(['allowed_spell_lists' => null]);
    expect(collect(app(EligibleSpellSearch::class)->search($characterId, $slotId, 'Crowding Cantrip'))
        ->pluck('name')->first())->toBe('Crowding Cantrip 00');
});

it('captures every restorable character table and reports exact state differences', function () {
    $characterId = workspaceCharacterId();
    $state = app(CharacterState::class);
    $captured = $state->capture($characterId);

    expect(array_keys($captured))->toBe([
        'schema_version', 'character', 'character_class_levels', 'character_source_instances',
        'spell_selection_slots', 'wizard_spellbook_entries', 'warning_acknowledgements',
    ])->and(data_get($captured, 'schema_version'))->toBe('a7-v1')
        ->and(array_keys(data_get($captured, 'character')))->toBe([
            'name', 'strength', 'dexterity', 'constitution', 'intelligence', 'wisdom', 'charisma',
            'proficiency_bonus_override', 'rules_edition_preference', 'allow_legacy', 'notes',
        ])->and(data_get($captured, 'character_class_levels'))->toHaveCount(6)
        ->and(data_get($captured, 'character_source_instances'))->toHaveCount(10)
        ->and(data_get($captured, 'spell_selection_slots'))->toHaveCount(40)
        ->and(data_get($captured, 'wizard_spellbook_entries'))->toHaveCount(6)
        ->and(data_get($captured, 'warning_acknowledgements'))->toBe([]);

    $before = [
        'character' => ['name' => 'Before'],
        'character_class_levels' => [['id' => 2, 'level' => 1]],
        'character_source_instances' => [['id' => 4, 'state' => 'active']],
        'spell_selection_slots' => [],
        'wizard_spellbook_entries' => [['id' => 8, 'spell_version_id' => 10]],
        'warning_acknowledgements' => [],
    ];
    $after = [
        'character' => ['name' => 'After'],
        'character_class_levels' => [['id' => 2, 'level' => 2]],
        'character_source_instances' => [],
        'spell_selection_slots' => [['id' => 6, 'state' => 'active']],
        'wizard_spellbook_entries' => [['id' => 8, 'spell_version_id' => 10]],
        'warning_acknowledgements' => [],
    ];
    expect($state->diff($before, $after))->toBe([
        ['entity_type' => 'character', 'entity_id' => null, 'previous_value' => ['name' => 'Before'], 'new_value' => ['name' => 'After']],
        ['entity_type' => 'character_class_levels', 'entity_id' => 2, 'previous_value' => ['id' => 2, 'level' => 1], 'new_value' => ['id' => 2, 'level' => 2]],
        ['entity_type' => 'character_source_instances', 'entity_id' => 4, 'previous_value' => ['id' => 4, 'state' => 'active'], 'new_value' => null],
        ['entity_type' => 'spell_selection_slots', 'entity_id' => 6, 'previous_value' => null, 'new_value' => ['id' => 6, 'state' => 'active']],
    ]);
});

it('rejects each malformed snapshot boundary before deleting live state', function (Closure $mutate, string $message) {
    $characterId = workspaceCharacterId();
    $state = app(CharacterState::class);
    $snapshot = $state->capture($characterId);
    $before = $snapshot;
    $mutate($snapshot, $characterId);

    expect(fn () => $state->restore($characterId, $snapshot))->toThrow(RuntimeException::class, $message)
        ->and($state->capture($characterId))->toBe($before);
})->with([
    'schema version' => [
        static function (array &$snapshot): void {
            $snapshot['schema_version'] = 'old';
        },
        'Unsupported character snapshot schema.',
    ],
    'character object' => [
        static function (array &$snapshot): void {
            $snapshot['character'] = 'invalid';
        },
        'Character snapshot is missing character data.',
    ],
    'missing character field' => [
        static function (array &$snapshot): void {
            unset($snapshot['character']['notes']);
        },
        'Character snapshot is missing notes.',
    ],
    'table is not a list' => [
        static function (array &$snapshot): void {
            $snapshot['character_class_levels'] = ['bad' => []];
        },
        'Snapshot table character_class_levels must be a list.',
    ],
    'row is not an object' => [
        static function (array &$snapshot): void {
            $snapshot['character_source_instances'][0] = 'bad';
        },
        'Snapshot table character_source_instances contains an invalid row.',
    ],
    'missing row owner' => [
        static function (array &$snapshot): void {
            unset($snapshot['spell_selection_slots'][0]['character_id']);
        },
        'Snapshot table spell_selection_slots contains a row belonging to another character.',
    ],
    'wrong row owner type' => [
        static function (array &$snapshot): void {
            $snapshot['wizard_spellbook_entries'][0]['character_id'] = '1';
        },
        'Snapshot table wizard_spellbook_entries contains a row belonging to another character.',
    ],
    'wrong row owner' => [
        static function (array &$snapshot, int $characterId): void {
            $snapshot['character_class_levels'][0]['character_id'] = $characterId + 1;
        },
        'Snapshot table character_class_levels contains a row belonging to another character.',
    ],
    'zero selected spell' => [
        static function (array &$snapshot): void {
            $snapshot['spell_selection_slots'][0]['current_spell_version_id'] = 0;
        },
        'Snapshot table spell_selection_slots contains an invalid current_spell_version_id.',
    ],
    'string fixed spell' => [
        static function (array &$snapshot): void {
            $snapshot['spell_selection_slots'][0]['fixed_spell_version_id'] = '1';
        },
        'Snapshot table spell_selection_slots contains an invalid fixed_spell_version_id.',
    ],
    'zero spellbook spell' => [
        static function (array &$snapshot): void {
            $snapshot['wizard_spellbook_entries'][0]['spell_version_id'] = 0;
        },
        'Snapshot table wizard_spellbook_entries contains an invalid spell_version_id.',
    ],
]);

it('restores active spell version id one at both slot and spellbook boundaries', function () {
    $characterId = workspaceCharacterId();
    expect((bool) DB::table('spell_versions')->where('id', 1)->value('is_active'))->toBeTrue();
    $state = app(CharacterState::class);
    $snapshot = $state->capture($characterId);
    $slotIndex = collect(data_get($snapshot, 'spell_selection_slots'))->search(
        static fn (array $slot): bool => data_get($slot, 'fixed_spell_version_id') === null,
    );
    expect($slotIndex)->not->toBeFalse();
    $snapshot['spell_selection_slots'][$slotIndex]['current_spell_version_id'] = 1;
    $snapshot['wizard_spellbook_entries'][0]['spell_version_id'] = 1;

    $state->restore($characterId, $snapshot);

    expect(data_get($state->capture($characterId), "spell_selection_slots.{$slotIndex}.current_spell_version_id"))->toBe(1)
        ->and(data_get($state->capture($characterId), 'wizard_spellbook_entries.0.spell_version_id'))->toBe(1);
});

it('restores character metadata time and removes acknowledgements absent from the snapshot', function () {
    $characterId = workspaceCharacterId();
    $state = app(CharacterState::class);
    $snapshot = $state->capture($characterId);
    DB::table('characters')->where('id', $characterId)->update(['updated_at' => '2000-01-01 00:00:00']);
    DB::table('warning_acknowledgements')->insert([
        'character_id' => $characterId, 'warning_fingerprint' => 'stale-after-snapshot',
        'note' => 'Must be removed', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $state->restore($characterId, $snapshot);

    expect((string) DB::table('characters')->where('id', $characterId)->value('updated_at'))
        ->not->toBe('2000-01-01 00:00:00')
        ->and(DB::table('warning_acknowledgements')->where('character_id', $characterId)->count())->toBe(0);
});

it('creates and opens an empty character without additional setup', function () {
    $response = $this->post('/characters', ['name' => 'Fresh Build'])->assertRedirect();
    $location = (string) $response->headers->get('Location');
    $this->get($location)->assertOk()->assertInertia(fn ($page) => $page
        ->component('Characters/Workspace')
        ->where('workspace.report.character.name', 'Fresh Build')
        ->where('workspace.report.character.character_level', 0)
        ->where('workspace.slots', [])
    );
});

it('changes one slot while leaving every other slot byte-identical', function () {
    $characterId = workspaceCharacterId();
    $slot = DB::table('spell_selection_slots')->where('character_id', $characterId)
        ->where('rule_key', 'wizard-cantrips')->where('ordinal', 1)->sole();
    $replacement = DB::table('spell_versions')->where('content_key', '2024:prestidigitation')->value('id');
    $before = DB::table('spell_selection_slots')->where('character_id', $characterId)
        ->where('id', '!=', data_get($slot, 'id'))->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();

    mutateCharacter($this, $characterId, 0, [
        'type' => 'set_slot', 'slot_id' => data_get($slot, 'id'), 'mode' => 'select',
        'spell_version_id' => $replacement,
    ])->assertOk()->assertJsonPath('revision', 1);

    $after = DB::table('spell_selection_slots')->where('character_id', $characterId)
        ->where('id', '!=', data_get($slot, 'id'))->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
    expect($after)->toBe($before)
        ->and((int) DB::table('spell_selection_slots')->where('id', data_get($slot, 'id'))->value('current_spell_version_id'))
        ->toBe((int) $replacement);
});

it('undo restores the prior spell selection', function () {
    $characterId = workspaceCharacterId();
    $slot = DB::table('spell_selection_slots')->where('character_id', $characterId)
        ->where('rule_key', 'wizard-cantrips')->where('ordinal', 1)->sole();
    $original = (int) data_get($slot, 'current_spell_version_id');
    $replacement = (int) DB::table('spell_versions')->where('content_key', '2024:prestidigitation')->value('id');
    $changed = mutateCharacter($this, $characterId, 0, [
        'type' => 'set_slot', 'slot_id' => data_get($slot, 'id'), 'mode' => 'select',
        'spell_version_id' => $replacement,
    ])->assertOk();

    $inverse = $changed->json('inverse');
    expect(array_keys($inverse))->toBe(['type', 'slot_id', 'mode', 'state', 'integrity'])
        ->and(collect($inverse)->except('integrity')->all())->toBe([
            'type' => 'set_slot',
            'slot_id' => (int) data_get($slot, 'id'),
            'mode' => 'restore',
            'state' => [
                'current_spell_version_id' => $original,
                'selection_eligibility' => data_get($slot, 'selection_eligibility'),
                'selection_invalid_reason' => data_get($slot, 'selection_invalid_reason'),
                'state' => data_get($slot, 'state'),
                'override_note' => data_get($slot, 'override_note'),
            ],
        ]);
    app(CharacterCommandIntegrity::class)->assertValid($characterId, $inverse);

    mutateCharacter($this, $characterId, 1, $inverse)
        ->assertOk()->assertJsonPath('revision', 2);

    expect((int) DB::table('spell_selection_slots')->where('id', data_get($slot, 'id'))->value('current_spell_version_id'))
        ->toBe($original);
});

it('timestamps slot changes and preserves the rule-specific orphan explanation on restore', function () {
    $characterId = workspaceCharacterId();
    $slot = DB::table('spell_selection_slots')->where('character_id', $characterId)
        ->where('rule_key', 'wizard-cantrips')->whereNotNull('current_spell_version_id')->first();
    $slotId = (int) data_get($slot, 'id');
    $spellVersionId = (int) data_get($slot, 'current_spell_version_id');
    DB::table('spell_selection_slots')->where('id', $slotId)->update([
        'state' => 'orphaned', 'selection_eligibility' => 'invalid',
        'selection_invalid_reason' => null, 'orphan_reason_code' => 'rule_no_longer_active',
        'updated_at' => '2000-01-01 00:00:00',
    ]);
    $command = app(CharacterCommandIntegrity::class)->attach($characterId, [
        'type' => 'set_slot', 'slot_id' => $slotId, 'mode' => 'restore',
        'state' => [
            'current_spell_version_id' => $spellVersionId, 'selection_eligibility' => 'valid',
            'selection_invalid_reason' => null, 'state' => 'active', 'override_note' => null,
        ],
    ]);

    mutateCharacter($this, $characterId, 0, $command)->assertOk();

    $restored = DB::table('spell_selection_slots')->where('id', $slotId)->sole();
    expect(data_get($restored, 'state'))->toBe('orphaned')
        ->and(data_get($restored, 'selection_invalid_reason'))
        ->toBe('Selection preserved because its grant rule is no longer active.')
        ->and((string) data_get($restored, 'updated_at'))->not->toBe('2000-01-01 00:00:00');
});

it('clears, overrides, and reselects a slot with exact persisted state', function () {
    $characterId = workspaceCharacterId();
    $slot = DB::table('spell_selection_slots')->where('character_id', $characterId)
        ->where('rule_key', 'wizard-cantrips')->where('ordinal', 1)->sole();
    $slotId = (int) data_get($slot, 'id');
    $spellId = (int) data_get($slot, 'current_spell_version_id');

    mutateCharacter($this, $characterId, 0, [
        'type' => 'set_slot', 'slot_id' => $slotId, 'mode' => 'clear',
    ])->assertOk();
    $cleared = DB::table('spell_selection_slots')->where('id', $slotId)->sole();
    expect(data_get($cleared, 'current_spell_version_id'))->toBeNull()
        ->and(data_get($cleared, 'selection_eligibility'))->toBe('unselected')
        ->and(data_get($cleared, 'selection_invalid_reason'))->toBeNull()
        ->and(data_get($cleared, 'state'))->toBe('active')
        ->and(data_get($cleared, 'override_note'))->toBeNull();

    mutateCharacter($this, $characterId, 1, [
        'type' => 'set_slot', 'slot_id' => $slotId, 'mode' => 'select',
        'spell_version_id' => $spellId,
    ])->assertOk();
    mutateCharacter($this, $characterId, 2, [
        'type' => 'set_slot', 'slot_id' => $slotId, 'mode' => 'keep_override',
        'note' => '  Deliberate exception.  ',
    ])->assertOk();
    $overridden = DB::table('spell_selection_slots')->where('id', $slotId)->sole();
    expect(data_get($overridden, 'state'))->toBe('kept_override')
        ->and(data_get($overridden, 'override_note'))->toBe('Deliberate exception.');

    mutateCharacter($this, $characterId, 3, [
        'type' => 'set_slot', 'slot_id' => $slotId, 'mode' => 'select',
        'spell_version_id' => $spellId,
    ])->assertOk();
    $reselected = DB::table('spell_selection_slots')->where('id', $slotId)->sole();
    expect(data_get($reselected, 'state'))->toBe('active')
        ->and(data_get($reselected, 'override_note'))->toBeNull();
});

it('rejects missing and locked slots with their public domain messages', function () {
    $characterId = workspaceCharacterId();
    $missingSlotId = (int) DB::table('spell_selection_slots')->max('id') + 1000;
    mutateCharacter($this, $characterId, 0, [
        'type' => 'set_slot', 'slot_id' => $missingSlotId, 'mode' => 'clear',
    ])->assertUnprocessable()->assertJsonPath('message', 'Spell slot does not belong to this character.');

    $slotId = (int) DB::table('spell_selection_slots')->where('character_id', $characterId)->value('id');
    DB::table('spell_selection_slots')->where('id', $slotId)->update(['is_locked' => true]);
    mutateCharacter($this, $characterId, 0, [
        'type' => 'set_slot', 'slot_id' => $slotId, 'mode' => 'clear',
    ])->assertUnprocessable()->assertJsonPath('message', 'This spell slot is locked.');
});

it('round-trips a named save point through the mutation path', function () {
    $characterId = workspaceCharacterId();
    $this->postJson("/characters/{$characterId}/save-points", ['label' => 'Before experiment'])
        ->assertCreated()->assertJsonPath('workspace.save_points.0.label', 'Before experiment');
    $savePointId = (int) DB::table('character_save_points')->where('character_id', $characterId)->value('id');

    mutateCharacter($this, $characterId, 0, [
        'type' => 'update_ability', 'ability' => 'intelligence', 'score' => 20,
    ])->assertOk();
    $restore = $this->getJson("/characters/{$characterId}/save-points/{$savePointId}/command")
        ->assertOk()->json('command');
    mutateCharacter($this, $characterId, 1, $restore)->assertOk()->assertJsonPath('revision', 2);

    expect((int) DB::table('characters')->where('id', $characterId)->value('intelligence'))->toBe(13);
});

it('changing an ability score recomputes only mechanically relevant casting math', function () {
    $characterId = workspaceCharacterId();
    $slotIds = DB::table('spell_selection_slots')->where('character_id', $characterId)
        ->where('rule_key', 'wizard-cantrips')->whereIn('ordinal', [1, 2])
        ->orderBy('ordinal')->pluck('id');
    $acidSplashId = (int) DB::table('spell_versions')->where('content_key', '2024:acid-splash')->value('id');
    $fireBoltId = (int) DB::table('spell_versions')->where('content_key', '2024:fire-bolt')->value('id');
    DB::table('spell_selection_slots')->where('id', $slotIds->get(0))
        ->update(['current_spell_version_id' => $acidSplashId]);
    DB::table('spell_selection_slots')->where('id', $slotIds->get(1))
        ->update(['current_spell_version_id' => $fireBoltId]);

    $response = mutateCharacter($this, $characterId, 0, [
        'type' => 'update_ability', 'ability' => 'intelligence', 'score' => 18,
    ])->assertOk();
    $slots = collect($response->json('workspace.slots'))->keyBy('id');
    expect($slots->get($slotIds->get(0)))->toMatchArray(['save_dc' => 15, 'attack_bonus' => null])
        ->and($slots->get($slotIds->get(1)))->toMatchArray(['save_dc' => null, 'attack_bonus' => 7]);
});

it('returns the exact mutation envelope, inverse, operation, and reversible audit contract', function () {
    $characterId = workspaceCharacterId();
    $operation = Str::uuid()->toString();
    $response = mutateCharacter($this, $characterId, 0, [
        'type' => 'update_ability', 'ability' => 'wisdom', 'score' => 16,
        'reason' => 'Mutation contract.',
    ], $operation)->assertOk();

    expect(array_keys($response->json()))->toBe(['inverse', 'revision', 'idempotent_replay', 'workspace'])
        ->and($response->json('inverse'))->toBe([
            'type' => 'update_ability', 'ability' => 'wisdom', 'score' => 13,
        ])->and($response->json('revision'))->toBe(1)
        ->and($response->json('idempotent_replay'))->toBeFalse();

    $storedOperation = DB::table('character_operations')->where('operation_uuid', $operation)->sole();
    expect((int) data_get($storedOperation, 'character_id'))->toBe($characterId)
        ->and((int) data_get($storedOperation, 'expected_revision'))->toBe(0)
        ->and((int) data_get($storedOperation, 'resulting_revision'))->toBe(1)
        ->and(json_decode((string) data_get($storedOperation, 'inverse_command'), true, 512, JSON_THROW_ON_ERROR))
        ->toBe($response->json('inverse'));

    $audit = DB::table('change_log')->where('operation_uuid', $operation)->sole();
    expect((int) data_get($audit, 'sequence'))->toBe(1)
        ->and((bool) data_get($audit, 'reversible'))->toBeTrue()
        ->and(data_get($audit, 'reason'))->toBe('Mutation contract.')
        ->and(data_get($audit, 'action_type'))->toBe('update_ability');

    $replay = mutateCharacter($this, $characterId, 0, [
        'type' => 'update_ability', 'ability' => 'charisma', 'score' => 30,
    ], $operation)->assertOk();
    expect($replay->json('inverse'))->toBe($response->json('inverse'))
        ->and($replay->json('revision'))->toBe(1)
        ->and($replay->json('idempotent_replay'))->toBeTrue();
});

it('enforces ability names and both inclusive score boundaries', function (): void {
    $characterId = workspaceCharacterId();

    foreach ([1, 30] as $index => $score) {
        mutateCharacter($this, $characterId, $index, [
            'type' => 'update_ability', 'ability' => 'strength', 'score' => $score,
        ])->assertOk()->assertJsonPath('inverse', [
            'type' => 'update_ability', 'ability' => 'strength', 'score' => $index === 0 ? 10 : 1,
        ]);
    }
    foreach ([0, 31] as $score) {
        mutateCharacter($this, $characterId, 2, [
            'type' => 'update_ability', 'ability' => 'strength', 'score' => $score,
        ])->assertUnprocessable()->assertJsonPath('message', 'Ability scores must be between 1 and 30.');
    }
    mutateCharacter($this, $characterId, 2, [
        'type' => 'update_ability', 'ability' => 'luck', 'score' => 10,
    ])->assertUnprocessable()->assertJsonPath('message', 'Unknown ability score.');
});

it('returns not found without recording an operation for an unknown character', function (): void {
    $missingId = (int) DB::table('characters')->max('id') + 1000;

    mutateCharacter($this, $missingId, 0, [
        'type' => 'update_ability', 'ability' => 'wisdom', 'score' => 16,
    ])->assertNotFound();
    expect(DB::table('character_operations')->where('character_id', $missingId)->count())->toBe(0);
});

it('accepts class level twenty but rejects invalid and total-level overflow boundaries', function (): void {
    $wizardId = (int) DB::table('class_definitions')->where('name', 'Wizard')->value('id');
    $unknownId = (int) DB::table('class_definitions')->max('id') + 1000;
    $characterId = (int) DB::table('characters')->insertGetId([
        'name' => 'Class Boundary', 'created_at' => now(), 'updated_at' => now(),
    ]);

    mutateCharacter($this, $characterId, 0, [
        'type' => 'update_class', 'class_definition_id' => $unknownId, 'level' => 1,
    ])->assertUnprocessable()->assertJsonPath('message', 'Unknown class.');
    mutateCharacter($this, $characterId, 0, [
        'type' => 'update_class', 'class_definition_id' => $wizardId, 'level' => 0,
    ])->assertUnprocessable()->assertJsonPath('message', 'Class level must be between 1 and 20.');
    mutateCharacter($this, $characterId, 0, [
        'type' => 'update_class', 'class_definition_id' => $wizardId, 'level' => 20,
    ])->assertOk()->assertJsonPath('revision', 1);
    expect((int) DB::table('character_class_levels')->where('character_id', $characterId)->value('level'))
        ->toBe(20);

    $warlockId = (int) DB::table('class_definitions')->where('name', 'Warlock')->value('id');
    mutateCharacter($this, $characterId, 1, [
        'type' => 'update_class', 'class_definition_id' => $warlockId, 'level' => 1,
    ])->assertUnprocessable()->assertJsonPath('message', 'A character cannot exceed level 20.');
});

it('records the first class at level one and rejects a subclass from another class', function (): void {
    $characterId = (int) DB::table('characters')->insertGetId([
        'name' => 'First Class Contract', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $wizardId = (int) DB::table('class_definitions')->where('name', 'Wizard')->value('id');
    $fighterId = (int) DB::table('class_definitions')->where('name', 'Fighter')->value('id');
    $foreignSubclassId = (int) DB::table('subclass_definitions')->insertGetId([
        'content_key' => 'test:fighter:foreign', 'class_definition_id' => $fighterId,
        'name' => 'Foreign Fighter Subclass', 'rules_edition' => '2024',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    mutateCharacter($this, $characterId, 0, [
        'type' => 'update_class', 'class_definition_id' => $wizardId,
        'level' => 1, 'subclass_definition_id' => $foreignSubclassId,
    ])->assertUnprocessable()->assertJsonPath('message', 'That subclass does not belong to the selected class.');
    mutateCharacter($this, $characterId, 0, [
        'type' => 'update_class', 'class_definition_id' => $wizardId,
        'level' => 1, 'subclass_definition_id' => null,
    ])->assertOk();

    expect((int) DB::table('character_source_instances')->where('character_id', $characterId)
        ->where('source_type', 'class')->value('acquired_at_character_level'))->toBe(1);
});

it('preserves nullable class config and fully synchronizes repeated subclass switches', function (): void {
    $characterId = (int) DB::table('characters')->insertGetId([
        'name' => 'Subclass Switch Contract', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $wizardId = (int) DB::table('class_definitions')->where('name', 'Wizard')->value('id');
    DB::table('character_source_instances')->insert([
        'character_id' => $characterId, 'instance_uuid' => Str::uuid()->toString(),
        'source_type' => 'class', 'source_definition_id' => $wizardId,
        'display_name' => 'Wizard pending', 'config' => null, 'state' => 'active',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $subclassA = (int) DB::table('subclass_definitions')->insertGetId([
        'content_key' => 'test:wizard:switch-a', 'class_definition_id' => $wizardId,
        'name' => 'Switch A', 'rules_edition' => '2024', 'spellcasting_ability' => 'intelligence',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $subclassB = (int) DB::table('subclass_definitions')->insertGetId([
        'content_key' => 'test:wizard:switch-b', 'class_definition_id' => $wizardId,
        'name' => 'Switch B', 'rules_edition' => '2024', 'spellcasting_ability' => 'intelligence',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    mutateCharacter($this, $characterId, 0, [
        'type' => 'update_class', 'class_definition_id' => $wizardId,
        'level' => 3, 'subclass_definition_id' => $subclassA,
    ])->assertOk();
    $sourceAId = (int) DB::table('character_source_instances')->where('character_id', $characterId)
        ->where('source_type', 'subclass')->where('source_definition_id', $subclassA)->value('id');
    DB::table('character_source_instances')->where('id', $sourceAId)->update([
        'config' => json_encode(['custom' => 'preserved'], JSON_THROW_ON_ERROR),
    ]);
    mutateCharacter($this, $characterId, 1, [
        'type' => 'update_class', 'class_definition_id' => $wizardId,
        'level' => 4, 'subclass_definition_id' => $subclassB,
    ])->assertOk();
    DB::table('subclass_definitions')->where('id', $subclassA)->update(['name' => 'Switch A Renamed']);
    mutateCharacter($this, $characterId, 2, [
        'type' => 'update_class', 'class_definition_id' => $wizardId,
        'level' => 5, 'subclass_definition_id' => $subclassA,
    ])->assertOk();

    $sources = DB::table('character_source_instances')->where('character_id', $characterId)
        ->where('source_type', 'subclass')->get()->keyBy('source_definition_id');
    expect(data_get($sources->get($subclassA), 'state'))->toBe('active')
        ->and(data_get($sources->get($subclassA), 'display_name'))->toBe('Switch A Renamed')
        ->and(json_decode((string) data_get($sources->get($subclassA), 'config'), true, 512, JSON_THROW_ON_ERROR))
        ->toBe(['custom' => 'preserved', 'spellcasting_ability' => 'intelligence'])
        ->and(data_get($sources->get($subclassB), 'state'))->toBe('tombstoned');

    DB::table('character_source_instances')->whereIn('id', [
        data_get($sources->get($subclassA), 'id'), data_get($sources->get($subclassB), 'id'),
    ])->update(['state' => 'active']);
    DB::table('character_source_instances')->where('id', data_get($sources->get($subclassA), 'id'))
        ->update(['config' => null]);
    mutateCharacter($this, $characterId, 3, [
        'type' => 'update_class', 'class_definition_id' => $wizardId,
        'level' => 5, 'subclass_definition_id' => $subclassA,
    ])->assertOk();
    expect(DB::table('character_source_instances')->where('id', data_get($sources->get($subclassB), 'id'))
        ->value('state'))->toBe('tombstoned');

    DB::table('character_source_instances')->whereIn('id', [
        data_get($sources->get($subclassA), 'id'), data_get($sources->get($subclassB), 'id'),
    ])->update(['state' => 'active']);
    mutateCharacter($this, $characterId, 4, [
        'type' => 'update_class', 'class_definition_id' => $wizardId,
        'level' => 5, 'subclass_definition_id' => $subclassB,
    ])->assertOk();
    expect(DB::table('character_source_instances')->where('id', data_get($sources->get($subclassA), 'id'))
        ->value('state'))->toBe('tombstoned');

    mutateCharacter($this, $characterId, 5, [
        'type' => 'update_class', 'class_definition_id' => $wizardId,
        'level' => 5, 'subclass_definition_id' => null,
    ])->assertOk();
    expect(DB::table('character_source_instances')->whereIn('id', [
        data_get($sources->get($subclassA), 'id'), data_get($sources->get($subclassB), 'id'),
    ])->pluck('state')->unique()->all())->toBe(['tombstoned']);
});

it('adding a class level generates new slots without disturbing existing slots', function () {
    $characterId = workspaceCharacterId();
    $warlockId = (int) DB::table('class_definitions')->where('name', 'Warlock')->value('id');
    $before = DB::table('spell_selection_slots')->where('character_id', $characterId)
        ->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();

    mutateCharacter($this, $characterId, 0, [
        'type' => 'update_class', 'class_definition_id' => $warlockId,
        'level' => 1, 'subclass_definition_id' => null,
    ])->assertOk()->assertJsonPath('workspace.report.caster.pact_magic.count', 1);

    $afterExisting = DB::table('spell_selection_slots')->where('character_id', $characterId)
        ->whereIn('id', array_column($before, 'id'))->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
    expect($afterExisting)->toBe($before)
        ->and(DB::table('spell_selection_slots')->where('character_id', $characterId)->count())->toBeGreaterThan(count($before));
    $classLevel = DB::table('character_class_levels')->where('character_id', $characterId)
        ->where('class_definition_id', $warlockId)->sole();
    $source = DB::table('character_source_instances')->where('character_id', $characterId)
        ->where('source_type', 'class')->where('source_definition_id', $warlockId)->sole();
    expect((int) data_get($classLevel, 'level'))->toBe(1)
        ->and((bool) data_get($classLevel, 'is_starting_class'))->toBeFalse()
        ->and(data_get($classLevel, 'subclass_definition_id'))->toBeNull()
        ->and(data_get($source, 'display_name'))->toBe('Warlock 1')
        ->and((int) data_get($source, 'acquired_at_character_level'))->toBe(7)
        ->and(data_get($source, 'state'))->toBe('active')
        ->and(json_decode((string) data_get($source, 'config'), true, 512, JSON_THROW_ON_ERROR))
        ->toBe(['spellcasting_ability' => 'charisma']);
});

it('updates an existing class and preserves custom source configuration while syncing subclasses', function () {
    $characterId = workspaceCharacterId();
    $wizardId = (int) DB::table('class_definitions')->where('name', 'Wizard')->value('id');
    $subclassId = (int) DB::table('subclass_definitions')->insertGetId([
        'content_key' => 'test:wizard:exact-update',
        'class_definition_id' => $wizardId,
        'name' => 'Exact Update School',
        'rules_edition' => '2024',
        'spellcasting_ability' => 'intelligence',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $sourceId = (int) DB::table('character_source_instances')->where('character_id', $characterId)
        ->where('source_type', 'class')->where('source_definition_id', $wizardId)->value('id');
    DB::table('character_source_instances')->where('id', $sourceId)->update([
        'config' => json_encode(['custom' => 'preserved', 'spellcasting_ability' => 'wisdom'], JSON_THROW_ON_ERROR),
    ]);

    mutateCharacter($this, $characterId, 0, [
        'type' => 'update_class', 'class_definition_id' => $wizardId,
        'level' => 2, 'subclass_definition_id' => $subclassId,
    ])->assertOk();

    $level = DB::table('character_class_levels')->where('character_id', $characterId)
        ->where('class_definition_id', $wizardId)->sole();
    $classSource = DB::table('character_source_instances')->where('id', $sourceId)->sole();
    $subclassSource = DB::table('character_source_instances')->where('character_id', $characterId)
        ->where('source_type', 'subclass')->where('source_definition_id', $subclassId)->sole();
    expect((int) data_get($level, 'level'))->toBe(2)
        ->and((int) data_get($level, 'subclass_definition_id'))->toBe($subclassId)
        ->and(data_get($classSource, 'display_name'))->toBe('Wizard 2')
        ->and(json_decode((string) data_get($classSource, 'config'), true, 512, JSON_THROW_ON_ERROR))->toBe([
            'custom' => 'preserved', 'spellcasting_ability' => 'intelligence',
        ])->and(data_get($subclassSource, 'display_name'))->toBe('Exact Update School')
        ->and(data_get($subclassSource, 'state'))->toBe('active');
});

it('undoes a structural class change through its snapshot inverse', function () {
    $characterId = workspaceCharacterId();
    $warlockId = (int) DB::table('class_definitions')->where('name', 'Warlock')->value('id');
    $beforeKeys = DB::table('spell_selection_slots')->where('character_id', $characterId)
        ->orderBy('id')->pluck('slot_key')->all();
    $changed = mutateCharacter($this, $characterId, 0, [
        'type' => 'update_class', 'class_definition_id' => $warlockId,
        'level' => 1, 'subclass_definition_id' => null,
    ])->assertOk();

    mutateCharacter($this, $characterId, 1, $changed->json('inverse'))->assertOk();

    expect(DB::table('character_class_levels')->where('character_id', $characterId)
        ->where('class_definition_id', $warlockId)->exists())->toBeFalse()
        ->and(DB::table('spell_selection_slots')->where('character_id', $characterId)
            ->orderBy('id')->pluck('slot_key')->all())->toBe($beforeKeys);
});

it('rejects stale revisions and replays an operation idempotently', function () {
    $characterId = workspaceCharacterId();
    $operation = Str::uuid()->toString();
    $command = ['type' => 'update_ability', 'ability' => 'wisdom', 'score' => 16];
    mutateCharacter($this, $characterId, 0, $command, $operation)->assertOk()->assertJsonPath('revision', 1);
    mutateCharacter($this, $characterId, 0, $command, $operation)
        ->assertOk()->assertJsonPath('idempotent_replay', true)->assertJsonPath('revision', 1);
    mutateCharacter($this, $characterId, 0, [
        'type' => 'update_ability', 'ability' => 'wisdom', 'score' => 18,
    ])->assertStatus(409)
        ->assertJsonPath('message', 'This character changed in another tab. Reload before trying again.')
        ->assertJsonPath('current_revision', 1);
    expect(DB::table('character_operations')->where('operation_uuid', $operation)->count())->toBe(1);
});

it('round-trips character rules and rejects legacy selection while legacy rules are disabled', function () {
    $characterId = workspaceCharacterId();
    $operation = Str::uuid()->toString();
    $changed = mutateCharacter($this, $characterId, 0, [
        'type' => 'update_character_rules', 'allow_legacy' => true,
    ], $operation)->assertOk()->assertJsonPath('revision', 1);

    expect((bool) DB::table('characters')->where('id', $characterId)->value('allow_legacy'))->toBeTrue();
    $audit = DB::table('change_log')->where('operation_uuid', $operation)->get();
    expect($audit)->toHaveCount(1)
        ->and($audit->pluck('group_id')->unique())->toHaveCount(1)
        ->and($audit->pluck('action_type')->unique()->all())->toBe(['update_character_rules']);

    mutateCharacter($this, $characterId, 0, [
        'type' => 'update_character_rules', 'allow_legacy' => true,
    ], $operation)->assertOk()->assertJsonPath('idempotent_replay', true)->assertJsonPath('revision', 1);
    expect(DB::table('character_operations')->where('operation_uuid', $operation)->count())->toBe(1)
        ->and(DB::table('change_log')->where('operation_uuid', $operation)->count())->toBe(1);

    mutateCharacter($this, $characterId, 1, $changed->json('inverse'))
        ->assertOk()->assertJsonPath('revision', 2);
    expect((bool) DB::table('characters')->where('id', $characterId)->value('allow_legacy'))->toBeFalse();

    $slotId = (int) DB::table('spell_selection_slots')->where('character_id', $characterId)
        ->where('rule_key', 'wizard-cantrips')->where('ordinal', 2)->value('id');
    $legacyChillTouch = (int) DB::table('spell_versions')->where('content_key', '2014:chill-touch')->value('id');
    mutateCharacter($this, $characterId, 2, [
        'type' => 'set_slot', 'slot_id' => $slotId, 'mode' => 'select',
        'spell_version_id' => $legacyChillTouch,
    ])->assertUnprocessable()->assertJsonPath('message', 'Enable legacy rules before selecting a 2014 spell version.');
    mutateCharacter($this, $characterId, 2, [
        'type' => 'update_character_rules', 'allow_legacy' => 'false',
    ])->assertUnprocessable()->assertJsonPath('message', 'allow_legacy must be a boolean.');
    expect((int) DB::table('characters')->where('id', $characterId)->value('revision'))->toBe(2);
});

it('round-trips source configuration with one audit group and rejects unsupported Magic Initiate lists', function () {
    $characterId = workspaceCharacterId();
    $sourceId = (int) DB::table('character_source_instances')
        ->where('character_id', $characterId)->where('display_name', 'Magic Initiate: Wizard')->value('id');
    $before = app(CharacterState::class)->capture($characterId);

    mutateCharacter($this, $characterId, 0, [
        'type' => 'update_source_config', 'source_instance_id' => $sourceId, 'chosen_list' => 'Bard',
    ])->assertUnprocessable()
        ->assertJsonPath('message', 'Magic Initiate must use the Cleric, Druid, or Wizard spell list.');
    expect(app(CharacterState::class)->capture($characterId))->toBe($before)
        ->and((int) DB::table('characters')->where('id', $characterId)->value('revision'))->toBe(0)
        ->and(DB::table('character_operations')->where('character_id', $characterId)->count())->toBe(0);

    $operation = Str::uuid()->toString();
    $changed = mutateCharacter($this, $characterId, 0, [
        'type' => 'update_source_config', 'source_instance_id' => $sourceId, 'chosen_list' => 'Cleric',
    ], $operation)->assertOk()->assertJsonPath('revision', 1);
    $after = app(CharacterState::class)->capture($characterId);
    $updatedSource = DB::table('character_source_instances')->find($sourceId);
    $parent = DB::table('character_source_instances')->find(data_get($updatedSource, 'parent_source_instance_id'));
    expect($after)->not->toBe($before)
        ->and(data_get($updatedSource, 'display_name'))->toBe('Magic Initiate: Cleric')
        ->and(json_decode((string) data_get($updatedSource, 'config'), true, 512, JSON_THROW_ON_ERROR))->toBe([
            'chosen_list' => 'Cleric', 'spellcasting_ability' => 'wisdom',
        ])->and(data_get(json_decode((string) data_get($parent, 'config'), true, 512, JSON_THROW_ON_ERROR), 'origin_feat_config'))
        ->toBe(['chosen_list' => 'Cleric', 'spellcasting_ability' => 'wisdom'])
        ->and(DB::table('spell_selection_slots')->where('source_instance_id', $sourceId)
            ->pluck('allowed_spell_lists')->unique()->all())->toBe(['["Cleric"]']);
    $audit = DB::table('change_log')->where('operation_uuid', $operation)->get();
    expect($audit->count())->toBeGreaterThan(1)
        ->and($audit->pluck('group_id')->unique())->toHaveCount(1)
        ->and($audit->pluck('action_type')->unique()->all())->toBe(['update_source_config']);

    mutateCharacter($this, $characterId, 0, [
        'type' => 'update_source_config', 'source_instance_id' => $sourceId, 'chosen_list' => 'Cleric',
    ], $operation)->assertOk()->assertJsonPath('idempotent_replay', true)->assertJsonPath('revision', 1);
    expect(app(CharacterState::class)->capture($characterId))->toBe($after)
        ->and(DB::table('character_operations')->where('operation_uuid', $operation)->count())->toBe(1);

    mutateCharacter($this, $characterId, 1, $changed->json('inverse'))
        ->assertOk()->assertJsonPath('revision', 2);
    expect(app(CharacterState::class)->capture($characterId))->toBe($before);
});

it('updates a standalone Magic Initiate source and regenerates its slot constraints', function () {
    $characterId = (int) DB::table('characters')->insertGetId([
        'name' => 'Standalone Magic Initiate', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $definitionId = (int) DB::table('feat_definitions')
        ->where('content_key', '2024:feat:magic-initiate')->value('id');
    mutateCharacter($this, $characterId, 0, [
        'type' => 'add_source', 'source_type' => 'feat',
        'source_definition_id' => $definitionId,
        'config' => ['chosen_list' => 'Cleric', 'spellcasting_ability' => 'wisdom'],
    ])->assertOk();
    $source = DB::table('character_source_instances')->where('character_id', $characterId)
        ->whereNull('parent_source_instance_id')->where('source_type', 'feat')->sole();

    mutateCharacter($this, $characterId, 1, [
        'type' => 'update_source_config', 'source_instance_id' => data_get($source, 'id'),
        'chosen_list' => 'Wizard',
    ])->assertOk();
    $updated = DB::table('character_source_instances')->where('id', data_get($source, 'id'))->sole();
    expect(data_get($updated, 'display_name'))->toBe('Magic Initiate: Wizard')
        ->and(json_decode((string) data_get($updated, 'config'), true, 512, JSON_THROW_ON_ERROR))->toBe([
            'chosen_list' => 'Wizard', 'spellcasting_ability' => 'intelligence',
        ])->and(DB::table('spell_selection_slots')->where('source_instance_id', data_get($source, 'id'))
        ->pluck('allowed_spell_lists')->unique()->all())->toBe(['["Wizard"]']);
});

it('selecting each bonus class Order materialises exactly one cantrip slot', function () {
    foreach ([
        ['Cleric', 'Thaumaturge', 'divine_order', 'cleric-divine-order-cantrip'],
        ['Druid', 'Magician', 'primal_order', 'druid-primal-order-cantrip'],
    ] as [$className, $chosenOption, $configKey, $ruleKey]) {
        ['character_id' => $characterId, 'source_id' => $sourceId] = orderClassSource($this, $className);
        $beforeCount = DB::table('spell_selection_slots')->where('source_instance_id', $sourceId)->count();

        mutateCharacter($this, $characterId, 1, [
            'type' => 'update_source_config', 'source_instance_id' => $sourceId,
            'chosen_option' => $chosenOption,
        ])->assertOk()
            ->assertJsonPath('revision', 2)
            ->assertJsonPath('workspace.order_sources.0.chosen_option', $chosenOption);

        $slots = DB::table('spell_selection_slots')->where('source_instance_id', $sourceId)->get();
        $bonus = $slots->where('rule_key', $ruleKey);
        $source = DB::table('character_source_instances')->find($sourceId);
        expect($slots)->toHaveCount($beforeCount + 1)
            ->and($bonus)->toHaveCount(1)
            ->and(data_get($bonus->first(), 'state'))->toBe('active')
            ->and((int) data_get($bonus->first(), 'spell_level_min'))->toBe(0)
            ->and((int) data_get($bonus->first(), 'spell_level_max'))->toBe(0)
            ->and(data_get($bonus->first(), 'allowed_spell_lists'))
            ->toBe(json_encode([$className], JSON_THROW_ON_ERROR))
            ->and(data_get(
                json_decode((string) data_get($source, 'config'), true, 512, JSON_THROW_ON_ERROR),
                $configKey,
            ))->toBe(['chosen_option' => $chosenOption, 'chosen_list' => $className]);
    }
});

it('switching Thaumaturge to Protector orphans its selected cantrip without clearing it', function () {
    ['character_id' => $characterId, 'source_id' => $sourceId] = orderClassSource($this);
    mutateCharacter($this, $characterId, 1, [
        'type' => 'update_source_config', 'source_instance_id' => $sourceId,
        'chosen_option' => 'Thaumaturge',
    ])->assertOk();
    $slot = DB::table('spell_selection_slots')->where('source_instance_id', $sourceId)
        ->where('rule_key', 'cleric-divine-order-cantrip')->sole();
    $guidanceId = (int) DB::table('spell_versions')->where('content_key', '2024:guidance')->value('id');
    mutateCharacter($this, $characterId, 2, [
        'type' => 'set_slot', 'slot_id' => data_get($slot, 'id'), 'mode' => 'select',
        'spell_version_id' => $guidanceId,
    ])->assertOk();

    mutateCharacter($this, $characterId, 3, [
        'type' => 'update_source_config', 'source_instance_id' => $sourceId,
        'chosen_option' => 'Protector',
    ])->assertOk()->assertJsonPath('revision', 4);

    $orphan = DB::table('spell_selection_slots')->where('id', data_get($slot, 'id'))->sole();
    $config = json_decode(
        (string) DB::table('character_source_instances')->where('id', $sourceId)->value('config'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
    expect((int) data_get($orphan, 'current_spell_version_id'))->toBe($guidanceId)
        ->and(data_get($orphan, 'slot_key'))->toBe(data_get($slot, 'slot_key'))
        ->and(data_get($orphan, 'state'))->toBe('orphaned')
        ->and(data_get($orphan, 'orphan_reason_code'))->toBe('rule_no_longer_active')
        ->and(data_get($orphan, 'selection_eligibility'))->toBe('invalid')
        ->and(data_get($orphan, 'selection_invalid_reason'))
        ->toBe('Selection preserved because its grant rule is no longer active.')
        ->and(data_get($config, 'divine_order'))->toBe(['chosen_option' => 'Protector']);

    mutateCharacter($this, $characterId, 4, [
        'type' => 'update_source_config', 'source_instance_id' => $sourceId,
        'chosen_option' => 'Thaumaturge',
    ])->assertOk()->assertJsonPath('revision', 5);
    $reactivated = DB::table('spell_selection_slots')->where('id', data_get($slot, 'id'))->sole();
    expect((int) data_get($reactivated, 'current_spell_version_id'))->toBe($guidanceId)
        ->and(data_get($reactivated, 'slot_key'))->toBe(data_get($slot, 'slot_key'))
        ->and(data_get($reactivated, 'state'))->toBe('active')
        ->and(data_get($reactivated, 'orphan_reason_code'))->toBeNull()
        ->and(data_get($reactivated, 'orphaned_at'))->toBeNull()
        ->and(data_get($reactivated, 'selection_eligibility'))->toBe('valid')
        ->and(data_get($reactivated, 'selection_invalid_reason'))->toBeNull();
});

it('undoing an Order switch restores the identical selected cantrip row', function () {
    ['character_id' => $characterId, 'source_id' => $sourceId] = orderClassSource($this);
    mutateCharacter($this, $characterId, 1, [
        'type' => 'update_source_config', 'source_instance_id' => $sourceId,
        'chosen_option' => 'Thaumaturge',
    ])->assertOk();
    $slot = DB::table('spell_selection_slots')->where('source_instance_id', $sourceId)
        ->where('rule_key', 'cleric-divine-order-cantrip')->sole();
    $guidanceId = (int) DB::table('spell_versions')->where('content_key', '2024:guidance')->value('id');
    mutateCharacter($this, $characterId, 2, [
        'type' => 'set_slot', 'slot_id' => data_get($slot, 'id'), 'mode' => 'select',
        'spell_version_id' => $guidanceId,
    ])->assertOk();
    $before = (array) DB::table('spell_selection_slots')->where('id', data_get($slot, 'id'))->sole();

    $switched = mutateCharacter($this, $characterId, 3, [
        'type' => 'update_source_config', 'source_instance_id' => $sourceId,
        'chosen_option' => 'Protector',
    ])->assertOk()->assertJsonPath('revision', 4);
    expect((array) DB::table('spell_selection_slots')->where('id', data_get($slot, 'id'))->sole())
        ->not->toBe($before);

    $undone = mutateCharacter($this, $characterId, 4, $switched->json('inverse'))
        ->assertOk()->assertJsonPath('revision', 5);
    expect((array) DB::table('spell_selection_slots')->where('id', data_get($slot, 'id'))->sole())
        ->toBe($before);
    $undone->assertJsonPath('workspace.order_sources.0.chosen_option', 'Thaumaturge');
});

it('rejects every invalid configurable-source boundary with its domain message', function () {
    $characterId = workspaceCharacterId();
    $source = DB::table('character_source_instances')->where('character_id', $characterId)
        ->where('display_name', 'Magic Initiate: Wizard')->sole();
    $sourceId = (int) data_get($source, 'id');
    $parentId = (int) data_get($source, 'parent_source_instance_id');
    $plainFeatId = (int) DB::table('feat_definitions')->insertGetId([
        'content_key' => 'test:plain-config-source', 'name' => 'Plain Config Source',
        'rules_edition' => '2024', 'repeatable' => true, 'grant_rules' => '[]',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $plainSourceId = (int) DB::table('character_source_instances')->insertGetId([
        'character_id' => $characterId, 'instance_uuid' => Str::uuid()->toString(),
        'source_type' => 'feat', 'source_definition_id' => $plainFeatId,
        'display_name' => 'Plain Config Source', 'config' => '{}', 'state' => 'active',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    foreach ([
        [$sourceId + 100000, 'Configurable source does not belong to this character.'],
        [$plainSourceId, 'Only Magic Initiate list configuration is editable here.'],
    ] as [$invalidSourceId, $message]) {
        mutateCharacter($this, $characterId, 0, [
            'type' => 'update_source_config', 'source_instance_id' => $invalidSourceId,
            'chosen_list' => 'Cleric',
        ])->assertUnprocessable()->assertJsonPath('message', $message);
    }

    $sorcererSourceId = (int) DB::table('character_source_instances')
        ->where('character_id', $characterId)->where('display_name', 'Sorcerer 1')->value('id');
    mutateCharacter($this, $characterId, 0, [
        'type' => 'update_source_config', 'source_instance_id' => $sorcererSourceId,
        'chosen_option' => 'Protector',
    ])->assertUnprocessable()
        ->assertJsonPath('message', 'Only Cleric or Druid class sources can configure an Order.');
    $clericSourceId = (int) DB::table('character_source_instances')
        ->where('character_id', $characterId)->where('display_name', 'Cleric 1')->value('id');
    mutateCharacter($this, $characterId, 0, [
        'type' => 'update_source_config', 'source_instance_id' => $clericSourceId,
        'chosen_option' => 'Magician',
    ])->assertUnprocessable()
        ->assertJsonPath('message', 'Cleric divine_order has an invalid chosen option.');

    DB::table('character_source_instances')->where('id', $sourceId)->update(['config' => '"scalar"']);
    mutateCharacter($this, $characterId, 0, [
        'type' => 'update_source_config', 'source_instance_id' => $sourceId, 'chosen_list' => 'Cleric',
    ])->assertUnprocessable()->assertJsonPath('message', 'Source configuration must be an object.');

    DB::table('character_source_instances')->where('id', $sourceId)->update(['config' => '{}']);
    DB::table('character_source_instances')->where('id', $parentId)->update(['config' => '{}']);
    mutateCharacter($this, $characterId, 0, [
        'type' => 'update_source_config', 'source_instance_id' => $sourceId, 'chosen_list' => 'Cleric',
    ])->assertUnprocessable()
        ->assertJsonPath('message', 'Magic Initiate parent configuration is missing origin_feat_config.');

    DB::table('class_definitions')->where('name', 'Cleric')->update(['spellcasting_ability' => null]);
    mutateCharacter($this, $characterId, 0, [
        'type' => 'update_source_config', 'source_instance_id' => $sourceId, 'chosen_list' => 'Cleric',
    ])->assertUnprocessable()
        ->assertJsonPath('message', 'Choose a spell list with a defined spellcasting ability.');
    expect((int) DB::table('characters')->where('id', $characterId)->value('revision'))->toBe(0);
});

it('adds a class source through the command with its level, DSL slots, and spellbook atomically', function () {
    $characterId = (int) DB::table('characters')->insertGetId([
        'name' => 'Class Source Command', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $sorcererId = (int) DB::table('class_definitions')->where('name', 'Sorcerer')->value('id');
    $before = app(CharacterState::class)->capture($characterId);

    $added = mutateCharacter($this, $characterId, 0, [
        'type' => 'add_source', 'source_type' => 'class',
        'source_definition_id' => $sorcererId, 'config' => ['level' => 1],
    ])->assertOk()->assertJsonPath('revision', 1);
    $sorcererSource = DB::table('character_source_instances')
        ->where('character_id', $characterId)->where('display_name', 'Sorcerer 1')->sole();
    expect(DB::table('character_class_levels')->where('character_id', $characterId)->sole())
        ->toMatchArray([
            'class_definition_id' => $sorcererId, 'level' => 1, 'is_starting_class' => 1,
        ])->and(json_decode((string) data_get($sorcererSource, 'config'), true, 512, JSON_THROW_ON_ERROR))
        ->toBe(['spellcasting_ability' => 'charisma'])
        ->and(data_get($sorcererSource, 'acquired_at_character_level'))->toBe(1)
        ->and(DB::table('spell_selection_slots')
            ->where('source_instance_id', data_get($sorcererSource, 'id'))->count())->toBe(6)
        ->and(DB::table('change_log')->where('character_id', $characterId)
            ->pluck('action_type')->unique()->all())->toBe(['add_source']);

    $afterSorcerer = app(CharacterState::class)->capture($characterId);
    mutateCharacter($this, $characterId, 1, [
        'type' => 'add_source', 'source_type' => 'class',
        'source_definition_id' => $sorcererId, 'config' => ['level' => 1],
    ])->assertUnprocessable()->assertJsonPath('message', 'Sorcerer is not repeatable.');
    expect(app(CharacterState::class)->capture($characterId))->toBe($afterSorcerer)
        ->and(DB::table('characters')->where('id', $characterId)->value('revision'))->toBe(1);

    $wizardId = (int) DB::table('class_definitions')->where('name', 'Wizard')->value('id');
    mutateCharacter($this, $characterId, 1, [
        'type' => 'add_source', 'source_type' => 'class',
        'source_definition_id' => $wizardId,
        'config' => ['level' => 1, 'wizard_spellbook_acquisitions' => [[]]],
    ])->assertUnprocessable()->assertJsonPath(
        'message',
        "Spellbook rule 'wizard-spellbook' acquisition 0 could not resolve its spell.",
    );
    expect(app(CharacterState::class)->capture($characterId))->toBe($afterSorcerer)
        ->and(DB::table('characters')->where('id', $characterId)->value('revision'))->toBe(1)
        ->and(DB::table('character_source_instances')
            ->where('character_id', $characterId)->where('display_name', 'Wizard 1')->exists())->toBeFalse();

    $acquisitions = [[
        'spell_version_key' => '2024:shield', 'acquisition' => 'starting',
    ]];
    mutateCharacter($this, $characterId, 1, [
        'type' => 'add_source', 'source_type' => 'class',
        'source_definition_id' => $wizardId,
        'config' => ['level' => 1, 'wizard_spellbook_acquisitions' => $acquisitions],
    ])->assertOk()->assertJsonPath('revision', 2);
    $wizardSource = DB::table('character_source_instances')
        ->where('character_id', $characterId)->where('display_name', 'Wizard 1')->sole();
    expect(json_decode((string) data_get($wizardSource, 'config'), true, 512, JSON_THROW_ON_ERROR))->toBe([
        'spellcasting_ability' => 'intelligence',
        'wizard_spellbook_acquisitions' => $acquisitions,
    ])->and(data_get($wizardSource, 'acquired_at_character_level'))->toBe(2)
        ->and(DB::table('wizard_spellbook_entries')->where('character_id', $characterId)->count())->toBe(1);

    mutateCharacter($this, $characterId, 2, $added->json('inverse'))
        ->assertOk()->assertJsonPath('revision', 3);
    expect(app(CharacterState::class)->capture($characterId))->toBe($before);
});

it('adds Divine and Primal Order options through class source config with exact bonus slots', function (
    string $className,
    string $configKey,
    string $chosenOption,
    int $expectedSlots,
): void {
    $characterId = (int) DB::table('characters')->insertGetId([
        'name' => "{$className} Order", 'created_at' => now(), 'updated_at' => now(),
    ]);
    $classId = (int) DB::table('class_definitions')->where('name', $className)->value('id');
    $order = ['chosen_option' => $chosenOption, 'chosen_list' => $className];

    mutateCharacter($this, $characterId, 0, [
        'type' => 'add_source', 'source_type' => 'class',
        'source_definition_id' => $classId,
        'config' => ['level' => 1, $configKey => $order],
    ])->assertOk()->assertJsonPath('revision', 1);

    $source = DB::table('character_source_instances')
        ->where('character_id', $characterId)->where('display_name', "{$className} 1")->sole();
    expect(json_decode((string) data_get($source, 'config'), true, 512, JSON_THROW_ON_ERROR))->toBe([
        'spellcasting_ability' => 'wisdom', $configKey => $order,
    ])->and(DB::table('spell_selection_slots')
        ->where('source_instance_id', data_get($source, 'id'))->count())->toBe($expectedSlots);

    $bonus = DB::table('spell_selection_slots')
        ->where('source_instance_id', data_get($source, 'id'))
        ->where('rule_key', $className === 'Cleric'
            ? 'cleric-divine-order-cantrip'
            : 'druid-primal-order-cantrip')
        ->sole();
    expect(data_get($bonus, 'allowed_spell_lists'))->toBe(json_encode([$className], JSON_THROW_ON_ERROR))
        ->and((int) data_get($bonus, 'spell_level_min'))->toBe(0)
        ->and((int) data_get($bonus, 'spell_level_max'))->toBe(0);
})->with([
    'Divine Order: Thaumaturge' => ['Cleric', 'divine_order', 'Thaumaturge', 8],
    'Primal Order: Magician' => ['Druid', 'primal_order', 'Magician', 7],
]);

it('rejects mismatched or malformed class Order config atomically', function (
    string $className,
    array $config,
    string $message,
): void {
    $characterId = (int) DB::table('characters')->insertGetId([
        'name' => 'Invalid Order', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $classId = (int) DB::table('class_definitions')->where('name', $className)->value('id');

    mutateCharacter($this, $characterId, 0, [
        'type' => 'add_source', 'source_type' => 'class',
        'source_definition_id' => $classId, 'config' => ['level' => 1, ...$config],
    ])->assertUnprocessable()->assertJsonPath('message', $message);
    expect(DB::table('characters')->where('id', $characterId)->value('revision'))->toBe(0)
        ->and(DB::table('character_source_instances')->where('character_id', $characterId)->count())->toBe(0)
        ->and(DB::table('character_class_levels')->where('character_id', $characterId)->count())->toBe(0);
})->with([
    'wrong class' => [
        'Sorcerer', ['divine_order' => ['chosen_option' => 'Thaumaturge', 'chosen_list' => 'Cleric']],
        'Only a Cleric class source can configure Divine Order.',
    ],
    'bad option' => [
        'Cleric', ['divine_order' => ['chosen_option' => 'Magician', 'chosen_list' => 'Cleric']],
        'Cleric divine_order has an invalid chosen option.',
    ],
    'wrong list' => [
        'Druid', ['primal_order' => ['chosen_option' => 'Magician', 'chosen_list' => 'Wizard']],
        'Druid Magician must use the Druid spell list.',
    ],
    'nonbonus list' => [
        'Cleric', ['divine_order' => ['chosen_option' => 'Protector', 'chosen_list' => 'Cleric']],
        'Cleric Protector must not configure a spell list.',
    ],
]);

it('adds Magic Initiate through the DSL with independent list and ability config and exact undo redo', function () {
    $characterId = workspaceCharacterId();
    $definitionId = (int) DB::table('feat_definitions')
        ->where('content_key', '2024:feat:magic-initiate')->value('id');
    $before = app(CharacterState::class)->capture($characterId);

    foreach ([
        [['chosen_list' => 'Bard', 'spellcasting_ability' => 'charisma'],
            'Magic Initiate must use the Cleric, Druid, or Wizard spell list.'],
        [['chosen_list' => 'Cleric', 'spellcasting_ability' => 'constitution'],
            'Magic Initiate must use Intelligence, Wisdom, or Charisma.'],
        [['chosen_list' => 'Wizard', 'spellcasting_ability' => 'charisma'],
            "Magic Initiate already uses chosen_list 'Wizard' for this character."],
    ] as [$config, $message]) {
        mutateCharacter($this, $characterId, 0, [
            'type' => 'add_source',
            'source_type' => 'feat',
            'source_definition_id' => $definitionId,
            'config' => $config,
        ])->assertUnprocessable()->assertJsonPath('message', $message);
        expect(app(CharacterState::class)->capture($characterId))->toBe($before);
    }

    $operation = Str::uuid()->toString();
    $added = mutateCharacter($this, $characterId, 0, [
        'type' => 'add_source',
        'source_type' => 'feat',
        'source_definition_id' => $definitionId,
        'config' => ['chosen_list' => 'Cleric', 'spellcasting_ability' => 'charisma'],
    ], $operation)->assertOk()->assertJsonPath('revision', 1);
    $source = DB::table('character_source_instances')
        ->where('character_id', $characterId)->where('display_name', 'Magic Initiate: Cleric')->sole();
    $slots = DB::table('spell_selection_slots')->where('source_instance_id', data_get($source, 'id'))
        ->orderBy('id')->get();
    $after = app(CharacterState::class)->capture($characterId);

    expect(json_decode((string) data_get($source, 'config'), true, 512, JSON_THROW_ON_ERROR))->toBe([
        'chosen_list' => 'Cleric',
        'spellcasting_ability' => 'charisma',
    ])->and($slots)->toHaveCount(3)
        ->and($slots->where('rule_key', 'magic-initiate-cantrips'))->toHaveCount(2)
        ->and($slots->where('rule_key', 'magic-initiate-cantrips')->every(
            fn (object $slot): bool => ! (bool) data_get($slot, 'with_slots')
                && data_get($slot, 'free_cast') === null
                && data_get($slot, 'allowed_spell_lists') === '["Cleric"]',
        ))->toBeTrue();
    $levelOne = $slots->where('rule_key', 'magic-initiate-level-one')->sole();
    expect((bool) data_get($levelOne, 'with_slots'))->toBeTrue()
        ->and(json_decode((string) data_get($levelOne, 'free_cast'), true, 512, JSON_THROW_ON_ERROR))->toBe([
            'uses' => 1, 'recovery' => 'long_rest', 'pool_scope' => 'per_spell',
        ]);

    $audit = DB::table('change_log')->where('operation_uuid', $operation)->get();
    expect($audit->count())->toBeGreaterThan(1)
        ->and($audit->pluck('group_id')->unique())->toHaveCount(1)
        ->and($audit->pluck('action_type')->unique()->all())->toBe(['add_source']);
    mutateCharacter($this, $characterId, 0, [
        'type' => 'remove_source', 'source_instance_id' => data_get($source, 'id'),
    ], $operation)->assertOk()->assertJsonPath('idempotent_replay', true)->assertJsonPath('revision', 1);
    expect(app(CharacterState::class)->capture($characterId))->toBe($after)
        ->and(DB::table('character_operations')->where('operation_uuid', $operation)->count())->toBe(1);

    $undone = mutateCharacter($this, $characterId, 1, $added->json('inverse'))
        ->assertOk()->assertJsonPath('revision', 2);
    expect(app(CharacterState::class)->capture($characterId))->toBe($before);
    mutateCharacter($this, $characterId, 2, $undone->json('inverse'))
        ->assertOk()->assertJsonPath('revision', 3);
    expect(app(CharacterState::class)->capture($characterId))->toBe($after);
});

it('adds species and background roots with nested Magic Initiate chains and rejects non-repeatable duplicates', function () {
    $characterId = (int) DB::table('characters')->insertGetId([
        'name' => 'Nested Source Test', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $humanId = (int) DB::table('species_definitions')->where('content_key', '2024:species:human')->value('id');
    $backgroundId = (int) DB::table('background_definitions')
        ->where('content_key', '2024:background:custom')->value('id');

    $invalid = [
        'origin_feat_key' => '2024:feat:magic-initiate',
        'origin_feat_config' => ['chosen_list' => 'Bard', 'spellcasting_ability' => 'charisma'],
    ];
    mutateCharacter($this, $characterId, 0, [
        'type' => 'add_source', 'source_type' => 'species',
        'source_definition_id' => $humanId, 'config' => $invalid,
    ])->assertUnprocessable()
        ->assertJsonPath('message', 'Magic Initiate must use the Cleric, Druid, or Wizard spell list.');
    mutateCharacter($this, $characterId, 0, [
        'type' => 'add_source', 'source_type' => 'species',
        'source_definition_id' => $humanId,
        'config' => ['origin_feat_key' => '2024:feat:magic-initiate'],
    ])->assertUnprocessable()
        ->assertJsonPath('message', 'Magic Initiate origin feat config must be an object.');

    $humanConfig = [
        'origin_feat_key' => '2024:feat:magic-initiate',
        'origin_feat_config' => ['chosen_list' => 'Wizard', 'spellcasting_ability' => 'charisma'],
    ];
    mutateCharacter($this, $characterId, 0, [
        'type' => 'add_source', 'source_type' => 'species',
        'source_definition_id' => $humanId, 'config' => $humanConfig,
    ])->assertOk()->assertJsonPath('revision', 1);
    $human = DB::table('character_source_instances')->where('character_id', $characterId)
        ->where('source_type', 'species')->sole();
    $humanFeat = DB::table('character_source_instances')
        ->where('parent_source_instance_id', data_get($human, 'id'))->sole();
    expect(data_get($humanFeat, 'display_name'))->toBe('Magic Initiate: Wizard')
        ->and(DB::table('spell_selection_slots')->where('source_instance_id', data_get($humanFeat, 'id'))->count())
        ->toBe(3);

    mutateCharacter($this, $characterId, 1, [
        'type' => 'add_source', 'source_type' => 'species',
        'source_definition_id' => $humanId, 'config' => $humanConfig,
    ])->assertUnprocessable()->assertJsonPath('message', 'Human is not repeatable.');

    $backgroundConfig = [
        'origin_feat_key' => '2024:feat:magic-initiate',
        'origin_feat_config' => ['chosen_list' => 'Druid', 'spellcasting_ability' => 'intelligence'],
    ];
    mutateCharacter($this, $characterId, 1, [
        'type' => 'add_source', 'source_type' => 'background',
        'source_definition_id' => $backgroundId, 'config' => $backgroundConfig,
    ])->assertOk()->assertJsonPath('revision', 2);
    $background = DB::table('character_source_instances')->where('character_id', $characterId)
        ->where('source_type', 'background')->sole();
    $backgroundFeat = DB::table('character_source_instances')
        ->where('parent_source_instance_id', data_get($background, 'id'))->sole();
    expect(data_get($backgroundFeat, 'display_name'))->toBe('Magic Initiate: Druid')
        ->and(DB::table('spell_selection_slots')->where('source_instance_id', data_get($backgroundFeat, 'id'))->count())
        ->toBe(3);

    $plainFeatId = DB::table('feat_definitions')->insertGetId([
        'content_key' => '2024:feat:plain-test',
        'name' => 'Plain Test Feat',
        'rules_edition' => '2024',
        'repeatable' => false,
        'grant_rules' => '[]',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $plain = mutateCharacter($this, $characterId, 2, [
        'type' => 'add_source', 'source_type' => 'feat',
        'source_definition_id' => $plainFeatId, 'config' => [],
    ])->assertOk()->assertJsonPath('revision', 3);
    $plainSource = DB::table('character_source_instances')->where('character_id', $characterId)
        ->where('source_definition_id', $plainFeatId)->sole();
    expect(data_get($plainSource, 'display_name'))->toBe('Plain Test Feat')
        ->and(data_get($plainSource, 'acquired_at_character_level'))->toBe(1)
        ->and(collect($plain->json('workspace.source_catalog.feat'))->firstWhere('id', $plainFeatId))
        ->toMatchArray(['configuration_kind' => 'none', 'repeatable' => false]);
});

it('rejects an unknown source definition before any source row is created', function () {
    $characterId = workspaceCharacterId();
    $missingDefinition = (int) DB::table('feat_definitions')->max('id') + 1000;
    $before = DB::table('character_source_instances')->where('character_id', $characterId)->count();

    mutateCharacter($this, $characterId, 0, [
        'type' => 'add_source', 'source_type' => 'feat',
        'source_definition_id' => $missingDefinition, 'config' => [],
    ])->assertUnprocessable()
        ->assertJsonPath('message', 'Unknown source definition for the selected source type.');
    expect(DB::table('character_source_instances')->where('character_id', $characterId)->count())->toBe($before);
});

it('removes a root source through the command and cascades to its nested feat', function (string $sourceType): void {
    $characterId = workspaceCharacterId();
    $root = DB::table('character_source_instances')
        ->where('character_id', $characterId)
        ->where('source_type', $sourceType)
        ->whereNull('parent_source_instance_id')
        ->sole();
    $child = DB::table('character_source_instances')
        ->where('parent_source_instance_id', data_get($root, 'id'))
        ->sole();
    $slotIds = DB::table('spell_selection_slots')
        ->where('source_instance_id', data_get($child, 'id'))
        ->orderBy('id')
        ->pluck('id')
        ->all();
    $before = app(CharacterState::class)->capture($characterId);

    $removed = mutateCharacter($this, $characterId, 0, [
        'type' => 'remove_source', 'source_instance_id' => data_get($root, 'id'),
    ])->assertOk()->assertJsonPath('revision', 1);

    expect(DB::table('character_source_instances')->whereIn('id', [
        data_get($root, 'id'), data_get($child, 'id'),
    ])->pluck('state')->unique()->all())->toBe(['tombstoned'])
        ->and(DB::table('spell_selection_slots')->whereIn('id', $slotIds)
            ->pluck('state')->unique()->all())->toBe(['orphaned']);

    mutateCharacter($this, $characterId, 1, $removed->json('inverse'))
        ->assertOk()->assertJsonPath('revision', 2);
    expect(app(CharacterState::class)->capture($characterId))->toBe($before);
})->with([
    'species' => ['species'],
    'background' => ['background'],
]);

it('tombstones a source, preserves orphan selections, and restores identical rows through undo redo', function () {
    $characterId = workspaceCharacterId();
    $source = DB::table('character_source_instances')->where('character_id', $characterId)
        ->where('display_name', 'Magic Initiate: Wizard')->sole();
    $sourceId = (int) data_get($source, 'id');
    $before = app(CharacterState::class)->capture($characterId);
    $sourceBefore = (array) $source;
    $slotsBefore = DB::table('spell_selection_slots')->where('source_instance_id', $sourceId)
        ->orderBy('id')->get()->map(static fn (object $slot): array => (array) $slot)->all();
    $operation = Str::uuid()->toString();

    $removed = mutateCharacter($this, $characterId, 0, [
        'type' => 'remove_source', 'source_instance_id' => $sourceId,
    ], $operation)->assertOk()->assertJsonPath('revision', 1);
    $sourceRemoved = (array) DB::table('character_source_instances')->find($sourceId);
    $slotsRemoved = DB::table('spell_selection_slots')->where('source_instance_id', $sourceId)
        ->orderBy('id')->get()->map(static fn (object $slot): array => (array) $slot)->all();
    $removedState = app(CharacterState::class)->capture($characterId);

    expect(data_get($sourceRemoved, 'state'))->toBe('tombstoned')
        ->and($slotsRemoved)->toHaveCount(3)
        ->and(array_column($slotsRemoved, 'id'))->toBe(array_column($slotsBefore, 'id'))
        ->and(array_column($slotsRemoved, 'slot_key'))->toBe(array_column($slotsBefore, 'slot_key'))
        ->and(array_column($slotsRemoved, 'current_spell_version_id'))
        ->toBe(array_column($slotsBefore, 'current_spell_version_id'))
        ->and(collect($slotsRemoved)->every(fn (array $slot): bool => data_get($slot, 'state') === 'orphaned'))
        ->toBeTrue()
        ->and(collect($slotsRemoved)->every(
            fn (array $slot): bool => data_get($slot, 'selection_eligibility') === 'invalid'
                && data_get($slot, 'selection_invalid_reason')
                    === 'Selection preserved because its source is no longer active.',
        ))->toBeTrue();
    $audit = DB::table('change_log')->where('operation_uuid', $operation)->get();
    expect($audit->count())->toBeGreaterThan(1)
        ->and($audit->pluck('group_id')->unique())->toHaveCount(1)
        ->and($audit->pluck('action_type')->unique()->all())->toBe(['remove_source']);

    mutateCharacter($this, $characterId, 0, [
        'type' => 'remove_source', 'source_instance_id' => $sourceId,
    ], $operation)->assertOk()->assertJsonPath('idempotent_replay', true)->assertJsonPath('revision', 1);
    expect(app(CharacterState::class)->capture($characterId))->toBe($removedState);

    $restored = mutateCharacter($this, $characterId, 1, $removed->json('inverse'))
        ->assertOk()->assertJsonPath('revision', 2);
    expect(app(CharacterState::class)->capture($characterId))->toBe($before)
        ->and((array) DB::table('character_source_instances')->find($sourceId))->toBe($sourceBefore)
        ->and(DB::table('spell_selection_slots')->where('source_instance_id', $sourceId)
            ->orderBy('id')->get()->map(static fn (object $slot): array => (array) $slot)->all())
        ->toBe($slotsBefore);

    mutateCharacter($this, $characterId, 2, $restored->json('inverse'))
        ->assertOk()->assertJsonPath('revision', 3);
    expect(app(CharacterState::class)->capture($characterId))->toBe($removedState);
});

it('round-trips warning acknowledgement with idempotent replay and grouped audit rows', function () {
    $characterId = workspaceCharacterId();
    DB::table('characters')->where('id', $characterId)->update(['allow_legacy' => true]);
    $slots = DB::table('spell_selection_slots')->where('character_id', $characterId)
        ->where('rule_key', 'wizard-cantrips')->whereIn('ordinal', [2, 3])->orderBy('ordinal')->get();
    $versions = [
        (int) DB::table('spell_versions')->where('content_key', '2014:chill-touch')->value('id'),
        (int) DB::table('spell_versions')->where('content_key', '2024:chill-touch')->value('id'),
    ];
    foreach ($slots->values() as $index => $slot) {
        DB::table('spell_selection_slots')->where('id', data_get($slot, 'id'))->update([
            'current_spell_version_id' => $versions[$index],
            'selection_eligibility' => 'valid',
            'selection_invalid_reason' => null,
        ]);
    }
    $warning = collect(data_get(app(BuildReportBuilder::class)->build($characterId), 'duplicate_assessments'))
        ->firstWhere('category', 'conflicting_version');
    $fingerprint = (string) data_get($warning, 'warning_fingerprint');
    expect($fingerprint)->toStartWith('conflicting_versions:');

    $operation = Str::uuid()->toString();
    $changed = mutateCharacter($this, $characterId, 0, [
        'type' => 'acknowledge_warning', 'warning_fingerprint' => $fingerprint, 'note' => 'Intentional.',
    ], $operation)->assertOk()->assertJsonPath('revision', 1);
    $inverse = $changed->json('inverse');
    expect(array_keys($inverse))->toBe(['type', 'mode', 'warning_fingerprint', 'integrity'])
        ->and(collect($inverse)->except('integrity')->all())->toBe([
            'type' => 'acknowledge_warning',
            'mode' => 'delete',
            'warning_fingerprint' => $fingerprint,
        ]);
    app(CharacterCommandIntegrity::class)->assertValid($characterId, $inverse);
    expect(DB::table('warning_acknowledgements')->where('character_id', $characterId)->value('note'))
        ->toBe('Intentional.');
    $audit = DB::table('change_log')->where('operation_uuid', $operation)->get();
    expect($audit)->toHaveCount(1)
        ->and($audit->pluck('group_id')->unique())->toHaveCount(1)
        ->and($audit->pluck('action_type')->unique()->all())->toBe(['acknowledge_warning']);

    mutateCharacter($this, $characterId, 0, [
        'type' => 'acknowledge_warning', 'warning_fingerprint' => $fingerprint, 'note' => 'Changed.',
    ], $operation)->assertOk()->assertJsonPath('idempotent_replay', true)->assertJsonPath('revision', 1);
    expect(DB::table('warning_acknowledgements')->where('character_id', $characterId)->value('note'))
        ->toBe('Intentional.');

    $deleted = mutateCharacter($this, $characterId, 1, $inverse)
        ->assertOk()->assertJsonPath('revision', 2);
    expect(DB::table('warning_acknowledgements')->where('character_id', $characterId)->count())->toBe(0);
    expect($deleted->json('inverse'))->toBe([
        'type' => 'acknowledge_warning',
        'warning_fingerprint' => $fingerprint,
        'note' => 'Intentional.',
    ]);

    $restored = mutateCharacter($this, $characterId, 2, $deleted->json('inverse'))
        ->assertOk()->assertJsonPath('revision', 3);
    expect($restored->json('inverse'))->toMatchArray([
        'type' => 'acknowledge_warning', 'mode' => 'delete', 'warning_fingerprint' => $fingerprint,
    ]);
    $updated = mutateCharacter($this, $characterId, 3, [
        'type' => 'acknowledge_warning', 'warning_fingerprint' => $fingerprint,
        'note' => '  Updated note.  ',
    ])->assertOk()->assertJsonPath('revision', 4);
    expect($updated->json('inverse'))->toBe([
        'type' => 'acknowledge_warning',
        'warning_fingerprint' => $fingerprint,
        'note' => 'Intentional.',
    ])->and(DB::table('warning_acknowledgements')->where('character_id', $characterId)->value('note'))
        ->toBe('Updated note.');

    mutateCharacter($this, $characterId, 4, [
        'type' => 'acknowledge_warning',
        'warning_fingerprint' => "  {$fingerprint}  ",
        'note' => 'Spaced fingerprint.',
    ])->assertOk()->assertJsonPath('revision', 5);
    expect(DB::table('warning_acknowledgements')->where('character_id', $characterId)
        ->where('warning_fingerprint', $fingerprint)->value('note'))->toBe('Spaced fingerprint.');

    mutateCharacter($this, $characterId, 5, [
        'type' => 'acknowledge_warning',
        'warning_fingerprint' => 'conflicting_versions:not-an-active-warning',
        'note' => 'No.',
    ])->assertUnprocessable()->assertJsonPath('message', 'The conflicting-version warning is no longer active.');
    mutateCharacter($this, $characterId, 5, [
        'type' => 'acknowledge_warning', 'warning_fingerprint' => 'not-a-conflict', 'note' => 'No.',
    ])->assertUnprocessable()->assertJsonPath('message', 'Unknown warning fingerprint.');
    mutateCharacter($this, $characterId, 5, [
        'type' => 'acknowledge_warning', 'mode' => 'invalid',
        'warning_fingerprint' => $fingerprint, 'note' => 'No.',
    ])->assertUnprocessable()->assertJsonPath('message', 'Unknown warning acknowledgement mode.');
    expect((int) DB::table('characters')->where('id', $characterId)->value('revision'))->toBe(5);
});

it('merges a stale slot edit only when intervening operations left that slot untouched', function () {
    $characterId = workspaceCharacterId();
    $slots = DB::table('spell_selection_slots')->where('character_id', $characterId)
        ->where('rule_key', 'wizard-cantrips')->whereIn('ordinal', [2, 3])
        ->orderBy('ordinal')->get();
    $firstSlot = $slots->first();
    $secondSlot = $slots->last();
    $fireBolt = (int) DB::table('spell_versions')->where('content_key', '2024:fire-bolt')->value('id');
    $minorIllusion = (int) DB::table('spell_versions')->where('content_key', '2024:minor-illusion')->value('id');
    $mageHand = (int) DB::table('spell_versions')->where('content_key', '2024:mage-hand')->value('id');

    mutateCharacter($this, $characterId, 0, [
        'type' => 'set_slot', 'slot_id' => data_get($firstSlot, 'id'), 'mode' => 'select',
        'spell_version_id' => $fireBolt,
    ])->assertOk()->assertJsonPath('revision', 1);
    mutateCharacter($this, $characterId, 0, [
        'type' => 'set_slot', 'slot_id' => data_get($secondSlot, 'id'), 'mode' => 'select',
        'spell_version_id' => $minorIllusion,
    ])->assertOk()->assertJsonPath('revision', 2);
    mutateCharacter($this, $characterId, 0, [
        'type' => 'set_slot', 'slot_id' => data_get($firstSlot, 'id'), 'mode' => 'select',
        'spell_version_id' => $mageHand,
    ])->assertStatus(409)->assertJsonPath('current_revision', 2);

    expect((int) DB::table('spell_selection_slots')->where('id', data_get($firstSlot, 'id'))
        ->value('current_spell_version_id'))->toBe($fireBolt)
        ->and((int) DB::table('spell_selection_slots')->where('id', data_get($secondSlot, 'id'))
            ->value('current_spell_version_id'))->toBe($minorIllusion)
        ->and(DB::table('character_operations')->where('character_id', $characterId)->count())->toBe(2);
});

it('lists only eligible spells for an inline slot search', function () {
    $characterId = workspaceCharacterId();
    $slotId = (int) DB::table('spell_selection_slots')->where('character_id', $characterId)
        ->where('rule_key', 'druid-cantrips')->value('id');
    $spells = $this->getJson("/characters/{$characterId}/slots/{$slotId}/eligible-spells?q=guid")
        ->assertOk()->json('spells');
    expect(collect($spells)->pluck('name')->all())->toContain('Guidance')
        ->and(collect($spells)->every(fn (array $spell): bool => data_get($spell, 'level') === 0))->toBeTrue();
});
