export type Ability = 'strength' | 'dexterity' | 'constitution' | 'intelligence' | 'wisdom' | 'charisma';
export type ProgressionType = 'full' | 'half_up' | 'half_down' | 'third_up' | 'third_down' | 'pact' | 'none';
export type RulesEdition = '2014' | '2024' | 'expanded';
export type SlotBucket = 'cantrip_known' | 'prepared' | 'known' | 'spellbook' | 'automatic';
export type DuplicateCategory = 'wasteful' | 'redundant_intentional' | 'conflicting_version' | 'none';
export type GrantRuleKind = 'fixed_spell' | 'choice_from_list' | 'choice_from_query' | 'grant_source' | 'capability' | 'spellbook_acquisition';
export type SlotState = 'active' | 'orphaned' | 'discarded' | 'kept_override';
export type SelectionEligibility = 'valid' | 'invalid' | 'unselected';
export type CastingMode = 'at_will' | 'slots_and_free_cast' | 'with_slots' | 'free_cast_only' | 'granted' | 'ritual_only' | 'available_on_long_rest';
export type DomainSourceType = 'class' | 'subclass' | 'feat' | 'species' | 'background';
export type SourceType = 'feat' | 'species' | 'background';
export type FreeCastRecovery = 'long_rest' | 'short_rest' | 'dawn' | 'at_will';
export type FreeCastPoolScope = 'per_spell' | 'shared';
export type EffectReliabilityCategory = 'attack_roll' | 'saving_throw' | 'fixed_effect' | 'modifier_scaled' | 'ritual_utility' | 'mixed';

export interface FreeCast {
    uses: number;
    recovery: FreeCastRecovery;
    pool_scope: FreeCastPoolScope;
}

interface GrantRuleBase {
    rule_key: string;
    always_prepared: boolean;
    with_slots: boolean;
    free_cast: FreeCast | null;
    active_from_class_level?: number;
    active_if_config?: { key: string; equals: string };
    distinct_config_by?: string;
}

interface SlotGrantRule extends GrantRuleBase {
    bucket: SlotBucket;
    label?: string;
}

export type GrantRule =
    | (SlotGrantRule & { kind: 'fixed_spell'; count: 1; spell_version_id?: number; spell_version_key?: string })
    | (SlotGrantRule & { kind: 'choice_from_list'; count: number; list: string; level_min: number; level_max: number })
    | (SlotGrantRule & { kind: 'choice_from_query'; count: number; level_min: number; level_max: number; schools?: string[]; tags?: string[] })
    | (GrantRuleBase & { kind: 'grant_source'; count: number; source_type: DomainSourceType; source_definition_id?: number; source_definition_key?: string; definition_key_config?: string; child_config?: Record<string, unknown>; child_config_config?: string; bucket?: never })
    | (GrantRuleBase & { kind: 'capability'; capability_key: string; collection: string; access_mode: string; tags: string[]; count?: never; bucket?: never })
    | (GrantRuleBase & { kind: 'spellbook_acquisition'; list: string; acquisitions_config: string; count?: never; bucket?: never });

export interface SpellRoute {
    spell_identity_id: number;
    spell_version_id: number;
    spell_name: string;
    spell_level: number;
    source_name: string;
    slot_id: number | null;
    slot_key: string | null;
    casting_mode: CastingMode;
    spellcasting_ability: Ability | null;
    attack_bonus: number | null;
    save_dc: number | null;
}

export interface DuplicateAssessment {
    spell_identity_id: number;
    spell_name: string;
    category: DuplicateCategory;
    sources: string[];
    slots: string[];
    explanation: string;
    warning_fingerprint: string | null;
    versions: Array<{ spell_version_id: number; content_key: string; edition: RulesEdition; label: string }>;
    acknowledgement: { id: number; note: string; created_at: string } | null;
}

