import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { parseIndex, classify, canonicalSourceBook, parseSpellLevel } from './scrape-spells.mjs';

/**
 * These fixtures encode the REAL header layouts, captured from both sites on
 * 2026-07-21. They differ in column count, column order and column naming, so a
 * positional parser silently swaps duration/components between editions. These
 * tests exist to make that regression impossible.
 */
const INDEX_2024 = `<table>
<tr><th>Name</th><th>School</th><th>Spell lists</th><th>Casting Time</th><th>Range</th><th>Components</th><th>Duration</th></tr>
<tr><td><a href="/spell:acid-splash">Acid Splash</a></td><td>Evocation</td><td>Artificer, Sorcerer, Wizard</td><td>Action</td><td>60 feet</td><td>V, S</td><td>Instantaneous</td></tr>
</table>`;

const INDEX_2014 = `<table>
<tr><th>Spell Name</th><th>School</th><th>Casting Time</th><th>Range</th><th>Duration</th><th>Components</th></tr>
<tr><td><a href="/spell:acid-splash">Acid Splash</a></td><td>Conjuration</td><td>1 Action</td><td>60 Feet</td><td>Instantaneous</td><td>V, S</td></tr>
</table>`;

describe('parseIndex is header-driven, not positional', () => {
    test('2024 layout maps components and duration correctly', () => {
        const [row] = parseIndex(INDEX_2024, '2024');
        assert.equal(row.name, 'Acid Splash');
        assert.equal(row.school, 'Evocation');
        assert.equal(row.components, 'V, S');
        assert.equal(row.duration, 'Instantaneous');
        assert.deepEqual(row.spellLists, ['Artificer', 'Sorcerer', 'Wizard']);
        assert.equal(row.slug, 'acid-splash');
    });

    test('2014 layout maps the SAME fields despite reversed column order', () => {
        const [row] = parseIndex(INDEX_2014, '2014');
        assert.equal(row.name, 'Acid Splash');
        assert.equal(row.school, 'Conjuration');
        // Columns 5 and 6 are swapped relative to 2024. Positional parsing fails here.
        assert.equal(row.components, 'V, S');
        assert.equal(row.duration, 'Instantaneous');
        assert.deepEqual(row.spellLists, []);
    });

    test('an unrecognised column fails loudly instead of guessing', () => {
        const changed = INDEX_2024.replace('<th>School</th>', '<th>Magic Type</th>');
        assert.throws(() => parseIndex(changed, '2024'), /Unrecognised column "Magic Type"/);
    });
});

describe('classify treats attack and save as sets', () => {
    test('a spell with both an attack and a save is mixed, not one or the other', () => {
        // Ice Knife: ranged spell attack AND a Dexterity save. Collapsing this to a
        // single scalar ships a wrong fact for every such spell.
        const r = classify('Make a ranged spell attack. Each creature must succeed on a Dexterity saving throw.');
        assert.deepEqual(r.attackModes, ['ranged_spell']);
        assert.deepEqual(r.saveAbilities, ['dexterity']);
        assert.equal(r.effectReliabilityCategory, 'mixed');
    });

    test('multiple distinct save abilities are all retained', () => {
        const r = classify('make a melee spell attack; a Strength saving throw; a Dexterity saving throw');
        assert.deepEqual(r.saveAbilities, ['strength', 'dexterity']);
        assert.deepEqual(r.attackModes, ['melee_spell']);
    });

    test('repeated mentions of one ability do not duplicate', () => {
        const r = classify('a Wisdom saving throw ... another Wisdom saving throw');
        assert.deepEqual(r.saveAbilities, ['wisdom']);
        assert.equal(r.effectReliabilityCategory, 'saving_throw');
    });

    test('a spell with neither is a fixed effect', () => {
        const r = classify('You create a spectral hand.');
        assert.equal(r.effectReliabilityCategory, 'fixed_effect');
        assert.deepEqual(r.attackModes, []);
    });

    test('concentration is read from duration, not the description', () => {
        assert.equal(classify('x', 'Concentration, up to 1 minute').concentration, true);
        assert.equal(classify('x', 'Instantaneous').concentration, false);
    });
});

describe('canonicalSourceBook disambiguates the two PHBs', () => {
    test("the 2024 site's bare \"Player's Handbook\" is qualified by edition", () => {
        // Both sites print the same string; only the origin distinguishes them.
        assert.equal(canonicalSourceBook("Player's Handbook", '2024'), "Player's Handbook (2024)");
        assert.equal(canonicalSourceBook("Player's Handbook", '2014'), "Player's Handbook");
    });

    test('other books pass through untouched', () => {
        assert.equal(
            canonicalSourceBook("Xanathar's Guide to Everything", '2014'),
            "Xanathar's Guide to Everything"
        );
    });
});

describe('parseSpellLevel handles BOTH edition formats', () => {
    test('2024 writes "Level N School (lists)"', () => {
        // Handling only the 2014 form silently gave level -1 to every non-cantrip
        // 2024 spell: 385 of 419 records.
        assert.equal(parseSpellLevel('Level 2 Abjuration (Artificer, Bard, Cleric)'), 2);
        assert.equal(parseSpellLevel('Level 9 Conjuration (Wizard)'), 9);
    });

    test('2014 writes "Nth-level school"', () => {
        assert.equal(parseSpellLevel('2nd-level abjuration'), 2);
        assert.equal(parseSpellLevel('1st-level evocation (ritual)'), 1);
        assert.equal(parseSpellLevel('9th-level necromancy'), 9);
    });

    test('cantrips are level 0 in either format', () => {
        assert.equal(parseSpellLevel('Evocation Cantrip (Sorcerer, Wizard)'), 0);
        assert.equal(parseSpellLevel('Evocation cantrip'), 0);
    });

    test('an unrecognised line returns -1 so the gate can reject it', () => {
        assert.equal(parseSpellLevel('something else entirely'), -1);
        assert.equal(parseSpellLevel(''), -1);
    });
});

describe('parseSpellLevel survives run-on pages', () => {
    test('recovers the level when the stat block is merged into one paragraph', () => {
        const runOn = "Forgotten Realms - Heroes of Faerun Level 8 Evocation (Cleric, Wizard) Casting Time: Bonus Action";
        assert.equal(parseSpellLevel(runOn), 8);
    });

    test('does not mistake description prose for a level line', () => {
        // Holy Star of Mystra's own text says "a spell of level 7 or lower".
        assert.equal(parseSpellLevel('if you succeed on a saving throw against a spell of level 7 or lower'), -1);
    });
});
