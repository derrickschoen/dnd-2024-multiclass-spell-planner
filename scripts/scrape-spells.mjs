#!/usr/bin/env node
/**
 * Standalone spell scraper.
 *
 * This tool is deliberately decoupled from the Laravel app: it never touches the
 * database and nothing in app/ imports it. It only writes data files, which the
 * app then ingests through `php artisan catalog:import` — the same path used for
 * hand-authored CSV/JSON. If this scraper breaks or the sites disappear, the
 * committed data/index/*.json keeps working.
 *
 * Two output tiers:
 *   data/index/<Source>.json + .csv  COMMITTED   facts only, no prose
 *   data/local/<Source>.full.json    GITIGNORED  includes descriptions
 *
 * Usage:
 *   npm run scrape -- --edition 2024 --limit 20
 *   npm run scrape -- --all
 *   npm run scrape -- --verify         re-classify from cache, no network
 */

import { mkdir, writeFile, readFile, readdir } from 'node:fs/promises';
import { existsSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { parse } from 'node-html-parser';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..');
const CACHE = join(ROOT, 'scripts/.cache');
// Verification can target a disposable directory so the committed catalog is
// never rewritten merely to prove cached pages still produce the same dataset.
const OUT_INDEX = process.env.SPELL_SCRAPER_INDEX_DIR || join(ROOT, 'data/index');
const OUT_LOCAL = process.env.SPELL_SCRAPER_LOCAL_DIR || join(ROOT, 'data/local');

const USER_AGENT = 'spell-planner-local/1.0 (personal use; contact: local user)';
const DELAY_MS = 750;
const CONCURRENCY = 2;

/**
 * Verified empirically 2026-07-21 by fetching both index pages: the two sites do
 * NOT share a column layout. 2024 has a "Spell lists" column and orders
 * Components before Duration; 2014 has no class column and orders Duration
 * before Components, and even names the first column differently. A positional
 * parser silently swaps duration/components across editions — hence header-driven
 * parsing, and a hard failure on any header we do not recognise.
 */
export const SITES = {
    2024: { origin: 'http://dnd2024.wikidot.com', indexPath: '/spell:all', edition: '2024' },
    2014: { origin: 'https://dnd5e.wikidot.com', indexPath: '/spells', edition: '2014' },
};

const HEADER_ALIASES = {
    'name': 'name',
    'spell name': 'name',
    'school': 'school',
    'spell lists': 'spellLists',
    'casting time': 'castingTime',
    'range': 'range',
    'components': 'components',
    'duration': 'duration',
};

/**
 * Every source book gets its own output file. This is intentionally NOT an
 * allowlist: the sites keep publishing new books (Heroes of Faerun, Forge of the
 * Artificer, the monthly D&D Beyond drops), and refusing to emit them would mean
 * the dataset silently rots. Instead every source is published and any book not
 * named below is reported at the end of the run, so new content is visible
 * rather than either dropped or slipped in unnoticed.
 *
 * Unearthed Arcana is the one exception: it is playtest material and is excluded
 * unless --allow-ua, so it cannot enter normal eligibility by accident.
 */
const KNOWN_SOURCES = [
    "Player's Handbook (2024)",
    "Player's Handbook",
    "Xanathar's Guide to Everything",
    "Tasha's Cauldron of Everything",
    'Elemental Evil',
    'Sword Coast Adventurer’s Guide',
    'Fizban’s Treasury of Dragons',
    'Strixhaven: A Curriculum of Chaos',
    'Acquisitions Incorporated',
    'Explorer’s Guide to Wildemount',
    'Lost Laboratory of Kwalish',
];

const SOURCE_FILENAMES = {
    "Player's Handbook (2024)": '2024-PHB',
    "Player's Handbook": '2014-PHB',
    "Xanathar's Guide to Everything": 'Xanathars-Guide',
    "Tasha's Cauldron of Everything": 'Tashas-Cauldron',
};

/**
 * The 2024 site's source line reads plain "Player's Handbook" with no year, which
 * is textually identical to the 2014 book. Only the origin site distinguishes
 * them, so the edition must qualify the book name — otherwise every 2024 PHB
 * spell files itself under the 2014 PHB.
 */
export function canonicalSourceBook(rawSourceBook, edition) {
    const raw = (rawSourceBook || '').trim();
    if (edition === '2024' && /^player'?s handbook$/i.test(raw)) return "Player's Handbook (2024)";
    return raw;
}

export const ABILITIES = ['Strength', 'Dexterity', 'Constitution', 'Intelligence', 'Wisdom', 'Charisma'];

const text = (node) => (node?.text ?? '').replace(/\s+/g, ' ').trim();
const slugify = (s) => s.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

/** Cache key includes edition — `chill-touch` exists on BOTH sites with different rules. */
const cachePath = (edition, slug) => join(CACHE, edition, `${slug}.html`);

async function fetchCached(url, edition, slug, { offline = false } = {}) {
    const path = cachePath(edition, slug);
    if (existsSync(path)) return readFile(path, 'utf8');
    if (offline) throw new Error(`--verify requires a cached page, missing: ${edition}/${slug}`);

    await sleep(DELAY_MS);
    const res = await fetch(url, { headers: { 'User-Agent': USER_AGENT }, redirect: 'follow' });
    if (!res.ok) throw new Error(`HTTP ${res.status} for ${url}`);
    const body = await res.text();
    await mkdir(dirname(path), { recursive: true });
    await writeFile(path, body);
    return body;
}

/** Pass 1 — index page. Header-driven; throws on an unrecognised column. */
export function parseIndex(htmlText, edition) {
    const root = parse(htmlText);
    const rows = [];

    for (const table of root.querySelectorAll('table')) {
        const headerCells = table.querySelectorAll('th');
        if (!headerCells.length) continue;

        const keys = headerCells.map((th) => {
            const raw = text(th).toLowerCase();
            const key = HEADER_ALIASES[raw];
            if (!key) {
                throw new Error(
                    `Unrecognised column "${text(th)}" on the ${edition} index. ` +
                    `The site layout changed; update HEADER_ALIASES rather than guessing by position.`
                );
            }
            return key;
        });

        for (const tr of table.querySelectorAll('tr')) {
            const cells = tr.querySelectorAll('td');
            if (cells.length !== keys.length) continue;

            const row = {};
            keys.forEach((k, i) => { row[k] = text(cells[i]); });

            const link = cells[0].querySelector('a');
            if (!link) continue;
            row.slug = (link.getAttribute('href') || '').replace(/^\/spell:/, '').replace(/^\//, '');
            row.edition = edition;
            row.spellLists = row.spellLists
                ? row.spellLists.split(',').map((s) => s.trim()).filter(Boolean)
                : [];
            rows.push(row);
        }
    }
    return rows;
}

/**
 * Derive mechanical facts. Attack and save are SETS, not scalars: Ice Knife is a
 * ranged spell attack AND a Dexterity save; Bigby's Hand has an attack plus
 * Strength and Dexterity saves. Collapsing them to one value ships wrong data.
 */
export function classify(description, duration = '', castingTime = '') {
    const body = description || '';
    const attackModes = [];
    if (/melee spell attack/i.test(body)) attackModes.push('melee_spell');
    if (/ranged spell attack/i.test(body)) attackModes.push('ranged_spell');
    if (!attackModes.length && /\bspell attack\b/i.test(body)) attackModes.push('spell');

    const saveAbilities = [...new Set(
        [...body.matchAll(/\b(Strength|Dexterity|Constitution|Intelligence|Wisdom|Charisma)\s+saving throw/gi)]
            .map((m) => m[1].toLowerCase())
    )];

    const concentration = /concentration/i.test(duration);
    const ritual = /\britual\b/i.test(castingTime) || /\britual\b/i.test(body.slice(0, 400));

    let category;
    if (attackModes.length && saveAbilities.length) category = 'mixed';
    else if (attackModes.length) category = 'attack_roll';
    else if (saveAbilities.length) category = 'saving_throw';
    else if (/\bspellcasting ability modifier\b/i.test(body)) category = 'modifier_scaled';
    else if (ritual) category = 'ritual_utility';
    else category = 'fixed_effect';

    return { attackModes, saveAbilities, concentration, ritual, effectReliabilityCategory: category };
}

/**
 * The two editions write the level line in completely different shapes:
 *   2014  "2nd-level abjuration"
 *   2024  "Level 2 Abjuration (Artificer, Bard, Cleric, ...)"
 * Handling only the 2014 form silently produced level -1 for every non-cantrip
 * 2024 spell -- 385 of 419 records -- which is why `assertLevel` below now
 * treats an unparsed level as a hard failure rather than a value.
 *
 * @returns 0-9, or -1 when the line could not be understood.
 */
export function parseSpellLevel(levelLine) {
    const acceptedLevel = (token) => {
        const level = Number(token);
        return Number.isInteger(level) && level >= 0 && level <= 9 ? level : -1;
    };

    const school = '(?:Abjuration|Conjuration|Divination|Enchantment|Evocation|Illusion|Necromancy|Transmutation)';
    const shapes = [
        { header: `Level\\s+(?<level>\\d+)\\s+${school}(?:\\s+\\([^)]*\\))?`, cantrip: false },
        { header: `(?<level>\\d+)(?:st|nd|rd|th)-level\\s+${school}(?:\\s+\\([^)]*\\))*`, cantrip: false },
        { header: `${school}\\s+Cantrip(?:\\s+\\([^)]*\\))?`, cantrip: true },
    ];

    for (const shape of shapes) {
        // Headers are either their own paragraph, start a merged stat-block
        // paragraph, or immediately follow Source metadata in that paragraph.
        // In merged forms, "Casting Time:" is the required closing delimiter.
        const completeForms = [
            `^\\s*${shape.header}\\s*$`,
            `^\\s*${shape.header}\\s+Casting Time:\\s+.+$`,
            `^\\s*Source:\\s*.+?\\s+${shape.header}\\s+Casting Time:\\s+.+$`,
        ];
        for (const completeForm of completeForms) {
            const match = levelLine.match(new RegExp(completeForm, 'i'));
            if (match) return shape.cantrip ? 0 : acceptedLevel(match.groups.level);
        }
    }

    return -1;
}

/** Pass 2 — detail page. Source book + attack/save only exist here. */
export function parseSpellPage(htmlText, indexRow) {
    const root = parse(htmlText);
    const content = root.querySelector('#page-content');
    if (!content) throw new Error(`No #page-content for ${indexRow.slug}`);

    const paragraphs = content.querySelectorAll('p').map(text).filter(Boolean);
    const sourceLine = paragraphs.find((p) => /^Source:/i.test(p)) || '';
    const rawSource = sourceLine.replace(/^Source:\s*/i, '').replace(/,?\s*p(age)?\.?\s*\d+.*$/i, '').trim();
    const pageMatch = sourceLine.match(/p(?:age)?\.?\s*(\d+)/i);

    // Some pages put the whole stat block in ONE paragraph separated by newlines,
    // which whitespace-collapsing turns into
    // "Heroes of Faerun Level 8 Evocation (Cleric, Wizard) Casting Time: ...".
    // Truncate at the first stat-block marker so the book name is recovered
    // rather than the spell being dropped or a bogus source file invented.
    const bookOnly = rawSource
        .split(/\s+Level \d|\s+(?:Casting Time|Components|Duration|Range):/i)[0]
        .trim();

    // A spell can be published in more than one book ("Xanathar's Guide to
    // Everything/Elemental Evil Player's Companion"). Publication is many-to-many
    // and is NOT identity, so this splits rather than minting a combined "book".
    const sourceBooks = bookOnly.split('/').map((s) => s.trim()).filter(Boolean);

    const levelLine = paragraphs.slice(0, 3).find((p) => parseSpellLevel(p) !== -1) || '';
    const level = parseSpellLevel(levelLine);

    const description = paragraphs
        .filter((p) => !/^(Source|Casting Time|Range|Components|Duration):/i.test(p) && p !== levelLine)
        .join('\n\n');

    return {
        ...classify(description, indexRow.duration, indexRow.castingTime),
        sourceBooks,
        sourcePage: pageMatch ? Number(pageMatch[1]) : null,
        level,
        description,
    };
}

const toCsv = (rows) => {
    if (!rows.length) return '';
    const cols = Object.keys(rows[0]);
    const cell = (v) => {
        const s = Array.isArray(v) ? v.join(';') : v === null || v === undefined ? '' : String(v);
        return /[",\n]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s;
    };
    return [cols.join(','), ...rows.map((r) => cols.map((c) => cell(r[c])).join(','))].join('\n');
};

export async function buildDataset({ editions = ['2024', '2014'], limit = 0, offline = false, allowUa = false } = {}) {
    await mkdir(OUT_INDEX, { recursive: true });
    await mkdir(OUT_LOCAL, { recursive: true });

    const bySource = new Map();
    const failures = [];
    const newSources = new Set();

    for (const ed of editions) {
        const site = SITES[ed];
        const indexHtml = await fetchCached(site.origin + site.indexPath, ed, '__index__', { offline });
        let rows = parseIndex(indexHtml, ed);
        if (limit) rows = rows.slice(0, limit);
        console.log(`[${ed}] index: ${rows.length} spells`);

        for (let i = 0; i < rows.length; i += CONCURRENCY) {
            const batch = rows.slice(i, i + CONCURRENCY);
            const settled = await Promise.allSettled(batch.map(async (row) => {
                const html = await fetchCached(`${site.origin}/spell:${row.slug}`, ed, row.slug, { offline });
                return { row, detail: parseSpellPage(html, row) };
            }));

            for (const [j, r] of settled.entries()) {
                if (r.status === 'rejected') { failures.push(`${ed}/${batch[j].slug}: ${r.reason.message}`); continue; }
                const { row, detail } = r.value;
                const sourceBooks = detail.sourceBooks.map((b) => canonicalSourceBook(b, ed));

                if (sourceBooks.some((b) => /unearthed arcana/i.test(b))) {
                    if (!allowUa) continue;
                }
                if (!sourceBooks.length) {
                    // No parseable source line IS a real failure: the record cannot
                    // be attributed to a book.
                    failures.push(`${ed}/${row.slug}: no parseable source book on the page`);
                    continue;
                }
                // Spell level drives eligibility, slot levels and preparation caps.
                // An unparsed level must never reach the dataset -- the original gate
                // only validated source books, so it published 385 levelless records.
                if (!Number.isInteger(detail.level) || detail.level < 0 || detail.level > 9) {
                    failures.push(`${ed}/${row.slug}: unparseable spell level (${detail.level})`);
                    continue;
                }
                sourceBooks.filter((b) => !KNOWN_SOURCES.includes(b)).forEach((b) => newSources.add(b));

                const record = {
                    identityKey: slugify(row.name),
                    versionKey: `${ed}:${slugify(row.name)}`,
                    name: row.name,
                    edition: ed,
                    level: detail.level,
                    school: row.school,
                    castingTime: row.castingTime,
                    range: row.range,
                    components: row.components,
                    duration: row.duration,
                    concentration: detail.concentration,
                    ritual: detail.ritual,
                    attackModes: detail.attackModes,
                    saveAbilities: detail.saveAbilities,
                    effectReliabilityCategory: detail.effectReliabilityCategory,
                    spellLists: row.spellLists,
                    // All books this version appears in. The importer turns this
                    // into many-to-many publications rather than duplicate versions.
                    sourceBooks,
                    sourcePage: detail.sourcePage,
                    sourceSlug: row.slug,
                    _description: detail.description,
                };

                // A reprinted spell is listed under each of its books, but keeps one
                // versionKey so import resolves it to a single version.
                for (const book of sourceBooks) {
                    const file = SOURCE_FILENAMES[book] || slugify(book);
                    if (!bySource.has(file)) bySource.set(file, []);
                    bySource.get(file).push(record);
                }
            }
            process.stdout.write(`\r[${ed}] ${Math.min(i + CONCURRENCY, rows.length)}/${rows.length}`);
        }
        process.stdout.write('\n');
    }

    // Atomic completeness gate: a partial run must never publish Tier 1, or a
    // failed detail fetch silently ships a spell with no source or save data.
    if (failures.length) {
        console.error(`\nAborting: ${failures.length} record(s) failed. Nothing written.`);
        failures.slice(0, 20).forEach((f) => console.error('  - ' + f));
        process.exitCode = 1;
        return { written: [], failures };
    }

    const written = [];
    for (const [file, records] of bySource) {
        records.sort((a, b) => a.level - b.level || a.name.localeCompare(b.name));
        const facts = records.map(({ _description, ...rest }) => rest);
        await writeFile(join(OUT_INDEX, `${file}.json`), JSON.stringify(facts, null, 2) + '\n');
        await writeFile(join(OUT_INDEX, `${file}.csv`), toCsv(facts) + '\n');
        await writeFile(join(OUT_LOCAL, `${file}.full.json`), JSON.stringify(records, null, 2) + '\n');
        written.push(`${file} (${records.length})`);
    }
    console.log('Wrote: ' + written.join(', '));
    if (newSources.size) {
        console.log(`\nSource books not in KNOWN_SOURCES (published anyway, listed so new content is never silent):`);
        [...newSources].sort().forEach((s) => console.log(`  + ${s}`));
    }
    return { written, failures, newSources: [...newSources] };
}

if (import.meta.url === `file://${process.argv[1]}`) {
    const argv = process.argv.slice(2);
    const flag = (n) => argv.includes(`--${n}`);
    const val = (n, d) => { const i = argv.indexOf(`--${n}`); return i >= 0 ? argv[i + 1] : d; };
    await buildDataset({
        editions: val('edition') ? [val('edition')] : ['2024', '2014'],
        limit: Number(val('limit', 0)),
        offline: flag('verify'),
        allowUa: flag('allow-ua'),
    });
}
