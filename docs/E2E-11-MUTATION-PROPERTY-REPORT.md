# UNIT E2E-11 mutation and property-test report

## Outcome

All three previously unmutated directories began and ended at 100% MSI. No
mutant escaped an accepted run, so there were no real gaps to kill and no
equivalent mutants to justify. The accepted scopes generated and killed 1,195
distinct mutants in total.

| Directory | Before | After | Escapes |
| --- | ---: | ---: | ---: |
| `app/Domain/Characters/` | 786/786 — 100% MSI | 786/786 — 100% MSI | 0 |
| `app/Domain/Catalog/` | 265/265 — 100% MSI | 265/265 — 100% MSI | 0 |
| `app/Domain/Reports/` | 144/144 — 100% MSI | 144/144 — 100% MSI | 0 |

Every accepted run also had zero uncovered mutants, errors, syntax errors,
timeouts, and skips.

## Scope and invalid-run handling

### M4 — Characters

The complete directory ran against coverage from the full Pest suite with
`--only-covering-test-cases`. It completed without a split: 786 generated, 786
killed, 100% mutation code coverage, and no skipped mutants. The after-run used
the identical scope and result.

Escaped-mutant ledger: **empty**. There are no real-gap or equivalent verdicts.

### M5 — Catalog

The initial full-suite coverage run was discarded: it generated 265 mutants but
skipped 224 because their selected test groups exceeded the configured 60-second
budget. Its displayed 100% MSI is invalid and is not used.

The accepted explicit scope was:

```text
app/Domain/Catalog/CatalogImporter.php
tests/Feature/CatalogImportTest.php
```

That focus generated the same 265-mutant denominator, had 100% mutation code
coverage, and killed all 265 without skips. The after-run used the identical
scope and result.

Escaped-mutant ledger: **empty**. There are no real-gap or equivalent verdicts.

### M6 — Reports

The initial full-suite coverage run was also discarded: it generated 144
mutants but skipped 131 under the 60-second budget. A `BuildReportTest`-only run
completed but generated only 134 mutants, proving that it omitted ten mutants
covered elsewhere.

The accepted scope split `BuildReportBuilder.php` across every Pest file that
directly calls it:

```text
tests/Feature/BuildReportTest.php                       134/134 killed
tests/Feature/CharacterWorkspaceTest.php                132/132 killed
tests/Feature/GuardCoverageTest.php                     124/124 killed
tests/Feature/SubclassProgressionTest.php               134/134 killed
tests/Feature/Api/CharacterWriteSurfaceAbuseTest.php    132/132 killed
```

The union, keyed by mutator plus exact diff, contains 144 distinct mutants. That
matches the broad full-suite denominator exactly. Every member of the union was
killed in at least one covering-file run, with no escape, timeout, error,
uncovered mutant, or skip in any accepted component. The after-run repeated the
same five scopes and the union again contained 144 killed mutants.

Escaped-mutant ledger: **empty**. There are no real-gap or equivalent verdicts.

## P1 — property-based rules invariants

`tests/Unit/RulesPropertyTest.php` adds a deterministic generator using
`Random\Engine\Mt19937`. It chooses unique classes from the twelve 2024 base
classes and distributes a randomly selected total of 1–20 positive levels among
them. Derived before/after builds retain unique class names and remain within the
level-20 cap.

Each of the six properties runs 1,000 generated cases:

| Property | Seed | Final result |
| --- | ---: | --- |
| Caster level is monotonic in every class level | 929298 | Pass |
| Warlock Pact Magic never enters shared slots | 929299 | Pass |
| Proficiency bonus depends only on total level | 929300 | Pass |
| Adding a non-caster level does not change shared slots | 929301 | Pass |
| Single-class maximum preparation does not exceed possessed slots | 929302 | Pass for legal base-class casters |
| Slot counts are non-increasing as spell level rises | 929303 | Pass |

The final property run is 6 tests and 9,795 assertions. A failed check prints a
single-line JSON object to stderr containing the property, seed, iteration, and
all involved builds before raising the assertion.

## Property failure — real finding

The first run **failed** seed 929302 at iteration 27:

```json
{"property":"single-class maximum preparable level does not exceed possessed slots","seed":929302,"iteration":27,"builds":{"single_class":[{"class":"Rogue","level":19,"progression":"third_down"}]}}
```

