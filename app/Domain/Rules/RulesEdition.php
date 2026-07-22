<?php

declare(strict_types=1);

namespace App\Domain\Rules;

enum RulesEdition: string
{
    case Legacy2014 = '2014';
    case Revised2024 = '2024';
    case Expanded = 'expanded';
}
