import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { readFile, readdir } from 'node:fs/promises';
import { parse as parseHtml } from 'node-html-parser';
import {
    applyCorrections,
    canonicalSourceBook,
    classify,
    parseIndex,
    parseSpellLevel,
    parseSpellLists,
    parseSpellPage,
} from './scrape-spells.mjs';

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

    test('a saving-throw benefit does not invent a spell save DC', () => {
        const r = classify('You make Constitution saving throws with advantage.');
        assert.deepEqual(r.saveAbilities, []);
        assert.equal(r.effectReliabilityCategory, 'fixed_effect');
    });

    test('concentration recognizes BOTH edition formats', () => {
        assert.equal(classify('x', 'C, up to 10 minutes').concentration, true);
        assert.equal(classify('x', 'Concentration, up to 1 minute').concentration, true);
        assert.equal(classify('x', 'Instantaneous').concentration, false);
    });

    test('ritual recognizes BOTH edition index formats and the spelled-out detail format', () => {
        assert.equal(classify('x', '', 'Action or R').ritual, true);
        assert.equal(classify('x', '', '1 hour or R').ritual, true);
        assert.equal(classify('x', '', '1 Action R').ritual, true);
        assert.equal(classify('x', '', '1 Hour R').ritual, true);
        assert.equal(classify('x', '', '1 hour or Ritual').ritual, true);
        assert.equal(classify('x', '', 'Action').ritual, false);
    });
});

describe('generated catalog preserves concentration and ritual corpus invariants', () => {
    test('both editions remain populated and named reference spells retain their traits', async () => {
        const directory = new URL('../data/index/', import.meta.url);
        const recordsByVersion = new Map();
        for (const file of (await readdir(directory)).filter((name) => name.endsWith('.json'))) {
            for (const record of JSON.parse(await readFile(new URL(file, directory), 'utf8'))) {
                recordsByVersion.set(record.versionKey, record);
            }
        }

        const modern = [...recordsByVersion.values()].filter((record) => record.edition === '2024');
        const legacy = [...recordsByVersion.values()].filter((record) => record.edition === '2014');
        assert.equal(modern.length, 419);
        assert.equal(modern.filter((record) => record.concentration).length, 175);
        assert.equal(modern.filter((record) => record.ritual).length, 33);
        assert.equal(legacy.length, 524);
        assert.equal(legacy.filter((record) => record.concentration).length, 234);
        assert.equal(legacy.filter((record) => record.ritual).length, 34);

        for (const edition of ['2024', '2014']) {
            const records = edition === '2024' ? modern : legacy;
            assert.equal(records.find((record) => record.name === 'Bane')?.concentration, true);
            assert.equal(records.find((record) => record.name === 'Find Familiar')?.ritual, true);
        }
    });
});

describe('parseSpellPage classifies spell effects outside paragraphs', () => {
    const modernRows = async () => new Map(parseIndex(
        await readFile(new URL('./.cache/2024/__index__.html', import.meta.url), 'utf8'),
        '2024',
    ).map(applyCorrections).map((row) => [row.slug, row]));

    const parseCached = async (slug) => {
        const rows = await modernRows();
        return parseSpellPage(
            await readFile(new URL(`./.cache/2024/${slug}.html`, import.meta.url), 'utf8'),
            rows.get(slug),
        );
    };

    test('includes Magic Circle list items and Prismatic table cells', async () => {
        assert.deepEqual((await parseCached('magic-circle')).saveAbilities, ['charisma']);
        assert.deepEqual(
            (await parseCached('prismatic-spray')).saveAbilities,
            ['dexterity', 'constitution', 'wisdom'],
        );
        assert.deepEqual(
            (await parseCached('prismatic-wall')).saveAbilities,
            ['constitution', 'dexterity', 'wisdom'],
        );
    });

    test('excludes saves belonging to embedded summoned-creature stat blocks', async () => {
        assert.deepEqual((await parseCached('giant-insect')).saveAbilities, []);
        assert.deepEqual((await parseCached('summon-dragon')).saveAbilities, []);

        const legacyIndex = parseIndex(
            await readFile(new URL('./.cache/2014/__index__.html', import.meta.url), 'utf8'),
            '2014',
        );
        const summonFey = legacyIndex.find((row) => row.slug === 'summon-fey');
        assert.deepEqual(parseSpellPage(
            await readFile(new URL('./.cache/2014/summon-fey.html', import.meta.url), 'utf8'),
            summonFey,
        ).saveAbilities, []);
        const summonDraconicSpirit = legacyIndex.find((row) => row.slug === 'summon-draconic-spirit');
        assert.deepEqual(parseSpellPage(
            await readFile(new URL('./.cache/2014/summon-draconic-spirit.html', import.meta.url), 'utf8'),
            summonDraconicSpirit,
        ).saveAbilities, []);
    });
});

