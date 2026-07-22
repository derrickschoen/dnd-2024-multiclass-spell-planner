import { expect, test, type Locator, type Page } from '@playwright/test';
import {
    auditLog,
    character,
    resetDatabase,
    slotFixtures,
    slots,
    type SlotFixture,
} from './support/database';

interface CastingRow {
    slotId: number;
    source: string;
    label: string;
    ability: 'INT' | 'WIS' | 'CHA';
    numbers: string;
}

interface MutationBody {
    revision: number;
    workspace: {
        report: {
            duplicate_assessments: Array<{
                spell_name: string;
                category: string;
                explanation: string;
            }>;
        };
    };
}

test.beforeEach(async ({ page }) => {
    resetDatabase();
    await page.goto('/characters/1');
    await expect(page.getByRole('heading', { name: 'A6 Sixfold Spellcaster', level: 1 })).toBeVisible();
});

test('S1: editing one slot leaves every other database row byte-identical', async ({ page }) => {
    const before = slots();
    const target = requireSlot(
        slotFixtures().find((slot) => slot.rule_key === 'wizard-cantrips' && slot.ordinal === 2),
        'the second Wizard cantrip slot',
    );

    expect(target.current_spell_version_id).toBeNull();
    await selectSpell(page, target.id, 'Fire Bolt');

    const after = slots();
    const changedTarget = requireSlot(after.find((slot) => slot.id === target.id), 'the edited slot after save');
    expect(changedTarget.current_spell_version_id).not.toBeNull();

    expect(after.filter((slot) => slot.id !== target.id)).toEqual(
        before.filter((slot) => slot.id !== target.id),
    );
});

test('S2: duplicate category and explanation transition wasteful → none → wasteful', async ({ page }) => {
    const mageHandSlots = slotFixtures().filter((slot) => slot.spell_name === 'Mage Hand');
    expect(mageHandSlots).toHaveLength(2);

    const wizardSlot = requireSlot(
        mageHandSlots.find((slot) => slot.rule_key === 'wizard-cantrips'),
        'the Wizard Mage Hand slot',
    );
    const initiateSlot = requireSlot(
        mageHandSlots.find((slot) => slot.allowed_spell_lists === '["Wizard"]' && slot.rule_key === 'magic-initiate-cantrips'),
        'the Magic Initiate: Wizard Mage Hand slot',
    );
    const warning = duplicateWarning(page, 'Mage Hand');
    const explanation = 'Mage Hand consumes limits in more than one selection.';

    await expect(duplicateCell(page, wizardSlot.id)).toHaveText(/Wasteful/);
    await expect(duplicateCell(page, initiateSlot.id)).toHaveText(/Wasteful/);
    await expect(warning).toContainText(explanation);

    const uniqueResponse = await selectSpell(page, initiateSlot.id, 'Fire Bolt');
    const uniqueAssessment = uniqueResponse.workspace.report.duplicate_assessments
        .find((assessment) => assessment.spell_name === 'Mage Hand');

    expect(uniqueAssessment).toEqual(expect.objectContaining({
        category: 'none',
        explanation: 'Mage Hand has no duplicate selection.',
    }));
    await expect(duplicateCell(page, wizardSlot.id)).toHaveText(/None/);
    await expect(duplicateCell(page, initiateSlot.id)).toHaveText(/None/);
    await expect(warning).toHaveCount(0);
    await expect(duplicateWarningsSection(page)).toContainText('No duplicate spell warnings.');
    await expect(duplicateWarningsSection(page)).not.toContainText(explanation);

    const wastefulResponse = await selectSpell(page, initiateSlot.id, 'Mage Hand');
    const wastefulAssessment = wastefulResponse.workspace.report.duplicate_assessments
        .find((assessment) => assessment.spell_name === 'Mage Hand');

    expect(wastefulAssessment).toEqual(expect.objectContaining({ category: 'wasteful', explanation }));
    await expect(duplicateCell(page, wizardSlot.id)).toHaveText(/Wasteful/);
    await expect(duplicateCell(page, initiateSlot.id)).toHaveText(/Wasteful/);
    await expect(duplicateWarning(page, 'Mage Hand')).toContainText(explanation);
});

test('S3: undo is persisted by the server and redo is discarded by a hard reload', async ({ page, context }) => {
    const target = requireSlot(
        slotFixtures().find((slot) => slot.rule_key === 'wizard-cantrips' && slot.ordinal === 2),
        'the second Wizard cantrip slot',
    );
    const redo = page.getByRole('button', { name: /Redo/ });

    await selectSpell(page, target.id, 'Fire Bolt');
    expect(requireSlot(slots().find((slot) => slot.id === target.id), 'the edited slot').current_spell_version_id)
        .not.toBeNull();

    const undoResponse = page.waitForResponse(isMutationResponse);
    await page.getByRole('button', { name: /Undo/ }).click();
    expect((await undoResponse).status()).toBe(200);
    await expect(redo).toBeEnabled();

    expect(requireSlot(slots().find((slot) => slot.id === target.id), 'the undone slot').current_spell_version_id)
        .toBeNull();
    expect(character().revision).toBe(2);
    const auditAfterUndo = auditLog();
    expect(auditAfterUndo).toHaveLength(2);

    const devtools = await context.newCDPSession(page);
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle' }),
        devtools.send('Page.reload', { ignoreCache: true }),
    ]);
    await devtools.detach();

    await expect(page.getByRole('heading', { name: 'A6 Sixfold Spellcaster', level: 1 })).toBeVisible();
    await expect(redo).toBeDisabled();
    expect(requireSlot(slots().find((slot) => slot.id === target.id), 'the slot after reload').current_spell_version_id)
        .toBeNull();
    expect(character().revision).toBe(2);
    expect(auditLog()).toEqual(auditAfterUndo);
});

