<?php

declare(strict_types=1);

namespace App\Domain\Characters\Commands;

use App\Domain\Characters\CharacterState;
use App\Domain\Grants\GrantRuleSlotGenerator;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class UpdateSourceConfigCommand implements CharacterCommand
{
    /** @var list<string> */
    private const ALLOWED_LISTS = ['Cleric', 'Druid', 'Wizard'];

    /** @var array<string, array{key: string, options: list<string>, bonus: string}> */
    private const ORDER_DEFINITIONS = [
        'Cleric' => [
            'key' => 'divine_order',
            'options' => ['Protector', 'Thaumaturge'],
            'bonus' => 'Thaumaturge',
        ],
        'Druid' => [
            'key' => 'primal_order',
            'options' => ['Warden', 'Magician'],
            'bonus' => 'Magician',
        ],
    ];

    /** @var array<string, mixed> */
    private array $previousState = [];

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
        $sourceId = (int) data_get($this->payload, 'source_instance_id');
        $source = DB::table('character_source_instances')
            ->where('character_id', $characterId)
            ->where('id', $sourceId)
            ->where('state', 'active')
            ->first();
        if ($source === null) {
            throw new InvalidArgumentException('Configurable source does not belong to this character.');
        }
        $config = $this->decodeConfig($source);
        if (data_get($source, 'source_type') === 'class') {
            $this->updateClassOrder($source, $config);

            return;
        }

        $definition = data_get($source, 'source_type') === 'feat'
            ? DB::table('feat_definitions')->find(data_get($source, 'source_definition_id'))
            : null;
        if ($definition === null || data_get($definition, 'content_key') !== '2024:feat:magic-initiate') {
            throw new InvalidArgumentException('Only Magic Initiate list configuration is editable here.');
        }
        $this->updateMagicInitiate($source, $config);
    }

    /** @param array<string, mixed> $config */
    private function updateMagicInitiate(object $source, array $config): void
    {
        $chosenList = trim((string) data_get($this->payload, 'chosen_list'));
        if (! in_array($chosenList, self::ALLOWED_LISTS, true)) {
            throw new InvalidArgumentException('Magic Initiate must use the Cleric, Druid, or Wizard spell list.');
        }
        $ability = DB::table('class_definitions')->where('name', $chosenList)->value('spellcasting_ability');
        if (! is_string($ability) || $ability === '') {
            throw new InvalidArgumentException('Choose a spell list with a defined spellcasting ability.');
        }

        $this->previousState = $this->state->capture($this->characterId);
        $config['chosen_list'] = $chosenList;
        $config['spellcasting_ability'] = strtolower($ability);
        DB::table('character_source_instances')->where('id', data_get($source, 'id'))->update([
            'display_name' => 'Magic Initiate: '.$chosenList,
            'config' => json_encode($config, JSON_THROW_ON_ERROR),
            'updated_at' => now(),
        ]);
        $parentId = data_get($source, 'parent_source_instance_id');
        $parent = $parentId === null ? null : DB::table('character_source_instances')->find($parentId);
        if ($parent !== null) {
            $parentConfig = json_decode((string) data_get($parent, 'config'), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($parentConfig) || ! is_array(data_get($parentConfig, 'origin_feat_config'))) {
                throw new InvalidArgumentException('Magic Initiate parent configuration is missing origin_feat_config.');
            }
            $parentConfig['origin_feat_config'] = $config;
            DB::table('character_source_instances')->where('id', $parentId)->update([
                'config' => json_encode($parentConfig, JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
            $this->generator->generateForSource((int) $parentId);

            return;
        }

        $this->generator->generateForSource((int) data_get($source, 'id'));
    }

    /** @param array<string, mixed> $config */
    private function updateClassOrder(object $source, array $config): void
    {
        $definition = DB::table('class_definitions')->find(data_get($source, 'source_definition_id'));
        $className = $definition === null ? '' : (string) data_get($definition, 'name');
        $order = data_get(self::ORDER_DEFINITIONS, $className);
        if (! is_array($order)) {
            throw new InvalidArgumentException('Only Cleric or Druid class sources can configure an Order.');
        }

        $chosenOption = trim((string) data_get($this->payload, 'chosen_option'));
        if (! in_array($chosenOption, data_get($order, 'options'), true)) {
            throw new InvalidArgumentException("{$className} ".data_get($order, 'key').' has an invalid chosen option.');
        }

        $this->previousState = $this->state->capture($this->characterId);
        $value = ['chosen_option' => $chosenOption];
        if ($chosenOption === data_get($order, 'bonus')) {
            $value['chosen_list'] = $className;
        }
        $config[(string) data_get($order, 'key')] = $value;
        DB::table('character_source_instances')->where('id', data_get($source, 'id'))->update([
            'config' => json_encode($config, JSON_THROW_ON_ERROR),
            'updated_at' => now(),
        ]);
        $this->generator->generateForSource((int) data_get($source, 'id'));
    }

    /** @return array<string, mixed> */
    private function decodeConfig(object $source): array
    {
        $config = data_get($source, 'config');
        $decoded = ($config === null || $config === '')
            ? []
            : json_decode((string) $config, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw new InvalidArgumentException('Source configuration must be an object.');
        }

        return $decoded;
    }

    public function inverse(): array
    {
        return $this->integrity->attach($this->characterId, [
            'type' => 'restore_snapshot',
            'snapshot' => $this->previousState,
        ]);
    }

    public function actionType(): string
    {
        return 'update_source_config';
    }
}