describe('upstream corrections are explicit and guarded', () => {
    test('corrects Befuddlement only when the cached typo still matches the manifest', async () => {
        const rows = parseIndex(
            await readFile(new URL('./.cache/2024/__index__.html', import.meta.url), 'utf8'),
            '2024',
        );
        const raw = rows.find((row) => row.slug === 'befuddlement');
        assert.equal(raw.duration, 'Instantanous');
        assert.equal(applyCorrections(raw).duration, 'Instantaneous');
        assert.deepEqual(Object.fromEntries(
            ['antilife-shell', 'expeditious-retreat', 'fog-cloud'].map((slug) => {
                const row = rows.find((candidate) => candidate.slug === slug);
                return [slug, applyCorrections(row).duration];
            }),
        ), {
            'antilife-shell': 'C, up to 1 hour',
            'expeditious-retreat': 'C, up to 10 minutes',
            'fog-cloud': 'C, up to 1 hour',
        });
        assert.throws(
            () => applyCorrections({ ...raw, duration: 'Already fixed upstream' }),
            /Stale correction 2024:befuddlement.duration/,
        );
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
        assert.equal(parseSpellLevel('1st-level divination (dunamancy:chronurgy)'), 1);
        assert.equal(parseSpellLevel('2nd-level conjuration (ritual) (dunamancy)'), 2);
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
        const runOn = "Source: Forgotten Realms - Heroes of Faerun Level 8 Evocation (Cleric, Wizard) Casting Time: Bonus Action";
        assert.equal(parseSpellLevel(runOn), 8);
    });

    test('does not mistake description prose for a level line', () => {
        // Holy Star of Mystra's own text says "a spell of level 7 or lower".
        assert.equal(parseSpellLevel('if you succeed on a saving throw against a spell of level 7 or lower'), -1);
    });

    test('prefers an explicit numeric header over later cantrip prose', () => {
        const runOn = 'Source: Test Book Level 8 Evocation (Wizard) Casting Time: Action This spell empowers a cantrip you cast.';
        assert.equal(parseSpellLevel(runOn), 8);
    });
});

describe('parseSpellLevel rejects truncated out-of-range numeric tokens', () => {
    test('rejects exact modern legacy and run-on corrupting cases', () => {
        assert.equal(parseSpellLevel('Level 10 Evocation (Wizard)'), -1);
        assert.equal(parseSpellLevel('10th-level evocation'), -1);
        assert.equal(parseSpellLevel('Source Book Level 90 Evocation (Wizard) Casting Time: Action'), -1);
    });

    test('accepts the valid upper boundary without truncation', () => {
        assert.equal(parseSpellLevel('Level 9 Evocation (Wizard)'), 9);
        assert.equal(parseSpellLevel('9th-level evocation'), 9);
    });
});

