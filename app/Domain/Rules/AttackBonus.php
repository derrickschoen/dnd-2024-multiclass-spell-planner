<?php

declare(strict_types=1);

namespace App\Domain\Rules;

use InvalidArgumentException;

final readonly class AttackBonus
{
    public function __construct(public int $value) {}

    public static function from(AbilityScore $abilityScore, int $proficiencyBonus): self
    {
        if ($proficiencyBonus < 0) {
            throw new InvalidArgumentException('Proficiency bonus cannot be negative.');
        }

        return new self($abilityScore->modifier() + $proficiencyBonus);
    }
}
