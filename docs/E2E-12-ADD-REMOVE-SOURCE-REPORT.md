# UNIT E2E-12 add/remove source report

## Outcome

`add_source` and `remove_source` are first-class character commands. They use the
existing executor for the transaction, revision guard, operation UUID replay,
and grouped audit rows, and use signed `restore_snapshot` inverses. Adding inserts
only the requested root and delegates all direct and nested source/slot creation
to `GrantRuleSlotGenerator`. Removing tombstones the source tree and orphans its
slots without clearing selections. Snapshot undo restores the original source and
slot rows, including IDs, keys, selections, and timestamps.

The workspace now provides labelled source-type and definition selectors,
conditional Magic Initiate list/ability controls, an add button, and labelled
remove buttons for active feats, species, and backgrounds. Removal uses a native
confirmation dialog that explicitly says selections are preserved. All controls
have the existing keyboard focus treatment and explicit text; none relies on
colour. They are outside the spell grid, so S11's `GRID_CHROME_CONTROLS` remains
deliberately unchanged at 7.

Magic Initiate accepts only Cleric, Druid, or Wizard and intelligence, wisdom, or
charisma. A repeated instance must choose a list not already active. The same
configuration rules apply when species/background DSL rules grant the feat.

## Browser scenarios and sensitivity

- S4 now calls the real `remove_source` command and its returned signed inverse.
  Its fixture-trigger deviation is retired. Removing the generator call made S4
  fail because its target slots remained active (`Expected: true`, `Received:
  false` for every slot being orphaned).
- S16 adds Magic Initiate: Cleric with Charisma in the browser. SQL assertions
  pin two cantrips to `with_slots = 0`, `free_cast = null`, levels 0–0, and the
  level-1 choice to `with_slots = 1` plus one `long_rest` free cast. Temporarily
  setting the cantrip DSL rule to `with_slots = true` failed with both received
  values `1` instead of `0`.
- S17 confirms removal in the UI, asserts orphaned slots retain their selections,
  then uses Undo and deep-compares the complete source and slot rows. Moving the
  inverse snapshot capture after removal failed because Undo returned the source
  as `tombstoned` with a changed timestamp instead of the original active row.

All temporary production mutations were restored. S4, S16, and S17 then passed
together, and all 17 scenarios passed in the final run.

## Mutation result and baseline deviation

The new command path has a trustworthy 100% result, using full relevant test
files rather than Infection's per-test filter:

```text
AddSourceCommand.php                       47/47 killed
RemoveSourceCommand.php                      7/7 killed
CharacterCommandPayloadValidator.php      197/197 killed
CharacterCommandFactory.php                 26/26 killed
Accepted new/changed command union         277/277 killed — 100% MSI
```

Every accepted component had 100% mutation code coverage and zero escapes,
skips, timeouts, errors, or uncovered mutants.

The requested whole `app/Domain/Characters/` 100% MSI cannot honestly be
reported. Investigation proved the documented 786/786 E2E-11 baseline was a
false positive: Infection copies `bin/pest-infection` under `/tmp`, where its
old project-root calculation pointed at the wrong tree, and its generated Pest
method filters matched no Pest 4 descriptions. The launcher now resolves the
real vendor/project path and translates generated Pest method names to their
human descriptions.

With that correction, a complete, no-skip run of the existing
`CharacterWorkspaceBuilder.php` alone generated 144 mutants and killed 38
(26% MSI), exposing 106 pre-existing survivors throughout old workspace fields;
this is a direct counterexample to the historical 786/786 claim. An attempted
broad Characters run generated 914 mutants but skipped 626 on the configured
timeout, so its displayed score was discarded. Consequently there is no valid
whole-directory after score to claim. This is the sole unmet verification
contract; the feature's changed command union is 277/277.

## Verification output

Fresh migration and seed:

```text
Dropping all tables ............................................ 4.24ms DONE
Creating migration table ....................................... 2.94ms DONE
0001_01_01_000000_create_users_table ........................... 0.94ms DONE
0001_01_01_000001_create_cache_table ........................... 0.33ms DONE
0001_01_01_000002_create_jobs_table ............................ 0.67ms DONE
2026_07_21_000100_create_catalog_tables ........................ 6.80ms DONE
2026_07_21_000200_create_character_tables ...................... 3.52ms DONE
2026_07_21_000300_add_spell_selection_eligibility .............. 1.97ms DONE
2026_07_21_000400_create_subclass_progressions ................. 0.31ms DONE
2026_07_21_000500_create_character_operations .................. 0.33ms DONE
Database\Seeders\ClassProgressionSeeder ......................... 35 ms DONE
Database\Seeders\ContentDefinitionSeeder ......................... 1 ms DONE
Database\Seeders\SeedCharacterSeeder ............................ 20 ms DONE
```

Pest:

```text
Tests:    358 passed (12447 assertions)
Duration: 28.14s
```

Typecheck and build:

```text
> typecheck
> vue-tsc --noEmit

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
✓ built in 483ms
```

Playwright:

```text
> test:e2e
> playwright test
Running 17 tests using 1 worker
  ✓ S1
  ✓ S2
  ✓ S3
  ✓ S4
  ✓ S5
  ✓ S6
  ✓ S7
  ✓ S8
  ✓ S9
  ✓ S10
  ✓ S11
  ✓ S12
  ✓ S13
  ✓ S14
  ✓ S15
  ✓ S16
  ✓ S17
17 passed (51.3s)
```

The seven golden values still hold:

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

## Deviations

- Whole-directory Characters MSI: not satisfied for the baseline/tooling reason
  above; no false 100% figure is reported.
- The required Claude plan critique was attempted twice with bounded waits. Both
  produced no review content and ended with `Execution error`; silence was not
  treated as approval.
- The required Claude implementation critique was also given a bounded 180-second
  run over the complete uncommitted diff. It produced no review content and
  exited 124 with `Execution error`; silence was not treated as approval.
- No commit or push was made.
