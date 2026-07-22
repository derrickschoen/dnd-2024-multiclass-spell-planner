<?php

declare(strict_types=1);

namespace App\Domain\Rules;

use InvalidArgumentException;

final readonly class SaveDC
{
    public function __construct(public int $value)
    {
        if ($value < 1) {
            throw new InvalidArgumentException("Save DC must be positive, got {$value}.");
        }
    }

    public static function from(AbilityScore $abilityScore, int $proficiencyBonus): self
    {
        if ($proficiencyBonus < 0) {
            throw new InvalidArgumentException('Proficiency bonus cannot be negative.');
        }

        return new self(8 + $abilityScore->modifier() + $proficiencyBonus);
    }
}
