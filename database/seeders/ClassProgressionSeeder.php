<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Grants\GrantRuleKind;
use App\Domain\Grants\SlotBucket;
use App\Domain\Rules\CasterContribution;
use App\Domain\Rules\CastingMode;
use App\Domain\Rules\SpellSlots;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

final class ClassProgressionSeeder extends Seeder
{
    private const STANDARD_PREPARED = [
        4, 5, 6, 7, 9, 10, 11, 12, 14, 15, 16, 16, 17, 17, 18, 18, 19, 20, 21, 22,
    ];

    private const SORCERER_PREPARED = [
        2, 4, 6, 7, 9, 10, 11, 12, 14, 15, 16, 16, 17, 17, 18, 18, 19, 20, 21, 22,
    ];

    private const WIZARD_PREPARED = [
        4, 5, 6, 7, 9, 10, 11, 12, 14, 15, 16, 16, 17, 18, 19, 21, 22, 23, 24, 25,
    ];

    private const HALF_PREPARED = [
        2, 3, 4, 5, 6, 6, 7, 7, 9, 9, 10, 10, 11, 11, 12, 12, 14, 14, 15, 15,
    ];

    private const WARLOCK_PREPARED = [
        2, 3, 4, 5, 6, 7, 8, 9, 10, 10, 11, 11, 12, 12, 13, 13, 14, 14, 15, 15,
    ];

