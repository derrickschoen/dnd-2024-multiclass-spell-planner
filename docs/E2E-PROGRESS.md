# End-to-end browser test suite — progress log

Standing task, resumed every 30 minutes by a cron loop. **Read this first, update
it last.** Each iteration must leave a committed, working tree.

## Ground rules

- Playwright drives real Chromium against https://dnd-spell-planner.ddev.site
- `ddev exec php artisan migrate:fresh --seed` then `ddev exec vendor/bin/pest`
  must keep passing. Migration rollback is out of scope.
- Codex runs as `codex exec --profile sol`. Never `-m`, never edit
  `~/.codex/config.toml` — other Claude sessions run concurrently.
- Produce with codex, review with a **fresh** codex session, fix significant
  findings, verify independently, commit.
- **Never trust a reported test count.** Rerun everything locally.

## The bar for a new test

This codebase has shipped **three** separate tests that could not fail:

1. a level-down test that reused one rule key at levels 1 and 4
2. an import test that asserted referenced versions *get* mutated
3. a third-caster test that asserted caster level but never slots

So every new E2E test must be **sensitivity-checked**: state explicitly whether it
would still pass if the production behaviour it covers were reverted. Record the
answer here. A test that cannot fail is worse than no test — it manufactures
confidence.

## Environment facts

- Mutation API: `POST /characters/{id}/mutations` with
  `{operation_uuid, expected_revision, command:{type, ...}}`
  Types: `set_slot`, `update_ability` (field is `score`, not `value`),
  `update_class`, `update_character_rules`, `update_source_config`,
  `acknowledge_warning`, `restore_snapshot`. Returns
  `{inverse, revision, workspace}`.
- Needs `X-CSRF-TOKEN` from the `csrf-token` meta tag plus the session cookie.
- Seeded character id 1, "A6 Sixfold Spellcaster": Sorcerer/Wizard/Bard/Paladin/
  Cleric/Druid all at 1, Human, Magic Initiate ×2. 40 slots, **11 filled**.
- Golden values: caster level 6, slots 4/3/3, PB +3, every class max preparable 1,
  Mage Hand = wasteful duplicate, Entangle = none, Detect Magic =
  origin `capability` / `ritual_only` / not a selection / no limit consumed.

## Scenario backlog (complex, not smoke)

Ordered roughly by value. Mark done as they land.

- [x] **S1 Cascade isolation** — edit one slot, assert every other slot row is
      byte-identical (ids, keys, selections, state) in the DB, not just on screen.
- [x] **S2 Duplicate category transitions** — drive Mage Hand through
      wasteful → none → wasteful by editing one of the two slots; assert the
      warning text and category change each way.
- [x] **S3 Undo/redo across reload** — undo, hard reload, confirm the DB reflects
      the undo and the redo stack is correctly gone (session-only by design).
- [x] **S4 Orphan recovery** — remove the Magic Initiate source, confirm its three
      slots become orphaned rather than deleted with their selections intact, then
      restore and confirm the identical rows return.
- [x] **S5 Ability propagation** — INT 13→20 changes every INT route from DC 12 /
      attack +4 to DC 16 / attack +8, and nothing on WIS or CHA routes moves.
- [x] **S6 Wizard three states** — a spellbook spell that is prepared, one that is
      unprepared+ritual (ritual-only), one unprepared+non-ritual (not castable);
      assert the on-screen explanatory text for each.
- [x] **S7 Save point round trip** — save, make several destructive edits, restore,
      assert full equality with the saved snapshot.
- [x] **S8 Add a Warlock level** — pact pool appears separately and is never summed
      into the shared slot table; the preparation callout accounts for it.
- [x] **S9 Level down** — reduce a class level, assert surplus slots orphan rather
      than vanish, then level back up and assert the same rows reactivate.
- [x] **S10 Stale tab / revision conflict** — two contexts, one edits, the other
      posts with a stale `expected_revision` and must be rejected with 409.
- [x] **S11 Accessibility** — keyboard-only traversal of the grid, visible focus,
      no colour-only warning signalling (every warning has text or an icon).


## Backlog tier 2 — added after S1-S11 completed

