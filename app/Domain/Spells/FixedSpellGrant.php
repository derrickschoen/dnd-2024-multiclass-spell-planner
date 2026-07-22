<?php

declare(strict_types=1);

namespace App\Domain\Spells;

use InvalidArgumentException;

final readonly class FixedSpellGrant extends SpellSlotAssignment
{
    public function __construct(public int $spellVersionId)
    {
        if ($spellVersionId < 1) {
            throw new InvalidArgumentException('A fixed spell version ID must be positive.');
        }
    }

    public function spellVersionId(): int
    {
        return $this->spellVersionId;
    }
}
