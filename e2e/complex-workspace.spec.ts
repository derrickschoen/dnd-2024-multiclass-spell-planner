import { expect, test, type Locator, type Page, type Response } from '@playwright/test';
import {
    addMagicInitiateSource,
    auditLog,
    character,
    characterOperations,
    classLevel,
    mutationFootprint,
    persistedCharacterState,
    removeMagicInitiateWizardSource,
    resetDatabase,
    restoreMagicInitiateWizardSource,
    savePointSnapshot,
    slotFixtures,
    slots,
    source,
    spellVersionId,
    warningAcknowledgements,
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
            caster: {
                caster_level: number;
                slots: Array<{ level: number; count: number }>;
                pact_magic: { count: number; level: number } | null;
            };
            classes: Array<{
                name: string;
                class_level: number;
                max_preparable_level: number;
            }>;
            preparation_callout: string;
            duplicate_assessments: Array<{
                spell_name: string;
                category: string;
                explanation: string;
                selection_count: number;
                versions: Array<{
                    spell_version_id: number;
                    content_key: string;
                    edition: string;
                    label: string;
                }>;
                warning_fingerprint: string | null;
                acknowledgement: { id: number; note: string; created_at: string } | null;
            }>;
            access_routes: Array<{
                slot_id: number | null;
                spell_version_id: number;
                spell_name: string;
                source_name: string;
                origin: string;
                casting_mode: string;
                is_selection: boolean;
                counts_against_limit: boolean;
            }>;
            invalid_selections: Array<{ id: number; spell_name: string; eligibility: string }>;
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
    expect(orphaned.map(withoutSlotLifecycleOrEligibility)).toEqual(before.map(withoutSlotLifecycleOrEligibility));
    expect(orphaned.every((slot) => slot.state === 'orphaned')).toBe(true);
    expect(orphaned.every((slot) => slot.orphan_reason_code === 'parent_rule_removed')).toBe(true);
    expect(orphaned.every((slot) => slot.selection_eligibility === 'invalid')).toBe(true);
    expect(orphaned.every((slot) => slot.selection_invalid_reason
        === 'Selection preserved because its source is no longer active.')).toBe(true);
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
    expect(restored.map(withoutSlotLifecycle)).toEqual(before.map(withoutSlotLifecycle));
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
        spell_name: 'Detect Magic',
        source_name: 'Wizard 1',
        origin: 'capability',
        casting_mode: 'ritual_only',
        is_selection: false,
        counts_against_limit: false,
    }));
    expect(routes.filter((route) => route.is_selection)).toEqual([
        expect.objectContaining({
            spell_name: 'Detect Magic',
            source_name: 'Magic Initiate: Wizard',
            origin: 'slot',
            casting_mode: 'slots_and_free_cast',
            counts_against_limit: true,
        }),
    ]);
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

test('S8: adding Warlock keeps Pact Magic separate from shared slots and explains cross-pool casting', async ({ page }) => {
    const liveReport = reportSection(page, 'Live report');
    const preparation = reportSection(page, 'Class preparation ceilings');
    const sharedSlotBadges = liveReport.locator('.status-badge.status-neutral');

    await expect(sharedSlotBadges).toHaveText(['L1: 4', 'L2: 3', 'L3: 3']);
    await expect(liveReport.getByText(/^Pact Magic:/)).toHaveCount(0);

    const response = await addClass(page, 'Warlock');

    expect(response.workspace.report.caster).toEqual({
        caster_level: 6,
        slots: [
            { level: 1, count: 4 },
            { level: 2, count: 3 },
            { level: 3, count: 3 },
        ],
        pact_magic: { count: 1, level: 1 },
    });
    expect(response.workspace.report.classes.find((entry) => entry.name === 'Warlock')).toEqual(
        expect.objectContaining({ class_level: 1, max_preparable_level: 1 }),
    );
    expect(response.workspace.report.preparation_callout).toContain(
        'shared Spellcasting slots through 3rd level and Pact Magic slots at 1st level',
    );
    expect(response.workspace.report.preparation_callout).toContain(
        'Either pool can cast an eligible prepared spell.',
    );
    expect(response.workspace.report.preparation_callout).toContain(
        'a slot from either pool does not unlock higher-level choices for another class',
    );

    await expect(sharedSlotBadges).toHaveText(['L1: 4', 'L2: 3', 'L3: 3']);
    await expect(sharedSlotBadges).toHaveCount(3);
    await expect(liveReport.getByText('Pact Magic: 1 × level 1', { exact: true })).toBeVisible();
    const warlockCeiling = preparation.getByText('Warlock 1').locator('..');
    await expect(warlockCeiling).toContainText('max L1');
    await expect(preparation).toContainText('Either pool can cast an eligible prepared spell.');
    expect(classLevel('Warlock')).toBe(1);
});

