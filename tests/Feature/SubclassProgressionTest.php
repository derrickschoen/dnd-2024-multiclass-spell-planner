<?php

declare(strict_types=1);

use App\Domain\Reports\BuildReportBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed();
});

it('reports Eldritch Knight preparation from its single-class subclass progression', function (array $case) {
    $fighterId = (int) DB::table('class_definitions')->where('name', 'Fighter')->value('id');
    $subclassId = (int) DB::table('subclass_definitions')->where('name', 'Eldritch Knight')->value('id');
    $characterId = DB::table('characters')->insertGetId([
        'name' => 'Eldritch Knight '.$case['level'],
        'intelligence' => 16,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('character_class_levels')->insert([
        'character_id' => $characterId,
        'class_definition_id' => $fighterId,
        'subclass_definition_id' => $subclassId,
        'level' => $case['level'],
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $report = app(BuildReportBuilder::class)->build($characterId);
    $fighter = collect(data_get($report, 'classes'))->sole();
    expect(data_get($fighter, 'subclass'))->toBe('Eldritch Knight')
        ->and(data_get($fighter, 'spellcasting_ability'))->toBe('intelligence')
        ->and(data_get($fighter, 'prepared_count'))->toBe($case['prepared'])
        ->and(data_get($fighter, 'max_preparable_level'))->toBe($case['max_spell_level'])
        ->and(data_get($report, 'caster.caster_level'))->toBe($case['multiclass_caster_level']);
})->with([
    'level 3' => [['level' => 3, 'prepared' => 3, 'max_spell_level' => 1, 'multiclass_caster_level' => 1]],
    'level 7' => [['level' => 7, 'prepared' => 5, 'max_spell_level' => 2, 'multiclass_caster_level' => 2]],
    'level 13' => [['level' => 13, 'prepared' => 9, 'max_spell_level' => 3, 'multiclass_caster_level' => 4]],
    'level 19' => [['level' => 19, 'prepared' => 12, 'max_spell_level' => 4, 'multiclass_caster_level' => 6]],
]);

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
