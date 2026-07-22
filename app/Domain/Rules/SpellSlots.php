<?php

declare(strict_types=1);

namespace App\Domain\Rules;

/**
 * The 2024 multiclass spellcaster slot table, plus Warlock's separate Pact Magic
 * pool.
 *
 * This deliberately answers two DIFFERENT questions that players constantly
 * conflate, and that the SRD itself calls out:
 *
 *   "This table might give you spell slots of a higher level than the spells you
 *    prepare. You can use those slots but only to cast your lower-level spells."
 *
 * So `slotsForCasterLevel()` says which slots you POSSESS, while
 * `maxPreparableLevelForClass()` says how high a spell each class can actually
 * prepare. A six-class level-1 character has 3rd-level slots and cannot prepare
 * anything above 1st.
 */
final class SpellSlots
{
    /** Slots by caster level → [level1, level2, ... level9]. */
    private const TABLE = [
        1 => [2, 0, 0, 0, 0, 0, 0, 0, 0],
        2 => [3, 0, 0, 0, 0, 0, 0, 0, 0],
        3 => [4, 2, 0, 0, 0, 0, 0, 0, 0],
        4 => [4, 3, 0, 0, 0, 0, 0, 0, 0],
        5 => [4, 3, 2, 0, 0, 0, 0, 0, 0],
        6 => [4, 3, 3, 0, 0, 0, 0, 0, 0],
        7 => [4, 3, 3, 1, 0, 0, 0, 0, 0],
        8 => [4, 3, 3, 2, 0, 0, 0, 0, 0],
        9 => [4, 3, 3, 3, 1, 0, 0, 0, 0],
        10 => [4, 3, 3, 3, 2, 0, 0, 0, 0],
        11 => [4, 3, 3, 3, 2, 1, 0, 0, 0],
        12 => [4, 3, 3, 3, 2, 1, 0, 0, 0],
        13 => [4, 3, 3, 3, 2, 1, 1, 0, 0],
        14 => [4, 3, 3, 3, 2, 1, 1, 0, 0],
        15 => [4, 3, 3, 3, 2, 1, 1, 1, 0],
        16 => [4, 3, 3, 3, 2, 1, 1, 1, 0],
        17 => [4, 3, 3, 3, 2, 1, 1, 1, 1],
        18 => [4, 3, 3, 3, 3, 1, 1, 1, 1],
        19 => [4, 3, 3, 3, 3, 2, 1, 1, 1],
        20 => [4, 3, 3, 3, 3, 2, 2, 1, 1],
    ];

    /** Warlock only: [slot count, slot level] by Warlock class level. */
    private const PACT_TABLE = [
        1 => [1, 1], 2 => [2, 1], 3 => [2, 2], 4 => [2, 2], 5 => [2, 3],
        6 => [2, 3], 7 => [2, 4], 8 => [2, 4], 9 => [2, 5], 10 => [2, 5],
        11 => [3, 5], 12 => [3, 5], 13 => [3, 5], 14 => [3, 5], 15 => [3, 5],
        16 => [3, 5], 17 => [4, 5], 18 => [4, 5], 19 => [4, 5], 20 => [4, 5],
    ];

    /**
     * @param  list<CasterContribution>  $contributions
     */
    public static function casterLevel(array $contributions): int
    {
        // Sum of independently rounded per-class contributions. Rounding the
        // pooled total instead would under-count mixed half-casters.
        return array_sum(array_map(
            static fn (CasterContribution $c): int => $c->casterLevels(),
            $contributions,
        ));
    }

    /**
     * Slots possessed, keyed by spell level (1-9). Empty levels are omitted.
     *
     * @return array<int, int>
     */
    public static function slotsForCasterLevel(int $casterLevel): array
    {
        if ($casterLevel < 1) {
            return [];
        }

        $row = self::TABLE[min($casterLevel, 20)];
        $slots = [];
        foreach ($row as $index => $count) {
            if ($count > 0) {
                $slots[$index + 1] = $count;
            }
        }

        return $slots;
    }

    /**
     * @param  list<CasterContribution>  $contributions
     * @return array<int, int>
     */
    public static function slots(array $contributions): array
    {
        return self::slotsForCasterLevel(self::casterLevel($contributions));
    }

    /**
     * Warlock's separate pool. Returned on its own precisely so it can never be
     * accidentally summed into the table above.
     *
     * @param  list<CasterContribution>  $contributions
     * @return array{count: int, level: int}|null
     */
    public static function pactMagic(array $contributions): ?array
    {
        $warlockLevels = 0;
        foreach ($contributions as $c) {
            if ($c->isPactCaster()) {
                $warlockLevels += $c->classLevel;
            }
        }

        if ($warlockLevels < 1) {
            return null;
        }

        [$count, $level] = self::PACT_TABLE[min($warlockLevels, 20)];

        return ['count' => $count, 'level' => $level];
    }

    /**
     * The highest spell level this ONE class can prepare or learn, judged as if
     * it were single-classed. This is what limits your actual spell choices --
     * not the slot table above.
     */
    public static function maxPreparableLevelForClass(CasterContribution $contribution): int
    {
        return $contribution->progression->maxPreparableLevel($contribution->classLevel);
    }

    /**
     * Total character level drives proficiency bonus -- NOT caster level. A
     * six-class level-1 character is character level 6, so PB is +3.
     */
    public static function proficiencyBonus(int $characterLevel): int
    {
        return (int) floor(($characterLevel - 1) / 4) + 2;
    }
}
