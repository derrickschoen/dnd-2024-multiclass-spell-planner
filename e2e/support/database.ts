import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const projectRoot = fileURLToPath(new URL('../..', import.meta.url));

export type DatabaseRow = Record<string, number | string | null>;
export type PersistedCharacterState = Record<string, unknown>;

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

function phpDatabase<T>(action: string, argument?: string, characterId = 1): T {
    const args = ['e2e/support/database.php', action, String(characterId)];
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

export function slots(characterId = 1): SlotRow[] {
    return phpDatabase<SlotRow[]>('slots', undefined, characterId);
}

export function slotFixtures(characterId = 1): SlotFixture[] {
    return phpDatabase<SlotFixture[]>('slot-fixtures', undefined, characterId);
}

export function character(characterId = 1): DatabaseRow {
    return phpDatabase<DatabaseRow>('character', undefined, characterId);
}

export function auditLog(characterId = 1): DatabaseRow[] {
    return phpDatabase<DatabaseRow[]>('audit', undefined, characterId);
}

export function characterOperations(characterId = 1): DatabaseRow[] {
    return phpDatabase<DatabaseRow[]>('operations', undefined, characterId);
}

export function warningAcknowledgements(): DatabaseRow[] {
    return phpDatabase<DatabaseRow[]>('warning-acknowledgements');
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

export function source(displayName: string, characterId = 1): DatabaseRow {
    return phpDatabase<DatabaseRow>('source', displayName, characterId);
}

export function classLevel(className: string, characterId = 1): number {
    return phpDatabase<number>('class-level', className, characterId);
}

export function spellVersionId(contentKey: string): number {
    return phpDatabase<number>('spell-version-id', contentKey);
}

export function buildReport<T>(characterId = 1): T {
    return phpDatabase<T>('build-report', undefined, characterId);
}
