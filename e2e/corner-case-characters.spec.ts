import { expect, test, type Locator, type Page, type Response } from '@playwright/test';
import { resetDatabase } from './support/database';

interface WorkspaceSlot {
    id: number;
    slot_key: string;
    source: string;
    level_min: number;
    level_max: number;
    attack_bonus: number | null;
    save_dc: number | null;
}

interface CharacterClassReport {
    name: string;
    subclass: string | null;
    class_level: number;
    max_preparable_level: number;
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
            classes: CharacterClassReport[];
        };
        slots: WorkspaceSlot[];
    };
}

test.beforeEach(async ({ page }) => {
    resetDatabase();
    await page.goto('/');
});

test('C1: Thirds Company rounds Eldritch Knight and Arcane Trickster separately', async ({ page }) => {
    const characterId = await createCharacter(page, 'Thirds Company');

    await addClass(page, characterId, 'Fighter');
    await setClassLevel(page, characterId, 'Fighter', 5);
    const fighter = await setSubclass(page, characterId, 'Fighter', 'Eldritch Knight');
    expect(fighter.workspace.slots.filter((slot) => slot.source === 'Eldritch Knight')).toHaveLength(6);

    await addClass(page, characterId, 'Rogue');
    await setClassLevel(page, characterId, 'Rogue', 4);
    const complete = await setSubclass(page, characterId, 'Rogue', 'Arcane Trickster');

    expect(complete.workspace.report.caster).toEqual({
        caster_level: 2,
        slots: [{ level: 1, count: 3 }],
        pact_magic: null,
    });
    expect(classSummary(complete.workspace.report.classes)).toEqual([
        { name: 'Fighter', subclass: 'Eldritch Knight', class_level: 5, max_preparable_level: 1 },
        { name: 'Rogue', subclass: 'Arcane Trickster', class_level: 4, max_preparable_level: 1 },
    ]);
    expect(complete.workspace.slots.filter((slot) => slot.source === 'Arcane Trickster')).toHaveLength(7);
    await expect(liveReport(page).getByText('Caster level').locator('..')).toContainText('2');
    await expect(liveReport(page).getByText('L1: 3', { exact: true })).toBeVisible();
    await expect(classCeilings(page)).toContainText('Fighter 5 · Eldritch Knight');
    await expect(classCeilings(page)).toContainText('Rogue 4 · Arcane Trickster');
});

test('C2: Iron Arcana spans negative casting math, own-table routing, and non-caster neutrality', async ({ page }) => {
    const characterId = await createCharacter(page, 'Iron Arcana');
    await setAbility(page, characterId, 'INT', 8);
    await addClass(page, characterId, 'Fighter');
    await setClassLevel(page, characterId, 'Fighter', 3);
    const subclass = await setSubclass(page, characterId, 'Fighter', 'Eldritch Knight');
    const saveSlot = requireSlot(
        subclass.workspace.slots.find((slot) => slot.slot_key.includes(':eldritch-knight-cantrips:1')),
        'the first Eldritch Knight cantrip slot',
    );
    const attackSlot = requireSlot(
        subclass.workspace.slots.find((slot) => slot.slot_key.includes(':eldritch-knight-cantrips:2')),
        'the second Eldritch Knight cantrip slot',
    );

    await selectSpell(page, characterId, saveSlot.id, 'Acid Splash');
    await selectSpell(page, characterId, attackSlot.id, 'Fire Bolt');
    await expect(slotRow(page, saveSlot.id).locator('td').nth(6)).toHaveText('9');
    await expect(slotRow(page, attackSlot.id).locator('td').nth(6)).toHaveText('+1');

    await setAbility(page, characterId, 'INT', 20);
    await expect(slotRow(page, saveSlot.id).locator('td').nth(6)).toHaveText('15');
    await expect(slotRow(page, attackSlot.id).locator('td').nth(6)).toHaveText('+7');

    const levelNineteen = await setClassLevel(page, characterId, 'Fighter', 19);
    expect(levelNineteen.workspace.report.caster).toEqual({
        caster_level: 6,
        slots: [
            { level: 1, count: 4 },
            { level: 2, count: 3 },
            { level: 3, count: 3 },
            { level: 4, count: 1 },
        ],
        pact_magic: null,
    });
    await expect(liveReport(page).getByText('L4: 1', { exact: true })).toBeVisible();

    const withBarbarian = await addClass(page, characterId, 'Barbarian');
    expect(withBarbarian.workspace.report.caster).toEqual(levelNineteen.workspace.report.caster);
    expect(classSummary(withBarbarian.workspace.report.classes)).toContainEqual({
        name: 'Barbarian', subclass: null, class_level: 1, max_preparable_level: 0,
    });
    await expect(classCeilings(page)).toContainText('Barbarian 1');
    await expect(liveReport(page).getByText('L4: 1', { exact: true })).toBeVisible();
});

