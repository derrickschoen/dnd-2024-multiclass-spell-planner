<?php

declare(strict_types=1);

use App\Domain\Reports\BuildReportBuilder;
use Database\Seeders\PactApexSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed();
});

it('seeds Pact Apex through commands with separate shared, Pact Magic, and Arcanum pools', function (): void {
    $this->seed(PactApexSeeder::class);
    $this->seed(PactApexSeeder::class);

    $character = DB::table('characters')->where('notes', 'seed:pact-apex')->sole();
    $characterId = (int) data_get($character, 'id');
    $report = app(BuildReportBuilder::class)->build($characterId);

    expect(DB::table('characters')->where('notes', 'seed:pact-apex')->count())->toBe(1)
        ->and((int) data_get($character, 'revision'))->toBe(2)
        ->and(DB::table('character_operations')->where('character_id', $characterId)->count())->toBe(2)
        ->and(DB::table('change_log')->where('character_id', $characterId)
            ->where('action_type', 'update_class')->distinct()->count('operation_uuid'))->toBe(2)
        ->and(data_get($report, 'caster'))->toBe([
            'caster_level' => 3,
            'slots' => [
                ['level' => 1, 'count' => 4],
                ['level' => 2, 'count' => 2],
            ],
            'pact_magic' => ['count' => 4, 'level' => 5],
        ])
        ->and(collect(data_get($report, 'classes'))->mapWithKeys(
            static fn (array $class): array => [(string) data_get($class, 'name') => [
                'level' => (int) data_get($class, 'class_level'),
                'max' => (int) data_get($class, 'max_preparable_level'),
            ]],
        )->all())->toBe([
            'Bard' => ['level' => 3, 'max' => 2],
            'Warlock' => ['level' => 17, 'max' => 5],
        ]);

    $arcanum = DB::table('spell_selection_slots')
        ->where('character_id', $characterId)
        ->where('rule_key', 'like', 'warlock-mystic-arcanum-%')
        ->orderBy('spell_level_min')
        ->get()
        ->map(static fn (object $slot): array => [
            'rule_key' => (string) data_get($slot, 'rule_key'),
            'level_min' => (int) data_get($slot, 'spell_level_min'),
            'level_max' => (int) data_get($slot, 'spell_level_max'),
            'with_slots' => (int) data_get($slot, 'with_slots'),
            'free_cast' => json_decode((string) data_get($slot, 'free_cast'), true, 512, JSON_THROW_ON_ERROR),
        ])
        ->all();

    expect($arcanum)->toBe(array_map(
        static fn (int $level): array => [
            'rule_key' => "warlock-mystic-arcanum-{$level}",
            'level_min' => $level,
            'level_max' => $level,
            'with_slots' => 0,
            'free_cast' => ['uses' => 1, 'recovery' => 'long_rest', 'pool_scope' => 'per_spell'],
        ],
        [6, 7, 8, 9],
    ));
});
