<?php

declare(strict_types=1);

use App\Domain\Grants\GrantRuleSlotGenerator;
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
    'persisted-character-state' => persistedCharacterState($characterId),
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
    'remove-magic-initiate-wizard-source' => reconcileMagicInitiateWizardSource($characterId, '[]'),
    'restore-magic-initiate-wizard-source' => reconcileMagicInitiateWizardSource(
        $characterId,
        (string) $argument,
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

/** @return array{previous_grant_rules: string, source: array<string, mixed>} */
function reconcileMagicInitiateWizardSource(int $characterId, string $grantRules): array
{
    json_decode($grantRules, true, 512, JSON_THROW_ON_ERROR);
    $child = DB::table('character_source_instances')
        ->where('character_id', $characterId)
        ->where('display_name', 'Magic Initiate: Wizard')
        ->sole();
    $parent = DB::table('character_source_instances')->find(data_get($child, 'parent_source_instance_id'));
    if ($parent === null || data_get($parent, 'source_type') !== 'species') {
        throw new RuntimeException('Magic Initiate: Wizard did not have the expected species parent.');
    }
    $definition = DB::table('species_definitions')->find(data_get($parent, 'source_definition_id'));
    if ($definition === null) {
        throw new RuntimeException('The Magic Initiate: Wizard parent definition was missing.');
    }
    $previous = (string) data_get($definition, 'grant_rules');
    DB::table('species_definitions')->where('id', data_get($definition, 'id'))->update([
        'grant_rules' => $grantRules,
        'updated_at' => now(),
    ]);
    app(GrantRuleSlotGenerator::class)->generateForSource((int) data_get($parent, 'id'));

    return [
        'previous_grant_rules' => $previous,
        'source' => (array) DB::table('character_source_instances')->find(data_get($child, 'id')),
    ];
}