Flake check on the tier-1 suite: 3 consecutive full runs, 11/11 each, 35.3-35.5s.
No intermittent failures.

- [x] **S12 Cross-edition version conflict** — a headline spec feature with NO test
      today. Enable `allow_legacy`, select both the 2014 and 2024 versions of one
      spell identity (e.g. Chill Touch: 2014 ranged vs 2024 melee spell attack),
      assert a prominent CONFLICTING VERSIONS warning naming both versions, then
      acknowledge it with a note and assert the acknowledgement persists.
- [x] **S13 Magic Initiate list change** — change a Magic Initiate instance from
      Wizard to Cleric. Its chosen list is CONFIGURATION, not identity: slot ids
      and slot_keys must be unchanged, while now-ineligible selections are flagged
      invalid and excluded from access routes rather than deleted. This is an
      explicit design guarantee that has never been exercised through the browser.
- [x] **S14 Concurrent non-conflicting edits** — the complement to S10. Two browser
      contexts edit DIFFERENT slots. Both must succeed, neither may clobber the
      other, and the audit log must contain both operations with distinct
      operation_uuids. S10 proves conflicts are rejected; nothing yet proves
      legitimate concurrent work is allowed.
- [x] **S15 Repeated Magic Initiate distinct-list rule** — a second Magic Initiate
      on the SAME list must be refused; on a different list accepted. Enforced in
      the browser, not just the domain layer.

## Iteration log

### Iteration 1 — E2E-1 batch 1 complete

Implemented Playwright configuration and complex browser scenarios S1, S2, S3,
and S5 in `e2e/complex-workspace.spec.ts`. Each test performs its own
`migrate:fresh --seed` reset and runs against the real ddev URL and SQLite
database. The database inspection helper boots Laravel directly without adding a
test-only HTTP route.

Sensitivity checks (all temporary production changes were restored):

- S1 failed when a temporary mutation churned `updated_at` on the 39 untouched
  slot rows; the deep equality showed all 39 timestamp differences.
- S2 failed at the initial row-category assertion when wasteful duplicates were
  temporarily classified as `none`.
- S3 failed at the database assertion when the inverse command temporarily
  replayed the selected spell instead of restoring the prior state.
- S5 failed at the raised-INT assertion when workspace modifier propagation was
  temporarily capped below the INT 20 modifier.

Clean verification observed: Pest 159 passed (1355 assertions), typecheck passed,
build passed, and Playwright 4 passed. No existing Pest test was modified.

### Iteration 2 — UNIT E2E-2 batch 1 review plus S4, S6, S7

Adversarial review of commit `e205668` found no test that was wholly unable to
fail, no silently empty locator assertion, and sound per-test isolation: Playwright
runs one worker without full parallelism, and synchronous `migrate:fresh --seed`
finishes before each navigation. Significant findings and fixes:

- Medium: S1 only asserted that selecting Fire Bolt wrote a non-null version id,
  so a wrong spell or wrong edition could pass. S1 now compares the database value
  to the independently queried `2024:fire-bolt` id. S3 received the same hardening.
- Medium: the shared spell-selection helper's fixed 150 ms sleep masked a real
  deferred combobox-reset race. Removing it made S2 intermittently fail to issue
  the second Mage Hand search in the full suite. The helper now retries only the
  input/search acquisition until it receives the exact query response, then checks
  status, payload, slot-scoped option, mutation response, revision, value, and
  autosave outside the retry. S2 passed five consecutive reset/runs afterwards.
- Low: S5 rewrote the raised INT values back to the baseline inside its structural
  comparison. A separate assertion still made the number behavior falsifiable, but
  the comparison was misleading. It now removes the numeric field from both sides
  and checks `+8 / 16` independently.

Implemented scenarios:

- S4 removes the Human parent's Magic Initiate grant rule and regenerates through
  `reconcileGrantedChildren`, proving all three Wizard-source rows remain orphaned
  with ids, source ids, slot keys, rule keys, ordinals, and selections preserved.
  Restoring the exact rule JSON reactivates those rows. Every non-target slot and
  the Magic Initiate: Druid source row remain byte-identical throughout.
