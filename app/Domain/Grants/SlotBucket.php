<?php

declare(strict_types=1);

namespace App\Domain\Grants;

enum SlotBucket: string
{
    case CantripKnown = 'cantrip_known';
    case Prepared = 'prepared';
    case Known = 'known';
    case Spellbook = 'spellbook';
    case Automatic = 'automatic';
}
