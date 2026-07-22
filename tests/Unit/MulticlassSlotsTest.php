<?php

declare(strict_types=1);

use App\Domain\Rules\CasterContribution;
use App\Domain\Rules\SpellSlots;

/**
 * Vector-based, not example-based. A single seed-character assertion would pass
 * while the engine was wrong at every other level, so the whole table and every
 * proficiency-bonus boundary are pinned.
 *
 * These run with no database and no application instance: app/Domain is pure PHP.
 */

function full(string $name, int $level): CasterContribution
{
    return new CasterContribution($name, $level, CasterContribution::FULL);
}

function halfUp(string $name, int $level): CasterContribution
{
    return new CasterContribution($name, $level, CasterContribution::HALF_UP);
}

function thirdDown(string $name, int $level): CasterContribution
{
    return new CasterContribution($name, $level, CasterContribution::THIRD_DOWN);
}

describe('per-class rounding', function () {
    it('accepts level zero as an empty class contribution', function () {
        expect(full('Wizard', 0)->casterLevels())->toBe(0);
    });

    it('rounds Paladin and Ranger up, each class independently', function () {
        // The load-bearing case: pooling first gives ceil(2/2) = 1, which is wrong.
        expect(halfUp('Paladin', 1)->casterLevels())->toBe(1);
        expect(halfUp('Ranger', 1)->casterLevels())->toBe(1);
        expect(SpellSlots::casterLevel([halfUp('Paladin', 1), halfUp('Ranger', 1)]))->toBe(2);
    });

    it('rounds third-casters down, each class independently', function () {
        // Pooling gives floor(9/3) = 3; per-class gives 1 + 1 = 2.
        expect(thirdDown('Eldritch Knight', 5)->casterLevels())->toBe(1);
        expect(thirdDown('Arcane Trickster', 4)->casterLevels())->toBe(1);
        expect(SpellSlots::casterLevel([thirdDown('Eldritch Knight', 5), thirdDown('Arcane Trickster', 4)]))->toBe(2);
    });

    it('counts half-casters up across the whole range', function () {
        $expected = [1 => 1, 2 => 1, 3 => 2, 4 => 2, 5 => 3, 6 => 3, 20 => 10];
        foreach ($expected as $classLevel => $casterLevels) {
            expect(halfUp('Paladin', $classLevel)->casterLevels())
                ->toBe($casterLevels, "Paladin {$classLevel}");
        }
    });

    it('counts full casters at face value', function () {
        expect(full('Wizard', 7)->casterLevels())->toBe(7);
    });

    it('rejects an unknown progression type instead of silently returning zero', function () {
        expect(fn () => (new CasterContribution('Mystery', 3, 'wat'))->casterLevels())
            ->toThrow(InvalidArgumentException::class);
    });

    it('pins every progression type across its rounding boundaries', function (string $type, array $expected) {
        foreach ($expected as $classLevel => $casterLevels) {
            expect((new CasterContribution('Test', $classLevel, $type))->casterLevels())
                ->toBe($casterLevels, "{$type} level {$classLevel}");
        }
    })->with([
        'full' => [CasterContribution::FULL, [0 => 0, 1 => 1, 2 => 2, 20 => 20]],
        'half down' => [CasterContribution::HALF_DOWN, [0 => 0, 1 => 0, 2 => 1, 3 => 1, 4 => 2, 20 => 10]],
        'third up' => [CasterContribution::THIRD_UP, [0 => 0, 1 => 1, 2 => 1, 3 => 1, 4 => 2, 20 => 7]],
        'third down' => [CasterContribution::THIRD_DOWN, [0 => 0, 1 => 0, 2 => 0, 3 => 1, 4 => 1, 5 => 1, 6 => 2, 20 => 6]],
        'pact' => [CasterContribution::PACT, [0 => 0, 1 => 0, 20 => 0]],
        'none' => [CasterContribution::NONE, [0 => 0, 1 => 0, 20 => 0]],
    ]);
});

