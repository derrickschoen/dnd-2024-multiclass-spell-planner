<?php

declare(strict_types=1);

namespace App\Domain\Characters\Commands;

use App\Domain\Characters\CharacterState;
use App\Domain\Grants\GrantRuleSlotGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class UpdateClassCommand implements CharacterCommand
{
    /** @var array<string, mixed> */
    private array $before = [];

    /** @param array<string, mixed> $payload */
    public function __construct(
        private readonly array $payload,
        private readonly CharacterState $state,
        private readonly GrantRuleSlotGenerator $generator,
        private readonly CharacterCommandIntegrity $integrity,
    ) {}

    private int $characterId;

    public function apply(int $characterId): void
    {
        $this->characterId = $characterId;
        $this->before = $this->state->capture($characterId);
        $classId = (int) data_get($this->payload, 'class_definition_id');
        $class = DB::table('class_definitions')->find($classId);
        if ($class === null) {
            throw new InvalidArgumentException('Unknown class.');
        }

        $level = data_get($this->payload, 'level');
        if ($level === null) {
            $this->remove($characterId, $classId);

            return;
        }
        $level = (int) $level;
        if ($level < 1 || $level > 20) {
            throw new InvalidArgumentException('Class level must be between 1 and 20.');
        }
        $otherLevels = (int) DB::table('character_class_levels')
            ->where('character_id', $characterId)
            ->where('class_definition_id', '!=', $classId)
            ->sum('level');
        if ($otherLevels + $level > 20) {
            throw new InvalidArgumentException('A character cannot exceed level 20.');
        }

        $subclassId = data_get($this->payload, 'subclass_definition_id');
        if ($subclassId !== null && ! DB::table('subclass_definitions')
            ->where('id', $subclassId)
            ->where('class_definition_id', $classId)
            ->exists()) {
            throw new InvalidArgumentException('That subclass does not belong to the selected class.');
        }

        $existing = DB::table('character_class_levels')
            ->where('character_id', $characterId)
            ->where('class_definition_id', $classId)
            ->first();
        if ($existing === null) {
            DB::table('character_class_levels')->insert([
                'character_id' => $characterId,
                'class_definition_id' => $classId,
                'subclass_definition_id' => $subclassId,
                'level' => $level,
                'is_starting_class' => ! DB::table('character_class_levels')->where('character_id', $characterId)->exists(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            DB::table('character_class_levels')->where('id', data_get($existing, 'id'))->update([
                'subclass_definition_id' => $subclassId,
                'level' => $level,
                'updated_at' => now(),
            ]);
        }

        $source = DB::table('character_source_instances')
            ->where('character_id', $characterId)
            ->where('source_type', 'class')
            ->where('source_definition_id', $classId)
            ->first();
        $configData = $source === null || data_get($source, 'config') === null
            ? []
            : json_decode((string) data_get($source, 'config'), true, 512, JSON_THROW_ON_ERROR);
        $configData['spellcasting_ability'] = data_get($class, 'spellcasting_ability');
        $config = json_encode($configData, JSON_THROW_ON_ERROR);
        if ($source === null) {
            $sourceId = DB::table('character_source_instances')->insertGetId([
                'character_id' => $characterId,
                'instance_uuid' => Str::uuid()->toString(),
                'source_type' => 'class',
                'source_definition_id' => $classId,
                'display_name' => data_get($class, 'name')." {$level}",
                'config' => $config,
                'acquired_at_character_level' => max(1, $otherLevels + 1),
                'state' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            $sourceId = (int) data_get($source, 'id');
            DB::table('character_source_instances')->where('id', $sourceId)->update([
                'display_name' => data_get($class, 'name')." {$level}",
                'config' => $config,
                'state' => 'active',
                'updated_at' => now(),
            ]);
        }
        $this->generator->generateForSource($sourceId);
        $this->syncSubclass($characterId, $classId, $subclassId, $level);
    }

    private function syncSubclass(int $characterId, int $classId, mixed $subclassId, int $level): void
    {
        $subclassDefinitionIds = DB::table('subclass_definitions')
            ->where('class_definition_id', $classId)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
        $sources = DB::table('character_source_instances')
            ->where('character_id', $characterId)
            ->where('source_type', 'subclass')
            ->whereIn('source_definition_id', $subclassDefinitionIds)
            ->get();
        foreach ($sources as $source) {
            if ($subclassId !== null && (int) data_get($source, 'source_definition_id') === (int) $subclassId) {
                continue;
            }
            DB::table('character_source_instances')->where('id', data_get($source, 'id'))->update([
                'state' => 'tombstoned', 'updated_at' => now(),
            ]);
            $this->generator->generateForSource((int) data_get($source, 'id'));
        }
        if ($subclassId === null) {
            return;
        }

        $definition = DB::table('subclass_definitions')->find((int) $subclassId);
        $source = $sources->firstWhere('source_definition_id', (int) $subclassId);
        $configData = $source === null || data_get($source, 'config') === null
            ? []
            : json_decode((string) data_get($source, 'config'), true, 512, JSON_THROW_ON_ERROR);
        $configData['spellcasting_ability'] = data_get($definition, 'spellcasting_ability');
        $config = json_encode($configData, JSON_THROW_ON_ERROR);
        if ($source === null) {
            $sourceId = DB::table('character_source_instances')->insertGetId([
                'character_id' => $characterId,
                'instance_uuid' => Str::uuid()->toString(),
                'source_type' => 'subclass',
                'source_definition_id' => (int) $subclassId,
                'display_name' => (string) data_get($definition, 'name'),
                'config' => $config,
                'acquired_at_character_level' => $level,
                'state' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            $sourceId = (int) data_get($source, 'id');
            DB::table('character_source_instances')->where('id', $sourceId)->update([
                'display_name' => (string) data_get($definition, 'name'),
                'config' => $config,
                'state' => 'active',
                'updated_at' => now(),
            ]);
        }
        $this->generator->generateForSource($sourceId);
    }

    private function remove(int $characterId, int $classId): void
    {
        $subclassIds = DB::table('subclass_definitions')->where('class_definition_id', $classId)->pluck('id');
        $sources = DB::table('character_source_instances')
            ->where('character_id', $characterId)
            ->where(function ($query) use ($classId, $subclassIds): void {
                $query->where(function ($nested) use ($classId): void {
                    $nested->where('source_type', 'class')->where('source_definition_id', $classId);
                })->orWhere(function ($nested) use ($subclassIds): void {
                    $nested->where('source_type', 'subclass')->whereIn('source_definition_id', $subclassIds);
                });
            })
            ->get();
        foreach ($sources as $source) {
            DB::table('character_source_instances')->where('id', data_get($source, 'id'))->update([
                'state' => 'tombstoned', 'updated_at' => now(),
            ]);
            $this->generator->generateForSource((int) data_get($source, 'id'));
        }
        DB::table('character_class_levels')
            ->where('character_id', $characterId)
            ->where('class_definition_id', $classId)
            ->delete();
    }

    public function inverse(): array
    {
        return $this->integrity->attach($this->characterId, [
            'type' => 'restore_snapshot',
            'snapshot' => $this->before,
        ]);
    }

    public function actionType(): string
    {
        return 'update_class';
    }
}
