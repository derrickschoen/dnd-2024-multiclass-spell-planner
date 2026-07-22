<?php

declare(strict_types=1);

use App\Domain\Rules\ClassProgressionLookup;
use Database\Seeders\ClassProgressionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('seeds twelve complete level-one-to-twenty class progressions', function () {
    $this->seed(ClassProgressionSeeder::class);

    expect(DB::table('class_definitions')->count())->toBe(12)
        ->and(DB::table('class_progressions')->count())->toBe(240);

    foreach (DB::table('class_definitions')->get() as $class) {
        expect(DB::table('class_progressions')
            ->where('class_definition_id', data_get($class, 'id'))
            ->pluck('class_level')->all()
        )->toBe(range(1, 20), (string) data_get($class, 'name'));
    }
});

it('pins progression metadata and keeps third-caster contribution on subclasses only', function () {
    $this->seed(ClassProgressionSeeder::class);

    $expected = [
        'Bard' => ['charisma', 'full', '1', null],
        'Cleric' => ['wisdom', 'full', '1', null],
        'Druid' => ['wisdom', 'full', '1', null],
        'Sorcerer' => ['charisma', 'full', '1', null],
        'Wizard' => ['intelligence', 'full', '1', null],
        'Paladin' => ['charisma', 'half_up', '1/2', 'up'],
        'Ranger' => ['wisdom', 'half_up', '1/2', 'up'],
        'Warlock' => ['charisma', 'pact', null, null],
        'Barbarian' => [null, 'none', null, null],
        'Fighter' => [null, 'none', null, null],
        'Monk' => [null, 'none', null, null],
        'Rogue' => [null, 'none', null, null],
    ];
    foreach ($expected as $name => [$ability, $type, $fraction, $rounding]) {
        $class = DB::table('class_definitions')->where('name', $name)->sole();
        expect(data_get($class, 'spellcasting_ability'))->toBe($ability, "{$name} ability")
            ->and(data_get($class, 'progression_type'))->toBe($type, "{$name} type")
            ->and(data_get($class, 'caster_fraction'))->toBe($fraction, "{$name} fraction")
            ->and(data_get($class, 'caster_rounding'))->toBe($rounding, "{$name} rounding");
    }

    foreach (['Fighter' => 'Eldritch Knight', 'Rogue' => 'Arcane Trickster'] as $className => $subclassName) {
        $class = DB::table('class_definitions')->where('name', $className)->sole();
        $subclass = DB::table('subclass_definitions')->where('name', $subclassName)->sole();
        expect(data_get($class, 'caster_fraction'))->toBeNull()
            ->and(data_get($class, 'caster_rounding'))->toBeNull()
            ->and((int) data_get($subclass, 'class_definition_id'))->toBe((int) data_get($class, 'id'))
            ->and(data_get($subclass, 'caster_fraction'))->toBe('1/3')
            ->and(data_get($subclass, 'caster_rounding'))->toBe('down');
    }
});

