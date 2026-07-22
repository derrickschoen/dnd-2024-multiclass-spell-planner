<?php

declare(strict_types=1);

namespace App\Domain\Characters\Commands;

use App\Domain\Characters\CharacterState;
use App\Domain\Characters\SourceType;
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
        $sourceTypeValue = data_get($this->payload, 'source_type');
        $sourceType = is_string($sourceTypeValue) ? SourceType::tryFrom($sourceTypeValue) : null;
        if ($sourceType === null) {
            throw new InvalidArgumentException('Unknown source type.');
        }
        /** @var int $definitionId */
        $definitionId = data_get($this->payload, 'source_definition_id');
        $definition = DB::table($sourceType->definitionTable())->find($definitionId);
        if (! is_object($definition)) {
            throw new InvalidArgumentException('Unknown source definition for the selected source type.');
        }

        if (($sourceType === SourceType::CharacterClass || ! data_get($definition, 'repeatable')) && DB::table('character_source_instances')
            ->where('character_id', $characterId)
            ->where('source_type', $sourceType->value)
            ->where('source_definition_id', $definitionId)
            ->where('state', 'active')
            ->exists()) {
            throw new InvalidArgumentException(data_get($definition, 'name').' is not repeatable.');
        }

        $config = data_get($this->payload, 'config');
        if (! is_array($config)) {
            throw new InvalidArgumentException('Source config must be an object.');
        }
        if ($sourceType === SourceType::CharacterClass) {
            $this->addClass($characterId, $definition, $config);

            return;
        }
        $this->validateConfiguration(data_get($definition, 'content_key'), $config);

        $this->before = $this->state->capture($characterId);
        $sourceId = DB::table('character_source_instances')->insertGetId([
            'character_id' => $characterId,
            'instance_uuid' => Str::uuid()->toString(),
            'source_type' => $sourceType->value,
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
        $orderConfig = $this->validatedOrderConfig((string) data_get($definition, 'name'), $config);

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
        if ($orderConfig !== null) {
            $sourceConfig[data_get($orderConfig, 'key')] = data_get($orderConfig, 'value');
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

    /** @param array<string, mixed> $config
     * @return array{key: string, value: array<string, string>}|null
     */
    private function validatedOrderConfig(string $className, array $config): ?array
    {
        if (array_key_exists('divine_order', $config) && $className !== 'Cleric') {
            throw new InvalidArgumentException('Only a Cleric class source can configure Divine Order.');
        }
        if (array_key_exists('primal_order', $config) && $className !== 'Druid') {
            throw new InvalidArgumentException('Only a Druid class source can configure Primal Order.');
        }
        $definition = match ($className) {
            'Cleric' => ['key' => 'divine_order', 'options' => ['Protector', 'Thaumaturge'], 'bonus' => 'Thaumaturge'],
            'Druid' => ['key' => 'primal_order', 'options' => ['Warden', 'Magician'], 'bonus' => 'Magician'],
            default => null,
        };
        if ($definition === null) {
            return null;
        }

        $key = (string) data_get($definition, 'key');
        $value = data_get($config, $key);
        if ($value === null) {
            return null;
        }
        if (! is_array($value)) {
            throw new InvalidArgumentException("{$className} {$key} config must be an object.");
        }
        $chosenOption = data_get($value, 'chosen_option');
        if (! is_string($chosenOption) || ! in_array($chosenOption, data_get($definition, 'options'), true)) {
            throw new InvalidArgumentException("{$className} {$key} has an invalid chosen option.");
        }
        $chosenList = data_get($value, 'chosen_list');
        if ($chosenOption === data_get($definition, 'bonus') && $chosenList !== $className) {
            throw new InvalidArgumentException("{$className} {$chosenOption} must use the {$className} spell list.");
        }
        if ($chosenOption !== data_get($definition, 'bonus') && $chosenList !== null) {
            throw new InvalidArgumentException("{$className} {$chosenOption} must not configure a spell list.");
        }

        $normalized = ['chosen_option' => $chosenOption];
        if (is_string($chosenList)) {
            $normalized['chosen_list'] = $chosenList;
        }

        return ['key' => $key, 'value' => $normalized];
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