- S6 asserts the exact six-spell spellbook, four prepared spells, Detect Magic as
  the sole ritual-only spell, Feather Fall as unprepared and non-ritual, and all
  required explanatory text. Selecting Detect Magic through Magic Initiate creates
  a real overlap; the capability route remains non-selection/non-counting, exactly
  one route is a selection, and the assessment and UI remain `none`/warning-free.
- S7 creates a save point in the UI; changes two slots, INT, and Wizard level; then
  restores in the UI. The stored snapshot is compared with an independent SQL
  projection of every character column and table in the `a7-v1` save-point contract,
  both before the edits and after restore.

Sensitivity checks (all temporary production changes were restored):

- S4 failed at the source-state assertion with `Expected: "tombstoned"` /
  `Received: "active"` when `reconcileGrantedChildren()` was temporarily disabled.
- S6 failed at the capability-route object assertion when ritual-only access was
  temporarily changed to `is_selection: true` and `counts_against_limit: true`;
  the diff showed both expected `false` values received as `true`.
- S7 failed with a full deep-equality database diff when snapshot restoration was
  temporarily skipped: INT remained 20, Wizard remained level 2, both changed spell
  ids remained, timestamps differed, and the new Wizard prepared slot remained.

Independent review ran for three rounds. Accepted findings led to the real parent
reconciliation path in S4, an independent SQL projection in S7, complement/blast-
radius assertions, non-deadlocking dialog handling, exact S3 spell identity, and
condition-based combobox synchronization. Rejected with rationale: S6's copy checks
are required on-screen assertions backed by separate route/duplicate assertions;
tables outside `CharacterState` are outside the save-point snapshot contract; and
the duplicated independent snapshot projection is intentionally a contract-change
tripwire that must be updated when the schema version changes.

Final verification observed:

```text
Tests:    159 passed (1355 assertions)
Duration: 15.77s

> typecheck
> vue-tsc --noEmit

> build
> vite build
✓ 567 modules transformed.
✓ built in 339ms

Running 7 tests using 1 worker
  ✓ S1
  ✓ S2
  ✓ S3
  ✓ S4
  ✓ S5
  ✓ S6
  ✓ S7
  7 passed (17.5s)
```

Deviation: no browser UI or mutation command exists for removing an origin-feat
source. S4's fixture driver therefore removes/restores the Human definition's exact
grant-rule JSON in the database and invokes the real production parent generator;
the browser and database assertions exercise the resulting application state. Per
the UNIT instruction, this iteration remains uncommitted.

### Iteration 3 — UNIT E2E-3 batch 2 review plus S8, S9, S10, S11

Adversarial review of commit `705c4ea` found one significant weakness:

- Medium, `e2e/complex-workspace.spec.ts:181`: S4's preservation projection kept
  only id, source id, slot key, rule key, ordinal, and selection. It would miss
  corruption of the target rows' bucket, eligibility constraints, counting/lock
  flags, labels, or other selection semantics. S4 now compares every slot column
  except the lifecycle fields that must change while orphaned (`state`, orphan
  metadata, prior config, and `updated_at`). The same semantic comparison runs
  after reactivation at line 200.

No significant S6 or S7 weakness remained: their positive cardinality and exact
list assertions prevent empty locators from passing; S6's capability flags and
S7's independent SQL state projection both failed under production mutations.
S6 received defensive route-identity hardening at lines 284-300 so both the
Wizard capability route and Magic Initiate selection route are pinned by source,
origin, casting mode, and counting behavior.

Implemented scenarios:

- S8 adds Warlock through the browser, pins the shared pool at 4/3/3 and caster
  level 6, pins Pact Magic separately at one level-1 slot, checks the Warlock
  preparation ceiling, and asserts the UI/report explains that either pool can
  cast an eligible prepared spell without merging preparation limits.
- S9 raises Wizard 1 to 3, selects Detect Magic and Feather Fall in the two new
  prepared rows, lowers to 1, and proves both exact rows remain orphaned. Raising
  back to 3 proves the same ids, slot keys, spell ids, and all semantic columns
  reactivate.
- S10 loads revision 0 in two independent Chromium contexts. The first changes
  INT; the stale context attempts WIS 18 through the real UI store with
  `expected_revision: 0`. The response is 409/current revision 1, and an exact
  before/after SQL projection proves the character, captured state, audit log,
  operations, and save points are unchanged by the rejected write.
