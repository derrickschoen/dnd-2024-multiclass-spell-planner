<?php

declare(strict_types=1);

namespace App\Domain\Characters\Commands;

use App\Domain\Spells\SpellSelectionEligibility;
use Illuminate\Support\Facades\DB;

final class UpdateCharacterRulesCommand implements CharacterCommand
{
    private bool $previousAllowLegacy;

    public function __construct(
        private readonly bool $allowLegacy,
        private readonly SpellSelectionEligibility $eligibility,
    ) {}

    public function apply(int $characterId): void
    {
        $this->previousAllowLegacy = (bool) DB::table('characters')
            ->where('id', $characterId)
            ->value('allow_legacy');
        DB::table('characters')->where('id', $characterId)->update([
            'allow_legacy' => $this->allowLegacy,
            'updated_at' => now(),
        ]);

        DB::table('spell_selection_slots')->where('character_id', $characterId)
            ->pluck('id')
            ->each(fn (mixed $slotId) => $this->eligibility->refresh((int) $slotId));
    }

    public function inverse(): array
    {
        return ['type' => 'update_character_rules', 'allow_legacy' => $this->previousAllowLegacy];
    }

    public function actionType(): string
    {
        return 'update_character_rules';
    }
}
