<?php

declare(strict_types=1);

use App\Domain\Rules\CasterContribution;
use App\Domain\Rules\SpellSlots;
use Random\Engine\Mt19937;
use Random\Randomizer;

const E2E11_PROPERTY_SEED = 0xE2E11;
const E2E11_PROPERTY_CASES = 1000;

/**
 * Legal 2024 base-class progressions. Subclass progression is separate state in
 * this domain and is covered against `subclass_progressions` by the feature
 * suite; silently treating a Fighter or Rogue as a subclass would not describe
 * a legal generated class mix.
 *
 * @return array<string, list<string>>
 */
function e2e11ClassProgressions(): array
{
    return [
        'Barbarian' => [CasterContribution::NONE],
        'Bard' => [CasterContribution::FULL],
        'Cleric' => [CasterContribution::FULL],
        'Druid' => [CasterContribution::FULL],
        'Fighter' => [CasterContribution::NONE],
        'Monk' => [CasterContribution::NONE],
        'Paladin' => [CasterContribution::HALF_UP],
        'Ranger' => [CasterContribution::HALF_UP],
        'Rogue' => [CasterContribution::NONE],
        'Sorcerer' => [CasterContribution::FULL],
        'Warlock' => [CasterContribution::PACT],
        'Wizard' => [CasterContribution::FULL],
    ];
}

/** @return list<CasterContribution> */
function e2e11RandomBuild(Randomizer $randomizer, ?int $totalLevel = null): array
{
    $totalLevel ??= $randomizer->getInt(1, 20);
    $catalog = e2e11ClassProgressions();
    $names = $randomizer->shuffleArray(array_keys($catalog));
    $classCount = $randomizer->getInt(1, min(count($names), $totalLevel));
    $names = array_slice($names, 0, $classCount);
    $levels = array_fill(0, $classCount, 1);

    for ($remaining = $totalLevel - $classCount; $remaining > 0; $remaining--) {
        $levels[$randomizer->getInt(0, $classCount - 1)]++;
    }

    return array_map(
        static function (string $name, int $level) use ($catalog, $randomizer): CasterContribution {
            $progressions = $catalog[$name];

            return new CasterContribution(
                $name,
                $level,
                $progressions[$randomizer->getInt(0, count($progressions) - 1)],
            );
        },
        $names,
        $levels,
    );
}

/** @param list<CasterContribution> $build */
function e2e11BuildData(array $build): array
{
    return array_map(static fn (CasterContribution $class): array => [
        'class' => $class->className,
        'level' => $class->classLevel,
        'progression' => $class->progressionType,
    ], $build);
}

/**
 * @param  array<string, mixed>  $builds
 */
