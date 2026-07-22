<?php

declare(strict_types=1);

use App\Domain\Characters\CharacterState;
use App\Domain\Characters\Commands\CharacterCommandIntegrity;
use App\Domain\Reports\BuildReportBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed();
});

function apiAbuseCharacterId(): int
{
    return (int) DB::table('characters')->where('notes', 'seed:a6')->value('id');
}

/** @return array<string, list<array<string, mixed>>> */
function apiAbuseDatabaseState(): array
{
    $tables = collect(DB::select(
        "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name",
    ))->map(static fn (object $row): string => (string) data_get($row, 'name'));

    return $tables->mapWithKeys(static function (string $table): array {
        $rows = DB::table($table)->get()
            ->map(static function (object $row): array {
                $normalized = (array) $row;
                ksort($normalized);

                return $normalized;
            })
            ->sortBy(static fn (array $row): string => json_encode($row, JSON_THROW_ON_ERROR))
            ->values()
            ->all();

        return [$table => $rows];
    })->all();
}

/** @param array<string, mixed> $command */
function apiAbuseMutation(
    $test,
    int $characterId,
    array $command,
    int $revision = 0,
    ?string $operationUuid = null,
): TestResponse {
    return $test->postJson("/characters/{$characterId}/mutations", [
        'operation_uuid' => $operationUuid ?? Str::uuid()->toString(),
        'expected_revision' => $revision,
        'command' => $command,
    ]);
}

function apiAbuseAssertRejectedWithoutWrites(TestResponse $response, array $before, int $status = 422): void
{
    $response->assertStatus($status)->assertJsonStructure(['message']);
    expect($response->json('message'))->toBeString()->not->toBe('')
        ->and(apiAbuseDatabaseState())->toBe($before);
}

/** @return array<string, int|string> */
function apiAbuseFixtures(int $characterId): array
{
    return [
        'slot' => (int) DB::table('spell_selection_slots')
            ->where('character_id', $characterId)
            ->where('rule_key', 'wizard-cantrips')
            ->where('ordinal', 2)
            ->value('id'),
        'source' => (int) DB::table('character_source_instances')
            ->where('character_id', $characterId)
            ->where('display_name', 'Magic Initiate: Wizard')
            ->value('id'),
        'class' => (int) DB::table('class_definitions')->where('name', 'Wizard')->value('id'),
        'spell' => (int) DB::table('spell_versions')->where('content_key', '2024:fire-bolt')->value('id'),
    ];
}

/**
 * @param  array<string, int|string>  $fixtures
 * @return list<array<string, mixed>>
 */