it('pins every class caster-relevant breakpoint', function (string $class, int $level, int $cantrips, int $prepared, array $slots, array $pact) {
    $this->seed(ClassProgressionSeeder::class);
    $row = DB::table('class_progressions as progression')
        ->join('class_definitions as class', 'class.id', '=', 'progression.class_definition_id')
        ->where('class.name', $class)
        ->where('progression.class_level', $level)
        ->select('progression.*')
        ->sole();

    expect((int) data_get($row, 'cantrips_known'))->toBe($cantrips, "{$class} {$level} cantrips")
        ->and((int) data_get($row, 'prepared_count'))->toBe($prepared, "{$class} {$level} prepared")
        ->and(json_decode((string) data_get($row, 'slots'), true))->toBe($slots, "{$class} {$level} slots")
        ->and(json_decode((string) data_get($row, 'pact_slots'), true))->toBe($pact, "{$class} {$level} pact slots");
})->with([
    ['Bard', 1, 2, 4, [1 => 2], []], ['Bard', 4, 3, 7, [1 => 4, 2 => 3], []],
    ['Bard', 10, 4, 15, [1 => 4, 2 => 3, 3 => 3, 4 => 3, 5 => 2], []], ['Bard', 20, 4, 22, [1 => 4, 2 => 3, 3 => 3, 4 => 3, 5 => 3, 6 => 2, 7 => 2, 8 => 1, 9 => 1], []],
    ['Cleric', 1, 3, 4, [1 => 2], []], ['Cleric', 4, 4, 7, [1 => 4, 2 => 3], []], ['Cleric', 10, 5, 15, [1 => 4, 2 => 3, 3 => 3, 4 => 3, 5 => 2], []], ['Cleric', 20, 5, 22, [1 => 4, 2 => 3, 3 => 3, 4 => 3, 5 => 3, 6 => 2, 7 => 2, 8 => 1, 9 => 1], []],
    ['Druid', 1, 2, 4, [1 => 2], []], ['Druid', 4, 3, 7, [1 => 4, 2 => 3], []], ['Druid', 10, 4, 15, [1 => 4, 2 => 3, 3 => 3, 4 => 3, 5 => 2], []], ['Druid', 20, 4, 22, [1 => 4, 2 => 3, 3 => 3, 4 => 3, 5 => 3, 6 => 2, 7 => 2, 8 => 1, 9 => 1], []],
    ['Sorcerer', 1, 4, 2, [1 => 2], []], ['Sorcerer', 2, 4, 4, [1 => 3], []], ['Sorcerer', 3, 4, 6, [1 => 4, 2 => 2], []], ['Sorcerer', 4, 5, 7, [1 => 4, 2 => 3], []], ['Sorcerer', 10, 6, 15, [1 => 4, 2 => 3, 3 => 3, 4 => 3, 5 => 2], []], ['Sorcerer', 20, 6, 22, [1 => 4, 2 => 3, 3 => 3, 4 => 3, 5 => 3, 6 => 2, 7 => 2, 8 => 1, 9 => 1], []],
    ['Wizard', 1, 3, 4, [1 => 2], []], ['Wizard', 4, 4, 7, [1 => 4, 2 => 3], []], ['Wizard', 10, 5, 15, [1 => 4, 2 => 3, 3 => 3, 4 => 3, 5 => 2], []], ['Wizard', 14, 5, 18, [1 => 4, 2 => 3, 3 => 3, 4 => 3, 5 => 2, 6 => 1, 7 => 1], []], ['Wizard', 16, 5, 21, [1 => 4, 2 => 3, 3 => 3, 4 => 3, 5 => 2, 6 => 1, 7 => 1, 8 => 1], []], ['Wizard', 20, 5, 25, [1 => 4, 2 => 3, 3 => 3, 4 => 3, 5 => 3, 6 => 2, 7 => 2, 8 => 1, 9 => 1], []],
    ['Paladin', 1, 0, 2, [1 => 2], []], ['Paladin', 3, 0, 4, [1 => 3], []], ['Paladin', 5, 0, 6, [1 => 4, 2 => 2], []], ['Paladin', 17, 0, 14, [1 => 4, 2 => 3, 3 => 3, 4 => 3, 5 => 1], []], ['Paladin', 20, 0, 15, [1 => 4, 2 => 3, 3 => 3, 4 => 3, 5 => 2], []],
    ['Ranger', 1, 0, 2, [1 => 2], []], ['Ranger', 3, 0, 4, [1 => 3], []], ['Ranger', 5, 0, 6, [1 => 4, 2 => 2], []], ['Ranger', 17, 0, 14, [1 => 4, 2 => 3, 3 => 3, 4 => 3, 5 => 1], []], ['Ranger', 20, 0, 15, [1 => 4, 2 => 3, 3 => 3, 4 => 3, 5 => 2], []],
    ['Warlock', 1, 2, 2, [], ['count' => 1, 'level' => 1]], ['Warlock', 2, 2, 3, [], ['count' => 2, 'level' => 1]], ['Warlock', 3, 2, 4, [], ['count' => 2, 'level' => 2]], ['Warlock', 4, 3, 5, [], ['count' => 2, 'level' => 2]], ['Warlock', 5, 3, 6, [], ['count' => 2, 'level' => 3]], ['Warlock', 9, 3, 10, [], ['count' => 2, 'level' => 5]], ['Warlock', 10, 4, 10, [], ['count' => 2, 'level' => 5]], ['Warlock', 11, 4, 11, [], ['count' => 3, 'level' => 5]], ['Warlock', 17, 4, 14, [], ['count' => 4, 'level' => 5]], ['Warlock', 20, 4, 15, [], ['count' => 4, 'level' => 5]],
    ['Barbarian', 1, 0, 0, [], []], ['Barbarian', 20, 0, 0, [], []],
    ['Fighter', 1, 0, 0, [], []], ['Fighter', 20, 0, 0, [], []],
    ['Monk', 1, 0, 0, [], []], ['Monk', 20, 0, 0, [], []],
    ['Rogue', 1, 0, 0, [], []], ['Rogue', 20, 0, 0, [], []],
]);