test('S9: level-down orphans surplus selected slots and level-up reactivates the same rows', async ({ page }) => {
    await setClassLevel(page, 'Wizard', 3);
    const wizardSourceId = source('Wizard 3').id;
    const upgradedPrepared = slotFixtures().filter((slot) => slot.source_instance_id === wizardSourceId
        && slot.rule_key === 'wizard-prepared');
    const surplus = upgradedPrepared.filter((slot) => slot.ordinal > 4);

    expect(upgradedPrepared).toHaveLength(6);
    expect(surplus).toHaveLength(2);
    expect(surplus.map((slot) => slot.current_spell_version_id)).toEqual([null, null]);

    await selectSpell(page, surplus[0]!.id, 'Detect Magic');
    await selectSpell(page, surplus[1]!.id, 'Feather Fall');
    const selectedSurplus = slots().filter((slot) => surplus.some((candidate) => candidate.id === slot.id));
    expect(selectedSurplus.map((slot) => slot.current_spell_version_id)).toEqual([
        spellVersionId('2024:detect-magic'),
        spellVersionId('2024:feather-fall'),
    ]);
    const identities = selectedSurplus.map(preservedSlotIdentity);
    const semantics = selectedSurplus.map(withoutSlotLifecycle);

    await setClassLevel(page, 'Wizard', 1);
    const orphaned = slots().filter((slot) => surplus.some((candidate) => candidate.id === slot.id));
    expect(orphaned).toHaveLength(2);
    expect(orphaned.map(preservedSlotIdentity)).toEqual(identities);
    expect(orphaned.map(withoutSlotLifecycleOrEligibility))
        .toEqual(selectedSurplus.map(withoutSlotLifecycleOrEligibility));
    expect(orphaned.map((slot) => slot.state)).toEqual(['orphaned', 'orphaned']);
    expect(orphaned.map((slot) => slot.orphan_reason_code)).toEqual([
        'rule_no_longer_active',
        'rule_no_longer_active',
    ]);
    expect(orphaned.map((slot) => slot.selection_eligibility)).toEqual(['invalid', 'invalid']);
    expect(orphaned.map((slot) => slot.selection_invalid_reason)).toEqual([
        'Selection preserved because its grant rule is no longer active.',
        'Selection preserved because its grant rule is no longer active.',
    ]);
    for (const slot of orphaned) {
        await expect(slotRow(page, slot.id).locator('td').nth(10)).toHaveText(/Orphaned/);
        await expect(slotRow(page, slot.id).locator('xpath=following-sibling::tr[1]')).toContainText(
            'Selection needs attention.',
        );
    }

    await setClassLevel(page, 'Wizard', 3);
    const reactivated = slots().filter((slot) => surplus.some((candidate) => candidate.id === slot.id));
    expect(reactivated).toHaveLength(2);
    expect(reactivated.map(preservedSlotIdentity)).toEqual(identities);
    expect(reactivated.map(withoutSlotLifecycle)).toEqual(semantics);
    expect(reactivated.map((slot) => slot.state)).toEqual(['active', 'active']);
    expect(reactivated.map((slot) => slot.orphan_reason_code)).toEqual([null, null]);
    for (const slot of reactivated) {
        await expect(slotRow(page, slot.id).locator('td').nth(10)).toHaveText(/Valid/);
    }
});

