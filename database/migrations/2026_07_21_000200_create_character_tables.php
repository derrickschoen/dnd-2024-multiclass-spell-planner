<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Character build state. The constraints here are what make editing
 * cascade-resistant: stable slot keys, composite foreign keys that pin every
 * child to the same character, and tombstones instead of deletes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('characters', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedTinyInteger('strength')->default(10);
            $table->unsignedTinyInteger('dexterity')->default(10);
            $table->unsignedTinyInteger('constitution')->default(10);
            $table->unsignedTinyInteger('intelligence')->default(10);
            $table->unsignedTinyInteger('wisdom')->default(10);
            $table->unsignedTinyInteger('charisma')->default(10);
            // Proficiency bonus is DERIVED from total class levels in the engine.
            // Only an explicit house-rule override is stored, so it can never go stale.
            $table->unsignedTinyInteger('proficiency_bonus_override')->nullable();
            $table->string('rules_edition_preference')->default('2024');
            $table->boolean('allow_legacy')->default(false);
            // Optimistic concurrency: rejects a stale tab replaying an old mutation.
            $table->unsignedBigInteger('revision')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        // Every source of spells: a class, a feat, a species, a background. Nested,
        // because 2024 Human grants an Origin feat which itself grants spells --
        // so the seed character is literally Human -> Magic Initiate -> spells.
        Schema::create('character_source_instances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->uuid('instance_uuid')->unique();
            $table->unsignedBigInteger('parent_source_instance_id')->nullable();
            $table->string('source_type');            // class|subclass|feat|species|background|...
            $table->unsignedBigInteger('source_definition_id')->nullable();
            $table->string('display_name');
            // Magic Initiate's chosen list and ability live here: they are
            // CONFIGURATION, not identity, so changing the list must not churn slot keys.
            $table->json('config')->nullable();
            $table->unsignedTinyInteger('acquired_at_character_level')->nullable();
            $table->string('state')->default('active'); // active | tombstoned
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('parent_source_instance_id')
                ->references('id')->on('character_source_instances')->nullOnDelete();
            $table->index(['character_id', 'state']);
        });

        // Explicit unique parent key for the composite FK below. Without it SQLite
        // raises "foreign key mismatch" rather than enforcing anything.
        DB::statement('CREATE UNIQUE INDEX character_source_instances_id_character_id_unique
                       ON character_source_instances (id, character_id)');

        Schema::create('character_class_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->foreignId('class_definition_id')->constrained();
            $table->unsignedBigInteger('subclass_definition_id')->nullable();
            $table->unsignedTinyInteger('level')->default(1);
            $table->boolean('is_starting_class')->default(false);
            $table->string('spellcasting_ability_override')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            // A duplicate class row would silently double caster level AND
            // proficiency bonus, so uniqueness is a correctness constraint.
            $table->unique(['character_id', 'class_definition_id']);

            // SQLite forbids cross-table subqueries in CHECK, so "the subclass must
            // belong to this class" is enforced by a composite FK instead.
            $table->foreign(['subclass_definition_id', 'class_definition_id'])
                ->references(['id', 'class_definition_id'])->on('subclass_definitions');
        });

        Schema::create('spell_selection_slots', function (Blueprint $table) {
            $table->id();
            // Denormalized deliberately: SQLite cannot enforce uniqueness through a
            // parent join, so per-character slot_key uniqueness needs this column.
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('source_instance_id');
            // {instance_uuid}:{rule_key}:{ordinal} -- excludes label, class level,
            // chosen list and selected spell so ordinary edits never churn keys.
            $table->string('slot_key');
            $table->string('rule_key');
            $table->unsignedSmallInteger('ordinal')->default(0);
            // cantrip_known | prepared | known | spellbook | automatic
            $table->string('bucket');
            $table->string('eligibility_kind');       // fixed_spell | choice_from_list | ...
            // An automatic grant and a user choice are different things and must not
            // share a column, or regeneration cannot tell them apart.
            $table->unsignedBigInteger('fixed_spell_version_id')->nullable();
            $table->unsignedBigInteger('current_spell_version_id')->nullable();
            $table->string('label')->nullable();
            $table->unsignedTinyInteger('spell_level_min')->default(0);
            $table->unsignedTinyInteger('spell_level_max')->default(9);
            $table->json('allowed_spell_lists')->nullable();
            $table->json('allowed_schools')->nullable();
            $table->json('allowed_tags')->nullable();
            $table->boolean('always_prepared')->default(false);
            $table->boolean('with_slots')->default(true);
            $table->json('free_cast')->nullable();    // {uses, recovery, pool_scope}
            $table->boolean('counts_against_limit')->default(true);
            $table->boolean('required')->default(false);
            $table->boolean('is_locked')->default(false);
            // active | orphaned | discarded | kept_override -- never hard-deleted
            $table->string('state')->default('active');
            $table->string('orphan_reason_code')->nullable();
            $table->unsignedBigInteger('orphaned_by_change_group_id')->nullable();
            $table->timestamp('orphaned_at')->nullable();
            $table->json('prior_config')->nullable();
            $table->text('override_note')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['character_id', 'slot_key']);
            $table->index(['character_id', 'state']);
            $table->index(['character_id', 'bucket']);

            // Pins the slot and its source to the SAME character.
            $table->foreign(['source_instance_id', 'character_id'])
                ->references(['id', 'character_id'])->on('character_source_instances')
                ->cascadeOnDelete();
            $table->foreign('fixed_spell_version_id')->references('id')->on('spell_versions');
            $table->foreign('current_spell_version_id')->references('id')->on('spell_versions');
        });

        // A Wizard's spellbook is an acquisition log, distinct from what is prepared.
        Schema::create('wizard_spellbook_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->foreignId('spell_version_id')->constrained();
            $table->string('acquisition');            // starting | level_up | copied | granted
            $table->unsignedInteger('copy_cost_gp')->nullable();
            $table->unsignedInteger('copy_time_hours')->nullable();
            $table->unsignedBigInteger('source_instance_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['character_id', 'spell_version_id']);
        });

        // Preparing references a BOOK ENTRY, not a bare spell: you cannot prepare a
        // Wizard spell that is not in your book.
        Schema::create('wizard_prepared_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->foreignId('wizard_spellbook_entry_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['character_id', 'wizard_spellbook_entry_id']);
        });

        Schema::create('change_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            // Explicit ordering: created_at is not a total order at this resolution.
            $table->unsignedBigInteger('sequence');
            $table->uuid('group_id')->nullable();
            $table->uuid('operation_uuid')->nullable();
            $table->string('entity_type');
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->json('previous_value')->nullable();
            $table->json('new_value')->nullable();
            $table->string('reason')->nullable();
            $table->string('action_type');
            $table->boolean('reversible')->default(true);
            $table->timestamps();
            $table->unique(['character_id', 'sequence']);
            $table->index(['character_id', 'group_id']);
            $table->index('operation_uuid');
        });

        Schema::create('character_save_points', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->string('label');
            $table->json('snapshot');
            $table->string('schema_version');
            $table->timestamps();
        });

        Schema::create('warning_acknowledgements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->string('warning_fingerprint');
            $table->text('note')->nullable();
            $table->timestamp('invalidated_at')->nullable();
            $table->timestamps();
            $table->unique(['character_id', 'warning_fingerprint']);
        });

        // Created now rather than in Stage 3: SQLite rewrites whole tables on many
        // ALTERs, so adding these later is disproportionately expensive.
        Schema::create('spell_loadouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('spell_loadout_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('spell_loadout_id')->constrained()->cascadeOnDelete();
            $table->foreignId('spell_version_id')->constrained();
            // primary_combat | situational_combat | exploration | social | ritual | emergency | downtime
            $table->string('role');
            $table->timestamps();
            $table->unique(['spell_loadout_id', 'spell_version_id', 'role']);
        });

        Schema::create('character_spell_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->foreignId('spell_version_id')->constrained();
            $table->boolean('favourite')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['character_id', 'spell_version_id']);
        });

        // House rules are character-scoped; the shared catalog is never mutated.
        Schema::create('character_rule_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->string('rule_key');
            $table->json('value');
            $table->text('note')->nullable();
            $table->timestamps();
            $table->unique(['character_id', 'rule_key']);
        });
    }

    public function down(): void
    {
        foreach ([
            'character_rule_overrides', 'character_spell_preferences', 'spell_loadout_entries',
            'spell_loadouts', 'warning_acknowledgements', 'character_save_points', 'change_log',
            'wizard_prepared_entries', 'wizard_spellbook_entries', 'spell_selection_slots',
            'character_class_levels', 'character_source_instances', 'characters',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
