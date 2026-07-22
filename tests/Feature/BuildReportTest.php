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

    $mutt = DB::table('characters')->where('notes', 'like', "seed:mutt\n%")->sole();
    $muttId = (int) data_get($mutt, 'id');
    expect(data_get($mutt, 'name'))->toBe('Mutt (SRD)')
        ->and((bool) data_get($mutt, 'allow_legacy'))->toBeTrue()
        ->and(data_get($mutt, 'revision'))->toBe(42)
        ->and(data_get($mutt, 'notes'))->toContain(
            'sheet:max_hp=43',
            'sheet:advancement=milestone',
            'INFERRED abilities (PDF has no scores)',
            'AUTHORITATIVE spell attribution',
        );

    $muttClasses = DB::table('character_class_levels as level')
        ->join('class_definitions as class', 'class.id', '=', 'level.class_definition_id')
        ->where('level.character_id', $muttId)
        ->orderBy('class.name')
        ->pluck('level.level', 'class.name')
        ->all();
    expect($muttClasses)->toBe([
        'Bard' => 1, 'Cleric' => 1, 'Druid' => 1, 'Paladin' => 1,
        'Sorcerer' => 1, 'Wizard' => 1,
    ])->and(DB::table('character_operations')->where('character_id', $muttId)->count())->toBe(42)
        ->and(DB::table('change_log')->where('character_id', $muttId)
            ->where('action_type', 'add_source')->distinct()->count('operation_uuid'))->toBe(6)
        ->and(DB::table('change_log')->where('character_id', $muttId)
            ->where('action_type', 'set_slot')->distinct()->count('operation_uuid'))->toBe(35)
        ->and(DB::table('change_log')->where('character_id', $muttId)
            ->where('action_type', 'update_character_rules')->distinct()->count('operation_uuid'))->toBe(1);

    $muttSlots = DB::table('spell_selection_slots')->where('character_id', $muttId)->get();
    expect($muttSlots)->toHaveCount(35)
        ->and($muttSlots->whereNotNull('current_spell_version_id'))->toHaveCount(35)
        ->and($muttSlots->every(
            static fn (object $slot): bool => data_get($slot, 'selection_eligibility') === 'valid',
        ))->toBeTrue();

    $duplicates = DB::table('spell_selection_slots as slot')
        ->join('spell_versions as version', 'version.id', '=', 'slot.current_spell_version_id')
        ->join('character_source_instances as source', 'source.id', '=', 'slot.source_instance_id')
        ->where('slot.character_id', $muttId)
        ->select(['version.display_name as spell_name', 'source.display_name as source_name'])
        ->orderBy('version.display_name')
        ->orderBy('source.display_name')
        ->get()
        ->groupBy('spell_name')
        ->filter(static fn ($rows): bool => $rows->count() > 1)
        ->map(static fn ($rows): array => $rows->pluck('source_name')->all())
        ->all();
    expect($duplicates)->toBe([]);

    $selectionsByClass = DB::table('spell_selection_slots as slot')
        ->join('character_source_instances as source', 'source.id', '=', 'slot.source_instance_id')
        ->join('class_definitions as class', 'class.id', '=', 'source.source_definition_id')
        ->join('spell_versions as version', 'version.id', '=', 'slot.current_spell_version_id')
        ->where('slot.character_id', $muttId)
        ->where('source.source_type', 'class')
        ->orderBy('class.name')->orderBy('slot.rule_key')->orderBy('slot.ordinal')
        ->get(['class.name as class_name', 'slot.rule_key', 'version.display_name as spell_name'])
        ->groupBy('class_name')
        ->map(static fn ($rows) => $rows->groupBy('rule_key')
            ->map(static fn ($ruleRows): array => $ruleRows->pluck('spell_name')->all())
            ->all())
        ->all();
    expect($selectionsByClass)->toBe([
        'Bard' => [
            'bard-cantrips' => ['Starry Wisp', 'Vicious Mockery'],
            'bard-prepared' => ['Bane', 'Dissonant Whispers', 'Sleep', 'Thunderwave'],
        ],
        'Cleric' => [
            'cleric-cantrips' => ['Light', 'Spare the Dying', 'Thaumaturgy'],
            'cleric-divine-order-cantrip' => ['Guidance'],
            'cleric-prepared' => ['Create or Destroy Water', 'Cure Wounds', 'Healing Word', 'Sanctuary'],
        ],
        'Druid' => [
            'druid-cantrips' => ['Poison Spray', 'Shillelagh'],
            'druid-prepared' => ['Faerie Fire', 'Goodberry', 'Jump', 'Speak with Animals'],
        ],
        'Paladin' => [
            'paladin-prepared' => ['Searing Smite', 'Divine Favor'],
        ],
        'Sorcerer' => [
            'sorcerer-cantrips' => ['Chill Touch', 'Ray of Frost', 'Shocking Grasp', 'True Strike'],
            'sorcerer-prepared' => ['Chromatic Orb', 'Ray of Sickness'],
        ],
        'Wizard' => [
            'wizard-cantrips' => ['Mage Hand', 'Minor Illusion', 'Elementalism'],
            'wizard-prepared' => ['Feather Fall', 'Find Familiar', 'Shield', 'Unseen Servant'],
        ],
    ]);

    $orderConfigs = DB::table('character_source_instances as source')
        ->join('class_definitions as class', 'class.id', '=', 'source.source_definition_id')
        ->where('source.character_id', $muttId)
        ->whereIn('class.name', ['Cleric', 'Druid'])
        ->orderBy('class.name')
        ->pluck('source.config', 'class.name')
        ->map(static fn (string $config): array => json_decode($config, true, 512, JSON_THROW_ON_ERROR))
        ->all();
    expect($orderConfigs)->toBe([
        'Cleric' => [
            'spellcasting_ability' => 'wisdom',
            'divine_order' => ['chosen_option' => 'Thaumaturge', 'chosen_list' => 'Cleric'],
        ],
        'Druid' => [
            'spellcasting_ability' => 'wisdom',
            'primal_order' => ['chosen_option' => 'Warden'],
        ],
    ]);

    foreach (['2014:mold-earth' => 'Wizard', '2014:shape-water' => 'Druid'] as $versionKey => $list) {
        expect(DB::table('spell_list_memberships as membership')
            ->join('spell_versions as version', 'version.id', '=', 'membership.spell_version_id')
            ->where('version.content_key', $versionKey)
            ->where('membership.spell_list_key', $list)
            ->exists())->toBeTrue();
    }

    $selectedIdentityIds = $muttSlots->pluck('current_spell_version_id')
        ->map(static fn (mixed $versionId): int => (int) DB::table('spell_versions')
            ->where('id', $versionId)->value('spell_identity_id'));
    $spellbookIdentityIds = DB::table('wizard_spellbook_entries as entry')
        ->join('spell_versions as version', 'version.id', '=', 'entry.spell_version_id')
        ->where('entry.character_id', $muttId)
        ->pluck('version.spell_identity_id');
    expect($selectedIdentityIds->unique())->toHaveCount(35)
        ->and($selectedIdentityIds->merge($spellbookIdentityIds)->unique())->toHaveCount(37)
        ->and(DB::table('wizard_spellbook_entries')->where('character_id', $muttId)->count())->toBe(6);
    $wizardBook = DB::table('wizard_spellbook_entries as entry')
        ->join('spell_versions as version', 'version.id', '=', 'entry.spell_version_id')
        ->where('entry.character_id', $muttId)
        ->orderBy('entry.id')
        ->pluck('version.display_name')
        ->all();
    expect($wizardBook)->toBe([
        'Comprehend Languages', 'Feather Fall', 'Find Familiar',
        'Shield', "Tenser's Floating Disk", 'Unseen Servant',
    ]);

    $muttReport = app(BuildReportBuilder::class)->build($muttId);
    expect(collect(data_get($muttReport, 'duplicate_assessments'))
        ->reject(static fn (array $assessment): bool => data_get($assessment, 'category') === 'none')
        ->values()->all())->toBe([])
        ->and(collect(data_get($muttReport, 'wizard.prepared'))->pluck('spell_name')->all())->toBe([
            'Feather Fall', 'Find Familiar', 'Shield', 'Unseen Servant',
        ]);
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

