<?php

declare(strict_types=1);

namespace App\Domain\Spells;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class SpellSelectionService
{
    public function __construct(private SpellSelectionEligibility $eligibility) {}

    public function select(int $slotId, int $spellVersionId): void
    {
        DB::transaction(function () use ($slotId, $spellVersionId): void {
            $slot = DB::table('spell_selection_slots as slot')
                ->join('character_source_instances as source', 'source.id', '=', 'slot.source_instance_id')
                ->where('slot.id', $slotId)
                ->where('slot.state', 'active')
                ->where('source.state', 'active')
                ->select('slot.*')
                ->first();
            if ($slot === null) {
                throw new InvalidArgumentException("Active spell selection slot {$slotId} does not exist.");
            }
            if ((bool) data_get($slot, 'is_locked')) {
                throw new InvalidArgumentException("Spell selection slot {$slotId} is locked.");
            }

            $result = $this->eligibility->evaluate($slot, $spellVersionId);
            if (data_get($result, 'status') !== 'valid') {
                throw new InvalidArgumentException((string) data_get($result, 'reason'));
            }

            DB::table('spell_selection_slots')->where('id', $slotId)->update([
                'current_spell_version_id' => $spellVersionId,
                'selection_eligibility' => 'valid',
                'selection_invalid_reason' => null,
                'updated_at' => now(),
            ]);
        });
    }
}
