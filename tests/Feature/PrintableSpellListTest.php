<?php

declare(strict_types=1);

use App\Domain\Reports\PrintableSpellListBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed();
});

function muttCharacterId(): int
{
    return (int) DB::table('characters')->where('notes', 'like', "seed:mutt\n%")->value('id');
}

/** @param array<string, mixed> $spellList @return array<string, mixed> */
function printableSource(array $spellList, string $source): array
{
    return (array) collect(data_get($spellList, 'source_groups'))->firstWhere('source', $source);
}

/** @param array<string, mixed> $spellList @return array<string, mixed> */
function unpreparedSection(array $spellList, string $className): array
{
    return (array) collect(data_get($spellList, 'unprepared_sections'))->firstWhere('class_name', $className);
}

it('builds Mutt printable sources with complete facts and only the mechanically relevant number', function (): void {
    $spellList = app(PrintableSpellListBuilder::class)->build(muttCharacterId());

    expect(data_get($spellList, 'variant'))->toBe('reference')
        ->and(data_get($spellList, 'text_status'))->toBe('not_requested')
        ->and(collect(data_get($spellList, 'source_groups'))->pluck('source')->all())->toBe([
            'Bard 1', 'Cleric 1', 'Druid 1', 'Paladin 1', 'Sorcerer 1', 'Wizard 1',
        ]);

    $chillTouch = collect(data_get(printableSource($spellList, 'Sorcerer 1'), 'spells'))
        ->firstWhere('name', 'Chill Touch');
    expect($chillTouch)->toMatchArray([
        'level' => 0,
        'school' => 'Necromancy',
        'casting_time' => 'Action',
        'action_type' => 'Action',
        'range' => 'Touch',
        'duration' => 'Instantaneous',
        'concentration' => false,
        'ritual' => false,
        'components' => 'V, S',
        'spellcasting_ability' => 'charisma',
        'attack_bonus' => 6,
        'save_dc' => null,
        'attack_modes' => ['melee_spell'],
        'save_abilities' => [],
        'description' => null,
    ]);

    $viciousMockery = collect(data_get(printableSource($spellList, 'Bard 1'), 'spells'))
        ->firstWhere('name', 'Vicious Mockery');
    expect($viciousMockery)->toMatchArray([
        'spellcasting_ability' => 'charisma',
        'attack_bonus' => null,
        'save_dc' => 14,
        'attack_modes' => [],
        'save_abilities' => ['wisdom'],
    ]);
});

it('builds exact modern Cleric Druid and Wizard long-rest swap sections for Mutt without swappable cantrips', function (): void {
    $spellList = app(PrintableSpellListBuilder::class)->build(muttCharacterId());
    $cleric = unpreparedSection($spellList, 'Cleric');
    $druid = unpreparedSection($spellList, 'Druid');
    $wizard = unpreparedSection($spellList, 'Wizard');

    expect(data_get($cleric, 'title'))->toBe('Cleric — not prepared (available to swap in on a long rest)')
        ->and(data_get($cleric, 'cantrip_note'))->toBe(
            'Unprepared cantrips are not listed because cantrips cannot be swapped on a long rest.',
        )
        ->and(collect(data_get($cleric, 'spells'))->pluck('name')->all())->toBe([
            'Bane', 'Bless', 'Command', 'Detect Evil and Good', 'Detect Magic',
            'Detect Poison and Disease', 'Guiding Bolt', 'Inflict Wounds',
            'Protection from Evil and Good', 'Purify Food and Drink', 'Shield of Faith', 'Wardaway',
        ])
        ->and(data_get($druid, 'title'))->toBe('Druid — not prepared (available to swap in on a long rest)')
        ->and(collect(data_get($druid, 'spells'))->pluck('name')->all())->toBe([
            'Animal Friendship', 'Buzzing Bee', 'Charm Person', 'Create or Destroy Water',
            'Cure Wounds', 'Detect Magic', 'Detect Poison and Disease', 'Entangle',
            'Fog Cloud', 'Healing Word', 'Ice Knife', 'Longstrider',
            'Protection from Evil and Good', 'Purify Food and Drink', 'Thunderwave',
        ])
        ->and(data_get($wizard, 'title'))->toBe('Wizard — not prepared (available to swap in on a long rest)')
        ->and(collect(data_get($wizard, 'spells'))->pluck('name')->all())->toBe([
            'Alarm', 'Burning Hands', 'Buzzing Bee', 'Charm Person', 'Chromatic Orb',
            'Color Spray', 'Comprehend Languages', 'Detect Magic', 'Disguise Self',
            'Expeditious Retreat', 'False Life', 'Fog Cloud', 'Grease', 'Ice Knife',
            'Identify', 'Illusory Script', 'Jump', 'Longstrider', 'Mage Armor',
            'Magic Missile', 'Protection from Evil and Good', 'Ray of Sickness',
            'Silent Image', 'Sleep', 'Spellfire Flare', "Tasha's Hideous Laughter",
            "Tenser's Floating Disk", 'Thunderwave', 'Wardaway', 'Witch Bolt',
        ]);

    expect(collect(data_get($cleric, 'spells'))->pluck('name'))->not->toContain(
        'Create or Destroy Water', 'Cure Wounds', 'Healing Word', 'Sanctuary', 'Guidance',
    )->and(collect(data_get($druid, 'spells'))->pluck('name'))->not->toContain(
        'Goodberry', 'Jump', 'Speak with Animals', 'Shillelagh', 'Poison Spray',
    )->and(collect(data_get($wizard, 'spells'))->pluck('name'))->not->toContain(
        'Feather Fall', 'Find Familiar', 'Shield', 'Unseen Servant', 'Mage Hand',
    )->and(collect(data_get($cleric, 'spells'))->pluck('level')->unique()->all())->toBe([1])
        ->and(collect(data_get($druid, 'spells'))->pluck('level')->unique()->all())->toBe([1])
        ->and(collect(data_get($wizard, 'spells'))->pluck('level')->unique()->all())->toBe([1]);
});