describe('parseSpellPage extracts only a real level header from run-on content', () => {
    const indexRow = {
        slug: 'run-on-test',
        duration: 'Instantaneous',
        castingTime: 'Action',
    };

    test('keeps a level 8 spell at level 8 when its merged description mentions a cantrip', () => {
        const html = `<div id="page-content"><p>
            Source: Heroes of Faerun, page 99
            Level 8 Evocation (Cleric, Wizard)
            Casting Time: Action Range: 60 feet Components: V, S Duration: Instantaneous
            Choose a cantrip you know; this spell magnifies its effect.
        </p></div>`;
        assert.equal(parseSpellPage(html, indexRow).level, 8);
    });

    test('still recognizes a genuine cantrip header in a merged stat block', () => {
        const html = `<div id="page-content"><p>
            Source: Player's Handbook
            Evocation Cantrip (Sorcerer, Wizard)
            Casting Time: Action Range: 60 feet Components: V, S Duration: Instantaneous
        </p></div>`;
        assert.equal(parseSpellPage(html, indexRow).level, 0);
    });

    test('rejects a fractional level when no complete real header exists', () => {
        const html = `<div id="page-content">
            <p>Source: Test Book</p>
            <p>Level 0.5 Evocation (Wizard)</p>
            <p>Casting Time: Action Range: 60 feet Components: V, S Duration: Instantaneous</p>
        </div>`;
        assert.equal(parseSpellPage(html, indexRow).level, -1);
    });

    test('rejects cantrip and numbered-level prose when the real header is absent', () => {
        const cantripProse = `<div id="page-content">
            <p>Source: Test Book</p>
            <p>This behaves like an Evocation cantrip when you cast it.</p>
        </div>`;
        const numberedProse = `<div id="page-content">
            <p>Source: Test Book</p>
            <p>You can cast it as a 2nd-level evocation spell.</p>
        </div>`;
        assert.equal(parseSpellPage(cantripProse, indexRow).level, -1);
        assert.equal(parseSpellPage(numberedProse, indexRow).level, -1);
    });
});

