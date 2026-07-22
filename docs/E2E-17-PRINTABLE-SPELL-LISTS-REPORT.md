# UNIT E2E-17 — Printable Spell Lists Report

Date: 2026-07-22

No commit or push was made.

## Delivered behaviour

Each character now has a `/characters/{id}/print` route with a labelled variant
selector and keyboard-reachable controls.

- **Reference sheet:** compact spell facts only: name, level, school, casting
  time/action type, range, duration, concentration, ritual, components, and the
  source-specific attack bonus or save DC when relevant.
- **Full reference:** the same facts plus the stored complete spell description.
  If no Tier 2 text has been imported, the page renders normally, shows a clear
  status message, and marks descriptions unavailable instead of erroring.

The page has dedicated print CSS: navigation and controls are hidden, output is
black on white at 10.5pt, reference cards use two columns, full descriptions use
one column, and individual spell cards avoid page breaks. Wizard spellbook,
prepared, and ritual-only states remain separate and retain their explanatory
text.

`catalog:import --with-text` opt-in loads `_description` from
`data/local/*.full.json` into the existing `spell_versions.short_summary` text
field. Exact `versionKey` parity is required when the local Tier 2 directory is
present. A wholly absent Tier 2 directory is allowed and reported clearly.
Normal imports neither require Tier 2 nor erase already imported text. No spell
description text is committed.

## Mutt's long-rest swap sections

- Cleric: **12** unprepared level-1 spells.
- Druid: **16** unprepared level-1 spells.

Both lists exclude spells already prepared for that class and include only
active 2024 class-list spells at or below that class's maximum preparable level.
The Druid result is 16, not 15: Mutt has four Druid selections, but Absorb
Elements is a 2014-only spell and therefore is not one of the 19 eligible 2024
Druid candidates. Goodberry, Jump, and Speak with Animals exclude the other
three. Both sections explicitly say that unprepared cantrips are omitted because
cantrips cannot be swapped on a long rest.

## Sensitivity checks

Each mutation below was applied temporarily to production behaviour, its focused
test was observed failing, and the original implementation was restored:

- Blocked updates to existing referenced descriptions: expected one update,
  observed zero.
- Required Tier 2 unconditionally: import raised `Tier 2 is required.`
- Removed action-type classification: the Action/Bonus Action/Reaction map
  assertion failed.
- Leaked descriptions into the reference variant: expected no descriptions,
  observed 37 test descriptions.
- Forced the print controller to reference mode: the full-variant route
  assertion failed.
- Disabled prepared-spell exclusion: the Cleric section expanded to all 16 and
  included prepared spells.
- Removed the cantrip explanation: the exact explanatory-note assertion failed.
- Emitted attack and save numbers for every spell: Chill Touch incorrectly
  gained save DC 14.
- Removed the Wizard state projection: the exact spellbook-state assertion
  failed.
- Claimed descriptions were available with Tier 2 absent: expected
  `unavailable`, observed `available`.
- Made controls visible in print media: Playwright expected the controls hidden
  but observed them visible.

All mutations were reverted before the final verification.

## Verification output

### Fresh migration and seed

Command: `ddev exec php artisan migrate:fresh --seed`

```text
Dropping all tables ............................................ 4.16ms DONE

INFO  Preparing database.

Creating migration table ....................................... 2.87ms DONE

INFO  Running migrations.

0001_01_01_000000_create_users_table ........................... 0.90ms DONE
0001_01_01_000001_create_cache_table ........................... 0.33ms DONE
0001_01_01_000002_create_jobs_table ............................ 0.67ms DONE
2026_07_21_000100_create_catalog_tables ........................ 6.43ms DONE
2026_07_21_000200_create_character_tables ...................... 3.52ms DONE
2026_07_21_000300_add_spell_selection_eligibility .............. 1.88ms DONE
2026_07_21_000400_create_subclass_progressions ................. 0.27ms DONE
2026_07_21_000500_create_character_operations .................. 0.29ms DONE

INFO  Seeding database.

Database\Seeders\ClassProgressionSeeder ......................... 35 ms DONE
Database\Seeders\ContentDefinitionSeeder ........................ 1 ms DONE
Database\Seeders\SeedCharacterSeeder ........................... 322 ms DONE
```

### Pest

The configured parallel runner rejected Pest 4 and instructed use of Pest, so
the documented fallback was used: `ddev exec vendor/bin/pest`.

```text
Tests:    460 passed (13112 assertions)
Duration: 84.04s
```

### Type checking

Command: `ddev exec npm run typecheck`

```text
> typecheck
> vue-tsc --noEmit
```

### Production build

Command: `ddev exec npm run build`

