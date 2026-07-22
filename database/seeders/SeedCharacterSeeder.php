<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Characters\CharacterCommandExecutor;
use App\Domain\Grants\GrantRuleSlotGenerator;
use App\Domain\Spells\SpellSelectionService;
use Illuminate\Database\Seeder;
use App\Domain\Catalog\CatalogSource;
use Illuminate\Support\Facades\DB;
use Throwable;
use Illuminate\Support\Str;
use RuntimeException;

final class SeedCharacterSeeder extends Seeder
{
    public function run(): void
    {
        if (! DB::table('characters')->where('notes', 'seed:a6')->exists()) {
            DB::transaction(fn () => $this->seedA6());
        }
        if (! DB::table('characters')->where('notes', 'like', "seed:mutt\n%")->exists()) {
            // Mutt is built from a real character sheet that legitimately uses
            // non-SRD content (Thunderclap, and Mold Earth from Xanathar's). A
            // fresh clone ships only the CC-BY SRD 5.2.1 subset, so those spells
            // are absent until `npm run scrape` has been run. Skipping with a
            // clear message keeps `migrate:fresh --seed` working, which is the
            // documented setup path; failing here would break it outright.
            try {
                $this->seedMutt();
            } catch (Throwable $exception) {
                if (! CatalogSource::isSrdOnly()) {
                    throw $exception;
                }
                $this->command?->warn(
                    'Skipped the "Mutt" sample character: it uses spells outside the '
                    .'SRD 5.2.1 subset. Run `npm run scrape` to build the full catalog, '
                    .'then re-run this seeder.'
                );
            }
        }
    }

    private function seedA6(): void
    {
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

        foreach (['2024:mage-armor', '2024:magic-missile', '2024:sleep', '2024:thunderwave'] as $index => $versionKey) {
            $this->select(
                (int) data_get($classSources, 'Wizard'),
                'wizard-prepared',
                $index + 1,
                $versionKey,
            );
        }
    }