describe('parseSpellLists audits every cached detail page', () => {
    const oldParseSpellLists = (pageText) => {
        const match = / Spell Lists\.\s*([A-Za-z,\s()]+?)(?:\s{2,}|window|$)/.exec(
            ' ' + pageText.replace(/\s+/g, ' ')
        );
        if (!match) return [];
        return match[1]
            .split(',')
            .map((name) => name.trim())
            .filter((name) => /^[A-Z][A-Za-z ]{2,}$/.test(name));
    };

    test('recovers every stated membership conservatively across all 993 pages', async () => {
        const pages = [];
        for (const edition of ['2014', '2024']) {
            const cacheDirectory = new URL(`./.cache/${edition}/`, import.meta.url);
            const indexHtml = await readFile(new URL('__index__.html', cacheDirectory), 'utf8');
            const indexBySlug = new Map(parseIndex(indexHtml, edition).map((row) => [row.slug, row]));
            const files = (await readdir(cacheDirectory))
                .filter((file) => file.endsWith('.html') && file !== '__index__.html')
                .toSorted();
            for (const file of files) {
                const slug = file.replace(/\.html$/, '');
                const content = parseHtml(await readFile(new URL(file, cacheDirectory), 'utf8'))
                    .querySelector('#page-content');
                assert.ok(content, `missing #page-content for ${edition}/${slug}`);
                const paragraphs = content.querySelectorAll('p')
                    .map((node) => node.text.replace(/\s+/g, ' ').trim());
                const source = paragraphs.find((paragraph) => /^Source:/i.test(paragraph)) || '';
                const after = parseSpellLists(content.text);
                const before = edition === '2024'
                    ? indexBySlug.get(slug)?.spellLists || []
                    : oldParseSpellLists(content.text);
                const effectiveAfter = edition === '2024'
                    ? indexBySlug.get(slug)?.spellLists || []
                    : after;
                pages.push({ edition, slug, source, text: content.text, before, after, effectiveAfter });
            }
        }

        assert.equal(pages.length, 993);
        assert.equal(pages.filter((page) => page.edition === '2014').length, 574);
        assert.equal(pages.filter((page) => page.edition === '2024').length, 419);

        // Modern flattened headers and the index must independently agree. This
        // makes the detail parser auditable without changing the index-first
        // production precedence.
        for (const page of pages.filter((candidate) => candidate.edition === '2024')) {
            assert.deepEqual(page.after, page.effectiveAfter, `modern list mismatch for ${page.slug}`);
        }
        assert.equal(pages.filter((page) => page.after.length === 0).length, 1);
        assert.equal(pages.find((page) => page.after.length === 0)?.slug, 'encode-thoughts');

        const publishedLegacy = pages.filter((page) =>
            page.edition === '2014' && !/unearthed arcana/i.test(page.source)
        );
        const previouslyEmpty = publishedLegacy.filter((page) => page.before.length === 0);
        assert.equal(publishedLegacy.length, 524);
        assert.equal(previouslyEmpty.length, 28);
        assert.equal(previouslyEmpty.filter((page) => page.after.length > 0).length, 28);

        const beforeMemberships = publishedLegacy.flatMap((page) => page.before);
        const afterMemberships = publishedLegacy.flatMap((page) => page.after);
        const recoveredMemberships = publishedLegacy.flatMap((page) =>
            page.after.filter((membership) => !page.before.includes(membership))
        );
        assert.equal(beforeMemberships.length, 1257); // includes the bogus `None`
        assert.equal(afterMemberships.length, 1401);
        assert.equal(recoveredMemberships.length, 145);
        assert.equal(beforeMemberships.filter((membership) => membership === 'None').length, 1);
        assert.equal(afterMemberships.filter((membership) => membership === 'None').length, 0);

        const boundaryPages = publishedLegacy.filter((page) =>
            page.after.some((membership) =>
                !membership.includes('(') && !page.before.includes(membership)
            )
        );
        const optionalPages = publishedLegacy.filter((page) =>
            page.after.some((membership) => membership.endsWith(' (Optional)'))
        );
        const qualifiedPages = publishedLegacy.filter((page) =>
            page.after.some((membership) => / \((Dunamancy|Graviturgy)\)$/.test(membership))
        );
        assert.equal(boundaryPages.length, 8);
        assert.equal(boundaryPages.flatMap((page) =>
            page.after.filter((membership) =>
                !membership.includes('(') && !page.before.includes(membership)
            )
        ).length, 17);
        assert.equal(optionalPages.length, 75);
        assert.equal(optionalPages.flatMap((page) =>
            page.after.filter((membership) => membership.endsWith(' (Optional)'))
        ).length, 122);
        assert.equal(qualifiedPages.length, 6);

        const bySlug = new Map(pages
            .filter((page) => page.edition === '2014')
            .map((page) => [page.slug, page.after]));
        assert.deepEqual(bySlug.get('fast-friends'), ['Bard', 'Cleric', 'Wizard']);
        assert.deepEqual(bySlug.get('encode-thoughts'), []);
        assert.deepEqual(bySlug.get('green-flame-blade'), [
            'Artificer', 'Sorcerer (Optional)', 'Warlock (Optional)', 'Wizard (Optional)',
        ]);
        assert.deepEqual(bySlug.get('spirit-of-death'), ['Sorcerer', 'Warlock', 'Wizard']);
        assert.deepEqual(Object.fromEntries([
            'fortunes-favor', 'gift-of-alacrity', 'magnify-gravity',
            'sapping-sting', 'wristpocket', 'immovable-object',
        ].map((slug) => [slug, bySlug.get(slug)])), {
            'fortunes-favor': ['Wizard (Dunamancy)'],
            'gift-of-alacrity': ['Wizard (Dunamancy)'],
            'magnify-gravity': ['Wizard (Dunamancy)'],
            'sapping-sting': ['Wizard (Dunamancy)'],
            wristpocket: ['Wizard (Dunamancy)'],
            'immovable-object': ['Wizard (Graviturgy)'],
        });
    });
});
