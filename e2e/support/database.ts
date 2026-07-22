import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const projectRoot = fileURLToPath(new URL('../..', import.meta.url));

export type DatabaseRow = Record<string, number | string | null>;
export type PersistedCharacterState = Record<string, unknown>;

interface GrantRuleReconciliation {
    previous_grant_rules: string;
    source: DatabaseRow;
}

export interface SlotRow extends DatabaseRow {
    id: number;
    character_id: number;
    source_instance_id: number;
    slot_key: string;
    rule_key: string;
    ordinal: number;
    current_spell_version_id: number | null;
    state: string;
    created_at: string;
    updated_at: string;
}

export interface SlotFixture extends SlotRow {
    allowed_spell_lists: string | null;
    source_name: string;
    source_config: string | null;
    spell_name: string | null;
}

function phpDatabase<T>(action: string, argument?: string): T {
    const args = ['e2e/support/database.php', action, '1'];
    if (argument !== undefined) args.push(argument);
    const output = execFileSync('php', args, {
        cwd: projectRoot,
        encoding: 'utf8',
    });

    return JSON.parse(output) as T;
}

export function resetDatabase(): void {
    execFileSync('php', ['artisan', 'migrate:fresh', '--seed', '--no-interaction'], {
        cwd: projectRoot,
        stdio: 'pipe',
    });
}

export function slots(): SlotRow[] {
    return phpDatabase<SlotRow[]>('slots');
}

export function slotFixtures(): SlotFixture[] {
    return phpDatabase<SlotFixture[]>('slot-fixtures');
}

export function character(): DatabaseRow {
    return phpDatabase<DatabaseRow>('character');
}

export function auditLog(): DatabaseRow[] {
    return phpDatabase<DatabaseRow[]>('audit');
}

export function persistedCharacterState(): PersistedCharacterState {
    return phpDatabase<PersistedCharacterState>('persisted-character-state');
}

export function mutationFootprint(): PersistedCharacterState {
    return phpDatabase<PersistedCharacterState>('mutation-footprint');
}

export function savePointSnapshot(label: string): PersistedCharacterState {
    return phpDatabase<PersistedCharacterState>('save-point-snapshot', label);
}

export function source(displayName: string): DatabaseRow {
    return phpDatabase<DatabaseRow>('source', displayName);
}

export function classLevel(className: string): number {
    return phpDatabase<number>('class-level', className);
}

export function removeMagicInitiateWizardSource(): GrantRuleReconciliation {
    return phpDatabase<GrantRuleReconciliation>('remove-magic-initiate-wizard-source');
}

export function restoreMagicInitiateWizardSource(grantRules: string): GrantRuleReconciliation {
    return phpDatabase<GrantRuleReconciliation>('restore-magic-initiate-wizard-source', grantRules);
}

export function spellVersionId(contentKey: string): number {
    return phpDatabase<number>('spell-version-id', contentKey);
}
