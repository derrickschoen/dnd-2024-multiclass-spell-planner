<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The shared, importable catalog: spells, classes, subclasses, feats, species,
 * backgrounds. Every row carries a stable `content_key` and a `provenance`, so
 * re-importing never has to match on display name and user-authored content is
 * never merged into imported content.
 */
return new class extends Migration
{
    public function up(): void
    {
        // A spell as a concept ("Chill Touch"), independent of any edition.
        Schema::create('spell_identities', function (Blueprint $table) {
            $table->id();
            $table->string('content_key')->unique();
            $table->string('canonical_name');
            $table->string('normalized_name')->index();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        // Renames and cross-edition links resolve here. Without an explicit alias
        // map, matching on normalized name is the only option and it silently
        // fails for renamed spells, which breaks cross-version duplicate warnings.
        Schema::create('spell_identity_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('spell_identity_id')->constrained()->cascadeOnDelete();
            $table->string('alias');
            $table->string('normalized_alias');
            $table->timestamps();
            $table->unique(['normalized_alias']);
        });

        // A specific edition's rules for that spell. 2014 and 2024 Chill Touch are
        // two rows here sharing one identity above, and are never merged.
        Schema::create('spell_versions', function (Blueprint $table) {
            $table->id();
            $table->string('content_key')->unique();
            $table->foreignId('spell_identity_id')->constrained()->cascadeOnDelete();
            $table->string('display_name');
            $table->string('rules_edition');           // 2024 | 2014 | expanded
            $table->unsignedTinyInteger('level');
            $table->string('school');
            $table->boolean('ritual')->default(false);
            $table->boolean('concentration')->default(false);
            $table->string('casting_time')->nullable();
            $table->string('action_type')->nullable();
            $table->string('range')->nullable();
            $table->string('duration')->nullable();
            $table->string('components')->nullable();
            $table->text('material_component_summary')->nullable();
            $table->boolean('healing')->default(false);
            $table->text('short_summary')->nullable();
            $table->string('upcast_type')->nullable();
            $table->text('upcast_summary')->nullable();
            $table->boolean('requires_mod_for_effect')->default(false);
            // attack_roll | saving_throw | modifier_scaled | fixed_effect | mixed | ritual_utility
            $table->string('effect_reliability_category')->default('fixed_effect');
            $table->string('provenance')->default('import'); // import | user
            $table->string('seed_version')->nullable();
            $table->boolean('is_active')->default(true);     // tombstone, never delete
            $table->timestamps();

            $table->unique(['spell_identity_id', 'rules_edition']);
            $table->index(['rules_edition', 'level']);
            $table->index('is_active');
        });

        // Publication is many-to-many and is NOT identity: a spell reprinted in
        // two books must not become two versions, nor overwrite itself.
        Schema::create('spell_version_publications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('spell_version_id')->constrained()->cascadeOnDelete();
            $table->string('source_book');
            $table->unsignedSmallInteger('source_page')->nullable();
            $table->string('source_reference')->nullable();
            $table->timestamps();
            $table->unique(['spell_version_id', 'source_book']);
        });

        // What eligibility checks against. Version-specific: the 2024 and 2014
        // class lists genuinely differ.
        Schema::create('spell_list_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('spell_version_id')->constrained()->cascadeOnDelete();
            $table->string('spell_list_key');
            $table->timestamps();
            $table->unique(['spell_version_id', 'spell_list_key']);
            $table->index('spell_list_key');
        });

        // Normalized rather than JSON: the spreadsheet grid filters on these, and
        // JSON-in-TEXT is unindexable in SQLite.
        foreach ([
            'spell_version_tags' => 'tag',
            'spell_version_damage_types' => 'damage_type',
            'spell_version_conditions' => 'condition_type',
            'spell_version_attack_modes' => 'attack_mode',
            'spell_version_save_abilities' => 'save_ability',
        ] as $tableName => $column) {
            Schema::create($tableName, function (Blueprint $table) use ($column) {
                $table->id();
                $table->foreignId('spell_version_id')->constrained()->cascadeOnDelete();
                $table->string($column);
                $table->unique(['spell_version_id', $column]);
                $table->index($column);
            });
        }

        Schema::create('class_definitions', function (Blueprint $table) {
            $table->id();
            $table->string('content_key')->unique();
            $table->string('name');
            $table->string('rules_edition');
            $table->string('spellcasting_ability')->nullable();
            // full | half_up | half_down | third_up | third_down | pact | none | custom
            $table->string('progression_type')->default('none');
            $table->string('caster_fraction')->nullable();   // 1 | 1/2 | 1/3
            $table->string('caster_rounding')->nullable();   // up | down
            $table->string('prepares_or_knows')->nullable();
            $table->boolean('supports_ritual_casting')->default(false);
            $table->string('ritual_casting_mode')->nullable();
            // Typed AND/OR clauses. 2024 multiclassing can require more than one
            // ability, so a single scalar column cannot express the prerequisite.
            $table->json('primary_ability_expression')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['name', 'rules_edition']);
        });

        Schema::create('class_progressions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_definition_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('class_level');
            $table->unsignedTinyInteger('cantrips_known')->default(0);
            // 2024 prepared counts come from this table, NEVER from an ability modifier.
            $table->unsignedTinyInteger('prepared_count')->default(0);
            $table->json('slots')->nullable();       // full/half/third slot row
            $table->json('pact_slots')->nullable();  // Warlock only, separate pool
            $table->json('grant_rules')->nullable(); // rules unlocked at this level
            $table->timestamps();
            $table->unique(['class_definition_id', 'class_level']);
        });

        Schema::create('subclass_definitions', function (Blueprint $table) {
            $table->id();
            $table->string('content_key')->unique();
            $table->foreignId('class_definition_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('rules_edition');
            $table->string('spellcasting_ability')->nullable();
            // Caster contribution belongs HERE, not on the class: Eldritch Knight is
            // a third-caster while a plain Fighter is not.
            $table->string('caster_fraction')->nullable();
            $table->string('caster_rounding')->nullable();
            $table->json('grant_rules')->nullable();
            $table->timestamps();

            // Needed so character_class_levels can reference (id, class_definition_id)
            // compositely; SQLite requires an explicitly declared unique parent key
            // or it raises "foreign key mismatch".
            $table->unique(['id', 'class_definition_id']);
            $table->unique(['class_definition_id', 'name', 'rules_edition']);
        });

        foreach (['feat_definitions', 'species_definitions', 'background_definitions'] as $tableName) {
            Schema::create($tableName, function (Blueprint $table) {
                $table->id();
                $table->string('content_key')->unique();
                $table->string('name');
                $table->string('rules_edition');
                $table->string('category')->nullable();   // e.g. origin | general | fighting_style
                $table->boolean('repeatable')->default(false);
                $table->json('prerequisites')->nullable();
                $table->json('grant_rules')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->unique(['name', 'rules_edition']);
            });
        }
    }

    public function down(): void
    {
        foreach ([
            'background_definitions', 'species_definitions', 'feat_definitions',
            'subclass_definitions', 'class_progressions', 'class_definitions',
            'spell_version_save_abilities', 'spell_version_attack_modes',
            'spell_version_conditions', 'spell_version_damage_types', 'spell_version_tags',
            'spell_list_memberships', 'spell_version_publications', 'spell_versions',
            'spell_identity_aliases', 'spell_identities',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
