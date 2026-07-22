<?php

declare(strict_types=1);

use App\Domain\Characters\CharacterState;
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
        ->has('workspace.removable_sources', 4)
        ->has('workspace.slots')
    );
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

    mutateCharacter($this, $characterId, 1, $changed->json('inverse'))
        ->assertOk()->assertJsonPath('revision', 2);

    expect((int) DB::table('spell_selection_slots')->where('id', data_get($slot, 'id'))->value('current_spell_version_id'))
        ->toBe($original);
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

it('changing an ability score recomputes the source save DC', function () {
    $characterId = workspaceCharacterId();
    $slotId = (int) DB::table('spell_selection_slots')->where('character_id', $characterId)
        ->where('rule_key', 'wizard-cantrips')->where('ordinal', 1)->value('id');

    $response = mutateCharacter($this, $characterId, 0, [
        'type' => 'update_ability', 'ability' => 'intelligence', 'score' => 18,
    ])->assertOk();
    $slot = collect($response->json('workspace.slots'))->firstWhere('id', $slotId);
    expect(data_get($slot, 'save_dc'))->toBe(15)
        ->and(data_get($slot, 'attack_bonus'))->toBe(7);
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
    ])->assertStatus(409)->assertJsonPath('current_revision', 1);
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
    expect($after)->not->toBe($before);
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

    mutateCharacter($this, $characterId, 1, $changed->json('inverse'))
        ->assertOk()->assertJsonPath('revision', 2);
    expect(DB::table('warning_acknowledgements')->where('character_id', $characterId)->count())->toBe(0);
    mutateCharacter($this, $characterId, 2, [
        'type' => 'acknowledge_warning', 'mode' => 'invalid',
        'warning_fingerprint' => $fingerprint, 'note' => 'No.',
    ])->assertUnprocessable()->assertJsonPath('message', 'Unknown warning acknowledgement mode.');
    expect((int) DB::table('characters')->where('id', $characterId)->value('revision'))->toBe(2);
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
