<?php

declare(strict_types=1);

namespace App\Domain\Rules;

use InvalidArgumentException;

enum ProgressionType: string
{
    case Full = 'full';
    case HalfUp = 'half_up';
    case HalfDown = 'half_down';
    case ThirdUp = 'third_up';
    case ThirdDown = 'third_down';
    case Pact = 'pact';
    case None = 'none';

    public function sharedCasterLevels(int $classLevel): int
    {
        if ($classLevel < 0) {
            throw new InvalidArgumentException("Class level cannot be negative, got {$classLevel}.");
        }

        return match ($this) {
            self::Full => $classLevel,
            self::HalfUp => (int) ceil($classLevel / 2),
            self::HalfDown => intdiv($classLevel, 2),
            self::ThirdUp => (int) ceil($classLevel / 3),
            self::ThirdDown => intdiv($classLevel, 3),
            self::Pact, self::None => 0,
        };
    }

    public function contributesToSharedSlots(): bool
    {
        return $this !== self::Pact && $this !== self::None;
    }

    public function maxPreparableLevel(int $classLevel): int
    {
        return match ($this) {
            self::Full => min(9, (int) ceil($classLevel / 2)),
            self::HalfUp => min(5, (int) ceil($classLevel / 4)),
            self::HalfDown => $classLevel < 2 ? 0 : min(5, (int) ceil($classLevel / 4)),
            self::ThirdUp, self::ThirdDown => $classLevel < 3
                ? 0
                : min(4, intdiv($classLevel - 1, 6) + 1),
            self::Pact => min(5, max(1, intdiv($classLevel + 1, 2))),
            self::None => 0,
        };
    }
}
