<?php

declare(strict_types=1);

namespace App\Domain\Reports;

use App\Domain\Rules\CasterContribution;
use App\Domain\Rules\SpellSlots;
use App\Domain\Spells\DuplicateWarningDetector;
use App\Domain\Spells\SpellAccessBuilder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final readonly class BuildReportBuilder
{
    public function __construct(
        private SpellAccessBuilder $access,
        private DuplicateWarningDetector $duplicates,
    ) {}

    /** @return array<string, mixed> */
    public function build(int $characterId): array
    {
        $character = DB::table('characters')->find($characterId);
        if ($character === null) {
            throw new RuntimeException("Character {$characterId} does not exist.");
        }

        [$classes, $contributions, $singleClassSlotTables] = $this->classesAndContributions($characterId);
        $characterLevel = array_sum(array_map(
            static fn (array $class): int => (int) data_get($class, 'class_level'),
            $classes,
        ));
        $proficiency = data_get($character, 'proficiency_bonus_override');
        $proficiency = $proficiency === null
            ? SpellSlots::proficiencyBonus($characterLevel)
            : (int) $proficiency;
        $casterLevel = SpellSlots::casterLevel($contributions);
        $slots = count($singleClassSlotTables) === 1
            ? $singleClassSlotTables[0]
            : SpellSlots::slotsForCasterLevel($casterLevel);
        ksort($slots);
        $slotRows = [];
        foreach ($slots as $level => $count) {
            $slotRows[] = ['level' => $level, 'count' => $count];
        }

        $routes = $this->access->buildForCharacter($characterId);
        $assessments = $this->duplicates->classify($routes);
        $maxSlotLevel = (int) array_key_last($slots);
        $maxClassSpellLevel = max(array_map(
            static fn (array $class): int => (int) data_get($class, 'max_preparable_level'),
            $classes,
        ));

        return [
            'character' => [
                'id' => (int) data_get($character, 'id'),
                'name' => (string) data_get($character, 'name'),
                'character_level' => $characterLevel,
                'proficiency_bonus' => $proficiency,
                'abilities' => [
                    'strength' => (int) data_get($character, 'strength'),
                    'dexterity' => (int) data_get($character, 'dexterity'),
                    'constitution' => (int) data_get($character, 'constitution'),
                    'intelligence' => (int) data_get($character, 'intelligence'),
                    'wisdom' => (int) data_get($character, 'wisdom'),
                    'charisma' => (int) data_get($character, 'charisma'),
                ],
            ],
            'caster' => [
                'caster_level' => $casterLevel,
                'slots' => $slotRows,
                'pact_magic' => SpellSlots::pactMagic($contributions),
            ],
            'classes' => $classes,
            'preparation_callout' => sprintf(
                'This build possesses %s-level slots, but every class can prepare only %s-level spells. Higher-level slots can upcast those lower-level spells; they do not unlock higher-level choices.',
                $this->ordinal($maxSlotLevel),
                $this->ordinal($maxClassSpellLevel),
            ),
            'access_routes' => $routes,
            'wizard' => $this->wizardSplit($characterId, $routes),
            'duplicate_assessments' => $assessments,
        ];
    }

    /**
     * @return array{
     *     0: list<array<string, mixed>>,
     *     1: list<CasterContribution>,
     *     2: list<array<int, int>>
     * }
     */
    private function classesAndContributions(int $characterId): array
    {
        $rows = DB::table('character_class_levels as level')
            ->join('class_definitions as class', 'class.id', '=', 'level.class_definition_id')
            ->leftJoin('subclass_definitions as subclass', 'subclass.id', '=', 'level.subclass_definition_id')
            ->where('level.character_id', $characterId)
            ->select([
                'level.*',
                'class.name as class_name',
                'class.spellcasting_ability',
                'class.progression_type',
                'class.caster_fraction',
                'class.caster_rounding',
                'subclass.name as subclass_name',
                'subclass.id as selected_subclass_id',
                'subclass.spellcasting_ability as subclass_spellcasting_ability',
                'subclass.caster_fraction as subclass_caster_fraction',
                'subclass.caster_rounding as subclass_caster_rounding',
            ])
            ->orderBy('class.name')
            ->get();

        $classes = [];
        $contributions = [];
        $singleClassSlotTables = [];
        foreach ($rows as $row) {
            $progressionType = (string) data_get($row, 'progression_type');
            if (data_get($row, 'subclass_caster_fraction') !== null) {
                $progressionType = $this->fractionType(
                    (string) data_get($row, 'subclass_caster_fraction'),
                    (string) data_get($row, 'subclass_caster_rounding'),
                );
            }
            $contribution = new CasterContribution(
                (string) data_get($row, 'class_name'),
                (int) data_get($row, 'level'),
                $progressionType,
            );
            $contributions[] = $contribution;
            $baseProgression = DB::table('class_progressions')
                ->where('class_definition_id', data_get($row, 'class_definition_id'))
                ->where('class_level', data_get($row, 'level'))
                ->first();
            $subclassProgression = data_get($row, 'selected_subclass_id') === null ? null : DB::table('subclass_progressions')
                ->where('subclass_definition_id', data_get($row, 'selected_subclass_id'))
                ->where('class_level', data_get($row, 'level'))
                ->first();
            $preparedCount = $subclassProgression === null
                ? data_get($baseProgression, 'prepared_count')
                : data_get($subclassProgression, 'prepared_count');
            $ownProgression = $subclassProgression ?? $baseProgression;
            $ownSlots = $this->decodeSlotTable(data_get($ownProgression, 'slots'));
            if ($ownSlots !== [] && $progressionType !== CasterContribution::PACT) {
                $singleClassSlotTables[] = $ownSlots;
            }
            $classes[] = [
                'name' => (string) data_get($row, 'class_name'),
                'subclass' => data_get($row, 'subclass_name'),
                'class_level' => (int) data_get($row, 'level'),
                'spellcasting_ability' => data_get(
                    $row,
                    'subclass_spellcasting_ability',
                    data_get($row, 'spellcasting_ability'),
                ),
                'progression_type' => $progressionType,
                'prepared_count' => (int) ($preparedCount ?? 0),
                'max_preparable_level' => $subclassProgression === null
                    ? SpellSlots::maxPreparableLevelForClass($contribution)
                    : (int) data_get($subclassProgression, 'max_spell_level'),
            ];
        }

        return [$classes, $contributions, $singleClassSlotTables];
    }

    /** @return array<int, int> */
    private function decodeSlotTable(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        $decoded = is_array($value)
            ? $value
            : json_decode((string) $value, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            return [];
        }

        $slots = [];
        foreach ($decoded as $level => $count) {
            $slots[(int) $level] = (int) $count;
        }

        return $slots;
    }

    /** @param list<array<string, mixed>> $routes @return array<string, mixed> */
    private function wizardSplit(int $characterId, array $routes): array
    {
        $preparedVersionIds = array_map(
            static fn (array $route): int => (int) data_get($route, 'spell_version_id'),
            array_values(array_filter(
                $routes,
                static fn (array $route): bool => data_get($route, 'selection_collection') === 'wizard_spellbook'
                    && data_get($route, 'casting_mode') === 'with_slots',
            )),
        );
        $entries = DB::table('wizard_spellbook_entries as entry')
            ->join('spell_versions as version', 'version.id', '=', 'entry.spell_version_id')
            ->where('entry.character_id', $characterId)
            ->orderBy('version.display_name')
            ->select([
                'entry.id', 'entry.acquisition', 'entry.copy_cost_gp', 'entry.copy_time_hours',
                'version.id as spell_version_id', 'version.display_name as spell_name', 'version.level',
            ])
            ->get()
            ->map(static fn (object $entry): array => [
                'spellbook_entry_id' => (int) data_get($entry, 'id'),
                'spell_version_id' => (int) data_get($entry, 'spell_version_id'),
                'spell_name' => (string) data_get($entry, 'spell_name'),
                'level' => (int) data_get($entry, 'level'),
                'acquisition' => (string) data_get($entry, 'acquisition'),
                'copy_cost_gp' => data_get($entry, 'copy_cost_gp'),
                'copy_time_hours' => data_get($entry, 'copy_time_hours'),
                'prepared' => in_array((int) data_get($entry, 'spell_version_id'), $preparedVersionIds, true),
            ])
            ->all();

        $prepared = array_values(array_filter(
            $entries,
            static fn (array $entry): bool => (bool) data_get($entry, 'prepared'),
        ));
        $ritualOnly = array_values(array_map(
            static fn (array $route): array => [
                'spellbook_entry_id' => data_get($route, 'spellbook_entry_id'),
                'spell_version_id' => data_get($route, 'spell_version_id'),
                'spell_name' => data_get($route, 'spell_name'),
                'level' => data_get($route, 'spell_level'),
            ],
            array_filter(
                $routes,
                static fn (array $route): bool => data_get($route, 'casting_mode') === 'ritual_only',
            ),
        ));

        return [
            'spellbook' => $entries,
            'prepared' => $prepared,
            'ritual_only' => $ritualOnly,
            'explanation' => 'Prepared spellbook spells can use spell slots. Ritual Adept also exposes any unprepared ritual-tagged spell in the spellbook as ritual-only access; that route is not a selection, does not consume preparation capacity, and is ignored by duplicate-waste checks. Unprepared non-ritual spells are not castable.',
        ];
    }

    private function fractionType(string $fraction, string $rounding): string
    {
        return match ([$fraction, $rounding]) {
            ['1/2', 'up'] => CasterContribution::HALF_UP,
            ['1/2', 'down'] => CasterContribution::HALF_DOWN,
            ['1/3', 'up'] => CasterContribution::THIRD_UP,
            ['1/3', 'down'] => CasterContribution::THIRD_DOWN,
            default => throw new RuntimeException("Unsupported caster fraction {$fraction} rounded {$rounding}."),
        };
    }

    private function ordinal(int $number): string
    {
        if ($number % 100 >= 11 && $number % 100 <= 13) {
            return $number.'th';
        }

        return $number.match ($number % 10) {
            1 => 'st',
            2 => 'nd',
            3 => 'rd',
            default => 'th',
        };
    }
}