- S11 Tabs through every enabled slot-grid control before and after orphan
  controls appear, proves each receives `:focus-visible` with a non-empty
  computed outline, and checks every form control has a native or ARIA label.
  Duplicate and orphan warnings are asserted by text/icon, plus every
  `.status-warning` is scanned for a non-colour cue in both states.

Sensitivity checks (all temporary production changes were restored):

- S4 failed at `removal.source.state` with expected `"tombstoned"`, received
  `"active"`, when granted-child reconciliation was disabled.
- S6 failed at the capability route object with received `is_selection: true`
  and `counts_against_limit: true` when those production flags were inverted.
- S7 failed with a full database diff when snapshot restoration was skipped;
  the diff included INT 20, Wizard 2, both changed spell ids, timestamps, and the
  extra Wizard prepared row.
- S8 failed at the exact caster object when Pact Magic reporting was disabled:
  expected `{count: 1, level: 1}`, received `null`.
- S9 failed at the first post-level-down cardinality assertion when reconciliation
  deleted surplus rows: expected length 2, received length 0.
- S10 failed at the response status when the revision guard was bypassed:
  expected 409, received 200.
- S11 failed with 47 `visibleOutline: false` entries when focus outlines were
  forcibly suppressed in the built production CSS. Removing only the custom
  outline did not break the behavior because Chromium retained its native focus
  ring. A separate colour-only mutation left the duplicate badge amber but empty;
  S11 failed with expected substring `"Wasteful"`, received `""`.

Independent review raised broad-comparison and environment-brittleness concerns.
They were rejected because exact shared slots, all preserved slot semantics, the
full mutation footprint, deterministic revision 0, Chromium focus styling, and
every-warning scanning are explicit scenario contracts. A later suggestion to
test focus placement after resolving an orphan was rejected as outside this
iteration's traversal/focus/label/signalling scope; the reviewer agreed the
requested S11 checks were covered. Self-review added the second traversal and
label audit after contextual orphan controls appear.

Final verification observed:

```text
INFO  Preparing database.
INFO  Running migrations.
INFO  Seeding database.

Tests:    159 passed (1355 assertions)
Duration: 16.74s

> typecheck
> vue-tsc --noEmit

> build
> vite build
vite v8.1.5 building client environment for production...
[plugin laravel:fonts] Optimized font fallbacks require the optional "fontaine" package. Install it, or set "optimizedFallbacks: false" on your fonts to disable the feature.
✓ 567 modules transformed.
✓ built in 514ms

> test:e2e
> playwright test

Running 11 tests using 1 worker
  ✓   1 [chromium] › e2e/complex-workspace.spec.ts:66:1 › S1: editing one slot leaves every other database row byte-identical (2.0s)
  ✓   2 [chromium] › e2e/complex-workspace.spec.ts:85:1 › S2: duplicate category and explanation transition wasteful → none → wasteful (2.6s)
  ✓   3 [chromium] › e2e/complex-workspace.spec.ts:128:1 › S3: undo is persisted by the server and redo is discarded by a hard reload (3.4s)
  ✓   4 [chromium] › e2e/complex-workspace.spec.ts:165:1 › S4: removing and restoring Magic Initiate: Wizard preserves its orphaned slot identities and selections (3.1s)
  ✓   5 [chromium] › e2e/complex-workspace.spec.ts:212:1 › S5: INT changes propagate to every INT route in both directions without moving WIS or CHA (1.3s)
  ✓   6 [chromium] › e2e/complex-workspace.spec.ts:244:1 › S6: Wizard spellbook shows prepared, ritual-only, and unprepared non-ritual states (1.3s)
  ✓   7 [chromium] › e2e/complex-workspace.spec.ts:306:1 › S7: a save point restores the complete persisted character state after destructive edits (4.0s)
  ✓   8 [chromium] › e2e/complex-workspace.spec.ts:358:1 › S8: adding Warlock keeps Pact Magic separate from shared slots and explains cross-pool casting (1.1s)
  ✓   9 [chromium] › e2e/complex-workspace.spec.ts:399:1 › S9: level-down orphans surplus selected slots and level-up reactivates the same rows (2.8s)
  ✓  10 [chromium] › e2e/complex-workspace.spec.ts:449:1 › S10: a stale browser context receives 409 and cannot change any database state (2.5s)
  ✓  11 [chromium] › e2e/complex-workspace.spec.ts:494:1 › S11: the slot grid is keyboard reachable, visibly focused, labelled, and warnings are not colour-only (11.5s)

  11 passed (36.2s)
```