function apiAbusePayloads(string $type, int $characterId, array $fixtures): array
{
    $snapshot = app(CharacterState::class)->capture($characterId);
    $integrity = app(CharacterCommandIntegrity::class);
    $slotRestoreState = [
        'current_spell_version_id' => null,
        'selection_eligibility' => 'unselected',
        'selection_invalid_reason' => null,
        'state' => 'active',
        'override_note' => null,
    ];

    return match ($type) {
        'update_ability' => [
            ['type' => $type, 'score' => 20],
            ['type' => $type, 'ability' => ['intelligence'], 'score' => 20],
            ['type' => $type, 'ability' => 'luck', 'score' => 20],
            ['type' => $type, 'ability' => 'intelligence'],
            ['type' => $type, 'ability' => 'intelligence', 'score' => '20'],
            ['type' => $type, 'ability' => 'intelligence', 'score' => null],
            ['type' => $type, 'ability' => 'intelligence', 'score' => 0],
            ['type' => $type, 'ability' => 'intelligence', 'score' => 31],
            ['type' => $type, 'ability' => 'intelligence', 'score' => PHP_INT_MAX],
        ],
        'set_slot' => [
            ['type' => $type, 'mode' => 'clear'],
            ['type' => $type, 'slot_id' => (string) data_get($fixtures, 'slot'), 'mode' => 'clear'],
            ['type' => $type, 'slot_id' => null, 'mode' => 'clear'],
            ['type' => $type, 'slot_id' => 0, 'mode' => 'clear'],
            ['type' => $type, 'slot_id' => PHP_INT_MAX, 'mode' => 'clear'],
            ['type' => $type, 'slot_id' => data_get($fixtures, 'slot')],
            ['type' => $type, 'slot_id' => data_get($fixtures, 'slot'), 'mode' => []],
            ['type' => $type, 'slot_id' => data_get($fixtures, 'slot'), 'mode' => 'teleport'],
            ['type' => $type, 'slot_id' => data_get($fixtures, 'slot'), 'mode' => 'select'],
            ['type' => $type, 'slot_id' => data_get($fixtures, 'slot'), 'mode' => 'select', 'spell_version_id' => '1'],
            ['type' => $type, 'slot_id' => data_get($fixtures, 'slot'), 'mode' => 'select', 'spell_version_id' => null],
            ['type' => $type, 'slot_id' => data_get($fixtures, 'slot'), 'mode' => 'select', 'spell_version_id' => PHP_INT_MAX],
            ['type' => $type, 'slot_id' => data_get($fixtures, 'slot'), 'mode' => 'keep_override', 'note' => []],
            ['type' => $type, 'slot_id' => data_get($fixtures, 'slot'), 'mode' => 'keep_override', 'note' => str_repeat('x', 2001)],
            ['type' => $type, 'slot_id' => data_get($fixtures, 'slot'), 'mode' => 'restore', 'state' => $slotRestoreState],
            $integrity->attach($characterId, [
                'type' => $type,
                'slot_id' => data_get($fixtures, 'slot'),
                'mode' => 'restore',
                'state' => array_replace($slotRestoreState, ['state' => 'unknown']),
            ]),
        ],
        'update_character_rules' => [
            ['type' => $type],
            ['type' => $type, 'allow_legacy' => 'false'],
            ['type' => $type, 'allow_legacy' => null],
            ['type' => $type, 'allow_legacy' => 0],
            ['type' => $type, 'allow_legacy' => []],
            ['type' => $type, 'allow_legacy' => false, 'legacy_limit' => PHP_INT_MAX],
        ],
        'update_source_config' => [
            ['type' => $type, 'chosen_list' => 'Cleric'],
            ['type' => $type, 'source_instance_id' => (string) data_get($fixtures, 'source'), 'chosen_list' => 'Cleric'],
            ['type' => $type, 'source_instance_id' => null, 'chosen_list' => 'Cleric'],
            ['type' => $type, 'source_instance_id' => 0, 'chosen_list' => 'Cleric'],
            ['type' => $type, 'source_instance_id' => PHP_INT_MAX, 'chosen_list' => 'Cleric'],
            ['type' => $type, 'source_instance_id' => data_get($fixtures, 'source')],
            ['type' => $type, 'source_instance_id' => data_get($fixtures, 'source'), 'chosen_list' => []],
            ['type' => $type, 'source_instance_id' => data_get($fixtures, 'source'), 'chosen_list' => 'Bard'],
            ['type' => $type, 'source_instance_id' => data_get($fixtures, 'source'), 'chosen_list' => str_repeat('x', 81)],
        ],
        'acknowledge_warning' => [
            ['type' => $type, 'note' => 'Intentional.'],
            ['type' => $type, 'warning_fingerprint' => null, 'note' => 'Intentional.'],
            ['type' => $type, 'warning_fingerprint' => [], 'note' => 'Intentional.'],
            ['type' => $type, 'warning_fingerprint' => 'unknown:warning', 'note' => 'Intentional.'],
            ['type' => $type, 'warning_fingerprint' => str_repeat('x', 256), 'note' => 'Intentional.'],
            ['type' => $type, 'warning_fingerprint' => data_get($fixtures, 'fingerprint'), 'mode' => []],
            ['type' => $type, 'warning_fingerprint' => data_get($fixtures, 'fingerprint'), 'mode' => 'ignore'],
            ['type' => $type, 'warning_fingerprint' => data_get($fixtures, 'fingerprint')],
            ['type' => $type, 'warning_fingerprint' => data_get($fixtures, 'fingerprint'), 'note' => null],
            ['type' => $type, 'warning_fingerprint' => data_get($fixtures, 'fingerprint'), 'note' => str_repeat('x', 2001)],
            [
                'type' => $type,
                'warning_fingerprint' => data_get($fixtures, 'fingerprint'),
                'note' => 'Intentional.',
                'acknowledgement_limit' => PHP_INT_MAX,
            ],
            ['type' => $type, 'warning_fingerprint' => 'conflicting_versions:missing', 'mode' => 'delete'],
        ],
        'update_class' => [
            ['type' => $type, 'level' => 1],
            ['type' => $type, 'class_definition_id' => (string) data_get($fixtures, 'class'), 'level' => 1],
            ['type' => $type, 'class_definition_id' => null, 'level' => 1],
            ['type' => $type, 'class_definition_id' => 0, 'level' => 1],
            ['type' => $type, 'class_definition_id' => PHP_INT_MAX, 'level' => 1],
            ['type' => $type, 'class_definition_id' => data_get($fixtures, 'class')],
            ['type' => $type, 'class_definition_id' => data_get($fixtures, 'class'), 'level' => '2'],
            ['type' => $type, 'class_definition_id' => data_get($fixtures, 'class'), 'level' => []],
            ['type' => $type, 'class_definition_id' => data_get($fixtures, 'class'), 'level' => 0],
            ['type' => $type, 'class_definition_id' => data_get($fixtures, 'class'), 'level' => 21],
            ['type' => $type, 'class_definition_id' => data_get($fixtures, 'class'), 'level' => PHP_INT_MAX],
            ['type' => $type, 'class_definition_id' => data_get($fixtures, 'class'), 'level' => 2, 'subclass_definition_id' => '1'],
        ],
        'restore_snapshot' => [
            ['type' => $type],
            ['type' => $type, 'snapshot' => null],
            ['type' => $type, 'snapshot' => 'snapshot'],
            ['type' => $type, 'snapshot' => $snapshot],
            ['type' => $type, 'snapshot' => $snapshot, 'integrity' => null],
            ['type' => $type, 'snapshot' => $snapshot, 'integrity' => 'not-a-signature'],
            $integrity->attach($characterId, ['type' => $type, 'snapshot' => ['schema_version' => 'unknown']]),
            $integrity->attach($characterId, [
                'type' => $type,
                'snapshot' => array_replace($snapshot, ['spell_selection_slots' => PHP_INT_MAX]),
            ]),
            $integrity->attach($characterId, [
                'type' => $type,
                'snapshot' => $snapshot,
                'restore_limit' => PHP_INT_MAX,
            ]),
        ],
        default => throw new InvalidArgumentException("Unknown A1 command {$type}."),
    };
}