test('S10: a stale browser context receives 409 and cannot change any database state', async ({ page, browser }) => {
    const staleContext = await browser.newContext({
        baseURL: 'https://dnd-spell-planner.ddev.site',
        ignoreHTTPSErrors: true,
    });
    const stalePage = await staleContext.newPage();
    try {
        await stalePage.goto('/characters/1');
        await expect(stalePage.getByText(/revision 0$/)).toBeVisible();
        await setAbility(page, 'INT', 20);
        const afterAcceptedWrite = mutationFootprint();
        expect(character().revision).toBe(1);
        expect(character().intelligence).toBe(20);
        expect(character().wisdom).toBe(13);

        const staleWisdom = abilityInput(stalePage, 'WIS');
        const rejectedResponse = stalePage.waitForResponse(isMutationResponse);
        await staleWisdom.fill('18');
        await staleWisdom.press('Tab');
        const response = await rejectedResponse;
        const request = response.request().postDataJSON() as {
            expected_revision: number;
            command: { type: string; ability: string; score: number };
        };
        const body = await response.json() as { message: string; current_revision: number };

        expect(request).toEqual(expect.objectContaining({
            expected_revision: 0,
            command: { type: 'update_ability', ability: 'wisdom', score: 18 },
        }));
        expect(response.status()).toBe(409);
        expect(body.current_revision).toBe(1);
        expect(body.message).toBe('This character changed in another tab. Reload before trying again.');
        await expect(stalePage.getByRole('alert')).toContainText('Could not save:');
        await expect(stalePage.getByRole('link', { name: 'Reload this character' })).toBeVisible();

        expect(mutationFootprint()).toEqual(afterAcceptedWrite);
        expect(character().revision).toBe(1);
        expect(character().intelligence).toBe(20);
        expect(character().wisdom).toBe(13);
    } finally {
        await staleContext.close();
    }
});

test('S11: the slot grid is keyboard reachable, visibly focused, labelled, and warnings are not colour-only', async ({ page }) => {
    const grid = page.locator('section').filter({ has: page.getByRole('heading', { name: 'Spell choice slots' }) });
    const gridControlSelector = 'button:not([disabled]):not([role="option"]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), a[href]';
    // Derived from the DATABASE, not from the DOM under test. Counting the rendered
    // controls and then asserting the keyboard walk visits that same count is
    // self-referential: if comboboxes silently stopped rendering for some rows,
    // both numbers would shrink together and a loose `> 40` guard would still pass.
    // One combobox per slot, plus the grid's own chrome (3 sort buttons, 4 filters).
    const GRID_CHROME_CONTROLS = 7;
    const expectedGridControls = slotFixtures().length + GRID_CHROME_CONTROLS;
    const renderedGridControls = await grid.locator(gridControlSelector).count();
    const labelled = await labelledFormControls(page);

    expect(renderedGridControls).toBe(expectedGridControls);
    // labelledFormControls is page-wide (ability inputs, class controls, undo and
    // save-point buttons all sit outside the grid), so it is a superset of the grid.
    // The load-bearing assertion is that NOTHING on the page is unlabelled.
    expect(labelled.total).toBeGreaterThanOrEqual(expectedGridControls);
    expect(labelled.unlabelled).toEqual([]);

    const initialKeyboardAudit = await keyboardAuditGrid(page, grid, gridControlSelector);
    expect(initialKeyboardAudit.visited).toEqual(
        Array.from({ length: expectedGridControls }, (_, index) => index),
    );
    expect(initialKeyboardAudit.focusFailures).toEqual([]);

    const duplicate = duplicateWarning(page, 'Mage Hand');
    await expect(duplicate).toContainText('Mage Hand consumes limits in more than one selection.');
    await expect(duplicate.locator('[aria-hidden="true"]')).toHaveText('⚠');
    await expect(duplicateCell(page, requireSlot(
        slotFixtures().find((slot) => slot.spell_name === 'Mage Hand' && slot.rule_key === 'wizard-cantrips'),
        'the Wizard Mage Hand slot',
    ).id)).toContainText('Wasteful');
    expect(await warningsWithoutTextCue(page)).toEqual([]);

    const wizardEntry = classEntry(page, 'Wizard');
    let removeDialog = '';
    page.once('dialog', async (dialog) => {
        removeDialog = dialog.message();
        await dialog.accept();
    });
    const removeResponse = page.waitForResponse(isMutationResponse);
    await wizardEntry.getByRole('button', { name: 'Remove' }).focus();
    await page.keyboard.press('Enter');
    expect((await removeResponse).status()).toBe(200);
    expect(removeDialog).toContain('Remove Wizard and orphan its spell choices?');

    const orphaned = slots().filter((slot) => slot.source_instance_id === source('Wizard 1').id);
    expect(orphaned.length).toBeGreaterThan(0);
    expect(orphaned.every((slot) => slot.state === 'orphaned')).toBe(true);
    for (const slot of orphaned) {
        const row = slotRow(page, slot.id);
        await expect(row.locator('td').nth(10)).toContainText('⚠ Orphaned');
        const warningRow = row.locator('xpath=following-sibling::tr[1]');
        await expect(warningRow).toContainText('Selection needs attention.');
        await expect(warningRow).toContainText(slot.current_spell_version_id === null
            ? 'parent_rule_removed'
            : 'Selection preserved because its source is no longer active.');
    }
    const invalidReport = reportSection(page, 'Invalid or orphaned selections');
    await expect(invalidReport.getByText('Selection preserved because its source is no longer active.').first())
        .toBeVisible();
    await expect(invalidReport.getByText('parent_rule_removed').first()).toBeVisible();
    const orphanedLabelAudit = await labelledFormControls(page);
    expect(orphanedLabelAudit.total).toBeGreaterThan(labelled.total);
    expect(orphanedLabelAudit.unlabelled).toEqual([]);
    const orphanedGridControls = await grid.locator(gridControlSelector).count();
    expect(orphanedGridControls).toBeGreaterThan(expectedGridControls);
    const orphanedKeyboardAudit = await keyboardAuditGrid(page, grid, gridControlSelector);
    expect(orphanedKeyboardAudit.visited).toEqual(
        Array.from({ length: orphanedGridControls }, (_, index) => index),
    );
    expect(orphanedKeyboardAudit.focusFailures).toEqual([]);
    expect(await warningsWithoutTextCue(page)).toEqual([]);
});

