<?php

declare(strict_types=1);

use App\Domain\Reports\BuildReportBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed();
});

it('reports a single-class third-caster from its own subclass spellcasting table', function (array $case) {
    $subclass = DB::table('subclass_definitions')->where('name', $case['subclass'])->sole();
    $characterId = DB::table('characters')->insertGetId([
        'name' => $case['subclass'].' '.$case['level'],
        'intelligence' => 16,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('character_class_levels')->insert([
        'character_id' => $characterId,
        'class_definition_id' => data_get($subclass, 'class_definition_id'),
        'subclass_definition_id' => data_get($subclass, 'id'),
        'level' => $case['level'],
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $report = app(BuildReportBuilder::class)->build($characterId);
    $fighter = collect(data_get($report, 'classes'))->sole();
    expect(data_get($fighter, 'subclass'))->toBe($case['subclass'])
        ->and(data_get($fighter, 'spellcasting_ability'))->toBe('intelligence')
        ->and(data_get($fighter, 'prepared_count'))->toBe($case['prepared'])
        ->and(data_get($fighter, 'max_preparable_level'))->toBe($case['max_spell_level'])
        ->and(data_get($report, 'caster.caster_level'))->toBe($case['multiclass_caster_level'])
        ->and(data_get($report, 'caster.slots'))->toBe($case['slots']);
})->with([
    'Eldritch Knight level 3' => [[
        'subclass' => 'Eldritch Knight', 'level' => 3, 'prepared' => 3,
        'max_spell_level' => 1, 'multiclass_caster_level' => 1,
        'slots' => [['level' => 1, 'count' => 2]],
    ]],
    'Eldritch Knight level 7' => [[
        'subclass' => 'Eldritch Knight', 'level' => 7, 'prepared' => 5,
        'max_spell_level' => 2, 'multiclass_caster_level' => 2,
        'slots' => [['level' => 1, 'count' => 4], ['level' => 2, 'count' => 2]],
    ]],
    'Eldritch Knight level 13' => [[
        'subclass' => 'Eldritch Knight', 'level' => 13, 'prepared' => 9,
        'max_spell_level' => 3, 'multiclass_caster_level' => 4,
        'slots' => [
            ['level' => 1, 'count' => 4], ['level' => 2, 'count' => 3], ['level' => 3, 'count' => 2],
        ],
    ]],
    'Eldritch Knight level 19' => [[
        'subclass' => 'Eldritch Knight', 'level' => 19, 'prepared' => 12,
        'max_spell_level' => 4, 'multiclass_caster_level' => 6,
        'slots' => [
            ['level' => 1, 'count' => 4], ['level' => 2, 'count' => 3],
            ['level' => 3, 'count' => 3], ['level' => 4, 'count' => 1],
        ],
    ]],
    'Arcane Trickster level 3' => [[
        'subclass' => 'Arcane Trickster', 'level' => 3, 'prepared' => 3,
        'max_spell_level' => 1, 'multiclass_caster_level' => 1,
        'slots' => [['level' => 1, 'count' => 2]],
    ]],
    'Arcane Trickster level 7' => [[
        'subclass' => 'Arcane Trickster', 'level' => 7, 'prepared' => 5,
        'max_spell_level' => 2, 'multiclass_caster_level' => 2,
        'slots' => [['level' => 1, 'count' => 4], ['level' => 2, 'count' => 2]],
    ]],
    'Arcane Trickster level 13' => [[
        'subclass' => 'Arcane Trickster', 'level' => 13, 'prepared' => 9,
        'max_spell_level' => 3, 'multiclass_caster_level' => 4,
        'slots' => [
            ['level' => 1, 'count' => 4], ['level' => 2, 'count' => 3], ['level' => 3, 'count' => 2],
        ],
    ]],
    'Arcane Trickster level 19' => [[
        'subclass' => 'Arcane Trickster', 'level' => 19, 'prepared' => 12,
        'max_spell_level' => 4, 'multiclass_caster_level' => 6,
        'slots' => [
            ['level' => 1, 'count' => 4], ['level' => 2, 'count' => 3],
            ['level' => 3, 'count' => 3], ['level' => 4, 'count' => 1],
        ],
    ]],
]);

it('uses own slots with one Spellcasting provider and multiclass slots with multiple providers', function () {
    $fighterId = (int) DB::table('class_definitions')->where('name', 'Fighter')->value('id');
    $barbarianId = (int) DB::table('class_definitions')->where('name', 'Barbarian')->value('id');
    $wizardId = (int) DB::table('class_definitions')->where('name', 'Wizard')->value('id');
    $eldritchKnightId = (int) DB::table('subclass_definitions')->where('name', 'Eldritch Knight')->value('id');

    $singleProviderId = DB::table('characters')->insertGetId([
        'name' => 'EK plus Barbarian', 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('character_class_levels')->insert([
        [
            'character_id' => $singleProviderId, 'class_definition_id' => $fighterId,
            'subclass_definition_id' => $eldritchKnightId, 'level' => 7,
            'created_at' => now(), 'updated_at' => now(),
        ],
        [
            'character_id' => $singleProviderId, 'class_definition_id' => $barbarianId,
            'subclass_definition_id' => null, 'level' => 3,
            'created_at' => now(), 'updated_at' => now(),
        ],
    ]);
    expect(data_get(app(BuildReportBuilder::class)->build($singleProviderId), 'caster.slots'))->toBe([
        ['level' => 1, 'count' => 4], ['level' => 2, 'count' => 2],
    ]);

    $multipleProvidersId = DB::table('characters')->insertGetId([
        'name' => 'EK plus Wizard', 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('character_class_levels')->insert([
        [
            'character_id' => $multipleProvidersId, 'class_definition_id' => $fighterId,
            'subclass_definition_id' => $eldritchKnightId, 'level' => 7,
            'created_at' => now(), 'updated_at' => now(),
        ],
        [
            'character_id' => $multipleProvidersId, 'class_definition_id' => $wizardId,
            'subclass_definition_id' => null, 'level' => 3,
            'created_at' => now(), 'updated_at' => now(),
        ],
    ]);
    $mixed = app(BuildReportBuilder::class)->build($multipleProvidersId);
    expect(data_get($mixed, 'caster.caster_level'))->toBe(5)
        ->and(data_get($mixed, 'caster.slots'))->toBe([
            ['level' => 1, 'count' => 4], ['level' => 2, 'count' => 3], ['level' => 3, 'count' => 2],
        ]);
});

it('seeds twenty subclass progression rows for both third-caster subclasses', function () {
    $counts = DB::table('subclass_progressions as progression')
        ->join('subclass_definitions as subclass', 'subclass.id', '=', 'progression.subclass_definition_id')
        ->selectRaw('subclass.name, count(*) as progression_count')
        ->groupBy('subclass.name')
        ->pluck('progression_count', 'subclass.name')
        ->all();

    expect($counts)->toBe([
        'Arcane Trickster' => 20,
        'Eldritch Knight' => 20,
    ]);
});