dataset('A1 command types', [
    'update ability' => ['update_ability'],
    'set slot' => ['set_slot'],
    'update character rules' => ['update_character_rules'],
    'update source config' => ['update_source_config'],
    'acknowledge warning' => ['acknowledge_warning'],
    'update class' => ['update_class'],
    'restore snapshot' => ['restore_snapshot'],
]);

it('A1 rejects malformed mutation envelopes without any database write', function (): void {
    $characterId = apiAbuseCharacterId();
    $valid = [
        'operation_uuid' => Str::uuid()->toString(),
        'expected_revision' => 0,
        'command' => ['type' => 'update_ability', 'ability' => 'wisdom', 'score' => 16],
    ];
    $payloads = [
        array_diff_key($valid, ['operation_uuid' => true]),
        array_replace($valid, ['operation_uuid' => null]),
        array_replace($valid, ['operation_uuid' => []]),
        array_replace($valid, ['operation_uuid' => str_repeat('x', 2000)]),
        array_diff_key($valid, ['expected_revision' => true]),
        array_replace($valid, ['expected_revision' => null]),
        array_replace($valid, ['expected_revision' => '0']),
        array_replace($valid, ['expected_revision' => -1]),
        array_diff_key($valid, ['command' => true]),
        array_replace($valid, ['command' => null]),
        array_replace($valid, ['command' => 'update']),
        array_replace($valid, ['command' => []]),
        array_replace($valid, ['command' => ['type' => 'teleport']]),
    ];
    $before = apiAbuseDatabaseState();

    foreach ($payloads as $payload) {
        apiAbuseAssertRejectedWithoutWrites(
            $this->postJson("/characters/{$characterId}/mutations", $payload),
            $before,
        );
    }
});

