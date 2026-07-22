<?php

declare(strict_types=1);

namespace App\Domain\Characters\Commands;

use InvalidArgumentException;

final class CharacterCommandPayloadValidator
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function validate(array $payload): array
    {
        $type = $this->requiredString($payload, 'type', 22);
        if (array_key_exists('reason', $payload)) {
            $this->requiredString($payload, 'reason', 255);
        }

        return match ($type) {
            'update_ability' => $this->updateAbility($payload),
            'set_slot' => $this->setSlot($payload),
            'update_character_rules' => $this->updateCharacterRules($payload),
            'update_source_config' => $this->updateSourceConfig($payload),
            'add_source' => $this->addSource($payload),
            'remove_source' => $this->removeSource($payload),
            'acknowledge_warning' => $this->acknowledgeWarning($payload),
            'update_class' => $this->updateClass($payload),
            'restore_snapshot' => $this->restoreSnapshot($payload),
            default => throw new InvalidArgumentException('Unknown character command type.'),
        };
    }

    /** @param array<string, mixed> $payload */
    private function updateAbility(array $payload): array
    {
        $this->rejectUnknown($payload, ['type', 'ability', 'score', 'reason']);
        $this->requiredString($payload, 'ability', 40);
        $this->requiredInteger($payload, 'score');

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    private function setSlot(array $payload): array
    {
        $this->rejectUnknown($payload, [
            'type', 'slot_id', 'mode', 'spell_version_id', 'note', 'state', 'integrity', 'reason',
        ]);
        $this->positiveInteger($payload, 'slot_id');
        $mode = $this->requiredString($payload, 'mode', 13);
        if (! in_array($mode, ['select', 'clear', 'keep_override', 'restore'], true)) {
            throw new InvalidArgumentException('Unknown slot mutation mode.');
        }

        if ($mode === 'select') {
            $this->positiveInteger($payload, 'spell_version_id');
        } elseif ($mode === 'keep_override') {
            $this->nonEmptyString($payload, 'note', 2000);
        } elseif ($mode === 'restore') {
            $state = data_get($payload, 'state');
            if (! is_array($state)) {
                throw new InvalidArgumentException('Slot restore state must be an object.');
            }
            $this->validateSlotRestoreState($state);
            $this->integrity($payload);
        }

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    private function updateCharacterRules(array $payload): array
    {
        $this->rejectUnknown($payload, ['type', 'allow_legacy', 'reason']);
        if (! array_key_exists('allow_legacy', $payload) || ! is_bool(data_get($payload, 'allow_legacy'))) {
            throw new InvalidArgumentException('allow_legacy must be a boolean.');
        }

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    private function updateSourceConfig(array $payload): array
    {
        $this->rejectUnknown($payload, ['type', 'source_instance_id', 'chosen_list', 'reason']);
        $this->positiveInteger($payload, 'source_instance_id');
        $this->nonEmptyString($payload, 'chosen_list', 80);

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    private function addSource(array $payload): array
    {
        $this->rejectUnknown($payload, [
            'type', 'source_type', 'source_definition_id', 'config', 'reason',
        ]);
        $sourceType = $this->requiredString($payload, 'source_type', 10);
        if (! in_array($sourceType, ['class', 'feat', 'species', 'background'], true)) {
            throw new InvalidArgumentException('Source type must be class, feat, species, or background.');
        }
        $this->positiveInteger($payload, 'source_definition_id');
        if (! array_key_exists('config', $payload) || ! is_array(data_get($payload, 'config'))) {
            throw new InvalidArgumentException('Source config must be an object.');
        }
        $config = data_get($payload, 'config');
        if ($config !== [] && array_is_list($config)) {
            throw new InvalidArgumentException('Source config must be an object.');
        }
        if ($sourceType === 'class') {
            $this->rejectUnknown($config, ['level', 'wizard_spellbook_acquisitions'], 'class source config');
            $level = $this->requiredInteger($config, 'level');
            if ($level < 1 || $level > 20) {
                throw new InvalidArgumentException('Class source level must be between 1 and 20.');
            }
            if (array_key_exists('wizard_spellbook_acquisitions', $config)) {
                $acquisitions = data_get($config, 'wizard_spellbook_acquisitions');
                if (! is_array($acquisitions) || ! array_is_list($acquisitions)) {
                    throw new InvalidArgumentException('Wizard spellbook acquisitions must be a list.');
                }
            }
        }

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    private function removeSource(array $payload): array
    {
        $this->rejectUnknown($payload, ['type', 'source_instance_id', 'reason']);
        $this->positiveInteger($payload, 'source_instance_id');

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    private function acknowledgeWarning(array $payload): array
    {
        $this->rejectUnknown($payload, [
            'type', 'mode', 'warning_fingerprint', 'note', 'integrity', 'reason',
        ]);
        $this->nonEmptyString($payload, 'warning_fingerprint', 255);
        $mode = array_key_exists('mode', $payload)
            ? $this->requiredString($payload, 'mode', 11)
            : 'acknowledge';
        if (! in_array($mode, ['acknowledge', 'delete'], true)) {
            throw new InvalidArgumentException('Unknown warning acknowledgement mode.');
        }
        $payload['mode'] = $mode;
        if ($mode === 'acknowledge') {
            $this->nonEmptyString($payload, 'note', 2000);
        } else {
            $this->integrity($payload);
        }

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    private function updateClass(array $payload): array
    {
        $this->rejectUnknown($payload, [
            'type', 'class_definition_id', 'level', 'subclass_definition_id', 'reason',
        ]);
        $this->positiveInteger($payload, 'class_definition_id');
        if (! array_key_exists('level', $payload)) {
            throw new InvalidArgumentException('Class level is required; use null to remove the class.');
        }
        if (data_get($payload, 'level') !== null) {
            $this->requiredInteger($payload, 'level');
        }
        if (array_key_exists('subclass_definition_id', $payload)
            && data_get($payload, 'subclass_definition_id') !== null) {
            $this->positiveInteger($payload, 'subclass_definition_id');
        }

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    private function restoreSnapshot(array $payload): array
    {
        $this->rejectUnknown($payload, ['type', 'snapshot', 'integrity', 'reason']);
        if (! array_key_exists('snapshot', $payload) || ! is_array(data_get($payload, 'snapshot'))) {
            throw new InvalidArgumentException('Character snapshot must be an object.');
        }
        $this->integrity($payload);

        return $payload;
    }

    /** @param array<string, mixed> $state */
    private function validateSlotRestoreState(array $state): void
    {
        $expected = [
            'current_spell_version_id', 'selection_eligibility', 'selection_invalid_reason',
            'state', 'override_note',
        ];
        $this->rejectUnknown($state, $expected, 'slot restore state');
        foreach ($expected as $key) {
            if (! array_key_exists($key, $state)) {
                throw new InvalidArgumentException("Slot restore state is missing {$key}.");
            }
        }
        $spellVersionId = data_get($state, 'current_spell_version_id');
        if ($spellVersionId !== null && (! is_int($spellVersionId) || $spellVersionId < 1)) {
            throw new InvalidArgumentException('Slot restore spell_version_id must be a positive integer or null.');
        }
        $selectionEligibility = data_get($state, 'selection_eligibility');
        if (! is_string($selectionEligibility)
            || ! in_array($selectionEligibility, ['valid', 'invalid', 'unselected'], true)) {
            throw new InvalidArgumentException('Unknown slot selection eligibility.');
        }
        $selectionInvalidReason = data_get($state, 'selection_invalid_reason');
        if ($selectionInvalidReason !== null && ! is_string($selectionInvalidReason)) {
            throw new InvalidArgumentException('Slot selection invalid reason must be a string or null.');
        }
        $slotState = data_get($state, 'state');
        if (! is_string($slotState)
            || ! in_array($slotState, ['active', 'orphaned', 'discarded', 'kept_override'], true)) {
            throw new InvalidArgumentException('Unknown restored slot state.');
        }
        $overrideNote = data_get($state, 'override_note');
        if ($overrideNote !== null && ! is_string($overrideNote)) {
            throw new InvalidArgumentException('Slot override note must be a string or null.');
        }
    }

    /** @param array<string, mixed> $payload */
    private function requiredInteger(array $payload, string $key): int
    {
        $value = data_get($payload, $key);
        if (! array_key_exists($key, $payload) || ! is_int($value)) {
            throw new InvalidArgumentException("{$key} must be an integer.");
        }

        return $value;
    }

    /** @param array<string, mixed> $payload */
    private function positiveInteger(array $payload, string $key): int
    {
        $value = $this->requiredInteger($payload, $key);
        if ($value < 1) {
            throw new InvalidArgumentException("{$key} must be a positive integer.");
        }

        return $value;
    }

    /** @param array<string, mixed> $payload */
    private function requiredString(array $payload, string $key, int $maximum): string
    {
        $value = data_get($payload, $key);
        if (! array_key_exists($key, $payload) || ! is_string($value)) {
            throw new InvalidArgumentException("{$key} must be a string.");
        }
        if (mb_strlen($value) > $maximum) {
            throw new InvalidArgumentException("{$key} must not exceed {$maximum} characters.");
        }

        return $value;
    }

    /** @param array<string, mixed> $payload */
    private function nonEmptyString(array $payload, string $key, int $maximum): string
    {
        $value = $this->requiredString($payload, $key, $maximum);
        if (trim($value) === '') {
            throw new InvalidArgumentException("{$key} must not be empty.");
        }

        return $value;
    }

    /** @param array<string, mixed> $payload */
    private function integrity(array $payload): string
    {
        $value = $this->requiredString($payload, 'integrity', 64);
        if (strlen($value) !== 64 || ! ctype_xdigit($value)) {
            throw new InvalidArgumentException('integrity must be a 64-character hexadecimal signature.');
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $allowed
     */
    private function rejectUnknown(array $payload, array $allowed, string $subject = 'command'): void
    {
        $unknown = array_values(array_diff(array_keys($payload), $allowed));
        if ($unknown !== []) {
            throw new InvalidArgumentException("Unknown {$subject} field: {$unknown[0]}.");
        }
    }
}
