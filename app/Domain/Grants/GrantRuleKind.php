<?php

declare(strict_types=1);

namespace App\Domain\Grants;

enum GrantRuleKind: string
{
    case FixedSpell = 'fixed_spell';
    case ChoiceFromList = 'choice_from_list';
    case ChoiceFromQuery = 'choice_from_query';
    case GrantSource = 'grant_source';
    case Capability = 'capability';
    case SpellbookAcquisition = 'spellbook_acquisition';

    public function mintsSlots(): bool
    {
        return match ($this) {
            self::FixedSpell, self::ChoiceFromList, self::ChoiceFromQuery => true,
            self::GrantSource, self::Capability, self::SpellbookAcquisition => false,
        };
    }

    public function requiresBucket(): bool
    {
        return $this->mintsSlots() || $this === self::SpellbookAcquisition;
    }
}
