<?php

declare(strict_types=1);

use App\Domain\Reports\BuildReportBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed();
});

it('seeds the requested six-class character and generates both Magic Initiates through grant_source', function () {
    $character = DB::table('characters')->where('notes', 'seed:a6')->sole();
    expect(data_get($character, 'charisma'))->toBe(17)
        ->and(data_get($character, 'intelligence'))->toBe(13)
        ->and(data_get($character, 'wisdom'))->toBe(13);

    $classes = DB::table('character_class_levels as level')
        ->join('class_definitions as class', 'class.id', '=', 'level.class_definition_id')
        ->where('level.character_id', data_get($character, 'id'))
        ->orderBy('class.name')
        ->pluck('level.level', 'class.name')
        ->all();
    expect($classes)->toBe([
        'Bard' => 1, 'Cleric' => 1, 'Druid' => 1, 'Paladin' => 1,
        'Sorcerer' => 1, 'Wizard' => 1,
    ]);

    $parents = DB::table('character_source_instances')
        ->where('character_id', data_get($character, 'id'))
        ->whereIn('source_type', ['species', 'background'])
        ->orderBy('source_type')
        ->get();
    expect($parents)->toHaveCount(2);
    $children = DB::table('character_source_instances')
        ->whereIn('parent_source_instance_id', $parents->pluck('id'))
        ->orderBy('display_name')
        ->get();
    expect($children)->toHaveCount(2)
        ->and($children->every(fn ($source) => str_starts_with((string) data_get($source, 'notes'), 'grant_rule:')))->toBeTrue()
        ->and($children->map(fn ($source) => data_get(json_decode((string) data_get($source, 'config'), true), 'chosen_list'))->sort()->values()->all())
        ->toBe(['Druid', 'Wizard']);

    foreach ($children as $source) {
        $slots = DB::table('spell_selection_slots')
            ->where('source_instance_id', data_get($source, 'id'))
            ->orderBy('rule_key')
            ->orderBy('ordinal')
            ->get();
        $cantrips = $slots->where('rule_key', 'magic-initiate-cantrips');
        $levelOne = $slots->where('rule_key', 'magic-initiate-level-one')->sole();
        expect($cantrips)->toHaveCount(2)
            ->and($cantrips->every(fn ($slot) => data_get($slot, 'free_cast') === null))->toBeTrue()
            ->and($cantrips->every(fn ($slot) => ! (bool) data_get($slot, 'with_slots')))->toBeTrue()
            ->and(json_decode((string) data_get($levelOne, 'free_cast'), true))->toBe([
                'uses' => 1, 'recovery' => 'long_rest', 'pool_scope' => 'per_spell',
            ])
            ->and((bool) data_get($levelOne, 'with_slots'))->toBeTrue();
    }

    $wizardSourceId = DB::table('character_source_instances as source')
        ->join('class_definitions as class', 'class.id', '=', 'source.source_definition_id')
        ->where('source.character_id', data_get($character, 'id'))
        ->where('source.source_type', 'class')
        ->where('class.name', 'Wizard')
        ->value('source.id');
    $wizardPreparedSlots = DB::table('spell_selection_slots')
        ->where('source_instance_id', $wizardSourceId)
        ->where('rule_key', 'wizard-prepared')
        ->orderBy('ordinal')
        ->get();
    expect($wizardPreparedSlots)->toHaveCount(4)
        ->and($wizardPreparedSlots->every(
            fn (object $slot): bool => data_get($slot, 'selection_collection') === 'wizard_spellbook',
        ))->toBeTrue()
        ->and($wizardPreparedSlots->whereNotNull('current_spell_version_id'))->toHaveCount(4)
        ->and(Schema::hasTable('wizard_prepared_entries'))->toBeFalse();
    foreach ($wizardPreparedSlots as $slot) {
        expect(DB::table('wizard_spellbook_entries')
            ->where('character_id', data_get($character, 'id'))
            ->where('spell_version_id', data_get($slot, 'current_spell_version_id'))
            ->exists())->toBeTrue();
    }
});

