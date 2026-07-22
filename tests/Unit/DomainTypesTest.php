<?php

declare(strict_types=1);

use App\Domain\Characters\SlotState;
use App\Domain\Grants\GrantRuleKind;
use App\Domain\Grants\SlotBucket;
use App\Domain\Rules\Ability;
use App\Domain\Rules\AbilityScore;
use App\Domain\Rules\ProgressionType;
use App\Domain\Rules\SpellLevel;
use App\Domain\Spells\FixedSpellGrant;
use App\Domain\Spells\SpellSlotAssignment;
use App\Domain\Spells\UnassignedSpellSlot;
use App\Domain\Spells\UserSpellSelection;

it('puts shared caster contribution and preparation behavior on progression types', function (): void {
    expect(ProgressionType::HalfUp->sharedCasterLevels(1))->toBe(1)
        ->and(ProgressionType::HalfDown->sharedCasterLevels(1))->toBe(0)
        ->and(ProgressionType::ThirdUp->sharedCasterLevels(4))->toBe(2)
        ->and(ProgressionType::ThirdDown->sharedCasterLevels(4))->toBe(1)
        ->and(ProgressionType::Pact->sharedCasterLevels(20))->toBe(0)
        ->and(ProgressionType::Pact->contributesToSharedSlots())->toBeFalse()
        ->and(ProgressionType::Full->maxPreparableLevel(5))->toBe(3);
});

it('represents bounded rules numbers with value objects', function (): void {
    $score = new AbilityScore(18);

    expect((new SpellLevel(0))->isCantrip())->toBeTrue()
        ->and((new SpellLevel(9))->value)->toBe(9)
        ->and($score->modifier())->toBe(4)
        ->and($score->spellAttackBonus(3)->value)->toBe(7)
        ->and($score->spellSaveDC(3)->value)->toBe(15)
        ->and(fn () => new SpellLevel(10))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new AbilityScore(0))->toThrow(InvalidArgumentException::class);
});

it('hydrates exactly one spell-slot assignment state', function (): void {
    expect(SpellSlotAssignment::fromReferences(null, null))->toBeInstanceOf(UnassignedSpellSlot::class)
        ->and(SpellSlotAssignment::fromReferences(12, null))->toEqual(new FixedSpellGrant(12))
        ->and(SpellSlotAssignment::fromReferences(null, 34))->toEqual(new UserSpellSelection(34))
        ->and(fn () => SpellSlotAssignment::fromReferences(12, 34))
        ->toThrow(InvalidArgumentException::class, 'both a fixed grant and a user selection');
});

it('exposes the problem vocabulary as backed enums', function (): void {
    expect(Ability::Wisdom->value)->toBe('wisdom')
        ->and(GrantRuleKind::Capability->mintsSlots())->toBeFalse()
        ->and(GrantRuleKind::ChoiceFromList->mintsSlots())->toBeTrue()
        ->and(SlotBucket::Spellbook->value)->toBe('spellbook')
        ->and(SlotState::KeptOverride->isUsable())->toBeTrue();
});
