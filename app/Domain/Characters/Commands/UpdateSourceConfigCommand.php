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
        $definition = data_get($source, 'source_type') === 'feat'
            ? DB::table('feat_definitions')->find(data_get($source, 'source_definition_id'))
            : null;
        if ($definition === null || data_get($definition, 'content_key') !== '2024:feat:magic-initiate') {
            throw new InvalidArgumentException('Only Magic Initiate list configuration is editable here.');
        }

        $chosenList = trim((string) data_get($this->payload, 'chosen_list'));
        if (! in_array($chosenList, self::ALLOWED_LISTS, true)) {
            throw new InvalidArgumentException('Magic Initiate must use the Cleric, Druid, or Wizard spell list.');
        }
        $ability = DB::table('class_definitions')->where('name', $chosenList)->value('spellcasting_ability');
        if (! is_string($ability) || $ability === '') {
            throw new InvalidArgumentException('Choose a spell list with a defined spellcasting ability.');
        }

        $this->previousState = $this->state->capture($characterId);
        $config = data_get($source, 'config');
        $config = ($config === null || $config === '')
            ? []
            : json_decode((string) $config, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($config)) {
            throw new InvalidArgumentException('Source configuration must be an object.');
        }
        $config['chosen_list'] = $chosenList;
        $config['spellcasting_ability'] = strtolower($ability);
        DB::table('character_source_instances')->where('id', $sourceId)->update([
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

        $this->generator->generateForSource($sourceId);
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