```text
> build
> vite build

vite v8.1.5 building client environment for production...
[plugin laravel:fonts] Optimized font fallbacks require the optional "fontaine" package. Install it, or set "optimizedFallbacks: false" on your fonts to disable the feature.
transforming...✓ 570 modules transformed.
rendering chunks...
computing gzip size...
public/build/manifest.json                                       1.56 kB │ gzip:  0.36 kB
public/build/fonts-manifest.json                                 5.74 kB │ gzip:  0.71 kB
public/build/assets/instrument-sans-400-normal-DRC__1Mx.woff2   16.86 kB
public/build/assets/instrument-sans-500-normal-Dk9ku72i.woff2   17.23 kB
public/build/assets/instrument-sans-600-normal-B7fBEWYG.woff2   17.40 kB
public/build/assets/instrument-sans-400-normal-D1W7dsQl.woff    21.24 kB
public/build/assets/instrument-sans-500-normal-Z6ESRlEs.woff    21.65 kB
public/build/assets/instrument-sans-600-normal-B9e8oLYv.woff    21.67 kB
public/build/assets/app-R3Z9ebH5.css                             0.97 kB │ gzip:  0.44 kB
public/build/assets/fonts-C9MNnjVw.css                           2.35 kB │ gzip:  0.38 kB
public/build/assets/app-C2FeUiqX.css                            76.31 kB │ gzip: 14.35 kB
public/build/assets/app-BDKL8-6k.js                            234.95 kB │ gzip: 77.29 kB

✓ built in 394ms
```

The `fontaine` line is an existing optional-package advisory; the build exited
successfully.

### Playwright

Command: `ddev exec npm run test:e2e`

```text
> test:e2e
> playwright test

Running 19 tests using 1 worker

  ✓  1 S1: editing one slot leaves every other database row byte-identical (2.3s)
  ✓  2 S2: duplicate category and explanation transition wasteful → none → wasteful (3.0s)
  ✓  3 S3: undo is persisted by the server and redo is discarded by a hard reload (3.7s)
  ✓  4 S4: removing and restoring Magic Initiate: Wizard preserves its orphaned slot identities and selections (3.7s)
  ✓  5 S5: INT changes propagate to every INT route in both directions without moving WIS or CHA (1.7s)
  ✓  6 S6: Wizard spellbook shows prepared, ritual-only, and unprepared non-ritual states (1.6s)
  ✓  7 S7: a save point restores the complete persisted character state after destructive edits (4.5s)
  ✓  8 S8: adding Warlock keeps Pact Magic separate from shared slots and explains cross-pool casting (1.5s)
  ✓  9 S9: level-down orphans surplus selected slots and level-up reactivates the same rows (3.3s)
  ✓ 10 S10: a stale browser context receives 409 and cannot change any database state (3.0s)
  ✓ 11 S11: the slot grid is keyboard reachable, visibly focused, labelled, and warnings are not colour-only (12.6s)
  ✓ 12 S12: selecting 2014 and 2024 Chill Touch warns prominently and keeps its acknowledgement after reload (4.4s)
  ✓ 13 S13: changing Magic Initiate from Wizard to Cleric preserves slot identity and excludes invalid selections (2.3s)
  ✓ 14 S14: concurrent edits to different slots both persist with distinct operation UUIDs (3.1s)
  ✓ 15 S15: repeated Magic Initiate refuses the same list and accepts a different list (2.5s)
  ✓ 16 S16: adding Magic Initiate materialises three DSL slots with per-slot casting modes (1.6s)
  ✓ 17 S17: removing a feat in the browser orphans selections and undo restores identical rows (2.4s)
  ✓ 18 T10: Mutt matches the authoritative sheet attribution with zero duplicates (3.4s)
  ✓ 19 E2E-17: Mutt prints reference and full variants with exact long-rest swap lists and relevant casting math (1.7s)

  19 passed (1.0m)
```

The suite now contains the 18 pre-existing scenarios plus the requested new
E2E-17 scenario; all 19 pass.

### Opt-in Tier 2 dry run

Command: `ddev exec php artisan catalog:import --with-text --dry-run`

```text
DRY RUN — transaction rolled back
Created versions: 0
Updated versions: 943
Tombstoned versions: 0
Created identities: 0
Updated identities: 0
Loaded descriptions: 943
```

This local corpus contains 943 active Tier 1 version records; the dry run loaded
the matching local descriptions and rolled the transaction back. The local Tier
2 files remain gitignored and no description text appears in the diff.

### A6 golden values and duplicates

```json
{
  "caster_level": 6,
  "slots": [
    {"level": 1, "count": 4},
    {"level": 2, "count": 3},
    {"level": 3, "count": 3}
  ],
  "proficiency_bonus": 3,
  "max_preparable_levels": {
    "Bard": 1,
    "Cleric": 1,
    "Druid": 1,
    "Paladin": 1,
    "Sorcerer": 1,
    "Wizard": 1
  },
  "mage_hand": "wasteful",
  "entangle": "none",
  "detect_magic": {
    "origin": "capability",
    "casting_mode": "ritual_only",
    "is_selection": false,
    "counts_against_limit": false
  }
}
{
  "mutt_duplicate_warnings": 0
}
```

### Print projection with Tier 2 absent from the database

```json
{
  "variant": "reference",
  "cleric_unprepared": 12,
  "druid_unprepared": 16,
  "text_status": "not_requested"
}
{
  "variant": "full",
  "text_status": "unavailable",
  "described_spells": 0
}
```

Targeted Pint passed for every changed PHP file, and `git diff --check` passed.