test('S12: selecting 2014 and 2024 Chill Touch warns prominently and keeps its acknowledgement after reload', async ({ page }) => {
    const targets = slotFixtures().filter((slot) => slot.rule_key === 'wizard-cantrips'
        && [2, 3].includes(slot.ordinal));
    expect(targets).toHaveLength(2);
    expect(targets.map((slot) => slot.current_spell_version_id)).toEqual([null, null]);
    const legacyToggle = page.getByRole('checkbox', { name: /Allow legacy 2014 spell versions/ });
    await expect(legacyToggle).not.toBeChecked();
    await expectKeyboardReachableWithVisibleFocus(page, legacyToggle);

    const legacyResponse = page.waitForResponse(isMutationResponse);
    await legacyToggle.check();
    expect((await legacyResponse).status()).toBe(200);
    await expect(legacyToggle).toBeChecked();
    expect(character().allow_legacy).toBe(1);

    await selectSpellVersion(page, targets[0]!.id, 'Chill Touch', '2014');
    const conflictResponse = await selectSpellVersion(page, targets[1]!.id, 'Chill Touch', '2024');
    const assessment = conflictResponse.workspace.report.duplicate_assessments
        .find((item) => item.spell_name === 'Chill Touch');
    expect(assessment).toEqual(expect.objectContaining({
        category: 'conflicting_version',
        versions: [
            expect.objectContaining({ content_key: '2014:chill-touch', edition: '2014', label: 'Chill Touch (2014)' }),
            expect.objectContaining({ content_key: '2024:chill-touch', edition: '2024', label: 'Chill Touch (2024)' }),
        ],
        acknowledgement: null,
    }));
    expect(targets.map((target) => requireSlot(
        slots().find((slot) => slot.id === target.id),
        `Chill Touch slot ${target.id}`,
    ).current_spell_version_id)).toEqual([
        spellVersionId('2014:chill-touch'),
        spellVersionId('2024:chill-touch'),
    ]);

    const warningSection = duplicateWarningsSection(page);
    await expect(warningSection.getByRole('heading', { name: 'CONFLICTING VERSIONS', exact: true })).toBeVisible();
    const warning = warningSection.getByRole('alert').filter({ hasText: 'Chill Touch' });
    await expect(warning).toHaveCount(1);
    await expect(warning).toContainText('Chill Touch (2014)');
    await expect(warning).toContainText('Chill Touch (2024)');
    const note = 'Intentional comparison of ranged 2014 and touch-range 2024 rules.';
    const acknowledgementNote = warning.getByLabel('Acknowledgement note');
    await expectKeyboardReachableWithVisibleFocus(page, acknowledgementNote);
    await acknowledgementNote.fill(note);
    const acknowledgeButton = warning.getByRole('button', { name: 'Acknowledge warning' });
    await expectKeyboardReachableWithVisibleFocus(page, acknowledgeButton);
    const acknowledgementResponse = page.waitForResponse(isMutationResponse);
    await acknowledgeButton.click();
    expect((await acknowledgementResponse).status()).toBe(200);
    await expect(warning).toContainText(`Acknowledged: ${note}`);

    const acknowledgements = warningAcknowledgements();
    expect(acknowledgements).toHaveLength(1);
    expect(acknowledgements[0]).toEqual(expect.objectContaining({
        character_id: 1,
        note,
        invalidated_at: null,
    }));
    expect(String(acknowledgements[0]!.warning_fingerprint)).toMatch(/^conflicting_versions:[a-f0-9]{64}$/);

    await page.reload({ waitUntil: 'networkidle' });
    await expect(page.getByRole('heading', { name: 'A6 Sixfold Spellcaster', level: 1 })).toBeVisible();
    const reloadedWarning = duplicateWarningsSection(page).getByRole('alert').filter({ hasText: 'Chill Touch' });
    await expect(reloadedWarning).toHaveCount(1);
    await expect(reloadedWarning).toContainText(`Acknowledged: ${note}`);
    expect(warningAcknowledgements()).toEqual(acknowledgements);
});

