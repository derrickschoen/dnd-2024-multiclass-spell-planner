<?php

declare(strict_types=1);

namespace App\Domain\Characters;

enum SlotState: string
{
    case Active = 'active';
    case Orphaned = 'orphaned';
    case Discarded = 'discarded';
    case KeptOverride = 'kept_override';

    public function isUsable(): bool
    {
        return $this === self::Active || $this === self::KeptOverride;
    }
}