it('A1 rejects abusive payloads for every :type command without any database write', function (string $type): void {
    $characterId = apiAbuseCharacterId();
    $fixtures = apiAbuseFixtures($characterId);
    if ($type === 'acknowledge_warning') {
        DB::table('characters')->where('id', $characterId)->update(['allow_legacy' => true]);
        $slots = DB::table('spell_selection_slots')->where('character_id', $characterId)
            ->where('rule_key', 'wizard-cantrips')->whereIn('ordinal', [2, 3])->orderBy('ordinal')->get();
        $versions = [
            (int) DB::table('spell_versions')->where('content_key', '2014:chill-touch')->value('id'),
            (int) DB::table('spell_versions')->where('content_key', '2024:chill-touch')->value('id'),
        ];
        foreach ($slots->values() as $index => $slot) {
            DB::table('spell_selection_slots')->where('id', data_get($slot, 'id'))->update([
                'current_spell_version_id' => $versions[$index],
                'selection_eligibility' => 'valid',
                'selection_invalid_reason' => null,
            ]);
        }
        $warning = collect(data_get(app(BuildReportBuilder::class)->build($characterId), 'duplicate_assessments'))
            ->firstWhere('category', 'conflicting_version');
        $fixtures['fingerprint'] = (string) data_get($warning, 'warning_fingerprint');
        expect(data_get($fixtures, 'fingerprint'))->toStartWith('conflicting_versions:');
    }
    $before = apiAbuseDatabaseState();

    foreach (apiAbusePayloads($type, $characterId, $fixtures) as $command) {
        apiAbuseAssertRejectedWithoutWrites(
            apiAbuseMutation($this, $characterId, $command),
            $before,
        );
    }
})->with('A1 command types');

