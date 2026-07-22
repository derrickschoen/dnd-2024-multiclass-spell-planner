<?php

declare(strict_types=1);

namespace App\Domain\Rules;

enum EffectReliabilityCategory: string
{
    case AttackRoll = 'attack_roll';
    case SavingThrow = 'saving_throw';
    case FixedEffect = 'fixed_effect';
    case ModifierScaled = 'modifier_scaled';
    case RitualUtility = 'ritual_utility';
    case Mixed = 'mixed';
}
