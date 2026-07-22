<?php

declare(strict_types=1);

namespace App\Domain\Rules;

enum CastingMode: string
{
    case AtWill = 'at_will';
    case SlotsAndFreeCast = 'slots_and_free_cast';
    case WithSlots = 'with_slots';
    case FreeCastOnly = 'free_cast_only';
    case Granted = 'granted';
    case RitualOnly = 'ritual_only';
    case AvailableOnLongRest = 'available_on_long_rest';
}