Deviation: S4 still cannot trigger origin-feat removal through the browser, as
recorded in Iteration 2. S8, S9, S10, and S11 are all triggered through browser
contexts; none required a test-only HTTP route or direct database mutation.
Per the UNIT instruction, this iteration remains uncommitted.

### Iteration 4 — UNIT E2E-4 tier 2 S12, S13, S14, S15

The brief adversarial pass over the four newest tier-1 tests found no significant
issue. S8 pins exact report values and positive UI cardinality; S9 pins exact
database rows and visible state text; S10 pins the rejected request, response, and
full persisted footprint; S11 derives its grid count from the database and scans
positive control/warning sets. None compares a value to itself, reads through the
path it is intended to verify, or relies on a locator that may silently be empty.
No tier-1 test was changed.

Implemented scenarios and supporting production behavior:

- S12 enables legacy rules through the browser, selects the exact 2014 and 2024
  Chill Touch catalog versions in separate Wizard slots, and pins both database
  version ids. The report renders a prominent `CONFLICTING VERSIONS` warning with
  `Chill Touch (2014)` and `Chill Touch (2024)`. Its content-key-based fingerprint
  and required-note acknowledgement are persisted in `warning_acknowledgements`,
  included in snapshot/audit state, and verified unchanged after reload.
- S13 changes the generated Magic Initiate: Wizard source to Cleric through the
  browser. The command updates both child and parent `origin_feat_config`, then
  regenerates from the parent. All three ids and slot keys stay exact, selections
  remain stored, their allowed list changes to Cleric, all become invalid, and all
  three slot ids disappear from access routes while appearing in invalid selections.
- S14 admits a stale `set_slot` only when joined operation/audit history proves the
  exact target slot was untouched since the submitted revision. Two browser
  contexts save different slots at submitted revision 0, producing revisions 1
  and 2, two exact persisted spell ids, two operations, two audit rows, and two
  distinct operation UUIDs. A focused Pest regression also proves a stale write to
  an already touched slot still receives 409.
- S15 transactionally inserts a real Magic Initiate source fixture and invokes
  `GrantRuleSlotGenerator`. Wizard is refused with no source/slot residue; Cleric
  is accepted with three exact list-constrained slots and appears in the browser
  after reload.

Sensitivity checks (all temporary production changes were restored, and each
scenario passed again after restoration):

- S12 failed at the response assessment when conflicting-version classification
  was suppressed: expected `category: "conflicting_version"`, received
  `category: "wasteful"`; the received fingerprint was also `null`.
- S13 failed at the database eligibility assertion when parent reconciliation was
  disabled: expected `["invalid", "invalid", "invalid"]`, received
  `["valid", "valid", "valid"]`.
- S14 failed inside the second browser's real spell selection when the safe stale
  slot merge was disabled: expected mutation status `200`, received `409`.
- S15 failed at the direct production-path result when distinct configuration
  enforcement was disabled: expected `accepted: false`, the exact duplicate-list
  error, no source, and no slots; received `accepted: true`, `error: null`, a new
  Wizard source, and three new slots.

Final verification observed:

