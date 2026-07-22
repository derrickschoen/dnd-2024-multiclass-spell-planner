import type {
    Ability,
    BuildReport,
    CastingMode,
    CharacterCommand,
    CharacterSummary,
    DomainSourceType,
    DuplicateCategory,
    EligibleSpell,
    ProgressionType,
    RulesEdition,
    SelectionEligibility,
    SlotBucket,
    SlotState,
    SourceType,
    Workspace,
} from '@/types';

const abilities = ['strength', 'dexterity', 'constitution', 'intelligence', 'wisdom', 'charisma'] as const satisfies readonly Ability[];
const progressions = ['full', 'half_up', 'half_down', 'third_up', 'third_down', 'pact', 'none'] as const satisfies readonly ProgressionType[];
const editions = ['2014', '2024', 'expanded'] as const satisfies readonly RulesEdition[];
const buckets = ['cantrip_known', 'prepared', 'known', 'spellbook', 'automatic'] as const satisfies readonly SlotBucket[];
const duplicateCategories = ['wasteful', 'redundant_intentional', 'conflicting_version', 'none'] as const satisfies readonly DuplicateCategory[];
const slotStates = ['active', 'orphaned', 'discarded', 'kept_override'] as const satisfies readonly SlotState[];
const eligibilities = ['valid', 'invalid', 'unselected'] as const satisfies readonly SelectionEligibility[];
const castingModes = ['at_will', 'slots_and_free_cast', 'with_slots', 'free_cast_only', 'granted', 'ritual_only', 'available_on_long_rest'] as const satisfies readonly CastingMode[];
const domainSourceTypes = ['class', 'subclass', 'feat', 'species', 'background'] as const satisfies readonly DomainSourceType[];
const sourceTypes = ['feat', 'species', 'background'] as const satisfies readonly SourceType[];

function invalid(path: string, expected: string): never {
    throw new TypeError(`Invalid server response at ${path}: expected ${expected}.`);
}

function object(value: unknown, path: string): asserts value is Record<string, unknown> {
    if (typeof value !== 'object' || value === null || Array.isArray(value)) invalid(path, 'an object');
}

function string(value: unknown, path: string): asserts value is string {
    if (typeof value !== 'string') invalid(path, 'a string');
}

function number(value: unknown, path: string): asserts value is number {
    if (typeof value !== 'number' || !Number.isFinite(value)) invalid(path, 'a finite number');
}

function boolean(value: unknown, path: string): asserts value is boolean {
    if (typeof value !== 'boolean') invalid(path, 'a boolean');
}

function nullable<T>(value: unknown, path: string, validate: (candidate: unknown, candidatePath: string) => asserts candidate is T): void {
    if (value !== null) validate(value, path);
}

function enumeration<T extends string>(value: unknown, values: readonly T[], path: string): asserts value is T {
    if (typeof value !== 'string' || !values.includes(value as T)) invalid(path, values.join(' | '));
}

function list(value: unknown, path: string, validate: (candidate: unknown, candidatePath: string) => void): asserts value is unknown[] {
    if (!Array.isArray(value)) invalid(path, 'a list');
    value.forEach((item, index) => validate(item, `${path}[${index}]`));
}

function stringList(value: unknown, path: string): asserts value is string[] {
    list(value, path, string);
}

function classOption(value: unknown, path: string): void {
    object(value, path);
    number(value.id, `${path}.id`);
    string(value.name, `${path}.name`);
}

function spellRoute(value: unknown, path: string): void {
    object(value, path);
    number(value.spell_identity_id, `${path}.spell_identity_id`);
    number(value.spell_version_id, `${path}.spell_version_id`);
    string(value.spell_name, `${path}.spell_name`);
    number(value.spell_level, `${path}.spell_level`);
    string(value.source_name, `${path}.source_name`);
    nullable(value.slot_id, `${path}.slot_id`, number);
    nullable(value.slot_key, `${path}.slot_key`, string);
    enumeration(value.casting_mode, castingModes, `${path}.casting_mode`);
    nullable(value.spellcasting_ability, `${path}.spellcasting_ability`, (item, itemPath): asserts item is Ability => enumeration(item, abilities, itemPath));
    nullable(value.attack_bonus, `${path}.attack_bonus`, number);
    nullable(value.save_dc, `${path}.save_dc`, number);
}

