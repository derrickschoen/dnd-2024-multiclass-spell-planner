<?php

declare(strict_types=1);

namespace App\Domain\Grants;

enum FreeCastRecovery: string
{
    case LongRest = 'long_rest';
    case ShortRest = 'short_rest';
    case Dawn = 'dawn';
    case AtWill = 'at_will';
}