    private function seedMutt(): void
    {
        // The PDF has no ability-score block. These three spellcasting scores are
        // INFERRED from its +6/+4 spell attacks (PB 3 plus modifiers +3/+1).
        $inferredSpellcastingAbilities = [
            'intelligence' => 13,
            'wisdom' => 13,
            'charisma' => 17,
        ];
        $characterId = DB::table('characters')->insertGetId([
            'name' => 'Mutt',
            'strength' => 10,
            'dexterity' => 10,
            'constitution' => 10,
            ...$inferredSpellcastingAbilities,
            'rules_edition_preference' => '2024',
            'notes' => implode("\n", [
                'seed:mutt',
                'sheet:max_hp=43',
                'sheet:advancement=milestone',
                'INFERRED abilities (PDF has no scores): CHA 17 / INT 13 / WIS 13 from PB 3 and +6/+4 spell attacks.',
                'UNSPECIFIED abilities defaulted for this planner: STR 10 / DEX 10 / CON 10.',
                'AUTHORITATIVE spell attribution: user-confirmed per-class sheet assignments in seedMutt().',
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $revision = 0;
        $this->executeMutt($characterId, $revision, [
            'type' => 'update_character_rules',
            'allow_legacy' => true,
            'reason' => 'Mutt uses confirmed legacy content (Mold Earth).',
        ]);

        $wizardSpellbook = array_map(
            static fn (string $key): array => [
                'spell_version_key' => $key,
                'acquisition' => 'starting',
            ],
            [
                '2024:comprehend-languages',
                '2024:feather-fall',
                '2024:find-familiar',
                '2024:shield',
                '2024:tenser-s-floating-disk',
                '2024:unseen-servant',
            ],
        );
        $classSources = [];
        foreach (['Sorcerer', 'Bard', 'Cleric', 'Druid', 'Paladin', 'Wizard'] as $className) {
            $classId = (int) DB::table('class_definitions')->where('name', $className)->value('id');
            $config = ['level' => 1];
            if ($className === 'Cleric') {
                $config['divine_order'] = [
                    'chosen_option' => 'Thaumaturge',
                    'chosen_list' => 'Cleric',
                ];
            }
            if ($className === 'Druid') {
                $config['primal_order'] = [
                    'chosen_option' => 'Warden',
                ];
            }
            if ($className === 'Wizard') {
                $config['wizard_spellbook_acquisitions'] = $wizardSpellbook;
            }
            $this->executeMutt($characterId, $revision, [
                'type' => 'add_source',
                'source_type' => 'class',
                'source_definition_id' => $classId,
                'config' => $config,
                'reason' => "Seed Mutt's {$className} class through add_source.",
            ]);
            $classSources[$className] = (int) DB::table('character_source_instances')
                ->where('character_id', $characterId)
                ->where('source_type', 'class')
                ->where('source_definition_id', $classId)
                ->where('state', 'active')
                ->value('id');
        }

        $cantrips = [
            'Sorcerer' => [
                '2024:chill-touch', '2024:ray-of-frost', '2024:shocking-grasp', '2024:true-strike',
            ],
            'Bard' => ['2024:thunderclap', '2024:vicious-mockery'],
            'Cleric' => ['2024:light', '2024:spare-the-dying', '2024:thaumaturgy'],
            'Druid' => ['2014:shape-water', '2024:shillelagh'],
            'Wizard' => ['2024:mage-hand', '2024:minor-illusion', '2014:mold-earth'],
        ];
        foreach ($cantrips as $className => $versions) {
            foreach ($versions as $index => $versionKey) {
                $this->selectMutt(
                    $characterId,
                    $revision,
                    (int) data_get($classSources, $className),
                    strtolower($className).'-cantrips',
                    $index + 1,
                    $versionKey,
                    'Sheet-attributed cantrip.',
                );
            }
        }

        $this->selectMutt(
            $characterId,
            $revision,
            (int) data_get($classSources, 'Cleric'),
            'cleric-divine-order-cantrip',
            1,
            '2024:guidance',
            'User-confirmed Divine Order (Thaumaturge) bonus cantrip.',
        );

        // These assignments are authoritative user-confirmed sheet data. Do not
        // infer alternatives from class eligibility or duplicate spell names.
        $authoritativeLeveledAssignments = [
            'Sorcerer' => ['2024:chromatic-orb', '2024:ray-of-sickness'],
            'Bard' => ['2024:bane', '2024:dissonant-whispers', '2024:sleep', '2024:thunderwave'],
            'Cleric' => ['2024:create-or-destroy-water', '2024:cure-wounds', '2024:healing-word', '2024:sanctuary'],
            'Druid' => ['2014:absorb-elements', '2024:goodberry', '2024:jump', '2024:speak-with-animals'],
            'Paladin' => ['2024:thunderous-smite', '2024:wrathful-smite'],
            'Wizard' => ['2024:feather-fall', '2024:find-familiar', '2024:shield', '2024:unseen-servant'],
        ];
        foreach ($authoritativeLeveledAssignments as $className => $versions) {
            foreach ($versions as $index => $versionKey) {
                $this->selectMutt(
                    $characterId,
                    $revision,
                    (int) data_get($classSources, $className),
                    strtolower($className).'-prepared',
                    $index + 1,
                    $versionKey,
                    'Authoritative user-confirmed sheet attribution.',
                );
            }
        }
    }

    /** @param array<string, mixed> $payload */
    private function executeMutt(int $characterId, int &$revision, array $payload): void
    {
        $result = app(CharacterCommandExecutor::class)->execute(
            $characterId,
            $payload,
            Str::uuid()->toString(),
            $revision,
        );
        $revision = (int) data_get($result, 'revision');
    }

    private function selectMutt(
        int $characterId,
        int &$revision,
        int $sourceId,
        string $ruleKey,
        int $ordinal,
        string $versionKey,
        string $reason,
    ): void {
        $versionId = DB::table('spell_versions')->where('content_key', $versionKey)->value('id');
        $slotId = DB::table('spell_selection_slots')
            ->where('character_id', $characterId)
            ->where('source_instance_id', $sourceId)
            ->where('rule_key', $ruleKey)
            ->where('ordinal', $ordinal)
            ->value('id');
        if ($versionId === null || $slotId === null) {
            throw new RuntimeException("Unable to seed {$versionKey} into {$ruleKey}:{$ordinal}.");
        }
        $this->executeMutt($characterId, $revision, [
            'type' => 'set_slot',
            'slot_id' => (int) $slotId,
            'mode' => 'select',
            'spell_version_id' => (int) $versionId,
            'reason' => $reason,
        ]);
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
        app(SpellSelectionService::class)->select((int) $slotId, (int) $versionId);
    }
}