function duplicateAssessment(value: unknown, path: string): void {
    object(value, path);
    number(value.spell_identity_id, `${path}.spell_identity_id`);
    string(value.spell_name, `${path}.spell_name`);
    enumeration(value.category, duplicateCategories, `${path}.category`);
    stringList(value.sources, `${path}.sources`);
    stringList(value.slots, `${path}.slots`);
    string(value.explanation, `${path}.explanation`);
    nullable(value.warning_fingerprint, `${path}.warning_fingerprint`, string);
    list(value.versions, `${path}.versions`, (version, versionPath) => {
        object(version, versionPath);
        number(version.spell_version_id, `${versionPath}.spell_version_id`);
        string(version.content_key, `${versionPath}.content_key`);
        enumeration(version.edition, editions, `${versionPath}.edition`);
        string(version.label, `${versionPath}.label`);
    });
    if (value.acknowledgement !== null) {
        object(value.acknowledgement, `${path}.acknowledgement`);
        number(value.acknowledgement.id, `${path}.acknowledgement.id`);
        string(value.acknowledgement.note, `${path}.acknowledgement.note`);
        string(value.acknowledgement.created_at, `${path}.acknowledgement.created_at`);
    }
}

function workspaceSlot(value: unknown, path: string): void {
    object(value, path);
    number(value.id, `${path}.id`);
    string(value.slot_key, `${path}.slot_key`);
    string(value.source, `${path}.source`);
    enumeration(value.source_type, domainSourceTypes, `${path}.source_type`);
    string(value.label, `${path}.label`);
    enumeration(value.bucket, buckets, `${path}.bucket`);
    number(value.level_min, `${path}.level_min`);
    number(value.level_max, `${path}.level_max`);
    nullable(value.spell_id, `${path}.spell_id`, number);
    nullable(value.spell_name, `${path}.spell_name`, string);
    nullable(value.spell_level, `${path}.spell_level`, number);
    nullable(value.spell_edition, `${path}.spell_edition`, (item, itemPath): asserts item is RulesEdition => enumeration(item, editions, itemPath));
    nullable(value.ability, `${path}.ability`, (item, itemPath): asserts item is Ability => enumeration(item, abilities, itemPath));
    nullable(value.attack_bonus, `${path}.attack_bonus`, number);
    nullable(value.save_dc, `${path}.save_dc`, number);
    boolean(value.ritual, `${path}.ritual`);
    boolean(value.concentration, `${path}.concentration`);
    enumeration(value.duplicate_status, duplicateCategories, `${path}.duplicate_status`);
    enumeration(value.state, slotStates, `${path}.state`);
    enumeration(value.eligibility, eligibilities, `${path}.eligibility`);
    nullable(value.invalid_reason, `${path}.invalid_reason`, string);
    nullable(value.orphan_reason, `${path}.orphan_reason`, string);
    nullable(value.override_note, `${path}.override_note`, string);
    boolean(value.locked, `${path}.locked`);
}

