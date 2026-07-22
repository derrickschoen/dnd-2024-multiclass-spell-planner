<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('migrates Wizard slots rules configs and spellbook rows to the lightweight ritual marker', function (): void {
    $this->seed();
    $migration = require database_path('migrations/2026_07_22_000700_simplify_wizard_spellbook.php');
    $migration->down();

    $characterId = (int) DB::table('characters')->where('notes', 'seed:a6')->value('id');
    $entryId = (int) DB::table('wizard_spellbook_entries')
        ->where('character_id', $characterId)
        ->value('id');
    DB::table('wizard_spellbook_entries')->where('id', $entryId)->update([
        'acquisition' => 'copied',
        'copy_cost_gp' => 50,
        'copy_time_hours' => 2,
        'notes' => 'Legacy provenance.',
    ]);

    $migration->up();

    expect(Schema::hasColumns('wizard_spellbook_entries', [
        'acquisition', 'copy_cost_gp', 'copy_time_hours', 'source_instance_id', 'notes',
    ]))->toBeFalse();

    $wizardSlots = DB::table('spell_selection_slots as slot')
        ->join('character_source_instances as source', 'source.id', '=', 'slot.source_instance_id')
        ->join('class_definitions as class', 'class.id', '=', 'source.source_definition_id')
        ->where('slot.character_id', $characterId)
        ->where('source.source_type', 'class')
        ->where('class.name', 'Wizard')
        ->where('slot.bucket', 'prepared')
        ->get(['slot.selection_collection', 'slot.selection_eligibility']);
    expect($wizardSlots)->not->toBeEmpty()
        ->and($wizardSlots->every(
            static fn (object $slot): bool => data_get($slot, 'selection_collection') === null
                && data_get($slot, 'selection_eligibility') === 'valid',
        ))->toBeTrue();

    $rules = json_decode((string) DB::table('class_progressions as progression')
        ->join('class_definitions as class', 'class.id', '=', 'progression.class_definition_id')
        ->where('class.name', 'Wizard')
        ->where('progression.class_level', 1)
        ->value('progression.grant_rules'), true, 512, JSON_THROW_ON_ERROR);
    $preparedRule = collect($rules)->firstWhere('rule_key', 'wizard-prepared');
    expect(data_get($preparedRule, 'selection_collection'))->toBeNull();

    $sourceConfig = json_decode((string) DB::table('character_source_instances as source')
        ->join('class_definitions as class', 'class.id', '=', 'source.source_definition_id')
        ->where('source.character_id', $characterId)
        ->where('class.name', 'Wizard')
        ->value('source.config'), true, 512, JSON_THROW_ON_ERROR);
    expect(collect(data_get($sourceConfig, 'wizard_spellbook_acquisitions'))->every(
        static fn (array $entry): bool => array_keys($entry) === ['spell_version_key'],
    ))->toBeTrue();
});
