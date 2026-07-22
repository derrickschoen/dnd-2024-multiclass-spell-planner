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
  `update_class`, `restore_snapshot`. Returns `{inverse, revision, workspace}`.
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
- [ ] **S8 Add a Warlock level** — pact pool appears separately and is never summed
      into the shared slot table; the preparation callout accounts for it.
- [ ] **S9 Level down** — reduce a class level, assert surplus slots orphan rather
      than vanish, then level back up and assert the same rows reactivate.
- [ ] **S10 Stale tab / revision conflict** — two contexts, one edits, the other
      posts with a stale `expected_revision` and must be rejected with 409.
- [ ] **S11 Accessibility** — keyboard-only traversal of the grid, visible focus,
      no colour-only warning signalling (every warning has text or an icon).

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