function buildReport(value: unknown, path: string): asserts value is BuildReport {
    object(value, path);
    const character = value.character;
    object(character, `${path}.character`);
    number(character.id, `${path}.character.id`);
    string(character.name, `${path}.character.name`);
    number(character.character_level, `${path}.character.character_level`);
    number(character.proficiency_bonus, `${path}.character.proficiency_bonus`);
    const abilityScores = character.abilities;
    object(abilityScores, `${path}.character.abilities`);
    abilities.forEach((ability) => number(abilityScores[ability], `${path}.character.abilities.${ability}`));
    object(value.caster, `${path}.caster`);
    number(value.caster.caster_level, `${path}.caster.caster_level`);
    list(value.caster.slots, `${path}.caster.slots`, (slot, slotPath) => {
        object(slot, slotPath); number(slot.level, `${slotPath}.level`); number(slot.count, `${slotPath}.count`);
    });
    if (value.caster.pact_magic !== null) {
        object(value.caster.pact_magic, `${path}.caster.pact_magic`);
        number(value.caster.pact_magic.level, `${path}.caster.pact_magic.level`);
        number(value.caster.pact_magic.count, `${path}.caster.pact_magic.count`);
    }
    list(value.classes, `${path}.classes`, (item, itemPath) => {
        object(item, itemPath);
        string(item.name, `${itemPath}.name`);
        nullable(item.subclass, `${itemPath}.subclass`, string);
        number(item.class_level, `${itemPath}.class_level`);
        enumeration(item.progression_type, progressions, `${itemPath}.progression_type`);
        nullable(item.spellcasting_ability, `${itemPath}.spellcasting_ability`, (ability, abilityPath): asserts ability is Ability => enumeration(ability, abilities, abilityPath));
        number(item.prepared_count, `${itemPath}.prepared_count`);
        number(item.max_preparable_level, `${itemPath}.max_preparable_level`);
    });
    string(value.preparation_callout, `${path}.preparation_callout`);
    list(value.access_routes, `${path}.access_routes`, spellRoute);
    list(value.duplicate_assessments, `${path}.duplicate_assessments`, duplicateAssessment);
    object(value.wizard, `${path}.wizard`);
    list(value.wizard.spellbook, `${path}.wizard.spellbook`, wizardEntry);
    list(value.wizard.prepared, `${path}.wizard.prepared`, wizardPreparedEntry);
    list(value.wizard.ritual_only, `${path}.wizard.ritual_only`, wizardEntry);
    string(value.wizard.explanation, `${path}.wizard.explanation`);
}

function wizardEntry(value: unknown, path: string): void {
    object(value, path);
    number(value.spellbook_entry_id, `${path}.spellbook_entry_id`);
    string(value.spell_name, `${path}.spell_name`);
    if (value.active !== undefined) boolean(value.active, `${path}.active`);
}

function wizardPreparedEntry(value: unknown, path: string): void {
    object(value, path);
    number(value.spell_version_id, `${path}.spell_version_id`);
    string(value.spell_name, `${path}.spell_name`);
}

function printableSpell(value: unknown, path: string): void {
    object(value, path);
    number(value.spell_version_id, `${path}.spell_version_id`);
    number(value.spell_identity_id, `${path}.spell_identity_id`);
    string(value.name, `${path}.name`);
    enumeration(value.edition, editions, `${path}.edition`);
    number(value.level, `${path}.level`);
    string(value.school, `${path}.school`);
    nullable(value.casting_time, `${path}.casting_time`, string);
    nullable(value.action_type, `${path}.action_type`, string);
    nullable(value.range, `${path}.range`, string);
    nullable(value.duration, `${path}.duration`, string);
    boolean(value.concentration, `${path}.concentration`);
    boolean(value.ritual, `${path}.ritual`);
    nullable(value.components, `${path}.components`, string);
    stringList(value.attack_modes, `${path}.attack_modes`);
    stringList(value.save_abilities, `${path}.save_abilities`);
    enumeration(value.casting_mode, castingModes, `${path}.casting_mode`);
    nullable(value.spellcasting_ability, `${path}.spellcasting_ability`, (item, itemPath): asserts item is Ability => enumeration(item, abilities, itemPath));
    nullable(value.attack_bonus, `${path}.attack_bonus`, number);
    nullable(value.save_dc, `${path}.save_dc`, number);
    nullable(value.description, `${path}.description`, string);
}

