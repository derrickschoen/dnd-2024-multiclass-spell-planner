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
                $assignedVersionId = data_get($upgrade, "assignments.{$slotId}");
                $versionId = $assignedVersionId ?? data_get($slot, 'current_spell_version_id');
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

        Schema::table('spell_selection_slots', function (Blueprint $table): void {
            $table->dropIndex('slots_character_collection_index');
            $table->dropColumn([
                'selection_collection',
                'selection_eligibility',
                'selection_invalid_reason',
            ]);
        });
    }

    /** @return array{wizard_slots: list<object>, assignments: array<int, int>} */
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

        foreach ($legacy->groupBy('character_id') as $characterId => $preparations) {
            if ($preparations->count() > $slotsByCharacter->get($characterId, collect())->count()) {
                throw new RuntimeException(
                    'Legacy Wizard preparations exceed available Wizard preparation slot capacity.'
                );
            }
        }

        $assignments = [];
        $claimedSlotIds = [];
        foreach ($legacy as $preparation) {
            $versionId = (int) data_get($preparation, 'spell_version_id');
            $candidates = $slotsByCharacter->get(data_get($preparation, 'character_id'), collect())
                ->filter(fn (object $slot): bool => ! in_array((int) data_get($slot, 'id'), $claimedSlotIds, true))
                ->sortBy(fn (object $slot): array => [
                    (int) data_get($slot, 'current_spell_version_id') === $versionId ? 0 : 1,
                    (int) data_get($slot, 'ordinal'),
                ]);
            $destination = $candidates->first(
                fn (object $slot): bool => $this->slotAllowsSpell($slot, $versionId),
            );
            if ($destination === null) {
                throw new RuntimeException(
                    "Legacy Wizard preparation for spell version {$versionId} is not eligible for any preparation slot."
                );
            }
            $slotId = (int) data_get($destination, 'id');
            $claimedSlotIds[] = $slotId;
            $assignments[$slotId] = $versionId;
        }

        return [
            'wizard_slots' => $wizardSlots->values()->all(),
            'assignments' => $assignments,
        ];
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
