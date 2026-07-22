<?php

declare(strict_types=1);

use App\Domain\Characters\SourceType;
use App\Domain\Grants\SlotBucket;
use App\Domain\Spells\SpellSelectionEligibility;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->rewriteWizardPreparationRules(false);
        $this->rewriteSpellbookConfigs(false);

        $slotIds = $this->wizardPreparedSlots()->pluck('slot.id');
        DB::table('spell_selection_slots')->whereIn('id', $slotIds)->update([
            'selection_collection' => null,
        ]);
        foreach ($slotIds as $slotId) {
            app(SpellSelectionEligibility::class)->refresh((int) $slotId);
        }

        Schema::table('wizard_spellbook_entries', function (Blueprint $table): void {
            $table->dropColumn([
                'acquisition',
                'copy_cost_gp',
                'copy_time_hours',
                'source_instance_id',
                'notes',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('wizard_spellbook_entries', function (Blueprint $table): void {
            $table->string('acquisition')->default('starting')->after('spell_version_id');
            $table->unsignedInteger('copy_cost_gp')->nullable()->after('acquisition');
            $table->unsignedInteger('copy_time_hours')->nullable()->after('copy_cost_gp');
            $table->unsignedBigInteger('source_instance_id')->nullable()->after('copy_time_hours');
            $table->text('notes')->nullable()->after('source_instance_id');
        });

        $this->rewriteSpellbookConfigs(true);
        $this->rewriteWizardPreparationRules(true);

        $slotIds = $this->wizardPreparedSlots()->pluck('slot.id');
        DB::table('spell_selection_slots')->whereIn('id', $slotIds)->update([
            'selection_collection' => 'wizard_spellbook',
        ]);
    }

    private function rewriteWizardPreparationRules(bool $constrainToSpellbook): void
    {
        $progressions = DB::table('class_progressions as progression')
            ->join('class_definitions as class', 'class.id', '=', 'progression.class_definition_id')
            ->where('class.name', 'Wizard')
            ->select(['progression.id', 'progression.grant_rules'])
            ->get();

        foreach ($progressions as $progression) {
            $rules = json_decode((string) data_get($progression, 'grant_rules'), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($rules)) {
                continue;
            }
            foreach ($rules as &$rule) {
                if (! is_array($rule) || data_get($rule, 'rule_key') !== 'wizard-prepared') {
                    continue;
                }
                if ($constrainToSpellbook) {
                    $rule['selection_collection'] = 'wizard_spellbook';
                } else {
                    unset($rule['selection_collection']);
                }
            }
            unset($rule);
            DB::table('class_progressions')->where('id', data_get($progression, 'id'))->update([
                'grant_rules' => json_encode($rules, JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
        }
    }

    private function rewriteSpellbookConfigs(bool $addLegacyProvenance): void
    {
        $sources = DB::table('character_source_instances')
            ->whereNotNull('config')
            ->select(['id', 'config'])
            ->get();
        foreach ($sources as $source) {
            $config = json_decode((string) data_get($source, 'config'), true, 512, JSON_THROW_ON_ERROR);
            $entries = is_array($config) ? data_get($config, 'wizard_spellbook_acquisitions') : null;
            if (! is_array($config) || ! is_array($entries)) {
                continue;
            }
            $config['wizard_spellbook_acquisitions'] = array_map(
                static function (mixed $entry) use ($addLegacyProvenance): mixed {
                    if (! is_array($entry)) {
                        return $entry;
                    }
                    $identity = array_intersect_key($entry, array_flip([
                        'spell_version_id', 'spell_version_key',
                    ]));
                    if ($addLegacyProvenance) {
                        $identity['acquisition'] = 'starting';
                    }

                    return $identity;
                },
                $entries,
            );
            DB::table('character_source_instances')->where('id', data_get($source, 'id'))->update([
                'config' => json_encode($config, JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
        }
    }

    private function wizardPreparedSlots(): Builder
    {
        return DB::table('spell_selection_slots as slot')
            ->join('character_source_instances as source', 'source.id', '=', 'slot.source_instance_id')
            ->join('class_definitions as class', 'class.id', '=', 'source.source_definition_id')
            ->where('source.source_type', SourceType::CharacterClass->value)
            ->where('class.name', 'Wizard')
            ->where('slot.bucket', SlotBucket::Prepared->value);
    }
};