export interface WorkspaceSlot {
    id: number;
    slot_key: string;
    source: string;
    source_type: DomainSourceType;
    label: string;
    bucket: SlotBucket;
    level_min: number;
    level_max: number;
    spell_id: number | null;
    spell_name: string | null;
    spell_level: number | null;
    spell_edition: RulesEdition | null;
    ability: Ability | null;
    attack_bonus: number | null;
    save_dc: number | null;
    ritual: boolean;
    concentration: boolean;
    duplicate_status: DuplicateCategory;
    state: SlotState;
    eligibility: SelectionEligibility;
    invalid_reason: string | null;
    orphan_reason: string | null;
    override_note: string | null;
    locked: boolean;
}

export interface ClassOption { id: number; name: string }
export interface CharacterClass {
    id: number;
    class_definition_id: number;
    subclass_definition_id: number | null;
    level: number;
    name: string;
    subclass_name: string | null;
    subclasses: ClassOption[];
}

export interface SavePoint { id: number; label: string; created_at: string }
export interface SourceDefinition {
    id: number;
    content_key: string;
    name: string;
    repeatable: boolean;
    configuration_kind: 'magic_initiate' | 'origin_feat_magic_initiate' | 'none';
}
export interface RemovableSource {
    id: number;
    parent_source_instance_id: number | null;
    source_type: SourceType;
    source_definition_id: number;
    display_name: string;
}
interface OrderSourceBase { id: number; display_name: string }
export type OrderSource = OrderSourceBase & ({
    class_name: 'Cleric';
    order_name: 'Divine Order';
    chosen_option: 'Protector' | 'Thaumaturge' | null;
    options: Array<'Protector' | 'Thaumaturge'>;
    bonus_option: 'Thaumaturge';
} | {
    class_name: 'Druid';
    order_name: 'Primal Order';
    chosen_option: 'Warden' | 'Magician' | null;
    options: Array<'Warden' | 'Magician'>;
    bonus_option: 'Magician';
});

export interface BuildReport {
    character: {
        id: number;
        name: string;
        character_level: number;
        proficiency_bonus: number;
        abilities: Record<Ability, number>;
    };
    caster: {
        caster_level: number;
        slots: Array<{ level: number; count: number }>;
        pact_magic: { level: number; count: number } | null;
    };
    classes: Array<{
        name: string;
        subclass: string | null;
        class_level: number;
        spellcasting_ability: Ability | null;
        progression_type: ProgressionType;
        prepared_count: number;
        max_preparable_level: number;
    }>;
    preparation_callout: string;
    access_routes: SpellRoute[];
    duplicate_assessments: DuplicateAssessment[];
    wizard: {
        spellbook: Array<{ spellbook_entry_id: number; spell_name: string; active: boolean }>;
        prepared: Array<{ spell_version_id: number; spell_name: string }>;
        ritual_only: Array<{ spellbook_entry_id: number; spell_name: string }>;
        explanation: string;
    };
}

export interface WorkspaceBuildReport extends BuildReport {
    invalid_selections: WorkspaceSlot[];
    summary: { unique_spells: number; access_routes: number; warning_count: number };
}

export interface Workspace {
    revision: number;
    report: WorkspaceBuildReport;
    classes: CharacterClass[];
    available_classes: ClassOption[];
    allow_legacy: boolean;
    configurable_sources: Array<{ id: number; display_name: string; chosen_list: string; spellcasting_ability: Ability }>;
    order_sources: OrderSource[];
    source_catalog: Record<SourceType, SourceDefinition[]>;
    removable_sources: RemovableSource[];
    spell_lists: string[];
    slots: WorkspaceSlot[];
    save_points: SavePoint[];
}

export interface CharacterCommand {
    type: string;
    [key: string]: unknown;
}

export interface EligibleSpell {
    id: number;
    name: string;
    level: number;
    school: string;
    ritual: boolean;
    concentration: boolean;
    edition: RulesEdition;
}

export interface CharacterSummary { id: number; name: string; level: number; classes: string[]; warning_count: number }