it('builds the golden read-only report values and duplicate classifications', function () {
    $characterId = DB::table('characters')->where('notes', 'seed:a6')->value('id');
    $report = app(BuildReportBuilder::class)->build($characterId);

    expect(data_get($report, 'character.character_level'))->toBe(6)
        ->and(data_get($report, 'character.proficiency_bonus'))->toBe(3)
        ->and(data_get($report, 'caster.caster_level'))->toBe(6)
        ->and(data_get($report, 'caster.slots'))->toBe([
            ['level' => 1, 'count' => 4],
            ['level' => 2, 'count' => 3],
            ['level' => 3, 'count' => 3],
        ])
        ->and(data_get($report, 'classes'))->toHaveCount(6)
        ->and(collect(data_get($report, 'classes'))->every(
            fn (array $class): bool => data_get($class, 'max_preparable_level') === 1,
        ))->toBeTrue()
        ->and(data_get($report, 'preparation_callout'))->toContain('3rd-level slots')
        ->and(data_get($report, 'preparation_callout'))->toContain('1st-level spells');

    $routes = collect(data_get($report, 'access_routes'));
    $mageHandRoutes = $routes->where('spell_name', 'Mage Hand');
    expect($mageHandRoutes)->toHaveCount(2)
        ->and($mageHandRoutes->pluck('source_name')->sort()->values()->all())->toBe([
            'Magic Initiate: Wizard', 'Wizard 1',
        ]);
    $mageHand = collect(data_get($report, 'duplicate_assessments'))->firstWhere('spell_name', 'Mage Hand');
    expect(data_get($mageHand, 'category'))->toBe('wasteful')
        ->and(data_get($mageHand, 'sources'))->toContain('Wizard 1', 'Magic Initiate: Wizard')
        ->and(data_get($mageHand, 'slots'))->toHaveCount(2);

    $entangleRoutes = $routes->where('spell_name', 'Entangle');
    $entangle = collect(data_get($report, 'duplicate_assessments'))->firstWhere('spell_name', 'Entangle');
    expect($entangleRoutes)->toHaveCount(1)
        ->and(data_get($entangleRoutes->first(), 'source_name'))->toBe('Magic Initiate: Druid')
        ->and(data_get($entangle, 'category'))->toBe('none');

    expect(data_get($report, 'wizard.spellbook'))->toHaveCount(6)
        ->and(data_get($report, 'wizard.prepared'))->toHaveCount(4)
        ->and(data_get($report, 'wizard.ritual_only'))->toHaveCount(1)
        ->and(data_get($report, 'wizard.explanation'))->toContain('does not consume preparation capacity');
    $detectMagic = $routes->firstWhere('spell_name', 'Detect Magic');
    expect(data_get($detectMagic, 'origin'))->toBe('capability')
        ->and(data_get($detectMagic, 'casting_mode'))->toBe('ritual_only')
        ->and(data_get($detectMagic, 'is_selection'))->toBeFalse()
        ->and(data_get($detectMagic, 'counts_against_limit'))->toBeFalse();
});

it('describes Pact Magic slots without inventing zero-level shared slots', function () {
    $warlockId = DB::table('class_definitions')->where('name', 'Warlock')->value('id');
    $characterId = DB::table('characters')->insertGetId([
        'name' => 'Warlock 5', 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('character_class_levels')->insert([
        'character_id' => $characterId,
        'class_definition_id' => $warlockId,
        'level' => 5,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $report = app(BuildReportBuilder::class)->build($characterId);
    expect(data_get($report, 'caster.slots'))->toBe([])
        ->and(data_get($report, 'caster.pact_magic'))->toBe(['count' => 2, 'level' => 3])
        ->and(data_get($report, 'preparation_callout'))->toContain(
            'no shared Spellcasting slots and Pact Magic slots at 3rd level',
        )
        ->and(data_get($report, 'preparation_callout'))->not->toContain('0th-level slots');
});

it('describes shared and Pact Magic slot pools together', function () {
    $wizardId = DB::table('class_definitions')->where('name', 'Wizard')->value('id');
    $warlockId = DB::table('class_definitions')->where('name', 'Warlock')->value('id');
    $characterId = DB::table('characters')->insertGetId([
        'name' => 'Wizard 1 Warlock 5', 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('character_class_levels')->insert([
        [
            'character_id' => $characterId, 'class_definition_id' => $wizardId, 'level' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ],
        [
            'character_id' => $characterId, 'class_definition_id' => $warlockId, 'level' => 5,
            'created_at' => now(), 'updated_at' => now(),
        ],
    ]);

    $report = app(BuildReportBuilder::class)->build($characterId);
    expect(data_get($report, 'caster.slots'))->toBe([['level' => 1, 'count' => 2]])
        ->and(data_get($report, 'caster.pact_magic'))->toBe(['count' => 2, 'level' => 3])
        ->and(data_get($report, 'preparation_callout'))->toContain(
            'shared Spellcasting slots through 1st level and Pact Magic slots at 3rd level',
        )
        ->and(data_get($report, 'preparation_callout'))->toContain(
            'Either pool can cast an eligible prepared spell',
        );
});

it('serves the typed Inertia build report page as read-only data', function () {
    $this->get('/build-report')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('BuildReport')
            ->where('report.character.character_level', 6)
            ->where('report.character.proficiency_bonus', 3)
            ->where('report.caster.caster_level', 6)
            ->where('report.caster.slots.0.count', 4)
            ->where('report.caster.slots.1.count', 3)
            ->where('report.caster.slots.2.count', 3)
            ->has('report.access_routes')
            ->has('report.duplicate_assessments')
        );
});