function e2e11AssertProperty(
    bool $condition,
    string $property,
    int $seed,
    int $iteration,
    array $builds,
): void {
    $context = json_encode([
        'property' => $property,
        'seed' => $seed,
        'iteration' => $iteration,
        'builds' => $builds,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    if (! $condition) {
        fwrite(STDERR, "\nPROPERTY FAILURE {$context}\n");
    }

    expect($condition)->toBeTrue($context);
}

it('keeps caster level monotonic as any class level increases', function (): void {
    $seed = E2E11_PROPERTY_SEED + 1;
    $randomizer = new Randomizer(new Mt19937($seed));

    for ($iteration = 0; $iteration < E2E11_PROPERTY_CASES; $iteration++) {
        // Leave one level of headroom so every class can be increased while the
        // resulting character remains within the legal level-20 cap.
        $build = e2e11RandomBuild($randomizer, $randomizer->getInt(1, 19));
        $before = SpellSlots::casterLevel($build);

        foreach ($build as $index => $class) {
            $raised = $build;
            $raised[$index] = new CasterContribution(
                $class->className,
                $class->classLevel + 1,
                $class->progressionType,
            );
            $after = SpellSlots::casterLevel($raised);

            e2e11AssertProperty(
                $after >= $before,
                'caster level is monotonic in each class level',
                $seed,
                $iteration,
                ['before' => e2e11BuildData($build), 'after' => e2e11BuildData($raised)],
            );
        }
    }
});

it('never sums the Warlock pact pool into shared multiclass slots', function (): void {
    $seed = E2E11_PROPERTY_SEED + 2;
    $randomizer = new Randomizer(new Mt19937($seed));

    for ($iteration = 0; $iteration < E2E11_PROPERTY_CASES; $iteration++) {
        $warlockLevel = $randomizer->getInt(1, 19);
        $otherTotal = $randomizer->getInt(1, 20 - $warlockLevel);
        $withoutWarlock = array_values(array_filter(
            e2e11RandomBuild($randomizer, $otherTotal),
            static fn (CasterContribution $class): bool => $class->className !== 'Warlock',
        ));
        if ($withoutWarlock === []) {
            $withoutWarlock = [new CasterContribution('Wizard', $otherTotal, CasterContribution::FULL)];
        }
        $withWarlock = [...$withoutWarlock, new CasterContribution(
            'Warlock',
            $warlockLevel,
            CasterContribution::PACT,
        )];

        e2e11AssertProperty(
            SpellSlots::slots($withWarlock) === SpellSlots::slots($withoutWarlock)
                && SpellSlots::casterLevel($withWarlock) === SpellSlots::casterLevel($withoutWarlock)
                && SpellSlots::pactMagic($withWarlock) !== null,
            'Warlock Pact Magic remains separate from shared slots',
            $seed,
            $iteration,
            [
                'without_warlock' => e2e11BuildData($withoutWarlock),
                'with_warlock' => e2e11BuildData($withWarlock),
            ],
        );
    }
});

it('makes proficiency bonus depend only on total character level', function (): void {
    $seed = E2E11_PROPERTY_SEED + 3;
    $randomizer = new Randomizer(new Mt19937($seed));

    for ($iteration = 0; $iteration < E2E11_PROPERTY_CASES; $iteration++) {
        $totalLevel = $randomizer->getInt(1, 20);
        $first = e2e11RandomBuild($randomizer, $totalLevel);
        $second = e2e11RandomBuild($randomizer, $totalLevel);
        $firstTotal = array_sum(array_map(
            static fn (CasterContribution $class): int => $class->classLevel,
            $first,
        ));
        $secondTotal = array_sum(array_map(
            static fn (CasterContribution $class): int => $class->classLevel,
            $second,
        ));
        $expected = 2 + intdiv($totalLevel - 1, 4);

        e2e11AssertProperty(
            $firstTotal === $totalLevel
                && $secondTotal === $totalLevel
                && SpellSlots::proficiencyBonus($firstTotal) === SpellSlots::proficiencyBonus($secondTotal)
                && SpellSlots::proficiencyBonus($firstTotal) === $expected,
            'proficiency bonus depends only on total character level',
            $seed,
            $iteration,
            ['first' => e2e11BuildData($first), 'second' => e2e11BuildData($second)],
        );
    }
});

it('does not change shared slots when a non-caster level is added', function (): void {
    $seed = E2E11_PROPERTY_SEED + 4;
    $randomizer = new Randomizer(new Mt19937($seed));
    $nonCasters = ['Barbarian', 'Fighter', 'Monk', 'Rogue'];

    for ($iteration = 0; $iteration < E2E11_PROPERTY_CASES; $iteration++) {
        $build = e2e11RandomBuild($randomizer, $randomizer->getInt(1, 19));
        $eligibleNonCasters = array_values(array_filter(
            $nonCasters,
            static function (string $name) use ($build): bool {
                $existing = array_find(
                    $build,
                    static fn (CasterContribution $class): bool => $class->className === $name,
                );

                return $existing === null || $existing->progressionType === CasterContribution::NONE;
            },
        ));
        $nonCaster = $eligibleNonCasters[$randomizer->getInt(0, count($eligibleNonCasters) - 1)];
        $after = $build;
        $matchingIndex = array_find_key(
            $after,
            static fn (CasterContribution $class): bool => $class->className === $nonCaster
                && $class->progressionType === CasterContribution::NONE,
        );

        if ($matchingIndex === null) {
            $after[] = new CasterContribution($nonCaster, 1, CasterContribution::NONE);
        } else {
            $class = $after[$matchingIndex];
            $after[$matchingIndex] = new CasterContribution(
                $class->className,
                $class->classLevel + 1,
                CasterContribution::NONE,
            );
        }

        e2e11AssertProperty(
            SpellSlots::slots($after) === SpellSlots::slots($build),
            'adding a non-caster level does not change shared slots',
            $seed,
            $iteration,
            ['before' => e2e11BuildData($build), 'after' => e2e11BuildData($after)],
        );
    }
});

it('never prepares above the highest possessed slot for a single-class caster', function (): void {
    $seed = E2E11_PROPERTY_SEED + 5;
    $randomizer = new Randomizer(new Mt19937($seed));
    $casters = array_filter(
        e2e11ClassProgressions(),
        static fn (array $progressions): bool => $progressions !== [CasterContribution::NONE],
    );
    $casterNames = array_keys($casters);

    for ($iteration = 0; $iteration < E2E11_PROPERTY_CASES; $iteration++) {
        $name = $casterNames[$randomizer->getInt(0, count($casterNames) - 1)];
        $progressions = array_values(array_filter(
            $casters[$name],
            static fn (string $progression): bool => $progression !== CasterContribution::NONE,
        ));
        $class = new CasterContribution(
            $name,
            $randomizer->getInt(1, 20),
            $progressions[$randomizer->getInt(0, count($progressions) - 1)],
        );
        $highestPossessed = $class->isPactCaster()
            ? (int) data_get(SpellSlots::pactMagic([$class]), 'level', 0)
            : (int) (array_key_last(SpellSlots::slots([$class])) ?? 0);

        e2e11AssertProperty(
            SpellSlots::maxPreparableLevelForClass($class) <= $highestPossessed,
            'single-class maximum preparable level does not exceed possessed slots',
            $seed,
            $iteration,
            ['single_class' => e2e11BuildData([$class])],
        );
    }
});

it('keeps slot counts non-increasing as spell level rises', function (): void {
    $seed = E2E11_PROPERTY_SEED + 6;
    $randomizer = new Randomizer(new Mt19937($seed));

    for ($iteration = 0; $iteration < E2E11_PROPERTY_CASES; $iteration++) {
        $build = e2e11RandomBuild($randomizer);
        $slots = SpellSlots::slots($build);
        $counts = array_values($slots);
        $nonIncreasing = true;
        for ($index = 1; $index < count($counts); $index++) {
            if ($counts[$index] > $counts[$index - 1]) {
                $nonIncreasing = false;
                break;
            }
        }

        e2e11AssertProperty(
            $nonIncreasing,
            'slot counts are non-increasing as spell level rises',
            $seed,
            $iteration,
            ['build' => e2e11BuildData($build), 'shared_slots' => $slots],
        );
    }
});
