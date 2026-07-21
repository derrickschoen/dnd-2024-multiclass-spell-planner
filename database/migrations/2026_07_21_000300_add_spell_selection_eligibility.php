<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Validate and allocate every legacy preparation before making any
        // schema change. A capacity or eligibility failure leaves the legacy
        // table and the pre-upgrade schema completely intact.
        $upgrade = $this->legacyWizardPreparationUpgrade();

        Schema::table('spell_selection_slots', function (Blueprint $table): void {
            $table->string('selection_collection')->nullable()->after('allowed_tags');
            $table->string('selection_eligibility')->default('unselected')->after('selection_collection');
            $table->text('selection_invalid_reason')->nullable()->after('selection_eligibility');
            $table->index(['character_id', 'selection_collection'], 'slots_character_collection_index');
        });

        DB::transaction(function () use ($upgrade): void {
            foreach (data_get($upgrade, 'wizard_slots', []) as $slot) {
                $slotId = (int) data_get($slot, 'id');
                $allAssignments = data_get($upgrade, 'assignments', []);
                $versionId = array_key_exists($slotId, $allAssignments)
                    ? $allAssignments[$slotId]
                    : data_get($slot, 'current_spell_version_id');
                $valid = $versionId !== null && $this->slotAllowsSpell($slot, (int) $versionId);
                DB::table('spell_selection_slots')->where('id', $slotId)->update([
                    'current_spell_version_id' => $versionId,
                    'selection_collection' => 'wizard_spellbook',
                    'selection_eligibility' => $versionId === null ? 'unselected' : ($valid ? 'valid' : 'invalid'),
                    'selection_invalid_reason' => $versionId === null || $valid
                        ? null
                        : "Existing Wizard preparation is not eligible for the character's spellbook-constrained slot.",
                    'updated_at' => now(),
                ]);
            }
        });

        // The old authority is removed only after every legacy row has a
        // validated destination and all assignments have been persisted.
        Schema::dropIfExists('wizard_prepared_entries');
    }

    public function down(): void
    {
        Schema::create('wizard_prepared_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->foreignId('wizard_spellbook_entry_id')
                ->constrained('wizard_spellbook_entries')
                ->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['character_id', 'wizard_spellbook_entry_id']);
        });

        $prepared = DB::table('spell_selection_slots as slot')
            ->join('character_source_instances as source', 'source.id', '=', 'slot.source_instance_id')
            ->join('class_definitions as class', 'class.id', '=', 'source.source_definition_id')
            ->join('wizard_spellbook_entries as entry', function ($join): void {
                $join->on('entry.character_id', '=', 'slot.character_id')
                    ->on('entry.spell_version_id', '=', 'slot.current_spell_version_id');
            })
            ->where('source.source_type', 'class')
            ->where('class.name', 'Wizard')
            ->where('slot.bucket', 'prepared')
            ->where('slot.state', 'active')
            ->where('slot.selection_collection', 'wizard_spellbook')
            ->where('slot.selection_eligibility', 'valid')
            ->select(['slot.character_id', 'entry.id as wizard_spellbook_entry_id'])
            ->distinct()
            ->get();
        foreach ($prepared as $entry) {
            DB::table('wizard_prepared_entries')->insert([
                'character_id' => data_get($entry, 'character_id'),
                'wizard_spellbook_entry_id' => data_get($entry, 'wizard_spellbook_entry_id'),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Schema::table('spell_selection_slots', function (Blueprint $table): void {
            $table->dropIndex('slots_character_collection_index');
            $table->dropColumn([
                'selection_collection',
                'selection_eligibility',
                'selection_invalid_reason',
            ]);
        });
    }

    /** @return array{wizard_slots: list<object>, assignments: array<int, int|null>} */
    private function legacyWizardPreparationUpgrade(): array
    {
        if (! Schema::hasTable('wizard_prepared_entries')) {
            return ['wizard_slots' => [], 'assignments' => []];
        }

        $wizardSlots = DB::table('spell_selection_slots as slot')
            ->join('character_source_instances as source', 'source.id', '=', 'slot.source_instance_id')
            ->join('class_definitions as class', 'class.id', '=', 'source.source_definition_id')
            ->where('source.source_type', 'class')
            ->where('class.name', 'Wizard')
            ->where('slot.bucket', 'prepared')
            ->where('slot.state', 'active')
            ->select('slot.*')
            ->orderBy('slot.character_id')
            ->orderBy('slot.ordinal')
            ->get();
        $slotsByCharacter = $wizardSlots->groupBy('character_id');

        $legacy = DB::table('wizard_prepared_entries as prepared')
            ->join('wizard_spellbook_entries as entry', 'entry.id', '=', 'prepared.wizard_spellbook_entry_id')
            ->select([
                'prepared.id',
                'prepared.character_id',
                'entry.spell_version_id',
            ])
            ->orderBy('prepared.character_id')
            ->orderBy('prepared.id')
            ->get();

        $legacyByCharacter = $legacy->groupBy('character_id');
        $characterIds = $slotsByCharacter->keys()
            ->merge($legacyByCharacter->keys())
            ->unique();
        $assignments = [];
        foreach ($characterIds as $characterId) {
            $slots = $slotsByCharacter->get($characterId, collect())->values();
            $legacyVersionIds = $legacyByCharacter->get($characterId, collect())
                ->pluck('spell_version_id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->unique()
                ->values();
            $currentVersionIds = $slots
                ->pluck('current_spell_version_id')
                ->filter(static fn (mixed $id): bool => $id !== null)
                ->map(static fn (mixed $id): int => (int) $id)
                ->unique()
                ->values();
            $combinedVersionIds = $currentVersionIds
                ->merge($legacyVersionIds)
                ->unique()
                ->values()
                ->all();

            if (count($combinedVersionIds) > $slots->count()) {
                throw new RuntimeException(
                    'Combined Wizard preparations exceed available Wizard preparation slot capacity.'
                );
            }

            foreach ($legacyVersionIds as $versionId) {
                if (! $slots->contains(fn (object $slot): bool => $this->slotAllowsSpell($slot, $versionId))) {
                    throw new RuntimeException(
                        "Legacy Wizard preparation for spell version {$versionId} is not eligible for any preparation slot."
                    );
                }
            }

            $characterAssignments = $this->assignCombinedPreparations($slots->all(), $combinedVersionIds);
            if ($characterAssignments === null) {
                throw new RuntimeException(
                    'Combined Wizard preparations cannot be assigned to eligible preparation slots.'
                );
            }
            $assignments += $characterAssignments;
        }

        return [
            'wizard_slots' => $wizardSlots->values()->all(),
            'assignments' => $assignments,
        ];
    }

    /**
     * @param  list<object>  $slots
     * @param  list<int>  $versionIds
     * @return array<int, int|null>|null
     */
    private function assignCombinedPreparations(array $slots, array $versionIds): ?array
    {
        usort($versionIds, function (int $left, int $right) use ($slots): int {
            $leftCount = count(array_filter($slots, fn (object $slot): bool => $this->slotAllowsSpell($slot, $left)));
            $rightCount = count(array_filter($slots, fn (object $slot): bool => $this->slotAllowsSpell($slot, $right)));

            return $leftCount <=> $rightCount;
        });

        $matched = $this->matchPreparations($slots, $versionIds, 0, []);
        if ($matched === null) {
            return null;
        }
        foreach ($slots as $slot) {
            $matched[(int) data_get($slot, 'id')] ??= null;
        }

        return $matched;
    }

    /**
     * @param  list<object>  $slots
     * @param  list<int>  $versionIds
     * @param  array<int, int>  $assignments
     * @return array<int, int>|null
     */
    private function matchPreparations(array $slots, array $versionIds, int $offset, array $assignments): ?array
    {
        if ($offset >= count($versionIds)) {
            return $assignments;
        }

        $versionId = $versionIds[$offset];
        $candidates = array_values(array_filter(
            $slots,
            fn (object $slot): bool => ! array_key_exists((int) data_get($slot, 'id'), $assignments)
                && $this->slotAllowsSpell($slot, $versionId),
        ));
        usort($candidates, static fn (object $left, object $right): int => [
            (int) data_get($left, 'current_spell_version_id') === $versionId ? 0 : 1,
            (int) data_get($left, 'ordinal'),
        ] <=> [
            (int) data_get($right, 'current_spell_version_id') === $versionId ? 0 : 1,
            (int) data_get($right, 'ordinal'),
        ]);

        foreach ($candidates as $slot) {
            $next = $assignments;
            $next[(int) data_get($slot, 'id')] = $versionId;
            $matched = $this->matchPreparations($slots, $versionIds, $offset + 1, $next);
            if ($matched !== null) {
                return $matched;
            }
        }

        return null;
    }

    private function slotAllowsSpell(object $slot, int $versionId): bool
    {
        $version = DB::table('spell_versions')->find($versionId);
        if ($version === null || ! (bool) data_get($version, 'is_active', true)) {
            return false;
        }
        $level = (int) data_get($version, 'level');
        if ($level < (int) data_get($slot, 'spell_level_min', 0)
            || $level > (int) data_get($slot, 'spell_level_max', 9)) {
            return false;
        }
        if (! DB::table('wizard_spellbook_entries')
            ->where('character_id', data_get($slot, 'character_id'))
            ->where('spell_version_id', $versionId)
            ->exists()) {
            return false;
        }

        $lists = $this->jsonList(data_get($slot, 'allowed_spell_lists'));
        if ($lists !== [] && ! DB::table('spell_list_memberships')
            ->where('spell_version_id', $versionId)
            ->whereIn('spell_list_key', $lists)
            ->exists()) {
            return false;
        }
        $schools = $this->jsonList(data_get($slot, 'allowed_schools'));
        if ($schools !== [] && ! in_array(data_get($version, 'school'), $schools, true)) {
            return false;
        }
        $tags = $this->jsonList(data_get($slot, 'allowed_tags'));
        if ($tags !== []) {
            $actual = DB::table('spell_version_tags')
                ->where('spell_version_id', $versionId)
                ->pluck('tag')
                ->all();
            if (array_diff($tags, $actual) !== []) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> */
    private function jsonList(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        $decoded = is_array($value)
            ? $value
            : json_decode((string) $value, true, 512, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? array_values($decoded) : [];
    }
};
