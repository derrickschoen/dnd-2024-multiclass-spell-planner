<?php

declare(strict_types=1);

namespace App\Domain\Characters\Commands;

use App\Domain\Characters\CharacterState;
use App\Domain\Grants\GrantRuleSlotGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class AddSourceCommand implements CharacterCommand
{
    /** @var list<string> */
    private const MAGIC_INITIATE_LISTS = ['Cleric', 'Druid', 'Wizard'];

    /** @var list<string> */
    private const MAGIC_INITIATE_ABILITIES = ['intelligence', 'wisdom', 'charisma'];

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
        /** @var string $sourceType */
        $sourceType = data_get($this->payload, 'source_type');
        /** @var int $definitionId */
        $definitionId = data_get($this->payload, 'source_definition_id');
        $definition = DB::table($this->definitionTable($sourceType))->find($definitionId);
        if ($definition === null) {
            throw new InvalidArgumentException('Unknown source definition for the selected source type.');
        }

        if (($sourceType === 'class' || ! data_get($definition, 'repeatable')) && DB::table('character_source_instances')
            ->where('character_id', $characterId)
            ->where('source_type', $sourceType)
            ->where('source_definition_id', $definitionId)
            ->where('state', 'active')
            ->exists()) {
            throw new InvalidArgumentException(data_get($definition, 'name').' is not repeatable.');
        }

        $config = data_get($this->payload, 'config');
        if (! is_array($config)) {
            throw new InvalidArgumentException('Source config must be an object.');
        }
        if ($sourceType === 'class') {
            $this->addClass($characterId, $definition, $config);

            return;
        }
        $this->validateConfiguration(data_get($definition, 'content_key'), $config);

        $this->before = $this->state->capture($characterId);
        $sourceId = DB::table('character_source_instances')->insertGetId([
            'character_id' => $characterId,
            'instance_uuid' => Str::uuid()->toString(),
            'source_type' => $sourceType,
            'source_definition_id' => $definitionId,
            'display_name' => $this->displayName($definition, $config),
            'config' => json_encode($config, JSON_THROW_ON_ERROR),
            'acquired_at_character_level' => max(1, DB::table('character_class_levels')
                ->where('character_id', $characterId)->sum('level')),
            'state' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->generator->generateForSource($sourceId);
    }

    /** @param array<string, mixed> $config */
    private function addClass(int $characterId, object $definition, array $config): void
    {
        $level = data_get($config, 'level');
        if (! is_int($level) || $level < 1 || $level > 20) {
            throw new InvalidArgumentException('Class source level must be between 1 and 20.');
        }
        $classId = (int) data_get($definition, 'id');
        if (DB::table('character_class_levels')
            ->where('character_id', $characterId)
            ->where('class_definition_id', $classId)
            ->exists()) {
            throw new InvalidArgumentException(data_get($definition, 'name').' is not repeatable.');
        }
        $otherLevels = (int) DB::table('character_class_levels')
            ->where('character_id', $characterId)
            ->sum('level');
        if ($otherLevels + $level > 20) {
            throw new InvalidArgumentException('A character cannot exceed level 20.');
        }

        $acquisitions = data_get($config, 'wizard_spellbook_acquisitions');
        if ($acquisitions !== null && data_get($definition, 'name') !== 'Wizard') {
            throw new InvalidArgumentException('Only a Wizard class source can configure a spellbook.');
        }
        if ($acquisitions !== null && (! is_array($acquisitions) || ! array_is_list($acquisitions))) {
            throw new InvalidArgumentException('Wizard spellbook acquisitions must be a list.');
        }

        $this->before = $this->state->capture($characterId);
        DB::table('character_class_levels')->insert([
            'character_id' => $characterId,
            'class_definition_id' => $classId,
            'level' => $level,
            'is_starting_class' => $otherLevels === 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $sourceConfig = ['spellcasting_ability' => data_get($definition, 'spellcasting_ability')];
        if ($acquisitions !== null) {
            $sourceConfig['wizard_spellbook_acquisitions'] = $acquisitions;
        }
        $sourceId = DB::table('character_source_instances')->insertGetId([
            'character_id' => $characterId,
            'instance_uuid' => Str::uuid()->toString(),
            'source_type' => 'class',
            'source_definition_id' => $classId,
            'display_name' => data_get($definition, 'name')." {$level}",
            'config' => json_encode($sourceConfig, JSON_THROW_ON_ERROR),
            'acquired_at_character_level' => $otherLevels + 1,
            'state' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->generator->generateForSource($sourceId);
    }

    /** @param array<string, mixed> $config */
    private function validateConfiguration(string $contentKey, array $config): void
    {
        if ($contentKey === '2024:feat:magic-initiate') {
            $this->validateMagicInitiate($config);
        } elseif (data_get($config, 'origin_feat_key') === '2024:feat:magic-initiate') {
            $originFeatConfig = data_get($config, 'origin_feat_config');
            if (! is_array($originFeatConfig)) {
                throw new InvalidArgumentException('Magic Initiate origin feat config must be an object.');
            }
            $this->validateMagicInitiate($originFeatConfig);
        }
    }

    /** @param array<string, mixed> $config */
    private function validateMagicInitiate(array $config): void
    {
        $chosenList = data_get($config, 'chosen_list');
        if (! is_string($chosenList) || ! in_array($chosenList, self::MAGIC_INITIATE_LISTS, true)) {
            throw new InvalidArgumentException('Magic Initiate must use the Cleric, Druid, or Wizard spell list.');
        }
        $ability = data_get($config, 'spellcasting_ability');
        if (! is_string($ability) || ! in_array($ability, self::MAGIC_INITIATE_ABILITIES, true)) {
            throw new InvalidArgumentException('Magic Initiate must use Intelligence, Wisdom, or Charisma.');
        }
    }

    private function definitionTable(string $sourceType): string
    {
        return match ($sourceType) {
            'class' => 'class_definitions',
            'feat' => 'feat_definitions',
            'species' => 'species_definitions',
            'background' => 'background_definitions',
        };
    }

    /** @param array<string, mixed> $config */
    private function displayName(object $definition, array $config): string
    {
        /** @var string $name */
        $name = data_get($definition, 'name');
        $chosenList = data_get($config, 'chosen_list');

        return is_string($chosenList) && $chosenList !== '' ? "{$name}: {$chosenList}" : $name;
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
        return 'add_source';
    }
}
