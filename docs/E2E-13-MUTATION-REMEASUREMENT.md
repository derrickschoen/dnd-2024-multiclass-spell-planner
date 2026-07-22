# E2E-13 mutation remeasurement

## Result

Every PHP file under `app/Domain/Reports`, `app/Domain/Catalog`, and
`app/Domain/Characters` was measured independently. No run used a directory score.
All accepted per-file runs had zero uncovered, skipped, timed-out, errored, ignored,
or syntax-error mutants. `CharacterCommand.php` is explicitly measured as a valid
zero-mutant interface; no file is unmeasured.

The corrected baseline was 838 killed and 485 escaped of 1,323 generated mutants
(63.34% aggregate MSI). The definitive result is 1,133 killed and 224 individually
justified equivalents of 1,357 generated mutants (83.49% aggregate MSI). The
per-file values below are authoritative; the aggregate is only a cross-check.

The final denominator is 34 higher because the new tests reached executable lines
that the baseline coverage map never reached. Those mutants were generated and
classified rather than silently treated as killed.

## Per-file measurements

`B total/killed/escaped` and `A total/killed/escaped` show the complete terminal
counts. Every `B skip` and `A skip` value is zero.

| Directory | File | Before MSI | B total/killed/escaped | B skip | After MSI | A total/killed/escaped | A skip |
|---|---|---:|---:|---:|---:|---:|---:|
| Reports | `BuildReportBuilder.php` | 54.17% | 144 / 78 / 66 | 0 | 67.35% | 147 / 99 / 48 | 0 |
| Catalog | `CatalogImporter.php` | 59.62% | 265 / 158 / 107 | 0 | 84.29% | 280 / 236 / 44 | 0 |
| Characters | `AcknowledgeWarningCommand.php` | 59.38% | 32 / 19 / 13 | 0 | 81.82% | 33 / 27 / 6 | 0 |
| Characters | `AddSourceCommand.php` | 97.92% | 48 / 47 / 1 | 0 | 100.00% | 48 / 48 / 0 | 0 |
| Characters | `CharacterCommand.php` | n/a (zero mutants) | 0 / 0 / 0 | 0 | n/a (zero mutants) | 0 / 0 / 0 | 0 |
| Characters | `CharacterCommandExecutor.php` | 67.86% | 56 / 38 / 18 | 0 | 78.57% | 56 / 44 / 12 | 0 |
| Characters | `CharacterCommandFactory.php` | 100.00% | 26 / 26 / 0 | 0 | 100.00% | 26 / 26 / 0 | 0 |
| Characters | `CharacterCommandIntegrity.php` | 53.57% | 28 / 15 / 13 | 0 | 96.55% | 29 / 28 / 1 | 0 |
| Characters | `CharacterCommandPayloadValidator.php` | 99.50% | 200 / 199 / 1 | 0 | 100.00% | 200 / 200 / 0 | 0 |
| Characters | `CharacterListBuilder.php` | 7.14% | 14 / 1 / 13 | 0 | 71.43% | 14 / 10 / 4 | 0 |
| Characters | `CharacterState.php` | 76.74% | 86 / 66 / 20 | 0 | 92.39% | 92 / 85 / 7 | 0 |
| Characters | `CharacterWorkspaceBuilder.php` | 29.17% | 144 / 42 / 102 | 0 | 64.58% | 144 / 93 / 51 | 0 |
| Characters | `EligibleSpellSearch.php` | 33.96% | 53 / 18 / 35 | 0 | 73.58% | 53 / 39 / 14 | 0 |
| Characters | `RemoveSourceCommand.php` | 100.00% | 8 / 8 / 0 | 0 | 100.00% | 8 / 8 / 0 | 0 |
| Characters | `RestoreSnapshotCommand.php` | 100.00% | 3 / 3 / 0 | 0 | 100.00% | 3 / 3 / 0 | 0 |
| Characters | `RevisionConflict.php` | 0.00% | 1 / 0 / 1 | 0 | 100.00% | 1 / 1 / 0 | 0 |
| Characters | `SetSlotCommand.php` | 68.52% | 54 / 37 / 17 | 0 | 80.00% | 55 / 44 / 11 | 0 |
| Characters | `UpdateAbilityCommand.php` | 55.56% | 18 / 10 / 8 | 0 | 94.44% | 18 / 17 / 1 | 0 |
| Characters | `UpdateCharacterRulesCommand.php` | 100.00% | 8 / 8 / 0 | 0 | 100.00% | 8 / 8 / 0 | 0 |
| Characters | `UpdateClassCommand.php` | 49.44% | 89 / 44 / 45 | 0 | 81.32% | 91 / 74 / 17 | 0 |
| Characters | `UpdateSourceConfigCommand.php` | 45.65% | 46 / 21 / 25 | 0 | 84.31% | 51 / 43 / 8 | 0 |