test('S5: INT changes propagate to every INT route in both directions without moving WIS or CHA', async ({ page }) => {
    const initial = await castingRows(page);
    const initialInt = rowsFor(initial, 'INT');
    const initialWis = rowsFor(initial, 'WIS');
    const initialCha = rowsFor(initial, 'CHA');

    expect(initialInt.length).toBeGreaterThan(0);
    expect(initialWis.length).toBeGreaterThan(0);
    expect(initialCha.length).toBeGreaterThan(0);
    expect(initialInt.every((row) => row.numbers === '+4 / 12')).toBe(true);
    expect(initialWis.every((row) => row.numbers === '+4 / 12')).toBe(true);
    expect(initialCha.every((row) => row.numbers === '+6 / 14')).toBe(true);

    await setAbility(page, 'INT', 20);

    const raised = await castingRows(page);
    expect(rowsFor(raised, 'INT').map((row) => ({ ...row, numbers: '+4 / 12' }))).toEqual(initialInt);
    expect(rowsFor(raised, 'INT').every((row) => row.numbers === '+8 / 16')).toBe(true);
    expect(rowsFor(raised, 'WIS')).toEqual(initialWis);
    expect(rowsFor(raised, 'CHA')).toEqual(initialCha);
    expect(character().intelligence).toBe(20);

    await setAbility(page, 'INT', 13);

    const restored = await castingRows(page);
    expect(rowsFor(restored, 'INT')).toEqual(initialInt);
    expect(rowsFor(restored, 'WIS')).toEqual(initialWis);
    expect(rowsFor(restored, 'CHA')).toEqual(initialCha);
    expect(character().intelligence).toBe(13);
});

function requireSlot<T extends SlotFixture | ReturnType<typeof slots>[number]>(
    slot: T | undefined,
    description: string,
): T {
    if (!slot) throw new Error(`Seed data did not contain ${description}.`);

    return slot;
}

async function selectSpell(page: Page, slotId: number, spellName: string): Promise<MutationBody> {
    const combobox = page.getByRole('combobox', { name: `Spell selection for slot ${slotId}` });
    await combobox.fill(spellName);
    const option = page.getByRole('option').filter({ hasText: spellName });
    await expect(option).toBeVisible();

    const mutationResponse = page.waitForResponse(isMutationResponse);
    await option.click();
    const response = await mutationResponse;
    expect(response.status()).toBe(200);
    const body = await response.json() as MutationBody;
    await expect(page.getByText(new RegExp(`revision ${body.revision}$`))).toBeVisible();
    await expect(combobox).toHaveValue(spellName);
    await expect(page.getByText('Autosaved', { exact: true })).toBeVisible();
    await page.waitForTimeout(150);

    return body;
}

function slotRow(page: Page, slotId: number): Locator {
    return page.getByRole('combobox', { name: `Spell selection for slot ${slotId}` }).locator('xpath=ancestor::tr');
}

function duplicateCell(page: Page, slotId: number): Locator {
    return slotRow(page, slotId).locator('td').nth(9);
}

function duplicateWarningsSection(page: Page): Locator {
    return page.locator('section').filter({ has: page.getByRole('heading', { name: 'Duplicate warnings' }) });
}

function duplicateWarning(page: Page, spellName: string): Locator {
    return duplicateWarningsSection(page).locator('article').filter({ hasText: spellName });
}

function isMutationResponse(response: { request(): { method(): string }; url(): string }): boolean {
    return response.request().method() === 'POST' && response.url().endsWith('/characters/1/mutations');
}

async function setAbility(page: Page, abbreviation: 'INT', score: number): Promise<void> {
    const abilitySection = page.locator('section').filter({ has: page.getByRole('heading', { name: 'Ability scores' }) });
    const input = abilitySection.locator('label').filter({ hasText: abbreviation }).locator('input');
    const mutationResponse = page.waitForResponse(isMutationResponse);
    await input.fill(String(score));
    await input.press('Tab');
    const response = await mutationResponse;
    expect(response.status()).toBe(200);
    const body = await response.json() as { revision: number };
    await expect(page.getByText(new RegExp(`revision ${body.revision}$`))).toBeVisible();
    await expect(input).toHaveValue(String(score));
}

async function castingRows(page: Page): Promise<CastingRow[]> {
    return page.locator('table.slot-table tbody tr').evaluateAll((rows) => rows.flatMap((row) => {
        const cells = [...row.querySelectorAll('td')];
        const ability = cells[5]?.textContent?.trim();
        const combobox = row.querySelector<HTMLInputElement>('input[role="combobox"]');
        const slotMatch = combobox?.getAttribute('aria-label')?.match(/(\d+)$/);
        if (cells.length !== 11 || !slotMatch || !['INT', 'WIS', 'CHA'].includes(ability ?? '')) return [];

        return [{
            slotId: Number(slotMatch[1]),
            source: cells[0]?.textContent?.trim() ?? '',
            label: cells[1]?.textContent?.trim() ?? '',
            ability: ability as CastingRow['ability'],
            numbers: cells[6]?.textContent?.trim() ?? '',
        }];
    }));
}

function rowsFor(rows: CastingRow[], ability: CastingRow['ability']): CastingRow[] {
    return rows.filter((row) => row.ability === ability).sort((left, right) => left.slotId - right.slotId);
}