test('C3: Pact Apex reaches Warlock 17 and Bard 3 through the GUI with all separate pools', async ({ page }) => {
    const characterId = await createCharacter(page, 'Pact Apex GUI');
    await addClass(page, characterId, 'Warlock');
    await setClassLevel(page, characterId, 'Warlock', 17);
    await addClass(page, characterId, 'Bard');
    const complete = await setClassLevel(page, characterId, 'Bard', 3);

    expect(complete.workspace.report.caster).toEqual({
        caster_level: 3,
        slots: [{ level: 1, count: 4 }, { level: 2, count: 2 }],
        pact_magic: { count: 4, level: 5 },
    });
    expect(classSummary(complete.workspace.report.classes)).toEqual([
        { name: 'Bard', subclass: null, class_level: 3, max_preparable_level: 2 },
        { name: 'Warlock', subclass: null, class_level: 17, max_preparable_level: 5 },
    ]);
    expect(complete.workspace.slots
        .filter((slot) => slot.slot_key.includes(':warlock-mystic-arcanum-'))
        .map((slot) => [slot.level_min, slot.level_max]))
        .toEqual([[6, 6], [7, 7], [8, 8], [9, 9]]);
    await expect(liveReport(page).getByText('L1: 4', { exact: true })).toBeVisible();
    await expect(liveReport(page).getByText('L2: 2', { exact: true })).toBeVisible();
    await expect(liveReport(page).getByText('Pact Magic: 4 × level 5', { exact: true })).toBeVisible();
});

test('C4: Ceiling Split owns ninth-level slots but neither class offers sixth-level candidates', async ({ page }) => {
    const characterId = await createCharacter(page, 'Ceiling Split');
    await addClass(page, characterId, 'Bard');
    await setClassLevel(page, characterId, 'Bard', 10);
    await addClass(page, characterId, 'Sorcerer');
    const complete = await setClassLevel(page, characterId, 'Sorcerer', 10);

    expect(complete.workspace.report.caster).toEqual({
        caster_level: 20,
        slots: [
            { level: 1, count: 4 }, { level: 2, count: 3 }, { level: 3, count: 3 },
            { level: 4, count: 3 }, { level: 5, count: 3 }, { level: 6, count: 2 },
            { level: 7, count: 2 }, { level: 8, count: 1 }, { level: 9, count: 1 },
        ],
        pact_magic: null,
    });
    expect(classSummary(complete.workspace.report.classes)).toEqual([
        { name: 'Bard', subclass: null, class_level: 10, max_preparable_level: 5 },
        { name: 'Sorcerer', subclass: null, class_level: 10, max_preparable_level: 5 },
    ]);
    await expect(liveReport(page).getByText('L9: 1', { exact: true })).toBeVisible();
    await expect(classCeilings(page).getByText('max L5', { exact: true })).toHaveCount(2);

    for (const source of ['Bard 10', 'Sorcerer 10']) {
        const preparedSlots = complete.workspace.slots.filter((candidate) => (
            candidate.source === source && candidate.slot_key.includes('-prepared:')
        ));
        expect(preparedSlots.length).toBeGreaterThan(0);
        expect([...new Set(preparedSlots.map((slot) => slot.level_max))]).toEqual([5]);
        const slot = requireSlot(
            preparedSlots.find((candidate) => candidate.level_min === 1),
            `a prepared slot for ${source}`,
        );
        await expectCandidateAbsent(page, characterId, slot.id, 'Eyebite');
    }
});

async function createCharacter(page: Page, name: string): Promise<number> {
    await page.getByLabel('Character name').fill(name);
    await Promise.all([
        page.waitForURL(/\/characters\/\d+$/),
        page.getByRole('button', { name: 'Create character' }).click(),
    ]);
    await expect(page.getByRole('heading', { name, level: 1 })).toBeVisible();
    const match = page.url().match(/\/characters\/(\d+)$/);
    if (!match) throw new Error(`Could not read the new character id from ${page.url()}.`);

    return Number(match[1]);
}

async function addClass(page: Page, characterId: number, className: string): Promise<MutationBody> {
    const section = classes(page);
    await section.getByLabel('Class to add').selectOption({ label: className });
    const response = page.waitForResponse(isMutationResponse(characterId));
    await section.getByRole('button', { name: 'Add class' }).click();
    const body = await mutationBody(await response);
    await expect(classEntry(page, className)).toBeVisible();

    return body;
}

