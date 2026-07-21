<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Grants\GrantRuleSlotGenerator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class SeedCharacterSeeder extends Seeder
{
    public function run(): void
    {
        if (DB::table('characters')->where('notes', 'seed:a6')->exists()) {
            return;
        }

        DB::transaction(function (): void {
            $characterId = DB::table('characters')->insertGetId([
                'name' => 'A6 Sixfold Spellcaster',
                'strength' => 10,
                'dexterity' => 10,
                'constitution' => 10,
                'intelligence' => 13,
                'wisdom' => 13,
                'charisma' => 17,
                'rules_edition_preference' => '2024',
                'notes' => 'seed:a6',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $generator = app(GrantRuleSlotGenerator::class);
            $classSources = [];
            foreach (['Sorcerer', 'Wizard', 'Bard', 'Paladin', 'Cleric', 'Druid'] as $index => $className) {
                $class = DB::table('class_definitions')->where('name', $className)->sole();
                DB::table('character_class_levels')->insert([
                    'character_id' => $characterId,
                    'class_definition_id' => data_get($class, 'id'),
                    'level' => 1,
                    'is_starting_class' => $index === 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $config = ['spellcasting_ability' => data_get($class, 'spellcasting_ability')];
                if ($className === 'Wizard') {
                    $config['wizard_spellbook_acquisitions'] = array_map(
                        static fn (string $key): array => [
                            'spell_version_key' => $key,
                            'acquisition' => 'starting',
                        ],
                        [
                            '2024:detect-magic', '2024:feather-fall', '2024:mage-armor',
                            '2024:magic-missile', '2024:sleep', '2024:thunderwave',
                        ],
                    );
                }
                $sourceId = $this->source(
                    $characterId,
                    'class',
                    (int) data_get($class, 'id'),
                    "{$className} 1",
                    $config,
                );
                $classSources[$className] = $sourceId;
                $generator->generateForSource($sourceId);
            }

            $human = DB::table('species_definitions')->where('content_key', '2024:species:human')->sole();
            $humanSource = $this->source(
                $characterId,
                'species',
                (int) data_get($human, 'id'),
                'Human',
                [
                    'origin_feat_key' => '2024:feat:magic-initiate',
                    'origin_feat_config' => [
                        'chosen_list' => 'Wizard',
                        'spellcasting_ability' => 'intelligence',
                    ],
                ],
            );
            $generator->generateForSource($humanSource);

            $background = DB::table('background_definitions')
                ->where('content_key', '2024:background:custom')
                ->sole();
            $backgroundSource = $this->source(
                $characterId,
                'background',
                (int) data_get($background, 'id'),
                'Custom Background',
                [
                    'origin_feat_key' => '2024:feat:magic-initiate',
                    'origin_feat_config' => [
                        'chosen_list' => 'Druid',
                        'spellcasting_ability' => 'wisdom',
                    ],
                ],
            );
            $generator->generateForSource($backgroundSource);

            $this->select((int) data_get($classSources, 'Wizard'), 'wizard-cantrips', 1, '2024:mage-hand');
            $magicInitiates = DB::table('character_source_instances')
                ->where('character_id', $characterId)
                ->where('source_type', 'feat')
                ->get();
            foreach ($magicInitiates as $source) {
                $chosenList = data_get(
                    json_decode((string) data_get($source, 'config'), true, 512, JSON_THROW_ON_ERROR),
                    'chosen_list',
                );
                if ($chosenList === 'Wizard') {
                    $this->select((int) data_get($source, 'id'), 'magic-initiate-cantrips', 1, '2024:mage-hand');
                    $this->select((int) data_get($source, 'id'), 'magic-initiate-cantrips', 2, '2024:prestidigitation');
                    $this->select((int) data_get($source, 'id'), 'magic-initiate-level-one', 1, '2024:shield');
                }
                if ($chosenList === 'Druid') {
                    $this->select((int) data_get($source, 'id'), 'magic-initiate-cantrips', 1, '2024:druidcraft');
                    $this->select((int) data_get($source, 'id'), 'magic-initiate-cantrips', 2, '2024:guidance');
                    $this->select((int) data_get($source, 'id'), 'magic-initiate-level-one', 1, '2024:entangle');
                }
            }

            foreach (['2024:mage-armor', '2024:magic-missile', '2024:sleep', '2024:thunderwave'] as $versionKey) {
                $entryId = DB::table('wizard_spellbook_entries as entry')
                    ->join('spell_versions as version', 'version.id', '=', 'entry.spell_version_id')
                    ->where('entry.character_id', $characterId)
                    ->where('version.content_key', $versionKey)
                    ->value('entry.id');
                if ($entryId === null) {
                    throw new RuntimeException("Seed spellbook entry {$versionKey} was not generated.");
                }
                DB::table('wizard_prepared_entries')->insert([
                    'character_id' => $characterId,
                    'wizard_spellbook_entry_id' => $entryId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });
    }

    /** @param array<string, mixed> $config */
    private function source(
        int $characterId,
        string $type,
        int $definitionId,
        string $displayName,
        array $config,
    ): int {
        return DB::table('character_source_instances')->insertGetId([
            'character_id' => $characterId,
            'instance_uuid' => Str::uuid()->toString(),
            'source_type' => $type,
            'source_definition_id' => $definitionId,
            'display_name' => $displayName,
            'config' => json_encode($config, JSON_THROW_ON_ERROR),
            'acquired_at_character_level' => 1,
            'state' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function select(int $sourceId, string $ruleKey, int $ordinal, string $versionKey): void
    {
        $versionId = DB::table('spell_versions')->where('content_key', $versionKey)->value('id');
        $slotId = DB::table('spell_selection_slots')
            ->where('source_instance_id', $sourceId)
            ->where('rule_key', $ruleKey)
            ->where('ordinal', $ordinal)
            ->value('id');
        if ($versionId === null || $slotId === null) {
            throw new RuntimeException("Unable to seed {$versionKey} into {$ruleKey}:{$ordinal}.");
        }
        DB::table('spell_selection_slots')->where('id', $slotId)->update([
            'current_spell_version_id' => $versionId,
            'updated_at' => now(),
        ]);
    }
}
