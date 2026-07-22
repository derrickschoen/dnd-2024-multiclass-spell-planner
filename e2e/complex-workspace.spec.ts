import { expect, test, type Locator, type Page, type Response } from '@playwright/test';
import {
    auditLog,
    character,
    classLevel,
    persistedCharacterState,
    removeMagicInitiateWizardSource,
    resetDatabase,
    restoreMagicInitiateWizardSource,
    savePointSnapshot,
    slotFixtures,
    slots,
    source,
    spellVersionId,
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
                selection_count: number;
            }>;
            access_routes: Array<{
                spell_name: string;
                source_name: string;
                origin: string;
                casting_mode: string;
                is_selection: boolean;
                counts_against_limit: boolean;
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
    expect(changedTarget.current_spell_version_id).toBe(spellVersionId('2024:fire-bolt'));

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
        .toBe(spellVersionId('2024:fire-bolt'));

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

test('S4: removing and restoring Magic Initiate: Wizard preserves its orphaned slot identities and selections', async ({ page }) => {
    const magicInitiateSource = source('Magic Initiate: Wizard');
    const sourceId = magicInitiateSource.id;
    const before = slots().filter((slot) => slot.source_instance_id === sourceId);
    const otherSlotsBefore = slots().filter((slot) => slot.source_instance_id !== sourceId);
    const druidSourceBefore = source('Magic Initiate: Druid');

    expect(magicInitiateSource.state).toBe('active');
    expect(before).toHaveLength(3);
    expect(before.every((slot) => slot.state === 'active' && slot.current_spell_version_id !== null)).toBe(true);

    const removal = removeMagicInitiateWizardSource();
    expect(removal.source.state).toBe('tombstoned');
    const orphaned = slots().filter((slot) => slot.source_instance_id === sourceId);

    expect(orphaned).toHaveLength(3);
    expect(orphaned.map(preservedSlotIdentity)).toEqual(before.map(preservedSlotIdentity));
    expect(orphaned.every((slot) => slot.state === 'orphaned')).toBe(true);
    expect(orphaned.every((slot) => slot.orphan_reason_code === 'parent_rule_removed')).toBe(true);
    expect(orphaned.every((slot) => slot.orphaned_at !== null)).toBe(true);
    expect(slots().filter((slot) => slot.source_instance_id !== sourceId)).toEqual(otherSlotsBefore);
    expect(source('Magic Initiate: Druid')).toEqual(druidSourceBefore);

    await page.reload();
    for (const slot of orphaned) {
        await expect(slotRow(page, slot.id).locator('td').nth(10)).toHaveText(/Orphaned/);
    }

    const restoration = restoreMagicInitiateWizardSource(removal.previous_grant_rules);
    expect(restoration.source.state).toBe('active');
    const restored = slots().filter((slot) => slot.source_instance_id === sourceId);

    expect(restored).toHaveLength(3);
    expect(restored.map(preservedSlotIdentity)).toEqual(before.map(preservedSlotIdentity));
    expect(restored.every((slot) => slot.state === 'active')).toBe(true);
    expect(restored.every((slot) => slot.orphan_reason_code === null && slot.orphaned_at === null)).toBe(true);
    expect(slots().filter((slot) => slot.source_instance_id !== sourceId)).toEqual(otherSlotsBefore);
    expect(source('Magic Initiate: Druid')).toEqual(druidSourceBefore);

    await page.reload();
    for (const slot of restored) {
        await expect(slotRow(page, slot.id).locator('td').nth(10)).toHaveText(/Valid/);
    }
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
    const raisedInt = rowsFor(raised, 'INT');
    expect(raisedInt.map(withoutCastingNumbers)).toEqual(initialInt.map(withoutCastingNumbers));
    expect(raisedInt.every((row) => row.numbers === '+8 / 16')).toBe(true);
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

test('S6: Wizard spellbook shows prepared, ritual-only, and unprepared non-ritual states', async ({ page }) => {
    const wizardPanel = wizardSpellbookPanel(page);
    const spellbook = wizardList(wizardPanel, 'Spellbook · 6');
    const prepared = wizardList(wizardPanel, 'Prepared · 4');
    const ritualOnly = wizardList(wizardPanel, 'Ritual-only · 1');
    const explanation = wizardPanel.locator('p').first();

    await expect(spellbook).toHaveText([
        'Detect Magic',
        'Feather Fall',
        'Mage Armor',
        'Magic Missile',
        'Sleep',
        'Thunderwave',
    ]);
    await expect(prepared).toHaveText(['Mage Armor', 'Magic Missile', 'Sleep', 'Thunderwave']);
    await expect(ritualOnly).toHaveText(['Detect Magic']);
    await expect(prepared.filter({ hasText: 'Mage Armor' })).toHaveCount(1);
    await expect(prepared.filter({ hasText: 'Detect Magic' })).toHaveCount(0);
    await expect(prepared.filter({ hasText: 'Feather Fall' })).toHaveCount(0);
    await expect(ritualOnly.filter({ hasText: 'Feather Fall' })).toHaveCount(0);
    await expect(explanation).toContainText('Prepared spellbook spells can use spell slots.');
    await expect(explanation).toContainText('ritual-only access');
    await expect(explanation).toContainText('that route is not a selection');
    await expect(explanation).toContainText('does not consume preparation capacity');
    await expect(explanation).toContainText('ignored by duplicate-waste checks');
    await expect(explanation).toContainText('Unprepared non-ritual spells are not castable.');

    const initiateSlot = requireSlot(
        slotFixtures().find((slot) => slot.source_name === 'Magic Initiate: Wizard'
            && slot.rule_key === 'magic-initiate-level-one'),
        'the Magic Initiate: Wizard level-one slot',
    );
    const overlap = await selectSpell(page, initiateSlot.id, 'Detect Magic');
    const routes = overlap.workspace.report.access_routes.filter((route) => route.spell_name === 'Detect Magic');
    const capability = routes.find((route) => route.casting_mode === 'ritual_only');
    const assessment = overlap.workspace.report.duplicate_assessments
        .find((item) => item.spell_name === 'Detect Magic');

    expect(routes).toHaveLength(2);
    expect(capability).toEqual(expect.objectContaining({
        origin: 'capability',
        casting_mode: 'ritual_only',
        is_selection: false,
        counts_against_limit: false,
    }));
    expect(routes.filter((route) => route.is_selection)).toHaveLength(1);
    expect(assessment).toEqual(expect.objectContaining({ category: 'none', selection_count: 1 }));
    await expect(duplicateCell(page, initiateSlot.id)).toHaveText(/None/);
    await expect(duplicateWarning(page, 'Detect Magic')).toHaveCount(0);
});

test('S7: a save point restores the complete persisted character state after destructive edits', async ({ page }) => {
    const label = 'Before destructive E2E edits';
    const savePoints = page.locator('section').filter({ has: page.getByRole('heading', { name: 'Save points' }) });
    const before = persistedCharacterState();
    const createResponse = page.waitForResponse((response) => response.request().method() === 'POST'
        && response.url().endsWith('/characters/1/save-points'));
    await savePoints.getByLabel('Save point name').fill(label);
    await savePoints.getByRole('button', { name: 'Save snapshot' }).click();
    expect((await createResponse).status()).toBe(201);
    await expect(savePoints.getByText(label, { exact: true })).toBeVisible();
    expect(savePointSnapshot(label)).toEqual(before);

    const wizardCantrip = requireSlot(
        slotFixtures().find((slot) => slot.rule_key === 'wizard-cantrips' && slot.ordinal === 1),
        'the selected Wizard cantrip slot',
    );
    const initiateSpell = requireSlot(
        slotFixtures().find((slot) => slot.source_name === 'Magic Initiate: Wizard'
            && slot.rule_key === 'magic-initiate-level-one'),
        'the selected Magic Initiate: Wizard level-one slot',
    );
    await selectSpell(page, wizardCantrip.id, 'Fire Bolt');
    await selectSpell(page, initiateSpell.id, 'Detect Magic');
    await setAbility(page, 'INT', 20);
    await setClassLevel(page, 'Wizard', 2);

    const changed = persistedCharacterState();
    expect(changed).not.toEqual(before);
    expect(requireSlot(slots().find((slot) => slot.id === wizardCantrip.id), 'changed Wizard cantrip')
        .current_spell_version_id).toBe(spellVersionId('2024:fire-bolt'));
    expect(requireSlot(slots().find((slot) => slot.id === initiateSpell.id), 'changed Magic Initiate spell')
        .current_spell_version_id).toBe(spellVersionId('2024:detect-magic'));
    expect(character().intelligence).toBe(20);
    expect(classLevel('Wizard')).toBe(2);

    let restoreDialogMessage: string | undefined;
    page.once('dialog', async (dialog) => {
        restoreDialogMessage = dialog.message();
        await dialog.accept();
    });
    const restoreResponse = page.waitForResponse(isMutationResponse);
    await savePoints.getByRole('button', { name: 'Restore' }).click();
    const response = await restoreResponse;
    expect(response.status()).toBe(200);
    const body = await response.json() as { revision: number };
    await expect(page.getByText(new RegExp(`revision ${body.revision}$`))).toBeVisible();
    expect(restoreDialogMessage).toContain(`Restore “${label}”?`);

    expect(persistedCharacterState()).toEqual(savePointSnapshot(label));
    expect(persistedCharacterState()).toEqual(before);
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
    const option = page.locator(`[id="spell-options-${slotId}"]`).getByRole('option').filter({ hasText: spellName });
    let eligible: Response | undefined;
    await expect(combobox).toBeEnabled();
    await expect(async () => {
        await combobox.fill(spellName);
        eligible = await page.waitForResponse((response) => {
            const url = new URL(response.url());

            return response.request().method() === 'GET'
                && url.pathname.endsWith(`/characters/1/slots/${slotId}/eligible-spells`)
                && url.searchParams.get('q') === spellName;
        }, { timeout: 1_000 });
    }).toPass({ timeout: 10_000, intervals: [0, 200, 500] });
    if (!eligible) throw new Error(`No eligible-spell response was received for ${spellName}.`);
    expect(eligible.status()).toBe(200);
    const searchBody = await eligible.json() as { spells: Array<{ name: string }> };
    expect(searchBody.spells.some((spell) => spell.name === spellName)).toBe(true);
    await expect(option).toBeVisible();
    await expect(option).toHaveAttribute('aria-selected', 'true');

    const mutationResponse = page.waitForResponse(isMutationResponse);
    await combobox.press('Enter');
    const response = await mutationResponse;
    expect(response.status()).toBe(200);
    const body = await response.json() as MutationBody;
    await expect(page.getByText(new RegExp(`revision ${body.revision}$`))).toBeVisible();
    await expect(combobox).toHaveValue(spellName);
    await expect(page.getByText('Autosaved', { exact: true })).toBeVisible();

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
    await expect(page.getByText('Autosaved', { exact: true })).toBeVisible();
}

async function setClassLevel(page: Page, className: string, level: number): Promise<void> {
    const classes = page.locator('section').filter({ has: page.getByRole('heading', { name: 'Classes' }) });
    const entry = classes.getByText(className, { exact: true }).locator('..');
    const input = entry.getByLabel('Level');
    const mutationResponse = page.waitForResponse(isMutationResponse);
    await input.fill(String(level));
    await input.press('Tab');
    const response = await mutationResponse;
    expect(response.status()).toBe(200);
    const body = await response.json() as { revision: number };
    await expect(page.getByText(new RegExp(`revision ${body.revision}$`))).toBeVisible();
    await expect(input).toHaveValue(String(level));
    await expect(page.getByText('Autosaved', { exact: true })).toBeVisible();
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

function withoutCastingNumbers(row: CastingRow): Omit<CastingRow, 'numbers'> {
    const { numbers: _numbers, ...identity } = row;

    return identity;
}

function preservedSlotIdentity(slot: ReturnType<typeof slots>[number]): Pick<
    ReturnType<typeof slots>[number],
    'id' | 'source_instance_id' | 'slot_key' | 'rule_key' | 'ordinal' | 'current_spell_version_id'
> {
    return {
        id: slot.id,
        source_instance_id: slot.source_instance_id,
        slot_key: slot.slot_key,
        rule_key: slot.rule_key,
        ordinal: slot.ordinal,
        current_spell_version_id: slot.current_spell_version_id,
    };
}

function wizardSpellbookPanel(page: Page): Locator {
    return page.locator('section').filter({ has: page.getByRole('heading', { name: 'Wizard spellbook access' }) });
}

function wizardList(panel: Locator, heading: string): Locator {
    return panel.getByRole('heading', { name: heading }).locator('..').getByRole('listitem');
}
