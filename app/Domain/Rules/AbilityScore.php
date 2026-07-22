<?php

declare(strict_types=1);

namespace App\Domain\Rules;

use InvalidArgumentException;

final readonly class AbilityScore
{
    public function __construct(public int $value)
    {
        if ($value < 1 || $value > 30) {
            throw new InvalidArgumentException("Ability score must be between 1 and 30, got {$value}.");
        }
    }

    public function modifier(): int
    {
        return (int) floor(($this->value - 10) / 2);
    }

    public function spellSaveDC(int $proficiencyBonus): SaveDC
    {
        return SaveDC::from($this, $proficiencyBonus);
    }

    public function spellAttackBonus(int $proficiencyBonus): AttackBonus
    {
        return AttackBonus::from($this, $proficiencyBonus);
    }
}
