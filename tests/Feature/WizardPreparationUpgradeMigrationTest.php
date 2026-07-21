<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/** @return array{migration: object, character_id: int, version_ids: list<int>} */
function legacyWizardUpgradeFixture(object $test): array
{
    $test->seed();
    $characterId = (int) DB::table('characters')->where('notes', 'seed:a6')->value('id');
    $legacyVersionIds = DB::table('spell_selection_slots')
        ->where('character_id', $characterId)
        ->where('rule_key', 'wizard-prepared')
        ->orderBy('ordinal')
        ->pluck('current_spell_version_id')
        ->map(static fn (mixed $id): int => (int) $id)
        ->all();
    $migration = require database_path('migrations/2026_07_21_000300_add_spell_selection_eligibility.php');
    $migration->down();

    return [
        'migration' => $migration,
        'character_id' => $characterId,
        'version_ids' => $legacyVersionIds,
    ];
}

it('round-trips every Wizard preparation through down and up without manual reconstruction', function () {
    $fixture = legacyWizardUpgradeFixture($this);
    $migration = data_get($fixture, 'migration');
    $characterId = data_get($fixture, 'character_id');
    $legacyVersionIds = data_get($fixture, 'version_ids');
    expect($legacyVersionIds)->toHaveCount(4);
    $rolledBackVersionIds = DB::table('wizard_prepared_entries as prepared')
        ->join('wizard_spellbook_entries as entry', 'entry.id', '=', 'prepared.wizard_spellbook_entry_id')
        ->where('prepared.character_id', $characterId)
        ->pluck('entry.spell_version_id')
        ->map(static fn (mixed $id): int => (int) $id)
        ->sort()
        ->values()
        ->all();
    expect($rolledBackVersionIds)->toBe(collect($legacyVersionIds)->sort()->values()->all());

    $migration->up();

    $migratedSlots = DB::table('spell_selection_slots')
        ->where('character_id', $characterId)
        ->where('rule_key', 'wizard-prepared')
        ->orderBy('ordinal')
        ->get();
    expect(Schema::hasTable('wizard_prepared_entries'))->toBeFalse()
        ->and($migratedSlots)->toHaveCount(4)
        ->and($migratedSlots->pluck('current_spell_version_id')->map(
            static fn (mixed $id): int => (int) $id,
        )->sort()->values()->all())->toBe(collect($legacyVersionIds)->sort()->values()->all())
        ->and($migratedSlots->every(
            static fn (object $slot): bool => data_get($slot, 'selection_collection') === 'wizard_spellbook'
                && data_get($slot, 'selection_eligibility') === 'valid'
                && data_get($slot, 'selection_invalid_reason') === null,
        ))->toBeTrue();
});

it('refuses a divergent union that cannot fit without overwriting an existing preparation', function () {
    $fixture = legacyWizardUpgradeFixture($this);
    $characterId = (int) data_get($fixture, 'character_id');
    $existingVersionIds = data_get($fixture, 'version_ids');
    $extraEntry = DB::table('wizard_spellbook_entries')
        ->where('character_id', $characterId)
        ->whereNotIn('spell_version_id', $existingVersionIds)
        ->orderBy('id')
        ->first();
    expect($extraEntry)->not->toBeNull();

    DB::table('wizard_prepared_entries')->delete();
    DB::table('wizard_prepared_entries')->insert([
        'character_id' => $characterId,
        'wizard_spellbook_entry_id' => data_get($extraEntry, 'id'),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(fn () => data_get($fixture, 'migration')->up())->toThrow(
        RuntimeException::class,
        'Combined Wizard preparations exceed available Wizard preparation slot capacity.',
    );

    $slotsAfterFailure = DB::table('spell_selection_slots')
        ->where('character_id', $characterId)
        ->where('rule_key', 'wizard-prepared')
        ->orderBy('ordinal')
        ->pluck('current_spell_version_id')
        ->map(static fn (mixed $id): int => (int) $id)
        ->all();
    expect($slotsAfterFailure)->toBe($existingVersionIds)
        ->and(DB::table('wizard_prepared_entries')->pluck('wizard_spellbook_entry_id')->all())
        ->toBe([(int) data_get($extraEntry, 'id')])
        ->and(Schema::hasColumn('spell_selection_slots', 'selection_collection'))->toBeFalse();
});

it('refuses to drop legacy preparations when Wizard slot capacity is insufficient', function () {
    $fixture = legacyWizardUpgradeFixture($this);
    DB::table('spell_selection_slots')
        ->where('character_id', data_get($fixture, 'character_id'))
        ->where('rule_key', 'wizard-prepared')
        ->orderByDesc('ordinal')
        ->limit(1)
        ->delete();

    expect(fn () => data_get($fixture, 'migration')->up())->toThrow(
        RuntimeException::class,
        'Combined Wizard preparations exceed available Wizard preparation slot capacity.',
    );
    expect(Schema::hasTable('wizard_prepared_entries'))->toBeTrue()
        ->and(Schema::hasColumn('spell_selection_slots', 'selection_collection'))->toBeFalse();
});

it('refuses to drop a legacy Wizard preparation that is ineligible for every slot', function () {
    $fixture = legacyWizardUpgradeFixture($this);
    $versionId = data_get($fixture, 'version_ids.0');
    DB::table('spell_versions')->where('id', $versionId)->update(['level' => 9]);

    expect(fn () => data_get($fixture, 'migration')->up())->toThrow(
        RuntimeException::class,
        "Legacy Wizard preparation for spell version {$versionId} is not eligible for any preparation slot.",
    );
    expect(Schema::hasTable('wizard_prepared_entries'))->toBeTrue()
        ->and(Schema::hasColumn('spell_selection_slots', 'selection_collection'))->toBeFalse();
});