async function setClassLevel(
    page: Page,
    characterId: number,
    className: string,
    level: number,
): Promise<MutationBody> {
    const input = classEntry(page, className).getByLabel('Level');
    const response = page.waitForResponse(isMutationResponse(characterId));
    await input.fill(String(level));
    await input.press('Tab');
    const body = await mutationBody(await response);
    await expect(input).toHaveValue(String(level));

    return body;
}

async function setSubclass(
    page: Page,
    characterId: number,
    className: string,
    subclassName: string,
): Promise<MutationBody> {
    const select = classEntry(page, className).getByLabel('Subclass');
    const response = page.waitForResponse(isMutationResponse(characterId));
    await select.selectOption({ label: subclassName });
    const body = await mutationBody(await response);
    await expect(select).toHaveValue(/\d+/);

    return body;
}

async function setAbility(
    page: Page,
    characterId: number,
    abbreviation: 'INT',
    score: number,
): Promise<MutationBody> {
    const input = reportSection(page, 'Ability scores').locator('label')
        .filter({ hasText: abbreviation }).locator('input');
    const response = page.waitForResponse(isMutationResponse(characterId));
    await input.fill(String(score));
    await input.press('Tab');
    const body = await mutationBody(await response);
    await expect(input).toHaveValue(String(score));

    return body;
}

async function selectSpell(
    page: Page,
    characterId: number,
    slotId: number,
    spellName: string,
): Promise<MutationBody> {
    const combobox = page.getByRole('combobox', { name: `Spell selection for slot ${slotId}` });
    const eligible = page.waitForResponse((response) => {
        const url = new URL(response.url());
        return response.request().method() === 'GET'
            && url.pathname === `/characters/${characterId}/slots/${slotId}/eligible-spells`
            && url.searchParams.get('q') === spellName;
    });
    await combobox.fill(spellName);
    const search = await eligible;
    expect(search.status()).toBe(200);
    expect((await search.json() as { spells: Array<{ name: string }> }).spells)
        .toContainEqual(expect.objectContaining({ name: spellName }));
    const mutation = page.waitForResponse(isMutationResponse(characterId));
    await page.locator(`#spell-options-${slotId}`).getByRole('option').filter({ hasText: spellName }).click();
    const body = await mutationBody(await mutation);
    await expect(combobox).toHaveValue(spellName);

    return body;
}

async function expectCandidateAbsent(
    page: Page,
    characterId: number,
    slotId: number,
    spellName: string,
): Promise<void> {
    const combobox = page.getByRole('combobox', { name: `Spell selection for slot ${slotId}` });
    const eligible = page.waitForResponse((response) => {
        const url = new URL(response.url());
        return url.pathname === `/characters/${characterId}/slots/${slotId}/eligible-spells`
            && url.searchParams.get('q') === spellName;
    });
    await combobox.fill(spellName);
    const response = await eligible;
    expect(response.status()).toBe(200);
    const body = await response.json() as { spells: Array<{ name: string; level: number }> };
    expect(body.spells).not.toContainEqual(expect.objectContaining({ name: spellName, level: 6 }));
    await expect(page.locator(`#spell-options-${slotId}`).getByRole('option')).toHaveCount(0);
    await expect(page.locator(`#spell-options-${slotId}`)).toContainText('No eligible spells match this search.');
    await combobox.press('Escape');
}

function classes(page: Page): Locator {
    return reportSection(page, 'Classes');
}

function classEntry(page: Page, className: string): Locator {
    return classes(page).getByText(className, { exact: true }).locator('..');
}

function liveReport(page: Page): Locator {
    return reportSection(page, 'Live report');
}

function classCeilings(page: Page): Locator {
    return reportSection(page, 'Class preparation ceilings');
}

function reportSection(page: Page, heading: string): Locator {
    return page.locator('section').filter({ has: page.getByRole('heading', { name: heading, exact: true }) });
}

function slotRow(page: Page, slotId: number): Locator {
    return page.getByRole('combobox', { name: `Spell selection for slot ${slotId}` }).locator('xpath=ancestor::tr');
}

function isMutationResponse(characterId: number): (response: Response) => boolean {
    return (response) => response.request().method() === 'POST'
        && response.url().endsWith(`/characters/${characterId}/mutations`);
}

async function mutationBody(response: Response): Promise<MutationBody> {
    expect(response.status()).toBe(200);

    return await response.json() as MutationBody;
}

function requireSlot(slot: WorkspaceSlot | undefined, description: string): WorkspaceSlot {
    if (!slot) throw new Error(`Could not find ${description}.`);

    return slot;
}

function classSummary(classesToSummarize: CharacterClassReport[]): CharacterClassReport[] {
    return classesToSummarize.map((entry) => ({
        name: entry.name,
        subclass: entry.subclass,
        class_level: entry.class_level,
        max_preparable_level: entry.max_preparable_level,
    }));
}
