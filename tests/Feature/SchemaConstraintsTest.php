<?php

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * These assert that the schema's safety guarantees are real rather than
 * decorative. Each one corresponds to a way character data could silently
 * corrupt if SQLite were not enforcing it.
 */

function makeCharacter(string $name = 'Test'): int
{
    return DB::table('characters')->insertGetId([
        'name' => $name, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function makeSourceInstance(int $characterId, string $displayName = 'Magic Initiate'): int
{
    return DB::table('character_source_instances')->insertGetId([
        'character_id' => $characterId,
        'instance_uuid' => Str::uuid()->toString(),
        'source_type' => 'feat',
        'display_name' => $displayName,
        'state' => 'active',
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

function makeSlot(int $characterId, int $sourceInstanceId, string $slotKey): int
{
    return DB::table('spell_selection_slots')->insertGetId([
        'character_id' => $characterId,
        'source_instance_id' => $sourceInstanceId,
        'slot_key' => $slotKey,
        'rule_key' => 'magic-initiate-cantrip',
        'ordinal' => 1,
        'bucket' => 'cantrip_known',
        'eligibility_kind' => 'choice_from_list',
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

it('keeps foreign key enforcement on', function () {
    expect((int) DB::select('PRAGMA foreign_keys')[0]->foreign_keys)->toBe(1);
});

it('refuses a slot whose source instance belongs to a different character', function () {
    $alice = makeCharacter('Alice');
    $bob = makeCharacter('Bob');
    $bobsSource = makeSourceInstance($bob);

    // Without the composite FK this insert succeeds and Alice silently acquires a
    // slot fed by Bob's feat -- corruption that no application code would catch.
    expect(fn () => makeSlot($alice, $bobsSource, 'x:y:1'))
        ->toThrow(QueryException::class);
});

it('accepts a slot whose source instance belongs to the same character', function () {
    $alice = makeCharacter('Alice');
    $slotId = makeSlot($alice, makeSourceInstance($alice), 'a:b:1');

    expect($slotId)->toBeGreaterThan(0);
});

it('scopes slot_key uniqueness per character, not globally', function () {
    $alice = makeCharacter('Alice');
    $bob = makeCharacter('Bob');

    makeSlot($alice, makeSourceInstance($alice), 'shared:key:1');

    // Importing a character must not collide with an existing one's keys.
    $bobSlot = makeSlot($bob, makeSourceInstance($bob), 'shared:key:1');
    expect($bobSlot)->toBeGreaterThan(0);

    // But the same character may not reuse a key.
    expect(fn () => makeSlot($alice, makeSourceInstance($alice), 'shared:key:1'))
        ->toThrow(QueryException::class);
});

it('refuses a duplicate class row for one character', function () {
    $character = makeCharacter();
    $classId = DB::table('class_definitions')->insertGetId([
        'content_key' => 'srd:class:wizard', 'name' => 'Wizard', 'rules_edition' => '2024',
        'progression_type' => 'full', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $row = fn () => [
        'character_id' => $character, 'class_definition_id' => $classId,
        'level' => 1, 'created_at' => now(), 'updated_at' => now(),
    ];
    DB::table('character_class_levels')->insert($row());

    // A duplicate would double both caster level and proficiency bonus.
    expect(fn () => DB::table('character_class_levels')->insert($row()))
        ->toThrow(QueryException::class);
});

it('refuses a subclass belonging to a different class', function () {
    $character = makeCharacter();

    $wizard = DB::table('class_definitions')->insertGetId([
        'content_key' => 'srd:class:wizard', 'name' => 'Wizard', 'rules_edition' => '2024',
        'progression_type' => 'full', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $fighter = DB::table('class_definitions')->insertGetId([
        'content_key' => 'srd:class:fighter', 'name' => 'Fighter', 'rules_edition' => '2024',
        'progression_type' => 'none', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $eldritchKnight = DB::table('subclass_definitions')->insertGetId([
        'content_key' => 'srd:subclass:eldritch-knight', 'class_definition_id' => $fighter,
        'name' => 'Eldritch Knight', 'rules_edition' => '2024',
        'caster_fraction' => '1/3', 'caster_rounding' => 'down',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    // Eldritch Knight is a Fighter subclass; attaching it to a Wizard level would
    // make the caster-level calculation nonsense.
    expect(fn () => DB::table('character_class_levels')->insert([
        'character_id' => $character,
        'class_definition_id' => $wizard,
        'subclass_definition_id' => $eldritchKnight,
        'level' => 3, 'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('allows a subclass attached to its own class', function () {
    $character = makeCharacter();
    $fighter = DB::table('class_definitions')->insertGetId([
        'content_key' => 'srd:class:fighter', 'name' => 'Fighter', 'rules_edition' => '2024',
        'progression_type' => 'none', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $eldritchKnight = DB::table('subclass_definitions')->insertGetId([
        'content_key' => 'srd:subclass:eldritch-knight', 'class_definition_id' => $fighter,
        'name' => 'Eldritch Knight', 'rules_edition' => '2024',
        'caster_fraction' => '1/3', 'caster_rounding' => 'down',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $id = DB::table('character_class_levels')->insertGetId([
        'character_id' => $character,
        'class_definition_id' => $fighter,
        'subclass_definition_id' => $eldritchKnight,
        'level' => 3, 'created_at' => now(), 'updated_at' => now(),
    ]);

    expect($id)->toBeGreaterThan(0);
});

it('keeps one spell identity across two edition versions', function () {
    $identity = DB::table('spell_identities')->insertGetId([
        'content_key' => 'spell:chill-touch', 'canonical_name' => 'Chill Touch',
        'normalized_name' => 'chill touch', 'created_at' => now(), 'updated_at' => now(),
    ]);

    foreach (['2024', '2014'] as $edition) {
        DB::table('spell_versions')->insert([
            'content_key' => "spell:chill-touch:{$edition}",
            'spell_identity_id' => $identity,
            'display_name' => 'Chill Touch', 'rules_edition' => $edition,
            'level' => 0, 'school' => 'Necromancy',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    expect(DB::table('spell_versions')->where('spell_identity_id', $identity)->count())->toBe(2);

    // One version per identity per edition -- a re-import must update, not duplicate.
    expect(fn () => DB::table('spell_versions')->insert([
        'content_key' => 'spell:chill-touch:2024:dup',
        'spell_identity_id' => $identity,
        'display_name' => 'Chill Touch', 'rules_edition' => '2024',
        'level' => 0, 'school' => 'Necromancy',
        'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});