it('returns the complete seeded report contract rather than only its headline totals', function () {
    $characterId = (int) DB::table('characters')->where('notes', 'seed:a6')->value('id');
    $report = app(BuildReportBuilder::class)->build($characterId);

    expect(array_keys($report))->toBe([
        'character', 'caster', 'classes', 'preparation_callout', 'access_routes',
        'wizard', 'duplicate_assessments',
    ])->and(data_get($report, 'preparation_callout'))->toBe(
        'This build possesses 3rd-level slots, but every class can prepare only 1st-level spells. '
        .'Higher-level slots can upcast those lower-level spells; they do not unlock higher-level choices.',
    )->and(data_get($report, 'character'))->toBe([
        'id' => $characterId,
        'name' => 'A6 Sixfold Spellcaster',
        'character_level' => 6,
        'proficiency_bonus' => 3,
        'abilities' => [
            'strength' => 10,
            'dexterity' => 10,
            'constitution' => 10,
            'intelligence' => 13,
            'wisdom' => 13,
            'charisma' => 17,
        ],
    ])->and(data_get($report, 'classes'))->toBe([
        ['name' => 'Bard', 'subclass' => null, 'class_level' => 1, 'spellcasting_ability' => 'charisma', 'progression_type' => 'full', 'prepared_count' => 4, 'max_preparable_level' => 1],
        ['name' => 'Cleric', 'subclass' => null, 'class_level' => 1, 'spellcasting_ability' => 'wisdom', 'progression_type' => 'full', 'prepared_count' => 4, 'max_preparable_level' => 1],
        ['name' => 'Druid', 'subclass' => null, 'class_level' => 1, 'spellcasting_ability' => 'wisdom', 'progression_type' => 'full', 'prepared_count' => 4, 'max_preparable_level' => 1],
        ['name' => 'Paladin', 'subclass' => null, 'class_level' => 1, 'spellcasting_ability' => 'charisma', 'progression_type' => 'half_up', 'prepared_count' => 2, 'max_preparable_level' => 1],
        ['name' => 'Sorcerer', 'subclass' => null, 'class_level' => 1, 'spellcasting_ability' => 'charisma', 'progression_type' => 'full', 'prepared_count' => 2, 'max_preparable_level' => 1],
        ['name' => 'Wizard', 'subclass' => null, 'class_level' => 1, 'spellcasting_ability' => 'intelligence', 'progression_type' => 'full', 'prepared_count' => 4, 'max_preparable_level' => 1],
    ]);

    $spellbook = collect(data_get($report, 'wizard.spellbook'))->map(
        static fn (array $entry): array => collect($entry)->except(['spellbook_entry_id', 'spell_version_id'])->all(),
    )->all();
    expect($spellbook)->toBe([
        ['spell_name' => 'Detect Magic', 'level' => 1, 'acquisition' => 'starting', 'copy_cost_gp' => null, 'copy_time_hours' => null, 'active' => true, 'prepared' => false],
        ['spell_name' => 'Feather Fall', 'level' => 1, 'acquisition' => 'starting', 'copy_cost_gp' => null, 'copy_time_hours' => null, 'active' => true, 'prepared' => false],
        ['spell_name' => 'Mage Armor', 'level' => 1, 'acquisition' => 'starting', 'copy_cost_gp' => null, 'copy_time_hours' => null, 'active' => true, 'prepared' => true],
        ['spell_name' => 'Magic Missile', 'level' => 1, 'acquisition' => 'starting', 'copy_cost_gp' => null, 'copy_time_hours' => null, 'active' => true, 'prepared' => true],
        ['spell_name' => 'Sleep', 'level' => 1, 'acquisition' => 'starting', 'copy_cost_gp' => null, 'copy_time_hours' => null, 'active' => true, 'prepared' => true],
        ['spell_name' => 'Thunderwave', 'level' => 1, 'acquisition' => 'starting', 'copy_cost_gp' => null, 'copy_time_hours' => null, 'active' => true, 'prepared' => true],
    ])->and(collect(data_get($report, 'wizard.prepared'))->pluck('spell_name')->all())->toBe([
        'Mage Armor', 'Magic Missile', 'Sleep', 'Thunderwave',
    ])->and(collect(data_get($report, 'wizard.ritual_only'))->map(
        static fn (array $entry): array => collect($entry)->except(['spellbook_entry_id', 'spell_version_id'])->all(),
    )->all())->toBe([
        ['spell_name' => 'Detect Magic', 'level' => 1],
    ])->and(array_is_list(data_get($report, 'wizard.prepared')))->toBeTrue()
        ->and(array_is_list(data_get($report, 'wizard.ritual_only')))->toBeTrue()
        ->and(collect(data_get($report, 'wizard.spellbook'))->every(
            static fn (array $entry): bool => is_int(data_get($entry, 'spellbook_entry_id'))
                && data_get($entry, 'spellbook_entry_id') > 0
                && is_int(data_get($entry, 'spell_version_id'))
                && data_get($entry, 'spell_version_id') > 0,
        ))->toBeTrue()
        ->and(collect(data_get($report, 'wizard.ritual_only'))->every(
            static fn (array $entry): bool => is_int(data_get($entry, 'spellbook_entry_id'))
                && data_get($entry, 'spellbook_entry_id') > 0
                && is_int(data_get($entry, 'spell_version_id'))
                && data_get($entry, 'spell_version_id') > 0,
        ))->toBeTrue();
});