```text
Dropping all tables ............................................ 3.97ms DONE

 INFO  Preparing database.

Creating migration table ....................................... 3.10ms DONE

 INFO  Running migrations.

0001_01_01_000000_create_users_table ........................... 0.89ms DONE
0001_01_01_000001_create_cache_table ........................... 0.32ms DONE
0001_01_01_000002_create_jobs_table ............................ 0.64ms DONE
2026_07_21_000100_create_catalog_tables ........................ 6.43ms DONE
2026_07_21_000200_create_character_tables ...................... 3.53ms DONE
2026_07_21_000300_add_spell_selection_eligibility .............. 1.84ms DONE
2026_07_21_000400_create_subclass_progressions ................. 0.28ms DONE
2026_07_21_000500_create_character_operations .................. 0.31ms DONE

 INFO  Seeding database.

Database\Seeders\ClassProgressionSeeder ............................ RUNNING
Database\Seeders\ClassProgressionSeeder ......................... 37 ms DONE
Database\Seeders\ContentDefinitionSeeder ........................... RUNNING
Database\Seeders\ContentDefinitionSeeder ......................... 1 ms DONE
Database\Seeders\SeedCharacterSeeder ............................... RUNNING
Database\Seeders\SeedCharacterSeeder ............................ 29 ms DONE

Tests:    160 passed (1364 assertions)
Duration: 15.57s

> typecheck
> vue-tsc --noEmit

> build
> vite build
vite v8.1.5 building client environment for production...
[plugin laravel:fonts] Optimized font fallbacks require the optional "fontaine" package. Install it, or set "optimizedFallbacks: false" on your fonts to disable the feature.
✓ 567 modules transformed.
✓ built in 347ms

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
  15 passed (45.4s)
```

UI limitation: the browser can edit an existing Magic Initiate chosen list (S13),
but it has no control for adding another feat/source instance. S15 therefore
asserts that the visible Source configuration section has no add-Magic-Initiate
control, drives the real transactional generator path directly as S4 does, then
verifies the accepted source and slots in the browser. It does not fake a browser
trigger or add a test-only HTTP route.

Review deviation: the required independent Claude review was attempted three
times (plan twice, implementation once). Each non-interactive CLI process produced
no review output and ended in `Execution error`, including bounded 180-second and
240-second attempts. Self-review found and fixed parent configuration persistence,
live-warning fingerprint validation, and same-slot concurrency coverage. No
independent findings were returned to accept or reject. Per the UNIT instruction,
this iteration remains uncommitted.

### Iteration 5 — UNIT E2E-5 independent review of c717ba4

The independent review found one significant production issue: the source-config
command accepted every class with a spellcasting ability even though 2024 Magic
Initiate and the UI restrict the choice to Cleric, Druid, or Wizard. A direct API
request could therefore configure Bard, Paladin, Ranger, Sorcerer, or Warlock.
`UpdateSourceConfigCommand` now enforces the same three-list domain boundary.

The review also hardened the other new command inputs: `allow_legacy` must be an
actual JSON boolean, and warning acknowledgement mode must be `acknowledge` or
`delete`. Three focused feature tests now drive each new command through the real
executor and prove apply/inverse round trips, expected revisions, transaction
rollback on invalid input, idempotent operation replay, and one shared audit group
per operation. The source-config test compares the complete captured character
state before apply and after undo. The legacy test also proves a 2014 version is
rejected while `allow_legacy` is false.

The load-bearing duplicate/access/eligibility paths were reviewed against their
parents. Conflict classification was already ahead of wasteful classification;
the commit did not reorder it. Added access-route fields do not change ritual-only
capability routing, and invalid legacy or reconfigured selections remain excluded
from access routes. The golden report and the existing ritual-only regression both
pass unchanged.

S12-S15 remain falsifiable: reverting legacy/conflict/acknowledgement behavior,
source reconciliation, safe stale-slot merge, or distinct-list enforcement removes
a required response, exact database value, or visible control asserted by its
scenario. S11's `slotFixtures() + 7` constant remains valid because the new controls
are outside the slot grid. S12 and S13 now additionally prove keyboard reachability
and visible focus for the legacy checkbox, source-list select, acknowledgement
input, and acknowledgement button; their labels and text warning cues were also
reviewed.

Final independent verification:

```text
Tests:    163 passed (1419 assertions)
Duration: 17.33s

> typecheck
> vue-tsc --noEmit

> build
> vite build
✓ 567 modules transformed.
✓ built in 413ms

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
  15 passed (46.7s)
```

The seven direct golden values remain caster level 6, slots 4/3/3, proficiency
+3, every class maximum preparable level 1, Mage Hand `wasteful`, Entangle `none`,
and Detect Magic `origin=capability`, `casting_mode=ritual_only`,
`is_selection=false`, `counts_against_limit=false`. No commit or push was made.
