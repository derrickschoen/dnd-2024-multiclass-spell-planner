<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$action = data_get($argv, 1);
$characterId = (int) data_get($argv, 2, 1);
$argument = data_get($argv, 3);

$result = match ($action) {
    'slots' => DB::table('spell_selection_slots')
        ->where('character_id', $characterId)
        ->orderBy('id')
        ->get()
        ->map(static fn (object $row): array => (array) $row)
        ->all(),
    'slot-fixtures' => DB::table('spell_selection_slots as slot')
        ->join('character_source_instances as source', 'source.id', '=', 'slot.source_instance_id')
        ->leftJoin(
            'spell_versions as selected',
            'selected.id',
            '=',
            DB::raw('COALESCE(slot.fixed_spell_version_id, slot.current_spell_version_id)'),
        )
        ->where('slot.character_id', $characterId)
        ->orderBy('slot.id')
        ->get([
            'slot.*',
            'source.display_name as source_name',
            'source.config as source_config',
            'selected.display_name as spell_name',
        ])
        ->map(static fn (object $row): array => (array) $row)
        ->all(),
    'character' => (array) DB::table('characters')->where('id', $characterId)->sole(),
    'audit' => DB::table('change_log')
        ->where('character_id', $characterId)
        ->orderBy('sequence')
        ->get()
        ->map(static fn (object $row): array => (array) $row)
        ->all(),
    'operations' => DB::table('character_operations')
        ->where('character_id', $characterId)
        ->orderBy('resulting_revision')
        ->get()
        ->map(static fn (object $row): array => (array) $row)
        ->all(),
    'warning-acknowledgements' => DB::table('warning_acknowledgements')
        ->where('character_id', $characterId)
        ->orderBy('id')
        ->get()
        ->map(static fn (object $row): array => (array) $row)
        ->all(),
    'persisted-character-state' => persistedCharacterState($characterId),
    'mutation-footprint' => mutationFootprint($characterId),
    'save-point-snapshot' => json_decode(
        (string) data_get(
            DB::table('character_save_points')
                ->where('character_id', $characterId)
                ->where('label', $argument)
                ->sole(),
            'snapshot',
        ),
        true,
        512,
        JSON_THROW_ON_ERROR,
    ),
    'spell-version-id' => (int) data_get(
        DB::table('spell_versions')->where('content_key', $argument)->sole(),
        'id',
    ),
    'source' => (array) DB::table('character_source_instances')
        ->where('character_id', $characterId)
        ->where('display_name', $argument)
        ->sole(),
    'class-level' => (int) data_get(
        DB::table('character_class_levels as level')
            ->join('class_definitions as class', 'class.id', '=', 'level.class_definition_id')
            ->where('level.character_id', $characterId)
            ->where('class.name', $argument)
            ->sole(['level.level']),
        'level',
    ),
    default => throw new InvalidArgumentException("Unknown database action: {$action}"),
};

echo json_encode($result, JSON_THROW_ON_ERROR);

/** @return array<string, mixed> */
function persistedCharacterState(int $characterId): array
{
    $character = DB::table('characters')->where('id', $characterId)->sole();
    $state = ['schema_version' => 'a7-v1', 'character' => []];
    foreach ([
        'name', 'strength', 'dexterity', 'constitution', 'intelligence', 'wisdom', 'charisma',
        'proficiency_bonus_override', 'rules_edition_preference', 'allow_legacy', 'notes',
    ] as $column) {
        $state['character'][$column] = data_get($character, $column);
    }
    foreach ([
        'character_class_levels',
        'character_source_instances',
        'spell_selection_slots',
        'wizard_spellbook_entries',
        'warning_acknowledgements',
    ] as $table) {
        $state[$table] = DB::table($table)
            ->where('character_id', $characterId)
            ->orderBy('id')
            ->get()
            ->map(static fn (object $row): array => (array) $row)
            ->all();
    }

    return $state;
}

/** @return array<string, mixed> */
function mutationFootprint(int $characterId): array
{
    return [
        'character' => (array) DB::table('characters')->where('id', $characterId)->sole(),
        'state' => persistedCharacterState($characterId),
        'change_log' => DB::table('change_log')
            ->where('character_id', $characterId)
            ->orderBy('id')
            ->get()
            ->map(static fn (object $row): array => (array) $row)
            ->all(),
        'operations' => DB::table('character_operations')
            ->where('character_id', $characterId)
            ->orderBy('id')
            ->get()
            ->map(static fn (object $row): array => (array) $row)
            ->all(),
        'save_points' => DB::table('character_save_points')
            ->where('character_id', $characterId)
            ->orderBy('id')
            ->get()
            ->map(static fn (object $row): array => (array) $row)
            ->all(),
    ];
}