it('A2 rejects slots, sources, save points, and acknowledgements owned by another character', function (): void {
    $characterId = apiAbuseCharacterId();
    $otherCharacterId = (int) DB::table('characters')->insertGetId([
        'name' => 'Other Character',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $wizardId = (int) DB::table('class_definitions')->where('name', 'Wizard')->value('id');
    apiAbuseMutation($this, $otherCharacterId, [
        'type' => 'update_class',
        'class_definition_id' => $wizardId,
        'level' => 1,
        'subclass_definition_id' => null,
    ])->assertOk();
    $this->postJson("/characters/{$otherCharacterId}/save-points", ['label' => 'Other save point'])
        ->assertCreated();
    $otherSlotId = (int) DB::table('spell_selection_slots')
        ->where('character_id', $otherCharacterId)->value('id');
    $otherSourceId = (int) DB::table('character_source_instances')
        ->where('character_id', $otherCharacterId)->value('id');
    $otherSavePointId = (int) DB::table('character_save_points')
        ->where('character_id', $otherCharacterId)->value('id');
    $fingerprint = 'conflicting_versions:other-character';
    DB::table('warning_acknowledgements')->insert([
        'character_id' => $otherCharacterId,
        'warning_fingerprint' => $fingerprint,
        'note' => 'Other acknowledgement',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $otherDelete = app(CharacterCommandIntegrity::class)->attach($otherCharacterId, [
        'type' => 'acknowledge_warning',
        'mode' => 'delete',
        'warning_fingerprint' => $fingerprint,
    ]);

    $attempts = [
        fn (): TestResponse => apiAbuseMutation($this, $characterId, [
            'type' => 'set_slot', 'slot_id' => $otherSlotId, 'mode' => 'clear',
        ]),
        fn (): TestResponse => apiAbuseMutation($this, $characterId, [
            'type' => 'update_source_config',
            'source_instance_id' => $otherSourceId,
            'chosen_list' => 'Cleric',
        ]),
        fn (): TestResponse => apiAbuseMutation($this, $characterId, $otherDelete),
    ];
    foreach ($attempts as $attempt) {
        $before = apiAbuseDatabaseState();
        apiAbuseAssertRejectedWithoutWrites($attempt(), $before);
    }

    $before = apiAbuseDatabaseState();
    apiAbuseAssertRejectedWithoutWrites(
        $this->getJson("/characters/{$characterId}/save-points/{$otherSavePointId}/command"),
        $before,
        404,
    );
});

it('A3 rejects wrong-list, out-of-range, and disabled-edition spell selections', function (): void {
    $characterId = apiAbuseCharacterId();
    $slotId = (int) data_get(apiAbuseFixtures($characterId), 'slot');
    $cases = [
        'Selected spell is not on an allowed spell list.' => '2024:guidance',
        'Selected spell is outside the slot level range.' => '2024:magic-missile',
        'Enable legacy rules before selecting a 2014 spell version.' => '2014:chill-touch',
    ];

    foreach ($cases as $message => $contentKey) {
        $spellVersionId = (int) DB::table('spell_versions')->where('content_key', $contentKey)->value('id');
        $before = apiAbuseDatabaseState();
        $response = apiAbuseMutation($this, $characterId, [
            'type' => 'set_slot',
            'slot_id' => $slotId,
            'mode' => 'select',
            'spell_version_id' => $spellVersionId,
        ])->assertUnprocessable()->assertJsonPath('message', $message);
        apiAbuseAssertRejectedWithoutWrites($response, $before);
    }
});

it('A4 replays one operation UUID across a different command and changed revision exactly once', function (): void {
    $characterId = apiAbuseCharacterId();
    $operationUuid = Str::uuid()->toString();
    apiAbuseMutation($this, $characterId, [
        'type' => 'update_ability', 'ability' => 'wisdom', 'score' => 16,
    ], 0, $operationUuid)->assertOk()->assertJsonPath('revision', 1);
    apiAbuseMutation($this, $characterId, [
        'type' => 'update_ability', 'ability' => 'intelligence', 'score' => 14,
    ], 1)->assertOk()->assertJsonPath('revision', 2);

    $beforeReplay = apiAbuseDatabaseState();
    apiAbuseMutation($this, $characterId, [
        'type' => 'update_character_rules', 'allow_legacy' => true,
    ], PHP_INT_MAX, $operationUuid)
        ->assertOk()
        ->assertJsonPath('idempotent_replay', true)
        ->assertJsonPath('revision', 2);

    $audit = DB::table('change_log')->where('operation_uuid', $operationUuid)->get();
    expect(apiAbuseDatabaseState())->toBe($beforeReplay)
        ->and((int) DB::table('characters')->where('id', $characterId)->value('wisdom'))->toBe(16)
        ->and((int) DB::table('characters')->where('id', $characterId)->value('intelligence'))->toBe(14)
        ->and((bool) DB::table('characters')->where('id', $characterId)->value('allow_legacy'))->toBeFalse()
        ->and(DB::table('character_operations')->where('operation_uuid', $operationUuid)->count())->toBe(1)
        ->and($audit)->toHaveCount(1)
        ->and($audit->pluck('group_id')->unique())->toHaveCount(1);
});

it('A5 rejects a restore command signed for another character', function (): void {
    $characterId = apiAbuseCharacterId();
    $otherCharacterId = (int) DB::table('characters')->insertGetId([
        'name' => 'Save Point Owner',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $this->postJson("/characters/{$otherCharacterId}/save-points", ['label' => 'Private state'])
        ->assertCreated();
    $savePointId = (int) DB::table('character_save_points')
        ->where('character_id', $otherCharacterId)->value('id');
    $command = $this->getJson("/characters/{$otherCharacterId}/save-points/{$savePointId}/command")
        ->assertOk()->json('command');

    $before = apiAbuseDatabaseState();
    $response = apiAbuseMutation($this, $characterId, $command)
        ->assertUnprocessable()
        ->assertJsonPath(
            'message',
            'This internal character command is invalid or belongs to another character.',
        );
    apiAbuseAssertRejectedWithoutWrites($response, $before);
});

it('A5 rejects a valid save-point snapshot after one referenced spell version is tombstoned', function (): void {
    $characterId = apiAbuseCharacterId();
    $this->postJson("/characters/{$characterId}/save-points", ['label' => 'Before catalog tombstone'])
        ->assertCreated();
    $savePointId = (int) DB::table('character_save_points')
        ->where('character_id', $characterId)->value('id');
    $command = $this->getJson("/characters/{$characterId}/save-points/{$savePointId}/command")
        ->assertOk()->json('command');
    $spellVersionId = (int) DB::table('spell_selection_slots')
        ->where('character_id', $characterId)
        ->whereNotNull('current_spell_version_id')
        ->value('current_spell_version_id');
    DB::table('spell_versions')->where('id', $spellVersionId)->update(['is_active' => false]);

    $before = apiAbuseDatabaseState();
    $response = apiAbuseMutation($this, $characterId, $command)
        ->assertUnprocessable()
        ->assertJsonPath(
            'message',
            "Character snapshot references inactive spell version {$spellVersionId}.",
        );
    apiAbuseAssertRejectedWithoutWrites($response, $before);
});
