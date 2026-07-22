<?php

declare(strict_types=1);

use App\Domain\Catalog\CatalogImporter;
use App\Domain\Characters\CharacterCommandExecutor;
use App\Domain\Characters\Commands\CharacterCommandIntegrity;
use App\Domain\Grants\GrantRuleSlotGenerator;
use App\Domain\Reports\BuildReportBuilder;
use App\Domain\Spells\SpellAccessBuilder;
use App\Domain\Spells\SpellSelectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function guardCharacter(string $name = 'Guard Character', bool $allowLegacy = false): int
{
    return DB::table('characters')->insertGetId([
        'name' => $name,
        'allow_legacy' => $allowLegacy,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/** @param list<string> $lists @param list<string> $tags */
function guardSpell(
    string $contentKey,
    string $name,
    int $level = 0,
    array $lists = [],
    array $tags = [],
    bool $active = true,
): int {
    $identityId = DB::table('spell_identities')->insertGetId([
        'content_key' => Str::after($contentKey, ':'),
        'canonical_name' => $name,
        'normalized_name' => Str::lower($name),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $versionId = DB::table('spell_versions')->insertGetId([
        'content_key' => $contentKey,
        'spell_identity_id' => $identityId,
        'display_name' => $name,
        'rules_edition' => Str::before($contentKey, ':'),
        'level' => $level,
        'school' => 'Evocation',
        'ritual' => in_array('ritual', $tags, true),
        'is_active' => $active,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    foreach ($lists as $list) {
        DB::table('spell_list_memberships')->insert([
            'spell_version_id' => $versionId,
            'spell_list_key' => $list,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
    foreach ($tags as $tag) {
        DB::table('spell_version_tags')->insert([
            'spell_version_id' => $versionId,
            'tag' => $tag,
        ]);
    }

    return $versionId;
}

/** @param list<array<string, mixed>> $rules */
function guardFeat(string $contentKey, string $name, array $rules): int
{
    return DB::table('feat_definitions')->insertGetId([
        'content_key' => $contentKey,
        'name' => $name,
        'rules_edition' => '2024',
        'grant_rules' => json_encode($rules, JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/** @param array<string, mixed> $config */
function guardSource(int $characterId, string $type, int $definitionId, array $config = []): int
{
    return DB::table('character_source_instances')->insertGetId([
        'character_id' => $characterId,
        'instance_uuid' => Str::uuid()->toString(),
        'source_type' => $type,
        'source_definition_id' => $definitionId,
        'display_name' => Str::headline($type),
        'config' => json_encode($config, JSON_THROW_ON_ERROR),
        'state' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/** @param array<string, mixed> $command */
function guardExecute(int $characterId, int $revision, array $command): array
{
    return app(CharacterCommandExecutor::class)->execute(
        $characterId,
        $command,
        Str::uuid()->toString(),
        $revision,
    );
}

/** @param list<array<string, mixed>> $records */
function guardCatalogDirectory(array $records): string
{
    $directory = sys_get_temp_dir().'/guard-catalog-'.Str::uuid();
    mkdir($directory, 0777, true);
    file_put_contents($directory.'/catalog.json', json_encode($records, JSON_THROW_ON_ERROR));

    return $directory;
}

/** @return array<string, mixed> */
function guardCatalogRecord(): array
{
    return [
        'identityKey' => 'guard-imported-spell',
        'versionKey' => '2024:guard-imported-spell',
        'name' => 'Guard Imported Spell',
        'edition' => '2024',
        'level' => 1,
        'school' => 'Evocation',
        'castingTime' => 'Action',
        'range' => '60 feet',
        'components' => 'V, S',
        'duration' => 'Instantaneous',
        'concentration' => false,
        'ritual' => false,
        'attackModes' => [],
        'saveAbilities' => [],
        'effectReliabilityCategory' => 'fixed_effect',
        'spellLists' => ['Guard'],
        'sourceBooks' => ['Guard Book'],
    ];
}

it('G1 refuses a new inactive fixed grant but preserves and invalidates an existing materialization', function (): void {
    $characterId = guardCharacter();
    $spellId = guardSpell('2024:inactive-fixed', 'Inactive Fixed', active: false);
    $featId = guardFeat('2024:feat:inactive-fixed', 'Inactive Fixed Feat', [[
        'kind' => 'fixed_spell',
        'rule_key' => 'inactive-fixed',
        'bucket' => 'automatic',
        'spell_version_id' => $spellId,
    ]]);
    $sourceId = guardSource($characterId, 'feat', $featId);
    $generator = app(GrantRuleSlotGenerator::class);

    expect(fn () => $generator->generateForSource($sourceId))
        ->toThrow(RuntimeException::class, "Grant rule 'inactive-fixed' references an inactive spell version.");
    expect(DB::table('spell_selection_slots')->count())->toBe(0);

    DB::table('spell_versions')->where('id', $spellId)->update(['is_active' => true]);
    $generator->generateForSource($sourceId);
    $slot = DB::table('spell_selection_slots')->sole();
    DB::table('spell_versions')->where('id', $spellId)->update(['is_active' => false]);
    $generator->generateForSource($sourceId);
    $preserved = DB::table('spell_selection_slots')->sole();

    expect((int) data_get($preserved, 'id'))->toBe((int) data_get($slot, 'id'))
        ->and((int) data_get($preserved, 'fixed_spell_version_id'))->toBe($spellId)
        ->and(data_get($preserved, 'selection_eligibility'))->toBe('invalid')
        ->and(data_get($preserved, 'selection_invalid_reason'))->toBe('Selected spell version is not active in the catalog.')
        ->and(app(SpellAccessBuilder::class)->buildForCharacter($characterId))->toBe([]);
});

it('G1 refuses a new inactive spellbook acquisition but retains an existing entry as unavailable', function (): void {
    $characterId = guardCharacter();
    $spellId = guardSpell('2024:inactive-book', 'Inactive Book Spell', 1, ['Wizard'], active: false);
    $featId = guardFeat('2024:feat:inactive-book', 'Inactive Book Feat', [[
        'kind' => 'spellbook_acquisition',
        'rule_key' => 'inactive-book',
        'bucket' => 'spellbook',
        'list' => 'Wizard',
        'acquisitions_config' => 'book',
    ]]);
    $sourceId = guardSource($characterId, 'feat', $featId, [
        'book' => [['spell_version_id' => $spellId]],
    ]);
    $generator = app(GrantRuleSlotGenerator::class);

    expect(fn () => $generator->generateForSource($sourceId))
        ->toThrow(RuntimeException::class, "Spellbook rule 'inactive-book' acquisition 0 references an inactive spell version.");
    expect(DB::table('wizard_spellbook_entries')->count())->toBe(0);

    DB::table('spell_versions')->where('id', $spellId)->update(['is_active' => true]);
    $generator->generateForSource($sourceId);
    $entryId = (int) DB::table('wizard_spellbook_entries')->value('id');
    DB::table('spell_versions')->where('id', $spellId)->update(['is_active' => false]);
    $generator->generateForSource($sourceId);
    $book = data_get(app(BuildReportBuilder::class)->build($characterId), 'wizard.spellbook');

    expect(DB::table('wizard_spellbook_entries')->pluck('id')->all())->toBe([$entryId])
        ->and($book)->toHaveCount(1)
        ->and(data_get($book, '0.spell_version_id'))->toBe($spellId)
        ->and(data_get($book, '0.active'))->toBeFalse();
});

it('G1 excludes an inactive kept override from access even when cached eligibility says valid', function (): void {
    $characterId = guardCharacter();
    $spellId = guardSpell('2024:override-spell', 'Override Spell', 0, ['Guard']);
    $featId = guardFeat('2024:feat:override', 'Override Feat', [[
        'kind' => 'choice_from_list',
        'rule_key' => 'override-choice',
        'count' => 1,
        'bucket' => 'cantrip_known',
        'list' => 'Guard',
        'level_min' => 0,
        'level_max' => 0,
    ]]);
    $sourceId = guardSource($characterId, 'feat', $featId);
    app(GrantRuleSlotGenerator::class)->generateForSource($sourceId);
    $slotId = (int) DB::table('spell_selection_slots')->value('id');
    app(SpellSelectionService::class)->select($slotId, $spellId);
    DB::table('spell_selection_slots')->where('id', $slotId)->update([
        'state' => 'kept_override',
        'override_note' => 'Intentional override.',
    ]);
    expect(app(SpellAccessBuilder::class)->buildForCharacter($characterId))->toHaveCount(1);

    DB::table('spell_versions')->where('id', $spellId)->update(['is_active' => false]);

    expect(DB::table('spell_selection_slots')->where('id', $slotId)->value('selection_eligibility'))->toBe('valid')
        ->and(app(SpellAccessBuilder::class)->buildForCharacter($characterId))->toBe([]);
});

it('G1 excludes an inactive unprepared ritual spellbook entry from capability access', function (): void {
    $characterId = guardCharacter();
    $spellId = guardSpell('2024:inactive-ritual', 'Inactive Ritual', 1, ['Wizard'], ['ritual']);
    $featId = guardFeat('2024:feat:ritual-access', 'Ritual Access', [[
        'kind' => 'capability',
        'rule_key' => 'ritual-access',
        'capability_key' => 'ritual-access',
        'collection' => 'wizard_spellbook',
        'tags' => ['ritual'],
        'access_mode' => 'ritual_only',
    ]]);
    $sourceId = guardSource($characterId, 'feat', $featId, ['spellcasting_ability' => 'intelligence']);
    DB::table('wizard_spellbook_entries')->insert([
        'character_id' => $characterId,
        'spell_version_id' => $spellId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    expect(app(SpellAccessBuilder::class)->buildForCharacter($characterId))->toHaveCount(1);

    DB::table('spell_versions')->where('id', $spellId)->update(['is_active' => false]);

    expect(app(SpellAccessBuilder::class)->buildForCharacter($characterId))->toBe([]);
});

it('G3 catalog removal tombstones a selected version, preserves and invalidates references, and revalidates on return', function (): void {
    $directory = guardCatalogDirectory([guardCatalogRecord()]);
    $importer = app(CatalogImporter::class);
    $importer->importDirectory($directory);
    $spellId = (int) DB::table('spell_versions')->value('id');
    $characterId = guardCharacter();
    $featId = guardFeat('2024:feat:import-choice', 'Import Choice', [[
        'kind' => 'choice_from_list',
        'rule_key' => 'import-choice',
        'count' => 1,
        'bucket' => 'known',
        'list' => 'Guard',
        'level_min' => 1,
        'level_max' => 1,
    ]]);
    $sourceId = guardSource($characterId, 'feat', $featId);
    app(GrantRuleSlotGenerator::class)->generateForSource($sourceId);
    $slotId = (int) DB::table('spell_selection_slots')->value('id');
    app(SpellSelectionService::class)->select($slotId, $spellId);
    DB::table('wizard_spellbook_entries')->insert([
        'character_id' => $characterId,
        'spell_version_id' => $spellId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    expect(app(SpellAccessBuilder::class)->buildForCharacter($characterId))->toHaveCount(1);

    file_put_contents($directory.'/catalog.json', '[]');
    $removed = $importer->importDirectory($directory);
    $invalid = DB::table('spell_selection_slots')->where('id', $slotId)->sole();
    $book = data_get(app(BuildReportBuilder::class)->build($characterId), 'wizard.spellbook.0');
    expect(data_get($removed, 'tombstoned'))->toBe(1)
        ->and((bool) DB::table('spell_versions')->where('id', $spellId)->value('is_active'))->toBeFalse()
        ->and((int) data_get($invalid, 'current_spell_version_id'))->toBe($spellId)
        ->and(data_get($invalid, 'selection_eligibility'))->toBe('invalid')
        ->and(data_get($invalid, 'selection_invalid_reason'))->toBe('Selected spell version is not active in the catalog.')
        ->and(DB::table('wizard_spellbook_entries')->where('spell_version_id', $spellId)->exists())->toBeTrue()
        ->and(data_get($book, 'active'))->toBeFalse()
        ->and(app(SpellAccessBuilder::class)->buildForCharacter($characterId))->toBe([]);

    file_put_contents($directory.'/catalog.json', json_encode([guardCatalogRecord()], JSON_THROW_ON_ERROR));
    $returned = $importer->importDirectory($directory);
    $valid = DB::table('spell_selection_slots')->where('id', $slotId)->sole();
    expect(data_get($returned, 'updated'))->toBe(1)
        ->and((int) data_get($valid, 'current_spell_version_id'))->toBe($spellId)
        ->and(data_get($valid, 'selection_eligibility'))->toBe('valid')
        ->and(data_get($valid, 'selection_invalid_reason'))->toBeNull()
        ->and(app(SpellAccessBuilder::class)->buildForCharacter($characterId))->toHaveCount(1);
});

it('G3 class level loss preserves a surplus choice as invalid and revalidates the identical slot on return', function (): void {
    $characterId = guardCharacter();
    $spellId = guardSpell('2024:level-guard', 'Level Guard', 1, ['Guard Mage']);
    $classId = DB::table('class_definitions')->insertGetId([
        'content_key' => '2024:class:guard-mage',
        'name' => 'Guard Mage',
        'rules_edition' => '2024',
        'spellcasting_ability' => 'intelligence',
        'progression_type' => 'full',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    foreach ([1 => [], 2 => [[
        'kind' => 'choice_from_list',
        'rule_key' => 'level-two-choice',
        'count' => 1,
        'bucket' => 'known',
        'list' => 'Guard Mage',
        'level_min' => 1,
        'level_max' => 1,
    ]]] as $level => $rules) {
        DB::table('class_progressions')->insert([
            'class_definition_id' => $classId,
            'class_level' => $level,
            'grant_rules' => json_encode($rules, JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    guardExecute($characterId, 0, [
        'type' => 'update_class',
        'class_definition_id' => $classId,
        'level' => 2,
        'subclass_definition_id' => null,
    ]);
    $slotId = (int) DB::table('spell_selection_slots')->value('id');
    app(SpellSelectionService::class)->select($slotId, $spellId);

    guardExecute($characterId, 1, [
        'type' => 'update_class',
        'class_definition_id' => $classId,
        'level' => 1,
        'subclass_definition_id' => null,
    ]);
    $orphan = DB::table('spell_selection_slots')->where('id', $slotId)->sole();
    expect((int) data_get($orphan, 'current_spell_version_id'))->toBe($spellId)
        ->and(data_get($orphan, 'state'))->toBe('orphaned')
        ->and(data_get($orphan, 'selection_eligibility'))->toBe('invalid')
        ->and(data_get($orphan, 'selection_invalid_reason'))->toBe('Selection preserved because its grant rule is no longer active.')
        ->and(app(SpellAccessBuilder::class)->buildForCharacter($characterId))->toBe([]);

    guardExecute($characterId, 2, [
        'type' => 'update_class',
        'class_definition_id' => $classId,
        'level' => 2,
        'subclass_definition_id' => null,
    ]);
    $restored = DB::table('spell_selection_slots')->where('id', $slotId)->sole();
    expect(data_get($restored, 'state'))->toBe('active')
        ->and((int) data_get($restored, 'current_spell_version_id'))->toBe($spellId)
        ->and(data_get($restored, 'selection_eligibility'))->toBe('valid')
        ->and(app(SpellAccessBuilder::class)->buildForCharacter($characterId))->toHaveCount(1);
});

it('G3 class level change revalidates a retained slot whose stable rule tightens eligibility', function (): void {
    $characterId = guardCharacter();
    $spellId = guardSpell('2024:retained-level-guard', 'Retained Level Guard', 2, ['Stable Mage']);
    $classId = DB::table('class_definitions')->insertGetId([
        'content_key' => '2024:class:stable-mage',
        'name' => 'Stable Mage',
        'rules_edition' => '2024',
        'spellcasting_ability' => 'intelligence',
        'progression_type' => 'full',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    foreach ([1 => 1, 3 => 2] as $level => $maximumSpellLevel) {
        DB::table('class_progressions')->insert([
            'class_definition_id' => $classId,
            'class_level' => $level,
            'grant_rules' => json_encode([[
                'kind' => 'choice_from_list',
                'rule_key' => 'stable-prepared-choice',
                'count' => 1,
                'bucket' => 'prepared',
                'list' => 'Stable Mage',
                'level_min' => 1,
                'level_max' => $maximumSpellLevel,
            ]], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    guardExecute($characterId, 0, [
        'type' => 'update_class',
        'class_definition_id' => $classId,
        'level' => 3,
        'subclass_definition_id' => null,
    ]);
    $slotId = (int) DB::table('spell_selection_slots')->value('id');
    app(SpellSelectionService::class)->select($slotId, $spellId);

    guardExecute($characterId, 1, [
        'type' => 'update_class',
        'class_definition_id' => $classId,
        'level' => 1,
        'subclass_definition_id' => null,
    ]);
    $tightened = DB::table('spell_selection_slots')->where('id', $slotId)->sole();
    expect(data_get($tightened, 'state'))->toBe('active')
        ->and((int) data_get($tightened, 'spell_level_max'))->toBe(1)
        ->and((int) data_get($tightened, 'current_spell_version_id'))->toBe($spellId)
        ->and(data_get($tightened, 'selection_eligibility'))->toBe('invalid')
        ->and(data_get($tightened, 'selection_invalid_reason'))->toBe('Selected spell is outside the slot level range.')
        ->and(app(SpellAccessBuilder::class)->buildForCharacter($characterId))->toBe([]);

    guardExecute($characterId, 2, [
        'type' => 'update_class',
        'class_definition_id' => $classId,
        'level' => 3,
        'subclass_definition_id' => null,
    ]);
    $restored = DB::table('spell_selection_slots')->where('id', $slotId)->sole();
    expect((int) data_get($restored, 'id'))->toBe($slotId)
        ->and((int) data_get($restored, 'current_spell_version_id'))->toBe($spellId)
        ->and((int) data_get($restored, 'spell_level_max'))->toBe(2)
        ->and(data_get($restored, 'selection_eligibility'))->toBe('valid')
        ->and(app(SpellAccessBuilder::class)->buildForCharacter($characterId))->toHaveCount(1);
});

it('G3 subclass replacement invalidates even a kept override and revalidates it when restored', function (): void {
    $characterId = guardCharacter();
    $spellId = guardSpell('2024:subclass-guard', 'Subclass Guard', 1, ['Guard Fighter']);
    $classId = DB::table('class_definitions')->insertGetId([
        'content_key' => '2024:class:guard-fighter',
        'name' => 'Guard Fighter',
        'rules_edition' => '2024',
        'progression_type' => 'none',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('class_progressions')->insert([
        'class_definition_id' => $classId,
        'class_level' => 3,
        'grant_rules' => '[]',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $firstSubclassId = DB::table('subclass_definitions')->insertGetId([
        'content_key' => '2024:subclass:first-guard',
        'class_definition_id' => $classId,
        'name' => 'First Guard',
        'rules_edition' => '2024',
        'grant_rules' => json_encode([[
            'kind' => 'choice_from_list',
            'rule_key' => 'subclass-choice',
            'count' => 1,
            'bucket' => 'known',
            'list' => 'Guard Fighter',
            'level_min' => 1,
            'level_max' => 1,
        ]], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $otherSubclassId = DB::table('subclass_definitions')->insertGetId([
        'content_key' => '2024:subclass:other-guard',
        'class_definition_id' => $classId,
        'name' => 'Other Guard',
        'rules_edition' => '2024',
        'grant_rules' => '[]',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    guardExecute($characterId, 0, [
        'type' => 'update_class',
        'class_definition_id' => $classId,
        'level' => 3,
        'subclass_definition_id' => $firstSubclassId,
    ]);
    $slotId = (int) DB::table('spell_selection_slots')->value('id');
    app(SpellSelectionService::class)->select($slotId, $spellId);
    DB::table('spell_selection_slots')->where('id', $slotId)->update([
        'state' => 'kept_override',
        'override_note' => 'Allowed only while this subclass remains selected.',
    ]);

    guardExecute($characterId, 1, [
        'type' => 'update_class',
        'class_definition_id' => $classId,
        'level' => 3,
        'subclass_definition_id' => $otherSubclassId,
    ]);
    $orphan = DB::table('spell_selection_slots')->where('id', $slotId)->sole();
    expect((int) data_get($orphan, 'current_spell_version_id'))->toBe($spellId)
        ->and(data_get($orphan, 'state'))->toBe('orphaned')
        ->and(data_get($orphan, 'selection_eligibility'))->toBe('invalid')
        ->and(data_get($orphan, 'selection_invalid_reason'))->toBe('Selection preserved because its source is no longer active.')
        ->and(app(SpellAccessBuilder::class)->buildForCharacter($characterId))->toBe([]);

    guardExecute($characterId, 2, [
        'type' => 'update_class',
        'class_definition_id' => $classId,
        'level' => 3,
        'subclass_definition_id' => $firstSubclassId,
    ]);
    $restored = DB::table('spell_selection_slots')->where('id', $slotId)->sole();
    expect(data_get($restored, 'state'))->toBe('active')
        ->and((int) data_get($restored, 'current_spell_version_id'))->toBe($spellId)
        ->and(data_get($restored, 'selection_eligibility'))->toBe('valid')
        ->and(app(SpellAccessBuilder::class)->buildForCharacter($characterId))->toHaveCount(1);
});

it('G3 disabling legacy rules preserves the selected version but invalidates and removes its route', function (): void {
    $characterId = guardCharacter(allowLegacy: true);
    $spellId = guardSpell('2014:legacy-guard', 'Legacy Guard', 0, ['Guard']);
    $featId = guardFeat('2024:feat:legacy-choice', 'Legacy Choice', [[
        'kind' => 'choice_from_list',
        'rule_key' => 'legacy-choice',
        'count' => 1,
        'bucket' => 'cantrip_known',
        'list' => 'Guard',
        'level_min' => 0,
        'level_max' => 0,
    ]]);
    $sourceId = guardSource($characterId, 'feat', $featId);
    app(GrantRuleSlotGenerator::class)->generateForSource($sourceId);
    $slotId = (int) DB::table('spell_selection_slots')->value('id');
    app(SpellSelectionService::class)->select($slotId, $spellId);
    expect(app(SpellAccessBuilder::class)->buildForCharacter($characterId))->toHaveCount(1);

    guardExecute($characterId, 0, [
        'type' => 'update_character_rules',
        'allow_legacy' => false,
    ]);
    $slot = DB::table('spell_selection_slots')->where('id', $slotId)->sole();
    expect((int) data_get($slot, 'current_spell_version_id'))->toBe($spellId)
        ->and(data_get($slot, 'selection_eligibility'))->toBe('invalid')
        ->and(data_get($slot, 'selection_invalid_reason'))->toBe('Enable legacy rules before selecting a 2014 spell version.')
        ->and(app(SpellAccessBuilder::class)->buildForCharacter($characterId))->toBe([]);
});

it('G2 eligible spell lookup rejects a slot owned by another character before querying candidates', function (): void {
    $ownerId = guardCharacter('Slot Owner');
    $attackerId = guardCharacter('Other Character');
    $featId = guardFeat('2024:feat:owned-slot', 'Owned Slot', [[
        'kind' => 'choice_from_list',
        'rule_key' => 'owned-slot',
        'count' => 1,
        'bucket' => 'cantrip_known',
        'list' => 'Guard',
        'level_min' => 0,
        'level_max' => 0,
    ]]);
    $sourceId = guardSource($ownerId, 'feat', $featId);
    app(GrantRuleSlotGenerator::class)->generateForSource($sourceId);
    $slotId = (int) DB::table('spell_selection_slots')->value('id');

    $this->getJson("/characters/{$attackerId}/slots/{$slotId}/eligible-spells?q=guard")
        ->assertNotFound();
});

it('G2 rejects the removed spellbook selection constraint in grant rules', function (): void {
    $characterId = guardCharacter('Preparing Wizard');
    $featId = guardFeat('2024:feat:prepared-ownership', 'Prepared Ownership', [[
        'kind' => 'choice_from_list',
        'rule_key' => 'prepared-ownership',
        'count' => 1,
        'bucket' => 'prepared',
        'list' => 'Wizard',
        'level_min' => 1,
        'level_max' => 1,
        'selection_collection' => 'wizard_spellbook',
    ]]);
    $sourceId = guardSource($characterId, 'feat', $featId);

    expect(fn () => app(GrantRuleSlotGenerator::class)->generateForSource($sourceId))
        ->toThrow(
            InvalidArgumentException::class,
            "Grant rule 'prepared-ownership' may not constrain a selection collection.",
        );
});

it('G3 slot inverse restore revalidates against rules changed after the inverse was issued', function (): void {
    $characterId = guardCharacter(allowLegacy: true);
    $spellId = guardSpell('2014:stale-inverse', 'Stale Inverse', 0, ['Guard']);
    $featId = guardFeat('2024:feat:stale-inverse', 'Stale Inverse Choice', [[
        'kind' => 'choice_from_list',
        'rule_key' => 'stale-inverse',
        'count' => 1,
        'bucket' => 'cantrip_known',
        'list' => 'Guard',
        'level_min' => 0,
        'level_max' => 0,
    ]]);
    $sourceId = guardSource($characterId, 'feat', $featId);
    app(GrantRuleSlotGenerator::class)->generateForSource($sourceId);
    $slotId = (int) DB::table('spell_selection_slots')->value('id');
    app(SpellSelectionService::class)->select($slotId, $spellId);

    $cleared = guardExecute($characterId, 0, [
        'type' => 'set_slot',
        'slot_id' => $slotId,
        'mode' => 'clear',
    ]);
    guardExecute($characterId, 1, [
        'type' => 'update_character_rules',
        'allow_legacy' => false,
    ]);
    guardExecute($characterId, 2, data_get($cleared, 'inverse'));

    $restored = DB::table('spell_selection_slots')->where('id', $slotId)->sole();
    expect((int) data_get($restored, 'current_spell_version_id'))->toBe($spellId)
        ->and(data_get($restored, 'selection_eligibility'))->toBe('invalid')
        ->and(data_get($restored, 'selection_invalid_reason'))
        ->toBe('Enable legacy rules before selecting a 2014 spell version.')
        ->and(app(SpellAccessBuilder::class)->buildForCharacter($characterId))->toBe([]);
});

it('G3 slot inverse restore cannot resurrect a kept override after its source is removed', function (): void {
    $characterId = guardCharacter();
    $spellId = guardSpell('2024:removed-override-inverse', 'Removed Override Inverse', 0, ['Guard']);
    $featId = guardFeat('2024:feat:removed-override-inverse', 'Removed Override Inverse Feat', [[
        'kind' => 'choice_from_list',
        'rule_key' => 'removed-override-inverse',
        'count' => 1,
        'bucket' => 'cantrip_known',
        'list' => 'Guard',
        'level_min' => 0,
        'level_max' => 0,
    ]]);
    $sourceId = guardSource($characterId, 'feat', $featId);
    $generator = app(GrantRuleSlotGenerator::class);
    $generator->generateForSource($sourceId);
    $slotId = (int) DB::table('spell_selection_slots')->value('id');
    app(SpellSelectionService::class)->select($slotId, $spellId);
    guardExecute($characterId, 0, [
        'type' => 'set_slot',
        'slot_id' => $slotId,
        'mode' => 'keep_override',
        'note' => 'Intentional before source removal.',
    ]);
    $cleared = guardExecute($characterId, 1, [
        'type' => 'set_slot',
        'slot_id' => $slotId,
        'mode' => 'clear',
    ]);

    DB::table('character_source_instances')->where('id', $sourceId)->update(['state' => 'tombstoned']);
    $generator->generateForSource($sourceId);
    guardExecute($characterId, 2, data_get($cleared, 'inverse'));

    $restored = DB::table('spell_selection_slots')->where('id', $slotId)->sole();
    expect((int) data_get($restored, 'current_spell_version_id'))->toBe($spellId)
        ->and(data_get($restored, 'state'))->toBe('orphaned')
        ->and(data_get($restored, 'selection_eligibility'))->toBe('invalid')
        ->and(data_get($restored, 'selection_invalid_reason'))
        ->toBe('Selection preserved because its source is no longer active.')
        ->and(app(SpellAccessBuilder::class)->buildForCharacter($characterId))->toBe([]);
});

it('G2 operation UUID replay cannot cross the character boundary', function (): void {
    $ownerId = guardCharacter('Operation Owner');
    $otherId = guardCharacter('Other Operation Character');
    $operationUuid = Str::uuid()->toString();
    app(CharacterCommandExecutor::class)->execute($ownerId, [
        'type' => 'update_ability',
        'ability' => 'wisdom',
        'score' => 18,
    ], $operationUuid, 0);

    $before = (array) DB::table('characters')->where('id', $otherId)->sole();
    $this->postJson("/characters/{$otherId}/mutations", [
        'operation_uuid' => $operationUuid,
        'expected_revision' => 0,
        'command' => ['type' => 'update_ability', 'ability' => 'charisma', 'score' => 20],
    ])->assertConflict()->assertJsonPath('current_revision', 0);

    expect((array) DB::table('characters')->where('id', $otherId)->sole())->toBe($before)
        ->and(DB::table('character_operations')->where('operation_uuid', $operationUuid)->count())->toBe(1);
});

it('G2 warning delete lookup cannot consume another character’s matching fingerprint', function (): void {
    $characterId = guardCharacter('Acknowledgement Deleter');
    $otherCharacterId = guardCharacter('Acknowledgement Owner');
    $fingerprint = 'conflicting_versions:shared-fingerprint';
    DB::table('warning_acknowledgements')->insert([
        'character_id' => $otherCharacterId,
        'warning_fingerprint' => $fingerprint,
        'note' => 'Other character acknowledgement.',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $command = app(CharacterCommandIntegrity::class)->attach($characterId, [
        'type' => 'acknowledge_warning',
        'mode' => 'delete',
        'warning_fingerprint' => $fingerprint,
    ]);

    expect(fn () => guardExecute($characterId, 0, $command))
        ->toThrow(InvalidArgumentException::class, 'Warning acknowledgement does not belong to this character.');
    expect(DB::table('warning_acknowledgements')
        ->where('character_id', $otherCharacterId)
        ->where('warning_fingerprint', $fingerprint)
        ->exists())->toBeTrue();
});

it('G1 direct selection service rejects an inactive candidate', function (): void {
    $characterId = guardCharacter();
    $spellId = guardSpell('2024:inactive-direct', 'Inactive Direct', 0, ['Guard'], active: false);
    $featId = guardFeat('2024:feat:inactive-direct', 'Inactive Direct Choice', [[
        'kind' => 'choice_from_list',
        'rule_key' => 'inactive-direct',
        'count' => 1,
        'bucket' => 'cantrip_known',
        'list' => 'Guard',
        'level_min' => 0,
        'level_max' => 0,
    ]]);
    $sourceId = guardSource($characterId, 'feat', $featId);
    app(GrantRuleSlotGenerator::class)->generateForSource($sourceId);
    $slotId = (int) DB::table('spell_selection_slots')->value('id');

    expect(fn () => app(SpellSelectionService::class)->select($slotId, $spellId))
        ->toThrow(InvalidArgumentException::class, 'Selected spell version is not active in the catalog.');
    expect(DB::table('spell_selection_slots')->where('id', $slotId)->value('current_spell_version_id'))->toBeNull();
});