Totals reconcile exactly: baseline 1,323 = 838 + 485; after 1,357 = 1,133 +
224. Every other Infection terminal-status total is zero.

## Measurement method and launcher corrections

The baseline coverage snapshot was created before adding the E2E-13 tests. Each
source file was then passed alone to Infection with that snapshot,
`--skip-initial-tests`, and `--only-covering-test-cases`. The after sweep used the
same isolation and a fresh complete coverage snapshot. The last five known real
survivors were rerun by stable ID after their focused tests; those killed results
were reconciled into the already-complete per-file denominators.

The launcher had to do all of the following for the results to be accepted:

- recover the real project root after Infection copies the Pest launcher;
- rewrite temporary/nonexistent test paths and relative phpunit suite paths back
  to the project;
- translate generated Pest method filters to punctuation-tolerant Pest test
  descriptions.

Control runs proved the correction: the old false-positive path either skipped
hundreds of mutants or reported missing `/tmp/infection/tests/...` files, while a
known `CharacterCommandPayloadValidator` control became 10/10 killed. No score in
the table includes skipped mutants.

## Escaped-mutant verdicts

The complete 485-row stable-ID ledger is in
[`E2E-13-ESCAPED-MUTANT-LEDGER.md`](E2E-13-ESCAPED-MUTANT-LEDGER.md). It records
261 real gaps now killed and 224 surviving equivalents. Every equivalent has its
own source location, exact mutation, and hand-checkable invariant; none is counted
as killed. No newly exposed final mutant escaped.

The main equivalent families are validated scalar casts, JSON depth 511/512/513
for application-generated shallow documents, key-only array normalization,
idempotent sorting/generation, database constraints, and unreachable branches
excluded by command validation. File-specific cases (such as the double 50-row
cap and source-instance cascade deletion) are justified on their individual rows.

## New tests and gaps killed

- `BuildReportTest` now pins the complete report DTO, exact preparation callouts,
  acknowledgement payload, all subclass caster fractions, wizard spellbook /
  prepared / ritual-only lists, positive identities, and unsupported fractions.
- `CatalogImportTest` now covers every malformed container/record/field boundary,
  the complete version/publication/pivot contract, split-file merging, edition
  priority, every mutable update/removal, timestamp presence, idempotence, isolated
  publication and pivot change counters, alias resolution, non-leading duration
  markers, and all reference surfaces.
- `CharacterWorkspaceTest` now pins complete list/workspace DTOs, warning counts,
  save-point and subclass identities, exact eligible-spell DTOs, literal queries,
  legacy switching, 50-result behavior, prefilter-before-cap behavior, complete
  snapshot validation/replacement, timestamps, command envelopes, replay,
  undo/redo, orphan explanations, class boundaries, nullable configuration,
  repeated subclass switching/deselection, and nested/standalone source
  regeneration.
- `CharacterCommandIntegrityTest` pins the canonical hard-coded signature,
  reordered equivalence, missing application key, and revision-conflict message.
- `CharacterCommandPayloadValidatorTest` adds the missing invalid slot-mode
  boundary.

These are behavioral assertions on returned DTOs, persisted state, errors, and
idempotence. None merely repeats a private expression, and no existing assertion
was weakened or removed.

## Verification output

Fresh migration and seed:

```text
Dropping all tables ............................................ 4.76ms DONE
INFO  Preparing database.
Creating migration table ....................................... 3.56ms DONE
INFO  Running migrations.
0001_01_01_000000_create_users_table ........................... 0.96ms DONE
0001_01_01_000001_create_cache_table ........................... 0.34ms DONE
0001_01_01_000002_create_jobs_table ............................ 0.66ms DONE
2026_07_21_000100_create_catalog_tables ........................ 7.18ms DONE
2026_07_21_000200_create_character_tables ...................... 3.55ms DONE
2026_07_21_000300_add_spell_selection_eligibility .............. 2.05ms DONE
2026_07_21_000400_create_subclass_progressions ................. 0.28ms DONE
2026_07_21_000500_create_character_operations .................. 0.32ms DONE
INFO  Seeding database.
Database\Seeders\ClassProgressionSeeder ......................... 51 ms DONE
Database\Seeders\ContentDefinitionSeeder ......................... 2 ms DONE
Database\Seeders\SeedCharacterSeeder ............................ 39 ms DONE
```

Pest:

```text
Tests:    434 passed (12822 assertions)
Duration: 48.64s
```

Typecheck:

```text
> typecheck
> vue-tsc --noEmit
```

Build:

```text
> build
> vite build
vite v8.1.5 building client environment for production...
[plugin laravel:fonts] Optimized font fallbacks require the optional "fontaine" package. Install it, or set "optimizedFallbacks: false" on your fonts to disable the feature.
✓ 567 modules transformed.
public/build/manifest.json                                       1.53 kB │ gzip:  0.34 kB
public/build/fonts-manifest.json                                 5.74 kB │ gzip:  0.71 kB
public/build/assets/instrument-sans-400-normal-DRC__1Mx.woff2   16.86 kB
public/build/assets/instrument-sans-500-normal-Dk9ku72i.woff2   17.23 kB
public/build/assets/instrument-sans-600-normal-B7fBEWYG.woff2   17.40 kB
public/build/assets/instrument-sans-400-normal-D1W7dsQl.woff    21.24 kB
public/build/assets/instrument-sans-500-normal-Z6ESRlEs.woff    21.65 kB
public/build/assets/instrument-sans-600-normal-B9e8oLYv.woff    21.67 kB
public/build/assets/fonts-C9MNnjVw.css                           2.35 kB │ gzip:  0.38 kB
public/build/assets/app-jSxdPPVW.css                            75.44 kB │ gzip: 14.22 kB
public/build/assets/app-BoawcbZI.js                            225.32 kB │ gzip: 75.10 kB
✓ built in 852ms
```

Playwright:

```text
Running 17 tests using 1 worker
✓ S1: editing one slot leaves every other database row byte-identical
✓ S2: duplicate category and explanation transition wasteful → none → wasteful
✓ S3: undo is persisted by the server and redo is discarded by a hard reload
✓ S4: removing and restoring Magic Initiate: Wizard preserves its orphaned slot identities and selections
✓ S5: INT changes propagate to every INT route in both directions without moving WIS or CHA
✓ S6: Wizard spellbook shows prepared, ritual-only, and unprepared non-ritual states
✓ S7: a save point restores the complete persisted character state after destructive edits
✓ S8: adding Warlock keeps Pact Magic separate from shared slots and explains cross-pool casting
✓ S9: level-down orphans surplus selected slots and level-up reactivates the same rows
✓ S10: a stale browser context receives 409 and cannot change any database state
✓ S11: the slot grid is keyboard reachable, visibly focused, labelled, and warnings are not colour-only
✓ S12: selecting 2014 and 2024 Chill Touch warns prominently and keeps its acknowledgement after reload
✓ S13: changing Magic Initiate from Wizard to Cleric preserves slot identity and excludes invalid selections
✓ S14: concurrent edits to different slots both persist with distinct operation UUIDs
✓ S15: repeated Magic Initiate refuses the same list and accepts a different list
✓ S16: adding Magic Initiate materialises three DSL slots with per-slot casting modes
✓ S17: removing a feat in the browser orphans selections and undo restores identical rows
17 passed (56.9s)
```

Fresh-seed golden extraction:

```json
{
    "caster_level": 6,
    "slots": [4, 3, 3],
    "proficiency_bonus": 3,
    "class_max_preparable_levels": {
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
```

## Independent review deviation

The required Claude plan critique and final implementation critique were each
attempted with a bounded 180-second wait. Both produced no findings and ended in
`Execution error`; silence was not treated as approval. Local reconciliation,
focused mutation-ID proofs, the full verification contract, Pint, and
`git diff --check` were completed instead.