describe('the multiclass slot table', function () {
    it('matches the published table at every caster level 1-20', function () {
        $table = [
            1 => [1 => 2],
            2 => [1 => 3],
            3 => [1 => 4, 2 => 2],
            4 => [1 => 4, 2 => 3],
            5 => [1 => 4, 2 => 3, 3 => 2],
            6 => [1 => 4, 2 => 3, 3 => 3],
            7 => [1 => 4, 2 => 3, 3 => 3, 4 => 1],
            8 => [1 => 4, 2 => 3, 3 => 3, 4 => 2],
            9 => [1 => 4, 2 => 3, 3 => 3, 4 => 3, 5 => 1],
            10 => [1 => 4, 2 => 3, 3 => 3, 4 => 3, 5 => 2],
            11 => [1 => 4, 2 => 3, 3 => 3, 4 => 3, 5 => 2, 6 => 1],
            12 => [1 => 4, 2 => 3, 3 => 3, 4 => 3, 5 => 2, 6 => 1],
            13 => [1 => 4, 2 => 3, 3 => 3, 4 => 3, 5 => 2, 6 => 1, 7 => 1],
            14 => [1 => 4, 2 => 3, 3 => 3, 4 => 3, 5 => 2, 6 => 1, 7 => 1],
            15 => [1 => 4, 2 => 3, 3 => 3, 4 => 3, 5 => 2, 6 => 1, 7 => 1, 8 => 1],
            16 => [1 => 4, 2 => 3, 3 => 3, 4 => 3, 5 => 2, 6 => 1, 7 => 1, 8 => 1],
            17 => [1 => 4, 2 => 3, 3 => 3, 4 => 3, 5 => 2, 6 => 1, 7 => 1, 8 => 1, 9 => 1],
            18 => [1 => 4, 2 => 3, 3 => 3, 4 => 3, 5 => 3, 6 => 1, 7 => 1, 8 => 1, 9 => 1],
            19 => [1 => 4, 2 => 3, 3 => 3, 4 => 3, 5 => 3, 6 => 2, 7 => 1, 8 => 1, 9 => 1],
            20 => [1 => 4, 2 => 3, 3 => 3, 4 => 3, 5 => 3, 6 => 2, 7 => 2, 8 => 1, 9 => 1],
        ];

        foreach ($table as $casterLevel => $expected) {
            expect(SpellSlots::slotsForCasterLevel($casterLevel))
                ->toBe($expected, "caster level {$casterLevel}");
        }
    });

    it('gives no slots below caster level 1', function () {
        expect(SpellSlots::slotsForCasterLevel(0))->toBe([]);
    });

    it('caps epic caster levels at the level twenty row', function () {
        expect(SpellSlots::slotsForCasterLevel(21))->toBe(SpellSlots::slotsForCasterLevel(20));
    });
});

describe('Warlock Pact Magic stays a separate pool', function () {
    it('contributes nothing to the multiclass caster level', function () {
        $warlock = new CasterContribution('Warlock', 5, CasterContribution::PACT);
        expect($warlock->casterLevels())->toBe(0);

        // A Wizard 1 / Warlock 5 has the slots of a caster level 1, plus a
        // separate pact pool -- not those of a caster level 6.
        expect(SpellSlots::casterLevel([full('Wizard', 1), $warlock]))->toBe(1);
        expect(SpellSlots::slots([full('Wizard', 1), $warlock]))->toBe([1 => 2]);
    });

    it('reports the pact pool independently', function () {
        $contributions = [full('Wizard', 1), new CasterContribution('Warlock', 5, CasterContribution::PACT)];
        expect(SpellSlots::pactMagic($contributions))->toBe(['count' => 2, 'level' => 3]);
    });

    it('matches every Pact Magic breakpoint and caps epic Warlock levels', function () {
        $expected = [
            1 => [1, 1], 2 => [2, 1], 3 => [2, 2], 4 => [2, 2],
            5 => [2, 3], 6 => [2, 3], 7 => [2, 4], 8 => [2, 4],
            9 => [2, 5], 10 => [2, 5], 11 => [3, 5], 12 => [3, 5],
            13 => [3, 5], 14 => [3, 5], 15 => [3, 5], 16 => [3, 5],
            17 => [4, 5], 18 => [4, 5], 19 => [4, 5], 20 => [4, 5],
            21 => [4, 5],
        ];

        foreach ($expected as $level => [$count, $slotLevel]) {
            expect(SpellSlots::pactMagic([
                new CasterContribution('Warlock', $level, CasterContribution::PACT),
            ]))->toBe(['count' => $count, 'level' => $slotLevel], "Warlock level {$level}");
        }
    });

    it('adds levels from multiple Pact Magic contributions', function () {
        expect(SpellSlots::pactMagic([
            new CasterContribution('Warlock A', 1, CasterContribution::PACT),
            new CasterContribution('Warlock B', 1, CasterContribution::PACT),
        ]))->toBe(['count' => 2, 'level' => 1]);
    });

    it('returns no pact pool when there is no Warlock', function () {
        expect(SpellSlots::pactMagic([full('Wizard', 5)]))->toBeNull();
    });
});