it('preserves Wizard spellbook prepared and ritual-only states and explanation in print', function (): void {
    $spellList = app(PrintableSpellListBuilder::class)->build(muttCharacterId());

    expect(collect(data_get($spellList, 'wizard.spellbook'))->pluck('spell_name')->all())->toBe([
        'Comprehend Languages', 'Feather Fall', 'Find Familiar',
        'Shield', "Tenser's Floating Disk", 'Unseen Servant',
    ])->and(collect(data_get($spellList, 'wizard.prepared'))->pluck('spell_name')->all())->toBe([
        'Feather Fall', 'Find Familiar', 'Shield', 'Unseen Servant',
    ])->and(collect(data_get($spellList, 'wizard.ritual_only'))->pluck('spell_name')->all())->toBe([
        'Comprehend Languages', "Tenser's Floating Disk",
    ])->and(data_get($spellList, 'wizard.explanation'))->toContain(
        '“In my book” marks only the spells that Ritual Adept can expose',
        'does not constrain Wizard preparation',
        'not the same as labeling a spell known or prepared',
        'whole Wizard spell list',
        'both in the book and as prepared',
        'ritual-only access',
        'that route is not a selection',
        'consumes no preparation capacity',
        'ignored by duplicate-waste checks',
        'Unprepared non-ritual book spells are not castable.',
    );
});

it('serves both print variants and degrades full mode when Tier 2 is absent', function (): void {
    $id = muttCharacterId();

    $this->get("/characters/{$id}/print")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Characters/Print')
            ->where('spellList.variant', 'reference')
            ->where('spellList.text_status', 'not_requested')
            ->has('spellList.source_groups', 6)
            ->where('spellList.unprepared_sections.0.spells', fn ($spells) => count($spells) === 12)
            ->where('spellList.unprepared_sections.1.spells', fn ($spells) => count($spells) === 15)
            ->where('spellList.unprepared_sections.2.spells', fn ($spells) => count($spells) === 30)
        );
    $this->get("/characters/{$id}/print?variant=full")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Characters/Print')
            ->where('spellList.variant', 'full')
            ->where('spellList.text_status', 'unavailable')
            ->where('spellList.source_groups.0.spells.0.description', null)
        );
    $this->get("/characters/{$id}/print?variant=unknown")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('spellList.variant', 'reference'));
    $this->get('/characters/999999/print')->assertNotFound();
});

it('includes installed description text in full mode without leaking it into reference mode', function (): void {
    DB::table('spell_versions')->update(['short_summary' => 'Complete test-only rules text.']);
    $builder = app(PrintableSpellListBuilder::class);

    $reference = $builder->build(muttCharacterId());
    $full = $builder->build(muttCharacterId(), true);
    expect(data_get($reference, 'text_status'))->toBe('not_requested')
        ->and(collect(data_get($reference, 'source_groups'))->flatMap(
            static fn (array $group): array => data_get($group, 'spells'),
        )->pluck('description')->filter()->all())->toBe([])
        ->and(data_get($full, 'text_status'))->toBe('available')
        ->and(collect(data_get($full, 'source_groups'))->flatMap(
            static fn (array $group): array => data_get($group, 'spells'),
        )->pluck('description')->unique()->values()->all())->toBe(['Complete test-only rules text.'])
        ->and(collect(data_get($full, 'unprepared_sections'))->flatMap(
            static fn (array $section): array => data_get($section, 'spells'),
        )->pluck('description')->unique()->values()->all())->toBe(['Complete test-only rules text.']);
});
