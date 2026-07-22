<?php

declare(strict_types=1);

namespace App\Domain\Characters;

enum SourceType: string
{
    case CharacterClass = 'class';
    case Subclass = 'subclass';
    case Feat = 'feat';
    case Species = 'species';
    case Background = 'background';

    public function definitionTable(): string
    {
        return $this->value.'_definitions';
    }
}
