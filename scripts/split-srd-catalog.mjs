#!/usr/bin/env node
/**
 * Split the scraped catalog into the subset that may be redistributed.
 *
 * SRD 5.2.1 is CC-BY-4.0, so those records can ship in a public repo with the
 * required attribution. Everything else — Xanathar's, Tasha's, Fizban's and the
 * rest of the ~20 books the scraper covers — is not CC-BY and stays local.
 *
 *   data/index/  full scraped catalog   GITIGNORED, built by `npm run scrape`
 *   data/srd/    SRD 5.2.1 subset only  COMMITTED, with ATTRIBUTION.md
 *
 * Usage: node scripts/split-srd-catalog.mjs <path-to-srd-spells.md>
 */

import { readFile, readdir, writeFile, mkdir } from 'node:fs/promises';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..');

/**
 * The SRD prints several spells under generic names, having stripped the
 * wizard's name. Verified by an independent SRD audit which matched 339 spells
 * one-to-one with no fuzzy guessing.
 */
const SRD_GENERIC_NAMES = {
    'Acid Arrow': "Melf's Acid Arrow",
    'Arcane Hand': "Bigby's Hand",
    'Arcane Sword': "Mordenkainen's Sword",
    "Arcanist's Magic Aura": "Nystul's Magic Aura",
    'Black Tentacles': "Evard's Black Tentacles",
    'Faithful Hound': "Mordenkainen's Faithful Hound",
    'Floating Disk': "Tenser's Floating Disk",
    'Freezing Sphere': "Otiluke's Freezing Sphere",
    'Hideous Laughter': "Tasha's Hideous Laughter",
    'Instant Summons': "Drawmij's Instant Summons",
    'Irresistible Dance': "Otto's Irresistible Dance",
    'Magnificent Mansion': "Mordenkainen's Magnificent Mansion",
    'Private Sanctum': "Mordenkainen's Private Sanctum",
    'Resilient Sphere': "Otiluke's Resilient Sphere",
    'Secret Chest': "Leomund's Secret Chest",
    'Telepathic Bond': "Rary's Telepathic Bond",
    'Tiny Hut': "Leomund's Tiny Hut",
};

/** Straight/curly apostrophes and case must not cause a false miss. */
const canonical = (name) =>
    name.replace(/[‘’ʼ]/g, "'").replace(/\s+/g, ' ').trim().toLowerCase();

/**
 * Verified against the real file rather than assumed: spells are `####` headings
 * (352 of them) whose next non-empty line is an italic descriptor, e.g.
 *
 *     #### Bane
 *
 *     _Level 1 Enchantment (Bard, Cleric, Warlock)_
 *
 * `##` and `###` are section headings and must not be treated as spells.
 */
export function srdSpellNames(markdown) {
    const names = new Set();
    for (const match of markdown.matchAll(/^####\s+(.+?)\s*$\n\s*\n_([^_]+)_/gm)) {
        const heading = match[1].trim();
        if (!/\b(Cantrip|Level [1-9])\b/i.test(match[2])) continue;
        names.add(canonical(heading));
        const aliased = SRD_GENERIC_NAMES[heading];
        if (aliased) names.add(canonical(aliased));
    }
    return names;
}

const srdMarkdown = await readFile(process.argv[2], 'utf8');
const srdNames = srdSpellNames(srdMarkdown);

const inDir = join(ROOT, 'data/index');
const outDir = join(ROOT, 'data/srd');
await mkdir(outDir, { recursive: true });

let kept = 0;
let dropped = 0;
const keptBooks = new Set();

for (const file of (await readdir(inDir)).filter((f) => f.endsWith('.json'))) {
    const records = JSON.parse(await readFile(join(inDir, file), 'utf8'));
    // SRD 5.2.1 covers the 2024 rules. A 2014 record, or a record sourced from a
    // different book, is NOT licensed merely because a spell of that name appears
    // in the SRD — so match on edition AND source book, not on name alone.
    const srdRecords = records.filter((r) =>
        r.edition === '2024'
        && srdNames.has(canonical(r.name))
        && r.sourceBooks.every((b) => b === "Player's Handbook (2024)"));
    dropped += records.length - srdRecords.length;
    if (!srdRecords.length) continue;

    kept += srdRecords.length;
    srdRecords.forEach((r) => r.sourceBooks.forEach((b) => keptBooks.add(b)));
    await writeFile(join(outDir, file), JSON.stringify(srdRecords, null, 2) + '\n');
}

console.log(`SRD headings recognised: ${srdNames.size}`);
console.log(`records kept: ${kept}   dropped (not in SRD): ${dropped}`);
console.log('books represented in the kept set:');
[...keptBooks].sort().forEach((b) => console.log(`  ${b}`));
