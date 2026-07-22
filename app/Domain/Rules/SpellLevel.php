<?php

declare(strict_types=1);

namespace App\Domain\Rules;

use InvalidArgumentException;

final readonly class SpellLevel
{
    public function __construct(public int $value)
    {
        if ($value < 0 || $value > 9) {
            throw new InvalidArgumentException("Spell level must be between 0 and 9, got {$value}.");
        }
    }

    public function isCantrip(): bool
    {
        return $this->value === 0;
    }
}