test('S13: changing Magic Initiate from Wizard to Cleric preserves slot identity and excludes invalid selections', async ({ page }) => {
    const wizardSource = source('Magic Initiate: Wizard');
    const sourceId = Number(wizardSource.id);
    const before = slots().filter((slot) => slot.source_instance_id === sourceId);
    expect(before).toHaveLength(3);
    expect(before.every((slot) => slot.current_spell_version_id !== null)).toBe(true);
    const identities = before.map((slot) => ({ id: slot.id, slot_key: slot.slot_key }));
    const selections = before.map((slot) => slot.current_spell_version_id);

    const listSelect = page.getByLabel(`Chosen spell list for source ${sourceId}`);
    await expect(listSelect).toHaveValue('Wizard');
    await expectKeyboardReachableWithVisibleFocus(page, listSelect);
    const mutationResponse = page.waitForResponse(isMutationResponse);
    await listSelect.selectOption('Cleric');
    const response = await mutationResponse;
    expect(response.status()).toBe(200);
    const body = await response.json() as MutationBody;
    await expect(listSelect).toHaveValue('Cleric');

    const after = slots().filter((slot) => slot.source_instance_id === sourceId);
    expect(after).toHaveLength(3);
    expect(after.map((slot) => ({ id: slot.id, slot_key: slot.slot_key }))).toEqual(identities);
    expect(after.map((slot) => slot.current_spell_version_id)).toEqual(selections);
    expect(after.map((slot) => slot.selection_eligibility)).toEqual(['invalid', 'invalid', 'invalid']);
    expect(after.map((slot) => slot.allowed_spell_lists)).toEqual(['["Cleric"]', '["Cleric"]', '["Cleric"]']);
    expect(JSON.parse(String(source('Magic Initiate: Cleric').config))).toEqual({
        chosen_list: 'Cleric',
        spellcasting_ability: 'wisdom',
    });
    expect(JSON.parse(String(source('Human').config))).toEqual({
        origin_feat_key: '2024:feat:magic-initiate',
        origin_feat_config: {
            chosen_list: 'Cleric',
            spellcasting_ability: 'wisdom',
        },
    });

    const routeSlotIds = body.workspace.report.access_routes.map((route) => route.slot_id);
    for (const slot of after) {
        expect(routeSlotIds).not.toContain(slot.id);
        expect(body.workspace.report.invalid_selections.map((invalid) => invalid.id)).toContain(slot.id);
        await expect(slotRow(page, slot.id).locator('td').nth(10)).toContainText('Invalid');
        await expect(slotRow(page, slot.id).locator('xpath=following-sibling::tr[1]')).toContainText(
            'Selected spell is not on an allowed spell list.',
        );
    }
});

