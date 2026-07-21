<?php

declare(strict_types=1);

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

it('lists only eligible spells for an inline slot search', function () {
    $characterId = workspaceCharacterId();
    $slotId = (int) DB::table('spell_selection_slots')->where('character_id', $characterId)
        ->where('rule_key', 'druid-cantrips')->value('id');
    $spells = $this->getJson("/characters/{$characterId}/slots/{$slotId}/eligible-spells?q=guid")
        ->assertOk()->json('spells');
    expect(collect($spells)->pluck('name')->all())->toContain('Guidance')
        ->and(collect($spells)->every(fn (array $spell): bool => data_get($spell, 'level') === 0))->toBeTrue();
});