function printableSpellList(value: unknown, path: string): void {
    object(value, path);
    enumeration(value.variant, ['reference', 'full'] as const, `${path}.variant`);
    enumeration(value.text_status, ['not_requested', 'unavailable', 'partial', 'available'] as const, `${path}.text_status`);
    object(value.character, `${path}.character`);
    number(value.character.id, `${path}.character.id`);
    string(value.character.name, `${path}.character.name`);
    number(value.character.character_level, `${path}.character.character_level`);
    number(value.character.proficiency_bonus, `${path}.character.proficiency_bonus`);
    list(value.source_groups, `${path}.source_groups`, (group, groupPath) => {
        object(group, groupPath); string(group.source, `${groupPath}.source`);
        nullable(group.ability, `${groupPath}.ability`, (item, itemPath): asserts item is Ability => enumeration(item, abilities, itemPath));
        nullable(group.attack_bonus, `${groupPath}.attack_bonus`, number); nullable(group.save_dc, `${groupPath}.save_dc`, number);
        list(group.spells, `${groupPath}.spells`, printableSpell);
    });
    list(value.unprepared_sections, `${path}.unprepared_sections`, (section, sectionPath) => {
        object(section, sectionPath); string(section.class_name, `${sectionPath}.class_name`); string(section.title, `${sectionPath}.title`);
        nullable(section.ability, `${sectionPath}.ability`, (item, itemPath): asserts item is Ability => enumeration(item, abilities, itemPath));
        number(section.max_level, `${sectionPath}.max_level`); string(section.cantrip_note, `${sectionPath}.cantrip_note`);
        list(section.spells, `${sectionPath}.spells`, printableSpell);
    });
    object(value.wizard, `${path}.wizard`);
    list(value.wizard.spellbook, `${path}.wizard.spellbook`, wizardEntry);
    list(value.wizard.prepared, `${path}.wizard.prepared`, wizardPreparedEntry);
    list(value.wizard.ritual_only, `${path}.wizard.ritual_only`, wizardEntry);
    string(value.wizard.explanation, `${path}.wizard.explanation`);
}

export function parseWorkspace(value: unknown, path = 'workspace'): Workspace {
    object(value, path);
    number(value.revision, `${path}.revision`);
    const reportValue: unknown = value.report;
    object(reportValue, `${path}.report`);
    const report = reportValue;
    buildReport(reportValue, `${path}.report`);
    list(report.invalid_selections, `${path}.report.invalid_selections`, workspaceSlot);
    object(report.summary, `${path}.report.summary`);
    number(report.summary.unique_spells, `${path}.report.summary.unique_spells`);
    number(report.summary.access_routes, `${path}.report.summary.access_routes`);
    number(report.summary.warning_count, `${path}.report.summary.warning_count`);
    list(value.classes, `${path}.classes`, (item, itemPath) => {
        object(item, itemPath);
        classOption(item, itemPath);
        number(item.class_definition_id, `${itemPath}.class_definition_id`);
        nullable(item.subclass_definition_id, `${itemPath}.subclass_definition_id`, number);
        number(item.level, `${itemPath}.level`);
        nullable(item.subclass_name, `${itemPath}.subclass_name`, string);
        list(item.subclasses, `${itemPath}.subclasses`, classOption);
    });
    list(value.available_classes, `${path}.available_classes`, classOption);
    boolean(value.allow_legacy, `${path}.allow_legacy`);
    list(value.configurable_sources, `${path}.configurable_sources`, (item, itemPath) => {
        object(item, itemPath); number(item.id, `${itemPath}.id`); string(item.display_name, `${itemPath}.display_name`);
        string(item.chosen_list, `${itemPath}.chosen_list`); enumeration(item.spellcasting_ability, abilities, `${itemPath}.spellcasting_ability`);
    });
    list(value.order_sources, `${path}.order_sources`, (item, itemPath) => {
        object(item, itemPath); number(item.id, `${itemPath}.id`); string(item.display_name, `${itemPath}.display_name`);
        enumeration(item.class_name, ['Cleric', 'Druid'] as const, `${itemPath}.class_name`);
        string(item.order_name, `${itemPath}.order_name`); nullable(item.chosen_option, `${itemPath}.chosen_option`, string);
        stringList(item.options, `${itemPath}.options`); string(item.bonus_option, `${itemPath}.bonus_option`);
    });
    list(value.removable_sources, `${path}.removable_sources`, (item, itemPath) => {
        object(item, itemPath); number(item.id, `${itemPath}.id`); nullable(item.parent_source_instance_id, `${itemPath}.parent_source_instance_id`, number);
        enumeration(item.source_type, sourceTypes, `${itemPath}.source_type`); number(item.source_definition_id, `${itemPath}.source_definition_id`);
        string(item.display_name, `${itemPath}.display_name`);
    });
    list(value.slots, `${path}.slots`, workspaceSlot);
    stringList(value.spell_lists, `${path}.spell_lists`);
    list(value.save_points, `${path}.save_points`, (item, itemPath) => {
        object(item, itemPath); number(item.id, `${itemPath}.id`); string(item.label, `${itemPath}.label`); string(item.created_at, `${itemPath}.created_at`);
    });
    const sourceCatalog = value.source_catalog;
    object(sourceCatalog, `${path}.source_catalog`);
    sourceTypes.forEach((sourceType) => list(sourceCatalog[sourceType], `${path}.source_catalog.${sourceType}`, (item, itemPath) => {
        classOption(item, itemPath);
        object(item, itemPath);
        string(item.content_key, `${itemPath}.content_key`); boolean(item.repeatable, `${itemPath}.repeatable`);
        enumeration(item.configuration_kind, ['magic_initiate', 'origin_feat_magic_initiate', 'none'] as const, `${itemPath}.configuration_kind`);
    }));

    return value as unknown as Workspace;
}

