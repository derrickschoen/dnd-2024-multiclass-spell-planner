<?php

declare(strict_types=1);

namespace App\Domain\Spells;

use App\Domain\Characters\SelectionEligibility;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class SpellSelectionEligibility
{
    /** @return array{status: 'valid'|'invalid'|'unselected', reason: string|null} */
    public function evaluate(object $slot, ?int $candidateSpellVersionId = null): array
    {
        $spellVersionId = $candidateSpellVersionId
            ?? data_get($slot, 'fixed_spell_version_id')
            ?? data_get($slot, 'current_spell_version_id');
        if ($spellVersionId === null) {
            return ['status' => SelectionEligibility::Unselected->value, 'reason' => null];
        }

        $version = DB::table('spell_versions')->find((int) $spellVersionId);
        if ($version === null || ! (bool) data_get($version, 'is_active', true)) {
            return $this->invalid('Selected spell version is not active in the catalog.');
        }
        $character = DB::table('characters')->find(data_get($slot, 'character_id'));
        $legacyAllowed = (bool) data_get($character, 'allow_legacy');
        if (data_get($version, 'rules_edition') === '2014' && ! $legacyAllowed) {
            return $this->invalid('Enable legacy rules before selecting a 2014 spell version.');
        }

        $level = (int) data_get($version, 'level');
        if ($level < (int) data_get($slot, 'spell_level_min', 0)
            || $level > (int) data_get($slot, 'spell_level_max', 9)) {
            return $this->invalid('Selected spell is outside the slot level range.');
        }

        $lists = $this->jsonList(data_get($slot, 'allowed_spell_lists'));
        if ($lists !== []) {
            $directMembership = DB::table('spell_list_memberships')
                ->where('spell_version_id', $spellVersionId)
                ->whereIn('spell_list_key', $lists)
                ->exists();
            $identityMembership = data_get($version, 'rules_edition') === '2014' && $legacyAllowed
                && DB::table('spell_list_memberships as membership')
                    ->join('spell_versions as listed', 'listed.id', '=', 'membership.spell_version_id')
                    ->where('listed.spell_identity_id', data_get($version, 'spell_identity_id'))
                    ->whereIn('membership.spell_list_key', $lists)
                    ->exists();
            if (! $directMembership && ! $identityMembership) {
                return $this->invalid('Selected spell is not on an allowed spell list.');
            }
        }

        $schools = $this->jsonList(data_get($slot, 'allowed_schools'));
        if ($schools !== [] && ! in_array(data_get($version, 'school'), $schools, true)) {
            return $this->invalid('Selected spell does not have an allowed school.');
        }

        $tags = $this->jsonList(data_get($slot, 'allowed_tags'));
        if ($tags !== []) {
            $actualTags = DB::table('spell_version_tags')
                ->where('spell_version_id', $spellVersionId)
                ->pluck('tag')
                ->all();
            if (array_diff($tags, $actualTags) !== []) {
                return $this->invalid('Selected spell does not have every required tag.');
            }
        }

        $collection = data_get($slot, 'selection_collection');
        if ($collection !== null) {
            throw new InvalidArgumentException("Unsupported selection collection '{$collection}'.");
        }

        return ['status' => SelectionEligibility::Valid->value, 'reason' => null];
    }

    public function refresh(int $slotId): void
    {
        $slot = DB::table('spell_selection_slots')->find($slotId);
        if (! is_object($slot)) {
            return;
        }
        $result = $this->evaluate($slot);
        if (data_get($slot, 'selection_eligibility') === data_get($result, 'status')
            && data_get($slot, 'selection_invalid_reason') === data_get($result, 'reason')) {
            return;
        }
        DB::table('spell_selection_slots')->where('id', $slotId)->update([
            'selection_eligibility' => data_get($result, 'status'),
            'selection_invalid_reason' => data_get($result, 'reason'),
            'updated_at' => now(),
        ]);
    }

    /** @return array{status: 'invalid', reason: string} */
    private function invalid(string $reason): array
    {
        return ['status' => SelectionEligibility::Invalid->value, 'reason' => $reason];
    }

    /** @return list<string> */
    private function jsonList(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        if (is_array($value)) {
            return array_values($value);
        }
        $decoded = json_decode((string) $value, true, 512, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? array_values($decoded) : [];
    }
}
