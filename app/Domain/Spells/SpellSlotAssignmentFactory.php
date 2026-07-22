<?php

declare(strict_types=1);

namespace App\Domain\Spells;

use InvalidArgumentException;

final class SpellSlotAssignmentFactory
{
    public static function fromReferences(?int $fixedSpellVersionId, ?int $currentSpellVersionId): SpellSlotAssignment
    {
        if ($fixedSpellVersionId !== null && $currentSpellVersionId !== null) {
            throw new InvalidArgumentException('A spell slot cannot hold both a fixed grant and a user selection.');
        }

        if ($fixedSpellVersionId !== null) {
            return new FixedSpellGrant($fixedSpellVersionId);
        }

        if ($currentSpellVersionId !== null) {
            return new UserSpellSelection($currentSpellVersionId);
        }

        return new UnassignedSpellSlot;
    }
}
