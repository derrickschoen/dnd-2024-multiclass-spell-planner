<?php

declare(strict_types=1);

namespace App\Domain\Characters\Commands;

use App\Domain\Characters\SelectionEligibility;
use App\Domain\Characters\SlotState;
use App\Domain\Spells\SpellSelectionEligibility;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class SetSlotCommand implements CharacterCommand
{
    /** @var array<string, mixed> */
    private array $previous = [];

    /** @param array<string, mixed> $payload */
    public function __construct(
        private readonly array $payload,
        private readonly SpellSelectionEligibility $eligibility,
        private readonly CharacterCommandIntegrity $integrity,
    ) {}

    private int $characterId;

    public function apply(int $characterId): void
    {
        $this->characterId = $characterId;
        $slotId = (int) data_get($this->payload, 'slot_id');
        $slot = DB::table('spell_selection_slots')
            ->where('character_id', $characterId)
            ->where('id', $slotId)
            ->first();
        if ($slot === null) {
            throw new InvalidArgumentException('Spell slot does not belong to this character.');
        }
        if ((bool) data_get($slot, 'is_locked')) {
            throw new InvalidArgumentException('This spell slot is locked.');
        }

        $fields = [
            'current_spell_version_id', 'selection_eligibility', 'selection_invalid_reason',
            'state', 'override_note',
        ];
        foreach ($fields as $field) {
            $this->previous[$field] = data_get($slot, $field);
        }

        $mode = (string) data_get($this->payload, 'mode', 'select');
        $updates = match ($mode) {
            'select' => $this->selectionUpdates($slot),
            'clear' => $this->clearUpdates($slot),
            'keep_override' => $this->overrideUpdates($slot),
            'restore' => $this->restoreUpdates($slot),
            default => throw new InvalidArgumentException('Unknown slot mutation mode.'),
        };
        DB::table('spell_selection_slots')->where('id', $slotId)->update(array_merge(
            $updates,
            ['updated_at' => now()],
        ));
    }

    /** @return array<string, mixed> */
    private function selectionUpdates(object $slot): array
    {
        $spellVersionId = (int) data_get($this->payload, 'spell_version_id');
        $result = $this->eligibility->evaluate($slot, $spellVersionId);
        if (data_get($result, 'status') !== SelectionEligibility::Valid->value) {
            throw new InvalidArgumentException((string) data_get($result, 'reason'));
        }

        return [
            'current_spell_version_id' => $spellVersionId,
            'selection_eligibility' => SelectionEligibility::Valid->value,
            'selection_invalid_reason' => null,
            'state' => data_get($slot, 'state') === SlotState::KeptOverride->value
                ? SlotState::Active->value : data_get($slot, 'state'),
            'override_note' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function clearUpdates(object $slot): array
    {
        $orphaned = data_get($slot, 'orphan_reason_code') !== null;

        return [
            'current_spell_version_id' => null,
            'selection_eligibility' => SelectionEligibility::Unselected->value,
            'selection_invalid_reason' => null,
            'state' => $orphaned ? SlotState::Discarded->value : SlotState::Active->value,
            'override_note' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function overrideUpdates(object $slot): array
    {
        if (data_get($slot, 'current_spell_version_id') === null) {
            throw new InvalidArgumentException('Choose a spell before keeping an override.');
        }
        $note = trim((string) data_get($this->payload, 'note'));
        if ($note === '') {
            throw new InvalidArgumentException('An override note is required.');
        }

        return ['state' => SlotState::KeptOverride->value, 'override_note' => $note];
    }

    /** @return array<string, mixed> */
    private function restoreUpdates(object $slot): array
    {
        $state = data_get($this->payload, 'state');
        if (! is_array($state)) {
            throw new InvalidArgumentException('Slot restore state is missing.');
        }
        $spellVersionId = data_get($state, 'current_spell_version_id');
        if ($spellVersionId !== null && ! DB::table('spell_versions')
            ->where('id', $spellVersionId)
            ->where('is_active', true)
            ->exists()) {
            throw new InvalidArgumentException("Slot restore references inactive spell version {$spellVersionId}.");
        }

        $updates = array_intersect_key($state, array_flip([
            'current_spell_version_id', 'selection_eligibility', 'selection_invalid_reason',
            'state', 'override_note',
        ]));
        if (in_array(data_get($updates, 'state'), [SlotState::Active->value, SlotState::KeptOverride->value], true)) {
            if (data_get($slot, 'state') === SlotState::Orphaned->value) {
                $updates['state'] = SlotState::Orphaned->value;
                $updates['selection_eligibility'] = $spellVersionId === null
                    ? SelectionEligibility::Unselected->value : SelectionEligibility::Invalid->value;
                $updates['selection_invalid_reason'] = $spellVersionId === null
                    ? null
                    : $this->orphanedSelectionReason($slot);

                return $updates;
            }
            $restoredSlot = new \stdClass;
            foreach (get_object_vars($slot) as $property => $value) {
                $restoredSlot->{$property} = $value;
            }
            $restoredSlot->current_spell_version_id = $spellVersionId;
            $result = $this->eligibility->evaluate($restoredSlot);
            $updates['selection_eligibility'] = data_get($result, 'status');
            $updates['selection_invalid_reason'] = data_get($result, 'reason');
        }

        return $updates;
    }

    private function orphanedSelectionReason(object $slot): string
    {
        return data_get($slot, 'selection_invalid_reason')
            ?? match (data_get($slot, 'orphan_reason_code')) {
                'rule_no_longer_active' => 'Selection preserved because its grant rule is no longer active.',
                default => 'Selection preserved because its source is no longer active.',
            };
    }

    public function inverse(): array
    {
        return $this->integrity->attach($this->characterId, [
            'type' => 'set_slot',
            'slot_id' => (int) data_get($this->payload, 'slot_id'),
            'mode' => 'restore',
            'state' => $this->previous,
        ]);
    }

    public function actionType(): string
    {
        return 'set_slot';
    }
}
