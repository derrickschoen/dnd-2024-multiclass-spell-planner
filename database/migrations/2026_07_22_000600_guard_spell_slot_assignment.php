<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INSERT_TRIGGER = 'spell_slots_exclusive_assignment_insert';

    private const UPDATE_TRIGGER = 'spell_slots_exclusive_assignment_update';

    private const CONSTRAINT = 'spell_slots_exclusive_assignment_check';

    public function up(): void
    {
        if (! Schema::hasTable('spell_selection_slots')) {
            return;
        }

        if (DB::table('spell_selection_slots')->whereNotNull('fixed_spell_version_id')
            ->whereNotNull('current_spell_version_id')->exists()) {
            throw new RuntimeException('Cannot enforce exclusive spell-slot assignments while corrupt rows exist.');
        }

        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::statement('CREATE TRIGGER '.self::INSERT_TRIGGER.'
                BEFORE INSERT ON spell_selection_slots
                WHEN NEW.fixed_spell_version_id IS NOT NULL
                  AND NEW.current_spell_version_id IS NOT NULL
                BEGIN
                    SELECT RAISE(ABORT, \'a spell slot cannot hold both a fixed grant and a user selection\');
                END');
            DB::statement('CREATE TRIGGER '.self::UPDATE_TRIGGER.'
                BEFORE UPDATE ON spell_selection_slots
                WHEN NEW.fixed_spell_version_id IS NOT NULL
                  AND NEW.current_spell_version_id IS NOT NULL
                BEGIN
                    SELECT RAISE(ABORT, \'a spell slot cannot hold both a fixed grant and a user selection\');
                END');

            return;
        }

        DB::statement('ALTER TABLE spell_selection_slots ADD CONSTRAINT '.self::CONSTRAINT.'
            CHECK (fixed_spell_version_id IS NULL OR current_spell_version_id IS NULL)');
    }

    public function down(): void
    {
        if (! Schema::hasTable('spell_selection_slots')) {
            return;
        }

        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::statement('DROP TRIGGER IF EXISTS '.self::INSERT_TRIGGER);
            DB::statement('DROP TRIGGER IF EXISTS '.self::UPDATE_TRIGGER);

            return;
        }

        DB::statement('ALTER TABLE spell_selection_slots DROP CONSTRAINT '.self::CONSTRAINT);
    }
};