it('uses the second-level ordinal in the exact multiclass preparation warning', function () {
    $characterId = (int) DB::table('characters')->insertGetId([
        'name' => 'Second-level Callout', 'created_at' => now(), 'updated_at' => now(),
    ]);
    foreach (['Bard' => 1, 'Wizard' => 2] as $className => $level) {
        DB::table('character_class_levels')->insert([
            'character_id' => $characterId,
            'class_definition_id' => DB::table('class_definitions')->where('name', $className)->value('id'),
            'level' => $level,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    expect(data_get(app(BuildReportBuilder::class)->build($characterId), 'preparation_callout'))->toBe(
        'This build possesses 2nd-level slots, but every class can prepare only 1st-level spells. '
        .'Higher-level slots can upcast those lower-level spells; they do not unlock higher-level choices.',
    );
});

it('rejects an unsupported subclass caster fraction with the exact diagnostic', function () {
    $fighterId = (int) DB::table('class_definitions')->where('name', 'Fighter')->value('id');
    $subclassId = (int) DB::table('subclass_definitions')->insertGetId([
        'content_key' => 'test:unsupported:fraction',
        'class_definition_id' => $fighterId,
        'name' => 'Unsupported Fraction',
        'rules_edition' => '2024',
        'spellcasting_ability' => 'intelligence',
        'caster_fraction' => '2/3',
        'caster_rounding' => 'up',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $characterId = (int) DB::table('characters')->insertGetId([
        'name' => 'Unsupported Fraction', 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('character_class_levels')->insert([
        'character_id' => $characterId,
        'class_definition_id' => $fighterId,
        'subclass_definition_id' => $subclassId,
        'level' => 3,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(fn (): array => app(BuildReportBuilder::class)->build($characterId))
        ->toThrow(RuntimeException::class, 'Unsupported caster fraction 2/3 rounded up.');
});

it('attaches the complete active warning acknowledgement contract', function () {
    $characterId = (int) DB::table('characters')->where('notes', 'seed:a6')->value('id');
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
    $initial = app(BuildReportBuilder::class)->build($characterId);
    $assessment = collect(data_get($initial, 'duplicate_assessments'))->firstWhere('category', 'conflicting_version');
    $createdAt = '2026-07-22 12:34:56';
    $acknowledgementId = DB::table('warning_acknowledgements')->insertGetId([
        'character_id' => $characterId,
        'warning_fingerprint' => data_get($assessment, 'warning_fingerprint'),
        'note' => 'Accepted for roleplay',
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);

    $acknowledgement = data_get(
        collect(data_get(app(BuildReportBuilder::class)->build($characterId), 'duplicate_assessments'))
            ->firstWhere('category', 'conflicting_version'),
        'acknowledgement',
    );
    expect($acknowledgement)->toBe([
        'id' => $acknowledgementId,
        'note' => 'Accepted for roleplay',
        'created_at' => $createdAt,
    ]);
});

it('maps every supported subclass caster fraction to its published contribution', function (
    string $fraction,
    string $rounding,
    string $progressionType,
    int $casterLevel,
) {
    $fighterId = (int) DB::table('class_definitions')->where('name', 'Fighter')->value('id');
    $subclassId = DB::table('subclass_definitions')->insertGetId([
        'content_key' => "test:{$fraction}:{$rounding}",
        'class_definition_id' => $fighterId,
        'name' => "Test {$fraction} {$rounding}",
        'rules_edition' => '2024',
        'spellcasting_ability' => 'intelligence',
        'caster_fraction' => $fraction,
        'caster_rounding' => $rounding,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $characterId = DB::table('characters')->insertGetId([
        'name' => 'Fraction contract', 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('character_class_levels')->insert([
        'character_id' => $characterId,
        'class_definition_id' => $fighterId,
        'subclass_definition_id' => $subclassId,
        'level' => 5,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $report = app(BuildReportBuilder::class)->build($characterId);
    expect(data_get($report, 'classes.0.progression_type'))->toBe($progressionType)
        ->and(data_get($report, 'classes.0.spellcasting_ability'))->toBe('intelligence')
        ->and(data_get($report, 'caster.caster_level'))->toBe($casterLevel);
})->with([
    ['1/2', 'up', 'half_up', 3],
    ['1/2', 'down', 'half_down', 2],
    ['1/3', 'up', 'third_up', 2],
    ['1/3', 'down', 'third_down', 1],
]);

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

it('describes a martial-only build without inventing zero-level slots', function () {
    $fighterId = DB::table('class_definitions')->where('name', 'Fighter')->value('id');
    $barbarianId = DB::table('class_definitions')->where('name', 'Barbarian')->value('id');
    $characterId = DB::table('characters')->insertGetId([
        'name' => 'Fighter Barbarian', 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('character_class_levels')->insert([
        [
            'character_id' => $characterId, 'class_definition_id' => $fighterId, 'level' => 2,
            'created_at' => now(), 'updated_at' => now(),
        ],
        [
            'character_id' => $characterId, 'class_definition_id' => $barbarianId, 'level' => 2,
            'created_at' => now(), 'updated_at' => now(),
        ],
    ]);

    $report = app(BuildReportBuilder::class)->build($characterId);
    expect(data_get($report, 'caster.slots'))->toBe([])
        ->and(data_get($report, 'caster.pact_magic'))->toBeNull()
        ->and(data_get($report, 'preparation_callout'))->toBe(
            'This build possesses no Spellcasting or Pact Magic slots.',
        )
        ->and(data_get($report, 'preparation_callout'))->not->toContain('0th-level slots');
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
