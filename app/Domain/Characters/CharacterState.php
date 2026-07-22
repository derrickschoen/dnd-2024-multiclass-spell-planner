<?php

declare(strict_types=1);

namespace App\Domain\Characters;

use Illuminate\Support\Facades\DB;
use RuntimeException;

final class CharacterState
{
    /** @var list<string> */
    private const CHARACTER_COLUMNS = [
        'name', 'strength', 'dexterity', 'constitution', 'intelligence', 'wisdom', 'charisma',
        'proficiency_bonus_override', 'rules_edition_preference', 'allow_legacy', 'notes',
    ];

    /** @var list<string> */
    private const TABLES = [
        'character_class_levels',
        'character_source_instances',
        'spell_selection_slots',
        'wizard_spellbook_entries',
        'warning_acknowledgements',
    ];

    /** @return array<string, mixed> */
    public function capture(int $characterId): array
    {
        $character = DB::table('characters')->find($characterId);
        if ($character === null) {
            throw new RuntimeException("Character {$characterId} does not exist.");
        }

        $state = ['schema_version' => 'a7-v1', 'character' => []];
        foreach (self::CHARACTER_COLUMNS as $column) {
            $state['character'][$column] = data_get($character, $column);
        }
        foreach (self::TABLES as $table) {
            $state[$table] = DB::table($table)
                ->where('character_id', $characterId)
                ->orderBy('id')
                ->get()
                ->map(static fn (object $row): array => (array) $row)
                ->all();
        }

        return $state;
    }

    /** @param array<string, mixed> $snapshot */
    public function restore(int $characterId, array $snapshot): void
    {
        $character = $this->validateSnapshot($characterId, $snapshot);
        DB::table('characters')->where('id', $characterId)->update(array_merge(
            array_intersect_key($character, array_flip(self::CHARACTER_COLUMNS)),
            ['updated_at' => now()],
        ));

        DB::table('warning_acknowledgements')->where('character_id', $characterId)->delete();
        DB::table('wizard_spellbook_entries')->where('character_id', $characterId)->delete();
        DB::table('spell_selection_slots')->where('character_id', $characterId)->delete();
        DB::table('character_source_instances')->where('character_id', $characterId)->delete();
        DB::table('character_class_levels')->where('character_id', $characterId)->delete();

        foreach (self::TABLES as $table) {
            foreach (data_get($snapshot, $table) as $row) {
                $row['character_id'] = $characterId;
                DB::table($table)->insert($row);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function validateSnapshot(int $characterId, array $snapshot): array
    {
        if (data_get($snapshot, 'schema_version') !== 'a7-v1') {
            throw new RuntimeException('Unsupported character snapshot schema.');
        }

        $character = data_get($snapshot, 'character');
        if (! is_array($character)) {
            throw new RuntimeException('Character snapshot is missing character data.');
        }
        foreach (self::CHARACTER_COLUMNS as $column) {
            if (! array_key_exists($column, $character)) {
                throw new RuntimeException("Character snapshot is missing {$column}.");
            }
        }

        $spellVersionIds = [];
        foreach (self::TABLES as $table) {
            $rows = data_get($snapshot, $table);
            if (! is_array($rows) || ! array_is_list($rows)) {
                throw new RuntimeException("Snapshot table {$table} must be a list.");
            }
            foreach ($rows as $row) {
                if (! is_array($row)) {
                    throw new RuntimeException("Snapshot table {$table} contains an invalid row.");
                }
                $rowCharacterId = data_get($row, 'character_id');
                if (! array_key_exists('character_id', $row)
                    || ! is_int($rowCharacterId)
                    || $rowCharacterId !== $characterId) {
                    throw new RuntimeException("Snapshot table {$table} contains a row belonging to another character.");
                }

                if ($table === 'spell_selection_slots') {
                    foreach (['fixed_spell_version_id', 'current_spell_version_id'] as $column) {
                        $versionId = data_get($row, $column);
                        if ($versionId !== null) {
                            if (! is_int($versionId) || $versionId < 1) {
                                throw new RuntimeException("Snapshot table {$table} contains an invalid {$column}.");
                            }
                            $spellVersionIds[] = $versionId;
                        }
                    }
                } elseif ($table === 'wizard_spellbook_entries') {
                    $versionId = data_get($row, 'spell_version_id');
                    if (! is_int($versionId) || $versionId < 1) {
                        throw new RuntimeException("Snapshot table {$table} contains an invalid spell_version_id.");
                    }
                    $spellVersionIds[] = $versionId;
                }
            }
        }

        $spellVersionIds = array_values(array_unique($spellVersionIds));
        $activeVersionIds = DB::table('spell_versions')
            ->whereIn('id', $spellVersionIds)
            ->where('is_active', true)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
        $inactiveVersionIds = array_values(array_diff($spellVersionIds, $activeVersionIds));
        if ($inactiveVersionIds !== []) {
            throw new RuntimeException(
                'Character snapshot references inactive spell version '.implode(', ', $inactiveVersionIds).'.',
            );
        }

        return $character;
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return list<array{entity_type: string, entity_id: int|null, previous_value: mixed, new_value: mixed}>
     */
    public function diff(array $before, array $after): array
    {
        $changes = [];
        if (data_get($before, 'character') !== data_get($after, 'character')) {
            $changes[] = [
                'entity_type' => 'character',
                'entity_id' => null,
                'previous_value' => data_get($before, 'character'),
                'new_value' => data_get($after, 'character'),
            ];
        }
        foreach (self::TABLES as $table) {
            $old = collect(data_get($before, $table, []))->keyBy('id');
            $new = collect(data_get($after, $table, []))->keyBy('id');
            foreach ($old->keys()->merge($new->keys())->unique()->sort() as $id) {
                $previous = $old->get($id);
                $next = $new->get($id);
                if ($previous === $next) {
                    continue;
                }
                $changes[] = [
                    'entity_type' => $table,
                    'entity_id' => is_numeric($id) ? (int) $id : null,
                    'previous_value' => $previous,
                    'new_value' => $next,
                ];
            }
        }

        return $changes;
    }
}