describe('maximum preparable spell level', function () {
    it('pins each progression table independently of shared spell slots', function (string $type, array $expected) {
        foreach ($expected as $classLevel => $spellLevel) {
            $contribution = new CasterContribution('Test', $classLevel, $type);
            expect(SpellSlots::maxPreparableLevelForClass($contribution))
                ->toBe($spellLevel, "{$type} level {$classLevel}");
        }
    })->with([
        'full' => [CasterContribution::FULL, [
            0 => 0, 1 => 1, 2 => 1, 3 => 2, 4 => 2, 5 => 3, 6 => 3,
            7 => 4, 8 => 4, 9 => 5, 10 => 5, 11 => 6, 12 => 6,
            13 => 7, 14 => 7, 15 => 8, 16 => 8, 17 => 9, 20 => 9, 21 => 9,
        ]],
        'half up' => [CasterContribution::HALF_UP, [
            0 => 0, 1 => 1, 4 => 1, 5 => 2, 8 => 2, 9 => 3,
            12 => 3, 13 => 4, 16 => 4, 17 => 5, 20 => 5, 21 => 5,
        ]],
        'half down' => [CasterContribution::HALF_DOWN, [
            0 => 0, 1 => 0, 2 => 1, 4 => 1, 5 => 2, 8 => 2,
            9 => 3, 12 => 3, 13 => 4, 16 => 4, 17 => 5, 20 => 5,
        ]],
        'third up' => [CasterContribution::THIRD_UP, [
            0 => 0, 1 => 0, 2 => 0, 3 => 1, 6 => 1, 7 => 2,
            12 => 2, 13 => 3, 18 => 3, 19 => 4, 20 => 4, 25 => 4,
        ]],
        'third down' => [CasterContribution::THIRD_DOWN, [
            0 => 0, 1 => 0, 2 => 0, 3 => 1, 6 => 1, 7 => 2,
            12 => 2, 13 => 3, 18 => 3, 19 => 4, 20 => 4, 25 => 4,
        ]],
        'pact' => [CasterContribution::PACT, [
            0 => 1, 1 => 1, 2 => 1, 3 => 2, 4 => 2, 5 => 3, 6 => 3,
            7 => 4, 8 => 4, 9 => 5, 16 => 5, 17 => 5, 20 => 5, 21 => 5,
        ]],
        'none' => [CasterContribution::NONE, [0 => 0, 1 => 0, 20 => 0]],
        'unknown' => ['unknown', [0 => 0, 1 => 0, 20 => 0]],
    ]);
});

describe('proficiency bonus follows character level, not caster level', function () {
    it('steps at every published boundary', function () {
        $expected = [
            1 => 2, 4 => 2, 5 => 3, 8 => 3, 9 => 4,
            12 => 4, 13 => 5, 16 => 5, 17 => 6, 20 => 6,
        ];
        foreach ($expected as $characterLevel => $pb) {
            expect(SpellSlots::proficiencyBonus($characterLevel))
                ->toBe($pb, "character level {$characterLevel}");
        }
    });
});

describe('the seed character', function () {
    $seed = fn () => [
        full('Sorcerer', 1), full('Wizard', 1), full('Bard', 1),
        full('Cleric', 1), full('Druid', 1), halfUp('Paladin', 1),
    ];

    it('reaches caster level 6 from six level-1 classes', function () use ($seed) {
        // 5 full casters + ceil(1/2) for the Paladin.
        expect(SpellSlots::casterLevel($seed()))->toBe(6);
    });

    it('possesses 4/3/3 slots including 3rd level', function () use ($seed) {
        expect(SpellSlots::slots($seed()))->toBe([1 => 4, 2 => 3, 3 => 3]);
    });

    it('has proficiency bonus +3, because it is character level 6', function () use ($seed) {
        $characterLevel = array_sum(array_map(fn ($c) => $c->classLevel, $seed()));
        expect($characterLevel)->toBe(6);
        expect(SpellSlots::proficiencyBonus($characterLevel))->toBe(3);
    });

    it('cannot prepare above 1st level in ANY class despite holding 3rd-level slots', function () use ($seed) {
        // This is the distinction the whole app exists to make visible.
        foreach ($seed() as $contribution) {
            expect(SpellSlots::maxPreparableLevelForClass($contribution))
                ->toBeLessThanOrEqual(1, "{$contribution->className} max preparable");
        }
        expect(array_key_exists(3, SpellSlots::slots($seed())))->toBeTrue();
    });
});