It revealed an important engine boundary: `SpellSlots::slots()` always computes
the shared multiclass table. It returns caster-level-6 shared slots, through 3rd
level, for a level-19 third-caster contribution, while
`maxPreparableLevelForClass()` returns 4. It cannot be used as the possessed-slot
oracle for a single-class subclass caster.

This did **not** reveal a wrong shipped report value. Fighter and Rogue are
non-casting base classes; Eldritch Knight and Arcane Trickster are separate
subclass state. `BuildReportBuilder` deliberately reads their own
`subclass_progressions` slot table, and the existing feature suite confirms both
level-19 subclasses possess a 4th-level slot. The initial generator had silently
treated a base Fighter/Rogue as a subclass without generating that subclass
state. The final generator now produces the requested legal base-class mixes;
subclass possession remains checked through `SubclassProgressionTest` rather
than through the shared-table API. No production behavior was changed or hidden.

## Sensitivity checks

Every new property was made false temporarily in production, run alone, and the
production change was immediately restored. Each filtered property failed and
printed its seed and generated build:

| Property | Temporary break | First reproduced failure |
| --- | --- | --- |
| Monotonic caster level | Full-caster contribution negated | seed 929298, iteration 1 |
| Pact separation | Pact levels contributed to shared caster level | seed 929299, iteration 0 |
| Proficiency only by total | Proficiency result raised by one | seed 929300, iteration 0 |
| Non-caster slot stability | `none` contributed its class level | seed 929301, iteration 0 |
| Preparation bounded by slots | Full-caster preparation raised one level | seed 929302, iteration 1 |
| Non-increasing slot counts | Caster-level-3 row changed from 4/2 to 4/5 | seed 929303, iteration 12 |

A post-check diff confirmed both production rules files were restored, and the
clean property suite passed again.

## New tests

- `tests/Unit/RulesPropertyTest.php`: six seeded randomized invariants, 1,000
  cases each, with reproducible failure output.

No mutation-gap test was necessary because the baseline escape ledgers were
empty. No existing test was weakened, deleted, or changed.

## Independent review

The required Claude plan critique and implementation critique were each invoked
with bounded waits. Both produced no review content and ended with `Execution
error`; neither changed files. Silence was not treated as approval. Local review
verified positive level partitions, unique classes, level caps, non-vacuous
loops, union completeness for Reports, and the six sensitivity failures above.

## Verification output

`ddev exec php artisan migrate:fresh --seed` followed by
`ddev exec vendor/bin/pest`:

```text
Dropping all tables ............................................ 4.07ms DONE

 INFO  Preparing database.

Creating migration table ....................................... 3.20ms DONE

 INFO  Running migrations.

0001_01_01_000000_create_users_table ........................... 0.92ms DONE
0001_01_01_000001_create_cache_table ........................... 0.35ms DONE
0001_01_01_000002_create_jobs_table ............................ 0.68ms DONE
2026_07_21_000100_create_catalog_tables ........................ 6.81ms DONE
2026_07_21_000200_create_character_tables ...................... 3.65ms DONE
2026_07_21_000300_add_spell_selection_eligibility .............. 1.92ms DONE
2026_07_21_000400_create_subclass_progressions ................. 0.28ms DONE
2026_07_21_000500_create_character_operations .................. 0.31ms DONE

 INFO  Seeding database.

Database\Seeders\ClassProgressionSeeder ............................ RUNNING
Database\Seeders\ClassProgressionSeeder ......................... 36 ms DONE
Database\Seeders\ContentDefinitionSeeder ........................... RUNNING
Database\Seeders\ContentDefinitionSeeder ......................... 1 ms DONE
Database\Seeders\SeedCharacterSeeder ............................... RUNNING
Database\Seeders\SeedCharacterSeeder ............................ 21 ms DONE

Tests:    274 passed (12061 assertions)
Duration: 23.65s
```

`ddev exec npm run typecheck` and `ddev exec npm run build`:

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
public/build/assets/app-CKcD7ioQ.css                            75.38 kB │ gzip: 14.21 kB
public/build/assets/app-BrTJ8e95.js                            221.47 kB │ gzip: 74.26 kB
✓ built in 362ms
```

`ddev exec npm run test:e2e`:

```text
> test:e2e
> playwright test

Running 15 tests using 1 worker
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
15 passed (48.3s)
```

Final direct golden-value output after another fresh seed:

```json
{
    "caster_level": 6,
    "slots": [
        4,
        3,
        3
    ],
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
