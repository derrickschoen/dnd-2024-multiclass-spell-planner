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