function command(value: unknown, path: string): asserts value is CharacterCommand {
    object(value, path);
    string(value.type, `${path}.type`);
}

export function parseMutationResponse(value: unknown): { inverse: CharacterCommand; revision: number; workspace: Workspace } {
    object(value, 'mutation response');
    command(value.inverse, 'mutation response.inverse');
    number(value.revision, 'mutation response.revision');
    const workspace = parseWorkspace(value.workspace, 'mutation response.workspace');
    return { inverse: value.inverse, revision: value.revision, workspace };
}

export function parseWorkspaceResponse(value: unknown): { workspace: Workspace } {
    object(value, 'workspace response');
    return { workspace: parseWorkspace(value.workspace, 'workspace response.workspace') };
}

export function parseCommandResponse(value: unknown): { command: CharacterCommand } {
    object(value, 'command response');
    command(value.command, 'command response.command');
    return { command: value.command };
}

function eligibleSpell(value: unknown, path: string): void {
    object(value, path);
    number(value.id, `${path}.id`); string(value.name, `${path}.name`); number(value.level, `${path}.level`);
    string(value.school, `${path}.school`); boolean(value.ritual, `${path}.ritual`); boolean(value.concentration, `${path}.concentration`);
    enumeration(value.edition, editions, `${path}.edition`);
}

export function parseEligibleSpellResponse(value: unknown): { spells: EligibleSpell[] } {
    object(value, 'eligible-spell response');
    list(value.spells, 'eligible-spell response.spells', eligibleSpell);
    return { spells: value.spells as EligibleSpell[] };
}

export function responseMessage(value: unknown, fallback: string): string {
    if (typeof value === 'object' && value !== null && 'message' in value && typeof value.message === 'string') return value.message;
    return fallback;
}

function characterSummary(value: unknown, path: string): void {
    object(value, path); number(value.id, `${path}.id`); string(value.name, `${path}.name`); number(value.level, `${path}.level`);
    stringList(value.classes, `${path}.classes`); number(value.warning_count, `${path}.warning_count`);
}

export function assertInertiaPage(value: unknown): void {
    object(value, 'Inertia page');
    string(value.component, 'Inertia page.component');
    object(value.props, 'Inertia page.props');
    switch (value.component) {
        case 'Characters/Workspace': parseWorkspace(value.props.workspace, 'Inertia page.props.workspace'); break;
        case 'Characters/Index': list(value.props.characters, 'Inertia page.props.characters', characterSummary); break;
        case 'BuildReport': buildReport(value.props.report, 'Inertia page.props.report'); break;
        case 'Characters/Print': printableSpellList(value.props.spellList, 'Inertia page.props.spellList'); break;
        case 'Health':
            string(value.props.laravel, 'Inertia page.props.laravel');
            string(value.props.php, 'Inertia page.props.php');
            string(value.props.journalMode, 'Inertia page.props.journalMode');
            number(value.props.foreignKeys, 'Inertia page.props.foreignKeys');
            break;
        default: invalid('Inertia page.component', 'a known page component');
    }
}
