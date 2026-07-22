<?php

declare(strict_types=1);

namespace App\Domain\Spells;

final readonly class UnassignedSpellSlot extends SpellSlotAssignment
{
    public function spellVersionId(): ?int
    {
        return null;
    }
}
