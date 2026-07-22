<?php

declare(strict_types=1);

namespace App\Domain\Spells;

enum DuplicateCategory: string
{
    case None = 'none';
    case ConflictingVersion = 'conflicting_version';
    case Wasteful = 'wasteful';
    case RedundantIntentional = 'redundant_intentional';

    public function isWarning(): bool
    {
        return $this !== self::None;
    }
}