it('adds Divine Order and Primal Order without inflating the base level-one counts', function () {
    $this->seed(ClassProgressionSeeder::class);

    foreach ([
        'Cleric' => [
            'cantrips' => 3,
            'rule_key' => 'cleric-divine-order-cantrip',
            'config_key' => 'divine_order',
            'option' => 'Thaumaturge',
        ],
        'Druid' => [
            'cantrips' => 2,
            'rule_key' => 'druid-primal-order-cantrip',
            'config_key' => 'primal_order',
            'option' => 'Magician',
        ],
    ] as $className => $expected) {
        $progression = DB::table('class_progressions as progression')
            ->join('class_definitions as class', 'class.id', '=', 'progression.class_definition_id')
            ->where('class.name', $className)
            ->where('progression.class_level', 1)
            ->select('progression.*')
            ->sole();
        $rule = collect(json_decode(
            (string) data_get($progression, 'grant_rules'), true, 512, JSON_THROW_ON_ERROR,
        ))->firstWhere('rule_key', data_get($expected, 'rule_key'));

        expect((int) data_get($progression, 'cantrips_known'))->toBe(data_get($expected, 'cantrips'))
            ->and((int) data_get($progression, 'prepared_count'))->toBe(4)
            ->and($rule)->toMatchArray([
                'kind' => 'choice_from_list',
                'rule_key' => data_get($expected, 'rule_key'),
                'count' => 1,
                'bucket' => 'cantrip_known',
                'list' => '$config.'.data_get($expected, 'config_key').'.chosen_list',
                'level_min' => 0,
                'level_max' => 0,
                'with_slots' => false,
                'active_from_class_level' => 1,
                'active_if_config' => [
                    'key' => data_get($expected, 'config_key').'.chosen_option',
                    'equals' => data_get($expected, 'option'),
                ],
            ]);
    }
});

it('takes prepared counts from the class table regardless of ability score', function () {
    $this->seed(ClassProgressionSeeder::class);
    $wizardId = DB::table('class_definitions')->where('name', 'Wizard')->value('id');
    $characters = [];
    foreach ([8, 20] as $intelligence) {
        $characterId = DB::table('characters')->insertGetId([
            'name' => "Intelligence {$intelligence}", 'intelligence' => $intelligence,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('character_class_levels')->insert([
            'character_id' => $characterId, 'class_definition_id' => $wizardId, 'level' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $characters[] = $characterId;
    }

    $lookup = app(ClassProgressionLookup::class);
    expect($lookup->preparedCountForCharacterClass($characters[0], $wizardId))->toBe(4)
        ->and($lookup->preparedCountForCharacterClass($characters[1], $wizardId))->toBe(4);
});

it('adds Mystic Arcanum on top of the ordinary Warlock prepared count', function (
    int $level,
    int $preparedCount,
    array $arcanumLevels,
) {
    $this->seed(ClassProgressionSeeder::class);
    $progression = DB::table('class_progressions as progression')
        ->join('class_definitions as class', 'class.id', '=', 'progression.class_definition_id')
        ->where('class.name', 'Warlock')
        ->where('progression.class_level', $level)
        ->select('progression.*')
        ->sole();
    $rules = collect(json_decode((string) data_get($progression, 'grant_rules'), true, 512, JSON_THROW_ON_ERROR));

    $ordinary = $rules->firstWhere('rule_key', 'warlock-prepared');
    expect((int) data_get($progression, 'prepared_count'))->toBe($preparedCount)
        ->and(data_get($ordinary, 'count'))->toBe($preparedCount)
        ->and(data_get($ordinary, 'level_min'))->toBe(1)
        ->and(data_get($ordinary, 'level_max'))->toBe(5)
        ->and(data_get($ordinary, 'with_slots'))->toBeTrue();

    $arcanum = $rules
        ->filter(static fn (array $rule): bool => str_starts_with(
            (string) data_get($rule, 'rule_key'),
            'warlock-mystic-arcanum-',
        ))
        ->values();
    expect($arcanum->pluck('level_min')->all())->toBe($arcanumLevels)
        ->and($arcanum->pluck('level_max')->all())->toBe($arcanumLevels);
    foreach ($arcanum as $rule) {
        $spellLevel = (int) data_get($rule, 'level_min');
        expect($rule)->toMatchArray([
            'kind' => 'choice_from_list',
            'rule_key' => "warlock-mystic-arcanum-{$spellLevel}",
            'count' => 1,
            'bucket' => 'prepared',
            'list' => 'Warlock',
            'level_min' => $spellLevel,
            'level_max' => $spellLevel,
            'with_slots' => false,
            'free_cast' => [
                'uses' => 1,
                'recovery' => 'long_rest',
                'pool_scope' => 'per_spell',
            ],
        ]);
    }
})->with([
    'Warlock 11' => [11, 11, [6]],
    'Warlock 12' => [12, 11, [6]],
    'Warlock 13' => [13, 12, [6, 7]],
    'Warlock 14' => [14, 12, [6, 7]],
    'Warlock 15' => [15, 13, [6, 7, 8]],
    'Warlock 16' => [16, 13, [6, 7, 8]],
    'Warlock 17' => [17, 14, [6, 7, 8, 9]],
    'Warlock 18' => [18, 14, [6, 7, 8, 9]],
    'Warlock 19' => [19, 15, [6, 7, 8, 9]],
    'Warlock 20' => [20, 15, [6, 7, 8, 9]],
]);
