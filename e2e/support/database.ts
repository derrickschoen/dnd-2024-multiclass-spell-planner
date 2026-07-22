import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const projectRoot = fileURLToPath(new URL('../..', import.meta.url));

export type DatabaseRow = Record<string, number | string | null>;

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

function phpDatabase<T>(action: string): T {
    const output = execFileSync('php', ['e2e/support/database.php', action, '1'], {
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
