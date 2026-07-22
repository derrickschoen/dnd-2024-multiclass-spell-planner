<?php

declare(strict_types=1);

namespace App\Domain\Characters;

enum SelectionEligibility: string
{
    case Valid = 'valid';
    case Invalid = 'invalid';
    case Unselected = 'unselected';
}
