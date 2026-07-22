<?php

declare(strict_types=1);

namespace App\Domain\Rules;

use InvalidArgumentException;

final readonly class AbilityScores
{
    public function __construct(
        public AbilityScore $strength,
        public AbilityScore $dexterity,
        public AbilityScore $constitution,
        public AbilityScore $intelligence,
        public AbilityScore $wisdom,
        public AbilityScore $charisma,
    ) {}

    /** @param array<string, mixed> $values */
    public static function fromArray(array $values): self
    {
        return new self(
            self::read($values, Ability::Strength),
            self::read($values, Ability::Dexterity),
            self::read($values, Ability::Constitution),
            self::read($values, Ability::Intelligence),
            self::read($values, Ability::Wisdom),
            self::read($values, Ability::Charisma),
        );
    }

    public function score(Ability $ability): AbilityScore
    {
        return match ($ability) {
            Ability::Strength => $this->strength,
            Ability::Dexterity => $this->dexterity,
            Ability::Constitution => $this->constitution,
            Ability::Intelligence => $this->intelligence,
            Ability::Wisdom => $this->wisdom,
            Ability::Charisma => $this->charisma,
        };
    }

    /** @param array<string, mixed> $values */
    private static function read(array $values, Ability $ability): AbilityScore
    {
        $value = $values[$ability->value] ?? null;
        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            throw new InvalidArgumentException("Missing or invalid {$ability->value} ability score.");
        }

        return new AbilityScore((int) $value);
    }
}