    public function run(): void
    {
        $classes = [
            'Barbarian' => [null, CasterContribution::NONE, null, null, array_fill(0, 20, 0), array_fill(0, 20, 0)],
            'Bard' => ['charisma', CasterContribution::FULL, '1', null, $this->cantrips(2, 3, 4), self::STANDARD_PREPARED],
            'Cleric' => ['wisdom', CasterContribution::FULL, '1', null, $this->cantrips(3, 4, 5), self::STANDARD_PREPARED],
            'Druid' => ['wisdom', CasterContribution::FULL, '1', null, $this->cantrips(2, 3, 4), self::STANDARD_PREPARED],
            'Fighter' => [null, CasterContribution::NONE, null, null, array_fill(0, 20, 0), array_fill(0, 20, 0)],
            'Monk' => [null, CasterContribution::NONE, null, null, array_fill(0, 20, 0), array_fill(0, 20, 0)],
            'Paladin' => ['charisma', CasterContribution::HALF_UP, '1/2', 'up', array_fill(0, 20, 0), self::HALF_PREPARED],
            'Ranger' => ['wisdom', CasterContribution::HALF_UP, '1/2', 'up', array_fill(0, 20, 0), self::HALF_PREPARED],
            'Rogue' => [null, CasterContribution::NONE, null, null, array_fill(0, 20, 0), array_fill(0, 20, 0)],
            'Sorcerer' => ['charisma', CasterContribution::FULL, '1', null, $this->cantrips(4, 5, 6), self::SORCERER_PREPARED],
            'Warlock' => ['charisma', CasterContribution::PACT, null, null, $this->cantrips(2, 3, 4), self::WARLOCK_PREPARED],
            'Wizard' => ['intelligence', CasterContribution::FULL, '1', null, $this->cantrips(3, 4, 5), self::WIZARD_PREPARED],
        ];

        foreach ($classes as $name => [$ability, $type, $fraction, $rounding, $cantrips, $prepared]) {
            $classId = $this->upsertClass($name, $ability, $type, $fraction, $rounding);
            for ($level = 1; $level <= 20; $level++) {
                $contribution = new CasterContribution($name, $level, $type);
                $slots = $contribution->progression->contributesToSharedSlots()
                    ? SpellSlots::slots([$contribution])
                    : [];
                $pact = $type === CasterContribution::PACT
                    ? SpellSlots::pactMagic([$contribution])
                    : null;
                $cantripCount = (int) data_get($cantrips, $level - 1, 0);
                $preparedCount = (int) data_get($prepared, $level - 1, 0);

                DB::table('class_progressions')->updateOrInsert(
                    ['class_definition_id' => $classId, 'class_level' => $level],
                    [
                        'cantrips_known' => $cantripCount,
                        'prepared_count' => $preparedCount,
                        'slots' => json_encode($slots, JSON_THROW_ON_ERROR),
                        'pact_slots' => json_encode($pact ?? [], JSON_THROW_ON_ERROR),
                        'grant_rules' => json_encode(
                            $this->grantRules($name, $contribution, $cantripCount, $preparedCount),
                            JSON_THROW_ON_ERROR,
                        ),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                );
            }
        }

        $eldritchKnightId = $this->upsertThirdCaster('Fighter', 'Eldritch Knight', '2024:subclass:eldritch-knight');
        $arcaneTricksterId = $this->upsertThirdCaster('Rogue', 'Arcane Trickster', '2024:subclass:arcane-trickster');
        $this->seedThirdCasterProgression($eldritchKnightId, 'eldritch-knight', [
            0, 0, 3, 4, 4, 4, 5, 6, 6, 7, 8, 8, 9, 10, 10, 11, 11, 11, 12, 13,
        ], 2, 3);
        $this->seedThirdCasterProgression($arcaneTricksterId, 'arcane-trickster', [
            0, 0, 3, 4, 4, 4, 5, 6, 6, 7, 8, 8, 9, 10, 10, 11, 11, 11, 12, 13,
        ], 3, 4);
    }

    /** @return list<int> */
    private function cantrips(int $levelsOneToThree, int $levelsFourToNine, int $levelsTenToTwenty): array
    {
        return [
            ...array_fill(0, 3, $levelsOneToThree),
            ...array_fill(0, 6, $levelsFourToNine),
            ...array_fill(0, 11, $levelsTenToTwenty),
        ];
    }

    private function upsertClass(
        string $name,
        ?string $ability,
        string $type,
        ?string $fraction,
        ?string $rounding,
    ): int {
        $contentKey = '2024:class:'.strtolower($name);
        DB::table('class_definitions')->updateOrInsert(
            ['content_key' => $contentKey],
            [
                'name' => $name,
                'rules_edition' => '2024',
                'spellcasting_ability' => $ability,
                'progression_type' => $type,
                'caster_fraction' => $fraction,
                'caster_rounding' => $rounding,
                'prepares_or_knows' => $ability === null ? null : 'prepared',
                'supports_ritual_casting' => $ability !== null,
                'ritual_casting_mode' => $name === 'Wizard' ? 'spellbook' : ($ability === null ? null : 'prepared'),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        return (int) DB::table('class_definitions')->where('content_key', $contentKey)->value('id');
    }

    /** @return list<array<string, mixed>> */
    private function grantRules(
        string $name,
        CasterContribution $contribution,
        int $cantripCount,
        int $preparedCount,
    ): array {
        if ($contribution->progressionType === CasterContribution::NONE) {
            return [];
        }

        $list = $name;
        $key = strtolower($name);
        $rules = [];
        $arcanumLevels = $name === 'Warlock'
            ? array_keys(array_filter([
                6 => $contribution->classLevel >= 11,
                7 => $contribution->classLevel >= 13,
                8 => $contribution->classLevel >= 15,
                9 => $contribution->classLevel >= 17,
            ]))
            : [];
        $ordinaryPreparedCount = $preparedCount;
        if ($cantripCount > 0) {
            $rules[] = [
                'kind' => 'choice_from_list',
                'rule_key' => "{$key}-cantrips",
                'count' => $cantripCount,
                'bucket' => 'cantrip_known',
                'list' => $list,
                'level_min' => 0,
                'level_max' => 0,
                'with_slots' => false,
            ];
        }
        if ($ordinaryPreparedCount > 0) {
            $rules[] = [
                'kind' => 'choice_from_list',
                'rule_key' => "{$key}-prepared",
                'count' => $ordinaryPreparedCount,
                'bucket' => 'prepared',
                'list' => $list,
                'level_min' => 1,
                'level_max' => SpellSlots::maxPreparableLevelForClass($contribution),
                'with_slots' => true,
            ];
        }
        if ($name === 'Cleric') {
            $rules[] = [
                'kind' => 'choice_from_list',
                'rule_key' => 'cleric-divine-order-cantrip',
                'count' => 1,
                'bucket' => 'cantrip_known',
                'list' => '$config.divine_order.chosen_list',
                'level_min' => 0,
                'level_max' => 0,
                'with_slots' => false,
                'active_from_class_level' => 1,
                'active_if_config' => [
                    'key' => 'divine_order.chosen_option',
                    'equals' => 'Thaumaturge',
                ],
            ];
        }
        if ($name === 'Druid') {
            $rules[] = [
                'kind' => 'choice_from_list',
                'rule_key' => 'druid-primal-order-cantrip',
                'count' => 1,
                'bucket' => 'cantrip_known',
                'list' => '$config.primal_order.chosen_list',
                'level_min' => 0,
                'level_max' => 0,
                'with_slots' => false,
                'active_from_class_level' => 1,
                'active_if_config' => [
                    'key' => 'primal_order.chosen_option',
                    'equals' => 'Magician',
                ],
            ];
        }
        foreach ($arcanumLevels as $spellLevel) {
            $rules[] = [
                'kind' => 'choice_from_list',
                'rule_key' => "warlock-mystic-arcanum-{$spellLevel}",
                'count' => 1,
                'bucket' => 'prepared',
                'list' => 'Warlock',
                'level_min' => $spellLevel,
                'level_max' => $spellLevel,
                'with_slots' => false,
                'free_cast' => [
                    'uses' => 1,
                    'recovery' => 'long_rest',
                    'pool_scope' => 'per_spell',
                ],
            ];
        }
        if ($name === 'Wizard') {
            $rules[] = [
                'kind' => GrantRuleKind::SpellbookAcquisition->value,
                'rule_key' => 'wizard-spellbook',
                'bucket' => SlotBucket::Spellbook->value,
                'list' => 'Wizard',
                'acquisitions_config' => 'wizard_spellbook_acquisitions',
            ];
            $rules[] = [
                'kind' => GrantRuleKind::Capability->value,
                'rule_key' => 'ritual-adept',
                'capability_key' => 'wizard-ritual-adept',
                'collection' => 'wizard_spellbook',
                'tags' => ['ritual'],
                'access_mode' => CastingMode::RitualOnly->value,
            ];
        }

        return $rules;
    }

    private function upsertThirdCaster(string $className, string $subclassName, string $contentKey): int
    {
        $classId = DB::table('class_definitions')->where('name', $className)->value('id');
        DB::table('subclass_definitions')->updateOrInsert(
            ['content_key' => $contentKey],
            [
                'class_definition_id' => $classId,
                'name' => $subclassName,
                'rules_edition' => '2024',
                'spellcasting_ability' => 'intelligence',
                'caster_fraction' => '1/3',
                'caster_rounding' => 'down',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        return (int) DB::table('subclass_definitions')->where('content_key', $contentKey)->value('id');
    }

    /** @param list<int> $prepared */
    private function seedThirdCasterProgression(
        int $subclassId,
        string $rulePrefix,
        array $prepared,
        int $startingCantrips,
        int $levelTenCantrips,
    ): void {
        for ($level = 1; $level <= 20; $level++) {
            $cantrips = $level < 3 ? 0 : ($level < 10 ? $startingCantrips : $levelTenCantrips);
            $preparedCount = (int) data_get($prepared, $level - 1, 0);
            $maxSpellLevel = match (true) {
                $level < 3 => 0,
                $level < 7 => 1,
                $level < 13 => 2,
                $level < 19 => 3,
                default => 4,
            };
            $slots = match (true) {
                $level < 3 => [],
                $level === 3 => [1 => 2],
                $level < 7 => [1 => 3],
                $level < 10 => [1 => 4, 2 => 2],
                $level < 13 => [1 => 4, 2 => 3],
                $level < 16 => [1 => 4, 2 => 3, 3 => 2],
                $level < 19 => [1 => 4, 2 => 3, 3 => 3],
                default => [1 => 4, 2 => 3, 3 => 3, 4 => 1],
            };
            $rules = [];
            if ($cantrips > 0) {
                $rules[] = [
                    'kind' => 'choice_from_list', 'rule_key' => "{$rulePrefix}-cantrips",
                    'count' => $cantrips, 'bucket' => 'cantrip_known', 'list' => 'Wizard',
                    'level_min' => 0, 'level_max' => 0, 'with_slots' => false,
                ];
            }
            if ($preparedCount > 0) {
                $rules[] = [
                    'kind' => 'choice_from_list', 'rule_key' => "{$rulePrefix}-prepared",
                    'count' => $preparedCount, 'bucket' => 'prepared', 'list' => 'Wizard',
                    'level_min' => 1, 'level_max' => $maxSpellLevel, 'with_slots' => true,
                ];
            }
            DB::table('subclass_progressions')->updateOrInsert(
                ['subclass_definition_id' => $subclassId, 'class_level' => $level],
                [
                    'cantrips_known' => $cantrips,
                    'prepared_count' => $preparedCount,
                    'max_spell_level' => $maxSpellLevel,
                    'slots' => json_encode($slots, JSON_THROW_ON_ERROR),
                    'grant_rules' => json_encode($rules, JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }
    }
}
