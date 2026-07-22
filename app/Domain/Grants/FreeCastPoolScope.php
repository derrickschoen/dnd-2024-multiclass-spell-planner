<?php

declare(strict_types=1);

namespace App\Domain\Grants;

enum FreeCastPoolScope: string
{
    case PerSpell = 'per_spell';
    case Shared = 'shared';
}
