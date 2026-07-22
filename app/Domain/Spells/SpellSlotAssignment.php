<?php

declare(strict_types=1);

namespace App\Domain\Spells;

abstract readonly class SpellSlotAssignment
{
    public static function fromReferences(?int $fixedSpellVersionId, ?int $currentSpellVersionId): self
    {
        return SpellSlotAssignmentFactory::fromReferences($fixedSpellVersionId, $currentSpellVersionId);
    }

    abstract public function spellVersionId(): ?int;
}
