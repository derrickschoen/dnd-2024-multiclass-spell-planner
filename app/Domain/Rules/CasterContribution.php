<?php

declare(strict_types=1);

namespace App\Domain\Rules;

use InvalidArgumentException;

/**
 * One class's contribution to the multiclass caster level.
 *
 * The fraction and rounding direction are DATA, not constants, because the 2024
 * rules are not internally consistent about them and house rules need to vary
 * them: Paladin/Ranger round UP (changed in 2024) while Eldritch Knight/Arcane
 * Trickster round DOWN (unchanged from 2014).
 *
 * Crucially the rounding is applied PER CLASS and the results are then summed.
 * Pooling the levels first and rounding once gives a different, wrong answer:
 * Paladin 1 / Ranger 1 is 2 caster levels, not 1.
 */
final readonly class CasterContribution
{
    public const FULL = 'full';
    public const HALF_UP = 'half_up';
    public const HALF_DOWN = 'half_down';
    public const THIRD_UP = 'third_up';
    public const THIRD_DOWN = 'third_down';
    public const PACT = 'pact';
    public const NONE = 'none';

    public function __construct(
        public string $className,
        public int $classLevel,
        public string $progressionType,
    ) {
        if ($classLevel < 0) {
            throw new InvalidArgumentException("Class level cannot be negative, got {$classLevel}.");
        }
    }

    /**
     * Levels this class adds to the shared multiclass caster level.
     *
     * Pact Magic contributes ZERO: Warlock slots are a separate pool that is
     * never merged into the multiclass table, even though 2024 lets the two
     * pools cast each other's prepared spells.
     */
    public function casterLevels(): int
    {
        return match ($this->progressionType) {
            self::FULL => $this->classLevel,
            self::HALF_UP => (int) ceil($this->classLevel / 2),
            self::HALF_DOWN => intdiv($this->classLevel, 2),
            self::THIRD_UP => (int) ceil($this->classLevel / 3),
            self::THIRD_DOWN => intdiv($this->classLevel, 3),
            self::PACT, self::NONE => 0,
            default => throw new InvalidArgumentException(
                "Unknown progression type '{$this->progressionType}' for {$this->className}."
            ),
        };
    }

    public function isPactCaster(): bool
    {
        return $this->progressionType === self::PACT;
    }
}