test('S14: concurrent edits to different slots both persist with distinct operation UUIDs', async ({ page, browser }) => {
    const targets = slotFixtures().filter((slot) => slot.rule_key === 'wizard-cantrips'
        && [2, 3].includes(slot.ordinal));
    expect(targets).toHaveLength(2);
    const secondContext = await browser.newContext({
        baseURL: 'https://dnd-spell-planner.ddev.site',
        ignoreHTTPSErrors: true,
    });
    const secondPage = await secondContext.newPage();
    try {
        await secondPage.goto('/characters/1');
        await expect(page.getByText(/revision 0$/)).toBeVisible();
        await expect(secondPage.getByText(/revision 0$/)).toBeVisible();

        const first = await selectSpell(page, targets[0]!.id, 'Fire Bolt');
        const second = await selectSpell(secondPage, targets[1]!.id, 'Minor Illusion');
        expect(first.revision).toBe(1);
        expect(second.revision).toBe(2);
        expect(targets.map((target) => requireSlot(
            slots().find((slot) => slot.id === target.id),
            `concurrently edited slot ${target.id}`,
        ).current_spell_version_id)).toEqual([
            spellVersionId('2024:fire-bolt'),
            spellVersionId('2024:minor-illusion'),
        ]);

        const operations = characterOperations();
        expect(operations).toHaveLength(2);
        expect(operations.map((operation) => operation.expected_revision)).toEqual([0, 0]);
        expect(operations.map((operation) => operation.resulting_revision)).toEqual([1, 2]);
        const operationUuids = operations.map((operation) => String(operation.operation_uuid));
        expect(new Set(operationUuids).size).toBe(2);
        const audit = auditLog();
        expect(audit).toHaveLength(2);
        expect(audit.map((entry) => entry.entity_type)).toEqual([
            'spell_selection_slots',
            'spell_selection_slots',
        ]);
        expect(audit.map((entry) => entry.entity_id).sort()).toEqual(targets.map((target) => target.id).sort());
        expect(new Set(audit.map((entry) => String(entry.operation_uuid)))).toEqual(new Set(operationUuids));
    } finally {
        await secondContext.close();
    }
});

