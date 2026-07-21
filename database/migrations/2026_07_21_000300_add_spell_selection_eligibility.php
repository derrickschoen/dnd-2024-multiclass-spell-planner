<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spell_selection_slots', function (Blueprint $table): void {
            $table->string('selection_collection')->nullable()->after('allowed_tags');
            $table->string('selection_eligibility')->default('unselected')->after('selection_collection');
            $table->text('selection_invalid_reason')->nullable()->after('selection_eligibility');
            $table->index(['character_id', 'selection_collection'], 'slots_character_collection_index');
        });

        // Preparation choices now live only in collection-constrained slots.
        // Retaining the old table would preserve a second, uncapped authority.
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
};
