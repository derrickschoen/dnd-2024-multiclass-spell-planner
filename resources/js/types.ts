export interface SpellRoute {
    spell_identity_id: number;
    spell_name: string;
    spell_level: number;
    source_name: string;
    slot_id: number | null;
    spellcasting_ability: string | null;
    attack_bonus: number | null;
    save_dc: number | null;
}

export interface DuplicateAssessment {
    spell_identity_id: number;
    spell_name: string;
    category: string;
    sources: string[];
    slots: string[];
    explanation: string;
    warning_fingerprint: string | null;
    versions: Array<{
        spell_version_id: number;
        content_key: string;
        edition: string;
        label: string;
    }>;
    acknowledgement: { id: number; note: string; created_at: string } | null;
}

export interface WorkspaceSlot {
    id: number;
    slot_key: string;
    source: string;
    source_type: string;
    label: string;
    bucket: string;
    level_min: number;
    level_max: number;
    spell_id: number | null;
    spell_name: string | null;
    spell_level: number | null;
    ability: string | null;
    attack_bonus: number | null;
    save_dc: number | null;
    ritual: boolean;
    concentration: boolean;
    duplicate_status: string;
    state: string;
    eligibility: string;
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

export interface BuildReport {
    character: {
        id: number;
        name: string;
        character_level: number;
        proficiency_bonus: number;
        abilities: Record<string, number>;
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
        spellcasting_ability: string | null;
        max_preparable_level: number;
    }>;
    preparation_callout: string;
    access_routes: SpellRoute[];
    duplicate_assessments: DuplicateAssessment[];
    wizard: {
        spellbook: Array<{ spellbook_entry_id: number; spell_name: string; active: boolean }>;
        prepared: Array<{ spellbook_entry_id: number; spell_name: string; active: boolean }>;
        ritual_only: Array<{ spellbook_entry_id: number; spell_name: string }>;
        explanation: string;
    };
    invalid_selections: WorkspaceSlot[];
    summary: { unique_spells: number; access_routes: number; warning_count: number };
}

export interface Workspace {
    revision: number;
    report: BuildReport;
    classes: CharacterClass[];
    available_classes: ClassOption[];
    allow_legacy: boolean;
    configurable_sources: Array<{
        id: number;
        display_name: string;
        chosen_list: string;
        spellcasting_ability: string;
    }>;
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
    edition: string;
}