test('S15: repeated Magic Initiate refuses the same list and accepts a different list', async ({ page }) => {
    const sourceConfiguration = reportSection(page, 'Source configuration');
    await expect(sourceConfiguration).toBeVisible();
    expect(await sourceConfiguration.getByRole('button', { name: /add.*magic initiate/i }).count()).toBe(0);
    const beforeSlots = slots();
    const beforeSources = slotFixtures().filter((slot) => slot.source_name === 'Magic Initiate: Wizard');
    expect(beforeSources).toHaveLength(3);

    const refused = addMagicInitiateSource('Wizard');
    expect(refused).toEqual({
        accepted: false,
        error: "Magic Initiate already uses chosen_list 'Wizard' for this character.",
        source: null,
        slots: [],
    });
    expect(slots()).toEqual(beforeSlots);
    expect(slotFixtures().filter((slot) => slot.source_name === 'Magic Initiate: Wizard')).toHaveLength(3);

    const accepted = addMagicInitiateSource('Cleric');
    expect(accepted.accepted).toBe(true);
    expect(accepted.error).toBeNull();
    expect(accepted.source).toEqual(expect.objectContaining({
        character_id: 1,
        source_type: 'feat',
        display_name: 'Magic Initiate: Cleric',
        state: 'active',
    }));
    expect(accepted.slots).toHaveLength(3);
    expect(accepted.slots.map((slot) => slot.allowed_spell_lists)).toEqual([
        '["Cleric"]', '["Cleric"]', '["Cleric"]',
    ]);

    await page.reload({ waitUntil: 'networkidle' });
    await expect(page.getByRole('heading', { name: 'A6 Sixfold Spellcaster', level: 1 })).toBeVisible();
    await expect(reportSection(page, 'Source configuration').getByText('Magic Initiate: Cleric', { exact: true })).toBeVisible();
    for (const slot of accepted.slots) {
        await expect(page.getByRole('combobox', { name: `Spell selection for slot ${slot.id}` })).toBeVisible();
    }
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

async function selectSpellVersion(
    page: Page,
    slotId: number,
    spellName: string,
    edition: '2014' | '2024',
): Promise<MutationBody> {
    const combobox = page.getByRole('combobox', { name: `Spell selection for slot ${slotId}` });
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
    if (!eligible) throw new Error(`No eligible-spell response was received for ${spellName} (${edition}).`);
    expect(eligible.status()).toBe(200);
    const searchBody = await eligible.json() as {
        spells: Array<{ id: number; name: string; edition: string }>;
    };
    const matching = searchBody.spells.filter((spell) => spell.name === spellName && spell.edition === edition);
    expect(matching).toHaveLength(1);
    const option = page.locator(`[id="spell-options-${slotId}"]`).getByRole('option')
        .filter({ hasText: spellName })
        .filter({ hasText: edition });
    await expect(option).toHaveCount(1);
    await expect(option).toBeVisible();

    const mutationResponse = page.waitForResponse(isMutationResponse);
    await option.click();
    const response = await mutationResponse;
    expect(response.status()).toBe(200);
    const body = await response.json() as MutationBody;
    await expect(page.getByText(new RegExp(`revision ${body.revision}$`))).toBeVisible();
    await expect(combobox).toHaveValue(spellName);
    expect(requireSlot(slots().find((slot) => slot.id === slotId), `${spellName} ${edition} slot`)
        .current_spell_version_id).toBe(matching[0]!.id);

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

async function setAbility(page: Page, abbreviation: 'INT' | 'WIS', score: number): Promise<void> {
    const input = abilityInput(page, abbreviation);
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

async function setClassLevel(page: Page, className: string, level: number): Promise<MutationBody> {
    const input = classEntry(page, className).getByLabel('Level');
    const mutationResponse = page.waitForResponse(isMutationResponse);
    await input.fill(String(level));
    await input.press('Tab');
    const response = await mutationResponse;
    expect(response.status()).toBe(200);
    const body = await response.json() as { revision: number };
    await expect(page.getByText(new RegExp(`revision ${body.revision}$`))).toBeVisible();
    await expect(input).toHaveValue(String(level));
    await expect(page.getByText('Autosaved', { exact: true })).toBeVisible();

    return body as MutationBody;
}

async function addClass(page: Page, className: string): Promise<MutationBody> {
    const classes = reportSection(page, 'Classes');
    await classes.getByLabel('Class to add').selectOption({ label: className });
    const mutationResponse = page.waitForResponse(isMutationResponse);
    await classes.getByRole('button', { name: 'Add class' }).click();
    const response = await mutationResponse;
    expect(response.status()).toBe(200);
    const body = await response.json() as MutationBody;
    await expect(page.getByText(new RegExp(`revision ${body.revision}$`))).toBeVisible();
    await expect(classEntry(page, className)).toBeVisible();
    await expect(page.getByText('Autosaved', { exact: true })).toBeVisible();

    return body;
}

function abilityInput(page: Page, abbreviation: 'INT' | 'WIS'): Locator {
    const abilitySection = reportSection(page, 'Ability scores');

    return abilitySection.locator('label').filter({ hasText: abbreviation }).locator('input');
}

function classEntry(page: Page, className: string): Locator {
    return reportSection(page, 'Classes').getByText(className, { exact: true }).locator('..');
}

function reportSection(page: Page, heading: string): Locator {
    return page.locator('section').filter({ has: page.getByRole('heading', { name: heading, exact: true }) });
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

function withoutSlotLifecycle(slot: ReturnType<typeof slots>[number]): Record<string, number | string | null> {
    const {
        state: _state,
        orphan_reason_code: _orphanReasonCode,
        orphaned_at: _orphanedAt,
        prior_config: _priorConfig,
        updated_at: _updatedAt,
        ...semantics
    } = slot;

    return semantics;
}

function withoutSlotLifecycleOrEligibility(
    slot: ReturnType<typeof slots>[number],
): Record<string, number | string | null> {
    const {
        selection_eligibility: _selectionEligibility,
        selection_invalid_reason: _selectionInvalidReason,
        ...semantics
    } = withoutSlotLifecycle(slot);

    return semantics;
}

function wizardSpellbookPanel(page: Page): Locator {
    return page.locator('section').filter({ has: page.getByRole('heading', { name: 'Wizard spellbook access' }) });
}

function wizardList(panel: Locator, heading: string): Locator {
    return panel.getByRole('heading', { name: heading }).locator('..').getByRole('listitem');
}

async function labelledFormControls(page: Page): Promise<{
    total: number;
    unlabelled: Array<{ tag: string; type: string; id: string }>;
}> {
    return page.locator('body').evaluate((body) => {
        const controls = [...body.querySelectorAll<HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement>(
            'input:not([type="hidden"]), select, textarea',
        )];
        const unlabelled = controls.filter((control) => {
            const ariaLabel = control.getAttribute('aria-label')?.trim();
            const labelledBy = control.getAttribute('aria-labelledby')?.split(/\s+/).some((id) => {
                const label = document.getElementById(id);

                return Boolean(label?.textContent?.trim());
            });
            const nativeLabel = [...(control.labels ?? [])].some((label) => Boolean(label.textContent?.trim()));

            return !ariaLabel && !labelledBy && !nativeLabel;
        }).map((control) => ({
            tag: control.tagName.toLowerCase(),
            type: control instanceof HTMLInputElement ? control.type : '',
            id: control.id,
        }));

        return { total: controls.length, unlabelled };
    });
}

async function gridFocusState(page: Page, selector: string): Promise<{
    index: number;
    role: string | null;
    focusVisible: boolean;
    visibleOutline: boolean;
    description: string;
    outline: string;
}> {
    return page.locator('section').filter({ has: page.getByRole('heading', { name: 'Spell choice slots' }) })
        .evaluate((section, controlSelector) => {
            const controls = [...section.querySelectorAll<HTMLElement>(controlSelector)];
            const active = document.activeElement as HTMLElement | null;
            const style = active ? getComputedStyle(active) : null;
            const outline = style
                ? `${style.outlineStyle} ${style.outlineWidth} ${style.outlineColor}`
                : 'no active element';
            const transparent = style?.outlineColor === 'transparent'
                || style?.outlineColor === 'rgba(0, 0, 0, 0)';

            return {
                index: active ? controls.indexOf(active) : -1,
                role: active?.getAttribute('role') ?? null,
                focusVisible: active?.matches(':focus-visible') ?? false,
                visibleOutline: Boolean(style && style.outlineStyle !== 'none'
                    && Number.parseFloat(style.outlineWidth) > 0 && !transparent),
                description: active
                    ? `${active.tagName.toLowerCase()} ${active.getAttribute('aria-label') ?? active.textContent?.trim() ?? ''}`
                    : 'no active element',
                outline,
            };
        }, selector);
}

async function keyboardAuditGrid(page: Page, grid: Locator, selector: string): Promise<{
    visited: number[];
    focusFailures: Array<{ index: number; description: string; outline: string }>;
}> {
    const expectedControls = await grid.locator(selector).count();
    const visited = new Set<number>();
    const focusFailures: Array<{ index: number; description: string; outline: string }> = [];
    await page.evaluate(() => (document.activeElement as HTMLElement | null)?.blur());
    for (let press = 0; press < 240 && visited.size < expectedControls; press += 1) {
        await page.keyboard.press('Tab');
        const state = await gridFocusState(page, selector);
        if (state.index >= 0) {
            visited.add(state.index);
            if (!state.focusVisible || !state.visibleOutline) focusFailures.push(state);
        }
        if (state.role === 'combobox') await page.keyboard.press('Escape');
    }

    return {
        visited: [...visited].sort((left, right) => left - right),
        focusFailures,
    };
}

async function expectKeyboardReachableWithVisibleFocus(page: Page, control: Locator): Promise<void> {
    await control.focus();
    await page.keyboard.press('Shift+Tab');
    await page.keyboard.press('Tab');
    await expect(control).toBeFocused();
    const focus = await control.evaluate((element) => {
        const style = getComputedStyle(element);
        const transparent = style.outlineColor === 'transparent'
            || style.outlineColor === 'rgba(0, 0, 0, 0)';

        return {
            focusVisible: element.matches(':focus-visible'),
            visibleOutline: style.outlineStyle !== 'none'
                && Number.parseFloat(style.outlineWidth) > 0 && !transparent,
        };
    });
    expect(focus).toEqual({ focusVisible: true, visibleOutline: true });
}

async function warningsWithoutTextCue(page: Page): Promise<string[]> {
    return page.locator('.status-warning').evaluateAll((warnings) => warnings
        .filter((warning) => {
            const text = [...warning.childNodes]
                .filter((node) => !(node instanceof HTMLElement && node.getAttribute('aria-hidden') === 'true'))
                .map((node) => node.textContent ?? '')
                .join('')
                .trim();
            const accessibleIcon = warning.querySelector('[aria-label], [title], img[alt]:not([alt=""])');

            return text === '' && accessibleIcon === null;
        })
        .map((warning) => warning.outerHTML));
}
