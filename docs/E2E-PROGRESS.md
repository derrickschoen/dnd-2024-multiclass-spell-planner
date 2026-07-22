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


## Backlog tier 3 — API-surface abuse (added after the Magic Initiate bug)

Tier 2's independent review found a rules bug that **fifteen browser scenarios
could not have caught**: `UpdateSourceConfigCommand` accepted any spellcasting
class as a Magic Initiate list, so a direct API request could create Magic
Initiate: Bard. The UI only ever offers the three legal lists, so UI-driven tests
structurally cannot reach that hole.

Conclusion: the browser suite is necessary but not sufficient. The write surface
is 7 command types across 4 POST/DELETE routes and needs adversarial coverage of
its own. These belong in **Pest feature tests** (direct, fast) rather than
Playwright — a browser adds nothing when the point is to bypass the browser.

- [x] **A1 Payload abuse, every command.** For all 7 command types: wrong types,
      missing required fields, out-of-range numbers, unknown enum values, null
      where an id is required, absurd magnitudes. Each must be rejected with a
      clear error and leave the database untouched. Assert no partial write.
- [x] **A2 Cross-character isolation.** Can a mutation posted to character 1 touch
      character 2's slots, sources, save points or acknowledgements? The composite
      FKs should make it impossible, but nothing proves the command layer checks
      ownership before relying on them.
- [x] **A3 Eligibility bypass.** POST `set_slot` with a spell that is NOT on the
      slot's allowed list, outside its level range, or of the wrong edition while
      `allow_legacy` is false. Must be refused or recorded invalid — never
      silently accepted as a valid selection.
- [x] **A4 Replay and idempotency.** Replay one `operation_uuid` across different
      command types and against a changed revision. Assert exactly one write, one
      audit group, and no duplicate rows.
- [x] **A5 Restore-snapshot abuse.** Restore a save point belonging to a different
      character; restore a snapshot referencing a tombstoned spell version.

Each needs the same sensitivity treatment: break the guard, observe the test fail,
restore.


## Backlog tier 4 — guard-coverage sweep (evidence-driven)

The recurring failure mode across this whole effort is NOT missing tests. It is
**adjacent-path incompleteness**: a guard is added where a test happened to look,
and the identical bug survives one door down.

Evidence:
- the scraper level parser was wrong FOUR times, each fix verified, each still wrong
- snapshot restore was hardened against tombstoned spell versions; `set_slot`
  restore accepted them until the next review
- Magic Initiate list validation existed in the UI but not on the API

So the next work is not more scenarios. It is a **matrix**: for each class of
guard, enumerate EVERY path that should enforce it and prove each one does.

- [ ] **G1 Tombstoned/inactive spell versions.** Only 5 files reference
      `is_active`. But `GrantRuleSlotGenerator`, `SpellSelectionService` and
      `SpellAccessBuilder` all handle spell-version references and do NOT. Can a
      `fixed_spell` grant materialise a tombstoned version? Can an access route
      surface one? Can a spellbook or prepared entry hold one?
- [ ] **G2 Character ownership.** Every command, controller and query that takes
      an id from the client: slots, sources, save points, acknowledgements,
      spellbook entries, loadouts. Which verify the row belongs to the character
      in the URL, and which rely on a composite FK to fail messily instead?
- [ ] **G3 Eligibility revalidation triggers.** S13 proved a Magic Initiate list
      change re-validates selections. What about a class LEVEL change, a SUBCLASS
      change, toggling `allow_legacy` off while a legacy spell is selected, or a
      catalog re-import that tombstones a selected version? Each should mark
      affected selections invalid; any that silently leaves them valid is the same
      bug in a new place.

Deliver the matrix itself as documentation, not just tests: a table of guard x
path with a verdict per cell, so the next gap is visible rather than discovered.


## Backlog tier 5 — mutation testing the EXISTING suite

Every iteration has sensitivity-checked its NEW tests by hand: break the
behaviour, watch the test fail, restore. That has worked -- but roughly 150 tests
predate this practice and have never been checked. Given this repo's record (three
confirmed can't-fail tests, plus more found in every review round since), assume
more are hiding in the older suite.

Infection does mechanically what we have been doing by inspection: it mutates
production code and reports which mutations NO test catches. An escaped mutant is
a precise, reproducible statement that some behaviour is unprotected.

- [x] **M1 Mutation-test the pure rules engine** (`app/Domain/Rules/`). Fast, no
      DB, and it is the core of the app: caster levels, slot tables, per-class
      rounding, proficiency bonus, max preparable level.
- [x] **M2 Mutation-test the spell/grant domain** (`app/Domain/Spells/`,
      `app/Domain/Grants/`) -- duplicate classification, access routes, eligibility.
- [x] **M3 Kill or justify every escaped mutant.** Some are legitimately
      equivalent; those get documented, not tested. The rest get a test.

Deliverable is the escaped-mutant list with a verdict per mutant, plus the tests
that kill the real ones.


## Backlog tier 6 — finish the mutation sweep, then a new instrument

The tier 5 sweep ran M1 = Rules and M2 = Spells + Grants. Infection is configured
for all of `app/Domain`, but three directories were never actually mutated:

    app/Domain/Characters/   652 lines   <- the commands where 3 HIGH bugs lived
    app/Domain/Catalog/      478 lines   <- the importer
    app/Domain/Reports/      315 lines   <- what the golden values derive from

That is 1,445 unmutated lines, and it includes the code with the worst track
record in this repo. Tier 5's headline finding was that the tests I trusted most
had 41% of their mutants escape; there is no reason to assume these are better.

- [x] **M4 Mutation-test `app/Domain/Characters/`** — commands, executor, state,
      workspace builder, payload validator, integrity. Highest priority: three
      HIGH severity bugs have already been found here by other means.
- [x] **M5 Mutation-test `app/Domain/Catalog/`** — the importer, including
      identity/alias resolution and tombstoning.
- [x] **M6 Mutation-test `app/Domain/Reports/`** — the golden values come from here.

Then a genuinely new instrument, because every technique so far has eventually
gone quiet and a different one found what it structurally could not:

- [x] **P1 Property-based invariants for the rules engine.** Generate random legal
      multiclass builds and assert invariants that must hold for ALL of them, not
      just enumerated vectors:
      caster level is monotonic in each class level; the Warlock pact pool is never
      summed into the shared table; max preparable level never exceeds the highest
      possessed slot level for a single-class build; proficiency bonus depends only
      on total character level; adding a non-caster class never changes slots.
      Example-based tests check the points you thought of. Properties check the
      space between them.


## Backlog tier 7 — close the gap the tests kept reporting

Honest read on yield: the testing instruments have saturated. Tier 6's mutation
sweep of Characters, Catalog and Reports returned 100% MSI BEFORE any new tests
(1,195 mutants, zero escapes), and property testing surfaced a generator bug
rather than an engine one. The last two iterations found no production defects.

Meanwhile the suite has been reporting the same deviation since tier 2: **there is
no way to add or remove a source through the app.** The seven commands cover
ability, slot, rules, source CONFIG, acknowledgement, class and snapshot. None
adds a feat, species or background. S4 and S15 both had to drive fixtures instead
of the UI because of it.

That is not a test weakness. It is the tests correctly reporting a missing
feature, repeatedly, for five iterations.

- [x] **F1 add_source / remove_source commands.** Same contract as the others:
      apply()/inverse(), idempotent operation_uuid, revision guard, one
      transaction, grouped audit rows. Removing a source must TOMBSTONE it and
      orphan its slots with selections preserved -- the behaviour S4 already
      proves, reached through a real command instead of a fixture.
- [x] **F2 UI to add and remove feats/species/background**, satisfying S11's
      accessibility guarantees: keyboard reachable, visibly focused, labelled, no
      colour-only signalling.
- [x] **S16 add a feat through the browser** — pick Magic Initiate, choose its
      list and ability, assert its three slots materialise via the DSL with
      correct per-slot casting modes (cantrips get no free cast; only the level-1
      spell does).
- [x] **S17 remove a feat through the browser** — retires the S4 fixture-trigger
      deviation. Assert orphaning and identical-row restoration through real UI.


## Backlog tier 8 — re-measure what tier 6 got wrong

Tier 6 reported Characters, Catalog and Reports at 100% MSI. That measurement was
invalid: the Infection launcher resolved the wrong project under Infection's
temporary directory and translated its Pest filters into descriptions matching no
tests, so it scored a run that executed almost nothing. The launcher is now fixed.

The one honest post-fix data point: `CharacterWorkspaceBuilder` alone produced 144
mutants and killed 38 -- about 26%, not 100%.

So roughly 1,445 lines are unmeasured, and the single measured sample suggests
substantial real gaps. Tier 5 showed what that looks like when measured properly:
145 real gaps in code that looked well tested.

A corrected broad run skipped 626 of 914 mutants and was rightly discarded rather
than reported. Tier 5 hit the same problem and solved it by splitting M2 per
class. Do that again.

- [x] **R1 Re-measure `app/Domain/Characters/` per class**, not as a directory.
      Report MSI per file with the escaped-mutant ledger. Zero skips, or say
      exactly which mutants were skipped and why.
- [x] **R2 Re-measure `app/Domain/Catalog/` and `app/Domain/Reports/`** the same way.
- [x] **R3 Kill the real gaps; justify the equivalents individually.**

The bar from tier 5 applies: do not weaken or delete an existing test to raise a
score, and do not add assertions that restate the implementation. A skipped mutant
is not a killed mutant, and an unmeasured file is not a covered file.


## Backlog tier 9 — load "Mutt" from the user's real character sheet

The user supplied `mutt-6.pdf`, a partial D&D Beyond export. Extracted:

- **Sorcerer 1 / Bard 1 / Cleric 1 / Druid 1 / Paladin 1 / Wizard 1**, level 6,
  max HP 43. Confirmed independently by hit dice `2d6 + 3d8 + 1d10`.
- **33 catalog-matched spells.** Cantrips attributable by class:
  Sorcerer: Chill Touch, Ray of Frost, Shocking Grasp, True Strike.
  Bard: Thunderclap, Vicious Mockery. Cleric: Light, Spare the Dying,
  Thaumaturgy. Druid: Shape Water, Shillelagh. Wizard: Mage Hand, Minor Illusion,
  Mold Earth.
  Leveled: Bane, Chromatic Orb, Comprehend Languages, Create or Destroy Water,
  Cure Wounds, Dissonant Whispers, Feather Fall, Find Familiar, Goodberry,
  Healing Word, Jump, Ray of Sickness, Sanctuary, Shield, Sleep, Speak with
  Animals, Tenser's Floating Disk, Thunderous Smite, Thunderwave, Unseen Servant,
  Wrathful Smite.

Two facts to carry, both honest limitations rather than guesses to bury:

1. **The PDF contains no ability scores** — it is the Class tab only. They were
   INFERRED from to-hit values: `+6` implies PB 3 + mod 3 (CHA 17); `+4` implies
   mod +1 (a 13). Consistent with the spec's CHA 17 / INT 13 / WIS 13, but
   inferred. Mark them as such in the seeder so nobody later treats them as read.
2. **`Mold Earth` is confirmed legacy content**, not a scraper assumption. The
   cached 2024 index has 427 entries, `mold-earth` is absent, and its nearest match
   is `move-earth`. It is Xanathar's-era content that was not reprinted for 2024,
   so Mutt legitimately requires `allow_legacy`; that is the feature working, not
   a workaround.

- [x] **T9 Seed "Mutt" as a second character** through the REAL `add_source` and
      `set_slot` commands, not direct inserts, so the DSL generates slots and every
      selection passes eligibility. This also gives a FULLY POPULATED demo: the
      existing seed leaves 29 of 40 slots blank, which undersells duplicate
      detection across five classes.
- [x] **T10 A browser scenario over Mutt** asserting real multi-source duplicates
      and per-class max preparable level across all six classes.

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

### Iteration 6 — UNIT E2E-6 API-surface abuse A1-A5

Added `tests/Feature/Api/CharacterWriteSurfaceAbuseTest.php`: 13 Pest feature
tests (one mutation-envelope test, seven A1 command datasets, A2, A3, A4, and
two A5 cases) with 506 assertions. Every rejected request captures and compares
every row in all 39 SQLite application tables before and after the request, not
only the expected target row. The complete Pest suite increased from 163 to 176
tests.

Production holes found and fixed, separately:

- **High — scalar coercion and missing-field semantics.** The factory and command
  implementations converted untrusted strings, nulls and arrays before domain
  validation; notably, a numeric-string score/id could be accepted, and omitted
  `update_class.level` meant removal. `CharacterCommandPayloadValidator` now
  enforces exact JSON scalar types, required keys, bounds, enum values, length
  limits and command-specific key allowlists before a command is constructed.
- **High — forgeable destructive inverse commands.** `set_slot:restore`,
  `restore_snapshot`, and acknowledgement deletion were ordinary client payloads;
  a caller could forge lifecycle/eligibility state or post another character's
  snapshot. Server-issued destructive inverses now carry an HMAC over the full
  canonical command and target character id. Slot, structural, acknowledgement
  and save-point inverses are all signed, and the factory verifies the signature
  before apply.
- **High — snapshot validation occurred after writes and ignored catalog
  tombstones.** `CharacterState::restore()` updated the character and deleted
  current rows before validating every snapshot table, relying on transaction
  rollback, and it accepted references to inactive spell versions. It now
  preflights schema shape, required character fields, every row's ownership and
  every fixed/current/spellbook spell reference before its first write, rejecting
  missing or inactive versions.
- **Medium — malformed JSON requests did not consistently behave as an API.** A
  numeric-string `expected_revision` passed Laravel's integer rule, while other
  envelope validation failures redirected with session errors because JSON
  rendering was limited to `/api/*`. Revisions now require an actual JSON integer,
  and requests that expect JSON receive JSON validation errors.
- **Medium — acknowledgement deletion could record a no-op mutation.** Deleting a
  nonexistent/cross-character acknowledgement succeeded, incremented revision and
  created an operation. Delete is now a signed internal inverse and also requires
  an existing acknowledgement scoped to the target character.
- **Low — cross-character save-point lookup returned a blank framework 404.** It
  now returns `{"message":"Save point does not belong to this character."}`
  with 404 and no write.

Sensitivity checks (all temporary production changes were restored, and the
focused 13-test file passed again afterwards):

- A1 mutation envelope: removing the exact-JSON-integer guard made
  `expected_revision: "0"` succeed; expected 422, received 200.
- A1 `update_ability`: bypassing its validator and restoring the old scalar casts
  made string score `"20"` succeed; expected 422, received 200.
- A1 `set_slot`: bypassing payload validation made a numeric-string owned slot id
  with `mode=clear` succeed; expected 422, received 200.
- A1 `update_character_rules`: bypassing payload validation admitted a command
  with the absurd unknown `legacy_limit`; expected 422, received 200.
- A1 `update_source_config`: bypassing payload validation admitted a
  numeric-string source id; expected 422, received 200.
- A1 `acknowledge_warning`: bypassing payload validation admitted the active
  warning with a 2,001-character note; expected 422, received 200.
- A1 `update_class`: bypassing payload validation admitted a numeric-string class
  id; expected 422, received 200.
- A1 `restore_snapshot`: bypassing payload validation admitted a correctly signed
  snapshot carrying the absurd unknown `restore_limit`; expected 422, received
  200.
- A2: removing `SetSlotCommand`'s character ownership predicate let character 1
  clear character 2's slot; expected 422, received 200.
- A3: suppressing the selection eligibility rejection let Guidance enter a
  Wizard cantrip slot as valid; expected 422, received 200.
- A4: suppressing the prior-operation replay branch made the same UUID/different
  command hit revision handling; expected the idempotent 200, received 409.
- A5 cross-character restore: removing the character id from the HMAC let a
  character-2 save-point command restore onto character 1; expected 422, received
  200.
- A5 tombstoned version: suppressing the inactive-version preflight restored the
  snapshot; expected 422, received 200.

Final verification observed:

```text
Dropping all tables ............................................ 4.34ms DONE

 INFO  Preparing database.

Creating migration table ....................................... 3.18ms DONE

 INFO  Running migrations.

0001_01_01_000000_create_users_table ........................... 0.96ms DONE
0001_01_01_000001_create_cache_table ........................... 0.33ms DONE
0001_01_01_000002_create_jobs_table ............................ 0.66ms DONE
2026_07_21_000100_create_catalog_tables ........................ 6.59ms DONE
2026_07_21_000200_create_character_tables ...................... 3.54ms DONE
2026_07_21_000300_add_spell_selection_eligibility .............. 1.92ms DONE
2026_07_21_000400_create_subclass_progressions ................. 0.32ms DONE
2026_07_21_000500_create_character_operations .................. 0.30ms DONE

 INFO  Seeding database.

Database\Seeders\ClassProgressionSeeder ............................ RUNNING
Database\Seeders\ClassProgressionSeeder ......................... 35 ms DONE
Database\Seeders\ContentDefinitionSeeder ........................... RUNNING
Database\Seeders\ContentDefinitionSeeder ......................... 1 ms DONE
Database\Seeders\SeedCharacterSeeder ............................... RUNNING
Database\Seeders\SeedCharacterSeeder ............................ 21 ms DONE

Tests:    176 passed (1925 assertions)
Duration: 23.41s

> typecheck
> vue-tsc --noEmit

> build
> vite build
vite v8.1.5 building client environment for production...
[plugin laravel:fonts] Optimized font fallbacks require the optional "fontaine" package. Install it, or set "optimizedFallbacks: false" on your fonts to disable the feature.
✓ 567 modules transformed.
✓ built in 350ms

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
  15 passed (45.5s)
```

Direct golden-value output after a final fresh seed:

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

Review deviation: the required independent Claude plan review ended in
`Execution error` and left an untracked dump-only probe file despite a read-only
prompt. Its probes independently targeted the same coercion, forged restore,
cross-character and no-op acknowledgement holes; the scratch file was removed.
The post-implementation review was retried with plan-mode permissions and a
240-second bound; it produced no findings and exited 124 with `Execution error`,
without changing the worktree. Self-review verified every internal restore issuer,
signature path and rollback/undo compatibility. No verification-contract
deviation occurred. No commit or push was made.

### Iteration 7 — UNIT E2E-7 independent review of 08b1685

The independent review found and fixed one significant sibling of the snapshot
tombstone issue:

- **High — a signed slot inverse could restore a tombstoned spell version.**
  `SetSlotCommand::restoreUpdates()` trusted its HMAC-signed historical state and
  wrote `current_spell_version_id` without checking whether the catalog version
  remained active. This is reachable without forgery: clear a selected spell,
  allow a later catalog import to tombstone the now-unreferenced version, then
  undo using the legitimate inverse issued before the import. The focused test
  failed before the fix with `Expected response status code [422] but received
  200`. The command now rejects the inactive version before its first write, and
  the test compares all 39 application tables to prove rollback-free rejection.

No other significant payload or restore gap remained. The validator runs before
command construction, requires exact JSON scalar types, makes class removal
explicit with `level: null`, and matches every payload shape emitted by the Vue
workspace. Mode-specific irrelevant fields remain harmless because the explicit
mode determines semantics; all fields that can affect a mode are required and
type-checked. Existing feature tests and all 15 browser scenarios cover the legal
UI flows, including class removal, source-list changes, acknowledgement, save-point
restore, and slot select/clear/override.

HMAC recommendation: **KEEP**. The implementation recursively sorts object keys
while preserving list order, uses HMAC-SHA-256 (so length extension does not
apply), binds the target character id, and compares with `hash_equals()`. A
missing APP_KEY throws explicitly and mutation transactions fail closed. Laravel
already requires and manages APP_KEY, so this does not introduce a second secret;
save-point commands are signed on demand and browser undo stacks are session-only.
The layer therefore provides useful provenance for raw lifecycle/snapshot restore
commands at modest cost, even though it is not authentication. Previously issued
signed inverses are replayable with a fresh operation UUID and current revision;
that is a documented capability/replay limitation, not a freshness guarantee.
It cannot defend against an owner who can edit both SQLite and APP_KEY, and is not
claimed to do so.

All internal inverse issuers and consumers were traced. Unsigned or incorrectly
signed slot restore, snapshot restore, and acknowledgement deletion fail closed;
character binding prevents cross-character use. Ordinary ability/rules inverses
remain valid public commands, while slot, class, source, acknowledgement, and
snapshot undo paths return payloads accepted by the stricter validator. Existing
round-trip feature tests plus S3 and S7 remained green after the sibling fix.

Falsifiability review: each committed A1-A5 test has a load-bearing response,
exact-message, revision/audit, or complete-database assertion that changes when
its intended guard is removed. None can remain green merely because a later
guard returns the same generic status. The new slot-inverse test was sensitivity-
checked directly: it received 200 before the production check and 422 afterward.

What changed: `SetSlotCommand` gained the pre-write active-version check, and
`CharacterWriteSurfaceAbuseTest` gained the falsifiable regression. No commit or
push was made.

Verification output observed verbatim:

```text
Dropping all tables ............................................ 4.22ms DONE

 INFO  Preparing database.

Creating migration table ....................................... 2.99ms DONE

 INFO  Running migrations.

0001_01_01_000000_create_users_table ........................... 1.03ms DONE
0001_01_01_000001_create_cache_table ........................... 0.39ms DONE
0001_01_01_000002_create_jobs_table ............................ 0.73ms DONE
2026_07_21_000100_create_catalog_tables ........................ 7.26ms DONE
2026_07_21_000200_create_character_tables ...................... 3.91ms DONE
2026_07_21_000300_add_spell_selection_eligibility .............. 2.07ms DONE
2026_07_21_000400_create_subclass_progressions ................. 0.43ms DONE
2026_07_21_000500_create_character_operations .................. 0.35ms DONE

 INFO  Seeding database.

Database\Seeders\ClassProgressionSeeder ............................ RUNNING
Database\Seeders\ClassProgressionSeeder ......................... 36 ms DONE
Database\Seeders\ContentDefinitionSeeder ........................... RUNNING
Database\Seeders\ContentDefinitionSeeder ......................... 1 ms DONE
Database\Seeders\SeedCharacterSeeder ............................... RUNNING
Database\Seeders\SeedCharacterSeeder ............................ 22 ms DONE

Tests:    177 passed (1936 assertions)
Duration: 24.05s

> typecheck
> vue-tsc --noEmit

> build
> vite build
vite v8.1.5 building client environment for production...
[plugin laravel:fonts] Optimized font fallbacks require the optional "fontaine" package. Install it, or set "optimizedFallbacks: false" on your fonts to disable the feature.
✓ 567 modules transformed.
✓ built in 525ms

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
  15 passed (46.1s)
```

Final direct golden-value output after another fresh seed:

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

Review deviation: the required independent Claude review was run against both
08b1685 and the uncommitted fix with a 240-second bound. It produced no review
output and exited 124 with `Execution error`; no files were changed. This silence
was not treated as approval. The finding above was independently reproduced and
the remaining paths were reviewed locally. No verification-contract deviation
occurred.

### Iteration 11 — UNIT E2E-10 mutation testing (M1–M3) complete

Installed `infection/infection` 0.34.0 as a dev dependency and configured
`infection.json5` for all of `app/Domain` with no source exclusions, four threads,
and a 60-second per-mutant timeout. Infection's PHPUnit adapter is pointed at
`bin/pest-infection`, a Pest 4 compatibility launcher that reports PHPUnit 12's
version during probing, delegates every real run to Pest, normalizes Pest's
generated `P\Tests\...` JUnit class names, and emits the success marker Infection
expects only after a zero exit code.

Mutation results:

```text
M1 pure rules before: 56/95 killed, 39 escaped, MSI 58.95%
M1 ClassProgressionLookup before: 3/3 killed, MSI 100%
M1 combined before: 59/98 killed, MSI 60.20%
M1 combined after: 123/128 killed, 5 equivalent escapes, MSI 96.09%

M2 focused before: 723/854 killed, 131 escaped, MSI 84.66%
M2 focused after: 860/885 killed, 25 equivalent escapes, MSI 97.18%

GrantRuleSlotGenerator: 321/321 killed, MSI 100%
SpellAccessBuilder: 157/157 killed, MSI 100%
SpellSelectionEligibility: 88/88 killed, MSI 100%
SpellSelectionService: 8/8 killed, MSI 100%
```

The initial unscoped M2 run generated 862 mutants but skipped 715 because their
covering-test sets exceeded the original 15-second timeout. Its displayed 100%
MSI was invalid and was discarded. M2 was explicitly split per class with the
directly relevant Pest files and a 60-second timeout. Every file in `Spells/` and
`Grants/` was run; nothing was silently omitted.

The original runs produced 170 escapes: 145 real gaps and 25 equivalent mutants.
All 145 real gaps are now killed. Broader after-coverage generated five additional
equivalent mutants, leaving 30 justified equivalent survivors across the final
runs. The complete one-row-per-mutant ledger, stable Infection IDs, verdicts,
rationales, scores, scoping, and new-test mapping are in
`docs/E2E-10-MUTATION-REPORT.md`.

New falsifiable coverage:

- `MulticlassSlotsTest`: level zero, every progression type, epic caps, all Pact
  Magic rows and aggregation, and all maximum-preparable breakpoints; killed 36
  original M1 escapes.
- `DuplicateWarningDetectorTest`: complete sorted assessment DTO, versions,
  labels, explanations, hard-coded fingerprint, source/slot lists, and list
  compaction; killed 32 original M2 escapes.
- `GrantRuleTest`: defaults for all six rule kinds, 33 malformed shapes,
  independent query/source alternatives, trimming, free-cast diagnostics, and
  JSON behavior; killed 77 original M2 escapes.

Verification output observed:

```text
Disabled xdebug

Dropping all tables ............................................ 4.20ms DONE
Creating migration table ....................................... 3.88ms DONE
0001_01_01_000000_create_users_table ........................... 0.94ms DONE
0001_01_01_000001_create_cache_table ........................... 0.38ms DONE
0001_01_01_000002_create_jobs_table ............................ 0.65ms DONE
2026_07_21_000100_create_catalog_tables ........................ 6.69ms DONE
2026_07_21_000200_create_character_tables ...................... 3.30ms DONE
2026_07_21_000300_add_spell_selection_eligibility .............. 1.93ms DONE
2026_07_21_000400_create_subclass_progressions ................. 0.27ms DONE
2026_07_21_000500_create_character_operations .................. 0.29ms DONE
Database\Seeders\ClassProgressionSeeder ......................... 36 ms DONE
Database\Seeders\ContentDefinitionSeeder ......................... 1 ms DONE
Database\Seeders\SeedCharacterSeeder ............................ 21 ms DONE

Tests:    268 passed (2266 assertions)
Duration: 24.11s

> typecheck
> vue-tsc --noEmit

> build
> vite build
vite v8.1.5 building client environment for production...
[plugin laravel:fonts] Optimized font fallbacks require the optional "fontaine" package. Install it, or set "optimizedFallbacks: false" on your fonts to disable the feature.
✓ 567 modules transformed.
✓ built in 746ms

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
15 passed (54.5s)
```

Final direct golden-value output after another fresh seed:

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
```

Review deviation: the required independent Claude review was attempted twice.
Both runs produced no output; the bounded retry exited 124 with `Execution error`.
This silence was not treated as approval. Self-review, Composer validation, PHP
syntax checks, targeted Pint, and `git diff --check` passed. Repository-wide Pint
still reports four pre-existing style findings, including the longstanding
file-level docblock placement in `MulticlassSlotsTest`; no formatting-only churn
was introduced. No commit or push was made.

### Iteration 12 — UNIT E2E-11 mutation sweep (M4–M6) and properties (P1) complete

Mutation results, using accepted runs with zero skipped, timed-out, errored, or
uncovered mutants:

```text
M4 Characters before: 786/786 killed, MSI 100%
M4 Characters after:  786/786 killed, MSI 100%

M5 Catalog before: 265/265 killed, MSI 100%
M5 Catalog after:  265/265 killed, MSI 100%

M6 Reports before: 144/144 killed, MSI 100%
M6 Reports after:  144/144 killed, MSI 100%
```

All three escaped-mutant ledgers are empty: there were no real gaps to kill and
no equivalent mutants to justify. Characters completed as one whole-directory
run. Catalog's broad run skipped 224/265 and was discarded; the accepted
`CatalogImporter.php` plus `CatalogImportTest.php` focus generated the same 265
mutants and killed all of them. Reports' broad run skipped 131/144 and was
discarded. Its accepted five-test-file split produced a 144-mutant union by
mutator plus exact diff, matching the broad denominator, and every union member
was killed. Full scope, discarded-run evidence, and ledgers are in
`docs/E2E-11-MUTATION-PROPERTY-REPORT.md`.

Added `RulesPropertyTest`: six deterministic Mt19937 properties, 1,000 generated
legal base-class builds each, seeds 929298–929303, 9,795 assertions. Every
property was sensitivity-checked by temporarily breaking its behavior; all six
failed with the seed, iteration, and generated build printed, then passed after
the production files were restored.

One property **did fail during development**: seed 929302 iteration 27 generated
a level-19 Rogue labeled `third_down`. It revealed that `SpellSlots::slots()` is
the shared multiclass table and is not a single-class subclass-slot oracle: it
ends at 3rd level while third-caster preparation reaches 4th. Shipped report
behavior is correct because Eldritch Knight and Arcane Trickster are explicit
subclass state backed by their own `subclass_progressions`; existing feature
vectors confirm both level-19 subclasses possess a 4th-level slot. The generator
now models the requested twelve base-class mix without inventing subclass state.
This finding and the original failure JSON are preserved in the report.

Clean verification observed:

```text
Tests:    274 passed (12061 assertions)
Duration: 23.65s

> typecheck
> vue-tsc --noEmit

> build
> vite build
✓ 567 modules transformed.
✓ built in 362ms

> test:e2e
> playwright test
Running 15 tests using 1 worker
15 passed (48.3s)
```

The final fresh-seed golden extraction remained caster level 6, slots 4/3/3,
proficiency +3, every class maximum 1, Mage Hand wasteful, Entangle none, and
Detect Magic capability/ritual-only/non-selection/non-counting. Verbatim
verification and JSON output are in the E2E-11 report.

Review deviation: the required Claude plan and implementation critiques were
both attempted with bounded waits. Each produced no content and ended with
`Execution error`; silence was not treated as approval. Local legality,
non-vacuity, stable-union completeness, restoration, formatting, and six
sensitivity checks passed. No commit or push was made.

### Iteration 13 — UNIT E2E-12 first-class add/remove source complete

Added validated `add_source` and `remove_source` commands with the established
transaction/revision/idempotency/audit contract and signed snapshot inverses.
Adds delegate direct and nested materialization to the grant-rule DSL; removals
tombstone the complete source tree and orphan its selected slots. The accessible
workspace UI now adds and removes feats, species, and backgrounds with labelled,
focus-visible controls and destructive confirmation.

S4 now uses the real removal command and signed undo, retiring its fixture
deviation. S16 pins Magic Initiate's two cantrips as no-free-cast/no-spell-slots
and its level-1 choice as the sole free-cast/with-slots grant. S17 confirms
removal in the browser, verifies preserved orphan selections, then deep-compares
identical source and slot rows after undo. Each scenario failed under a deliberate
production break and passed after restoration.

Clean verification observed:

```text
Tests:    358 passed (12447 assertions)
Duration: 28.14s

> typecheck
> vue-tsc --noEmit

> build
> vite build
✓ 567 modules transformed.
✓ built in 483ms

> test:e2e
> playwright test
Running 17 tests using 1 worker
17 passed (51.3s)
```

The seven golden values remain unchanged. The new/changed command mutation union
is 277/277 killed (100%, with no skips or uncovered mutants). The historical
Characters 786/786 baseline was proven invalid: its launcher resolved the wrong
project under Infection's temporary directory and translated Infection's Pest
filters to descriptions that matched no tests. After fixing both defects, the
existing workspace builder alone produced 144 mutants and killed 38, disproving
the old whole-directory claim. A broad run skipped 626/914 and was discarded, so
no false whole-directory after score is reported. Full outputs, sensitivity
failures, and deviations are in `docs/E2E-12-ADD-REMOVE-SOURCE-REPORT.md`. No
commit or push was made.

### Iteration 14 — UNIT E2E-13 corrected per-file mutation remeasurement complete

Remeasured all 21 PHP files in Reports, Catalog, and Characters independently
through the corrected Pest launcher. Every accepted run had zero skipped,
uncovered, timed-out, errored, ignored, or syntax-error mutants; the
`CharacterCommand` interface is explicitly recorded as a valid zero-mutant file.
No file is unmeasured.

The corrected baseline was 838/1,323 killed (63.34%) with 485 escapes. The final
result is 1,133/1,357 killed (83.49%), with 261 baseline real gaps killed and 224
surviving equivalents justified individually by stable ID. The higher final
denominator is from newly reached executable lines, not from treating unmeasured
mutants as killed. Per-file scores and skip counts are authoritative and are
recorded in `docs/E2E-13-MUTATION-REMEASUREMENT.md`; the complete 485-row verdict
ledger is `docs/E2E-13-ESCAPED-MUTANT-LEDGER.md`.

New behavioral tests pin complete report/workspace/list contracts, import
validation and synchronization, alias and edition resolution, capped eligibility
prefilters, snapshot replacement, command integrity, slot timestamps/orphan
explanations, and repeated class/subclass/source transitions. No existing test
was weakened or removed.

Clean verification observed:

```text
Tests:    434 passed (12822 assertions)
Duration: 48.64s

> typecheck
> vue-tsc --noEmit

> build
> vite build
✓ 567 modules transformed.
✓ built in 852ms

> test:e2e
> playwright test
Running 17 tests using 1 worker
17 passed (56.9s)
```

The fresh-seed seven golden values remain caster level 6, slots 4/3/3,
proficiency +3, every class maximum 1, Mage Hand wasteful, Entangle none, and
Detect Magic capability/ritual-only/non-selection/non-counting. Both required
Claude reviews were attempted with bounded waits; each returned no content and
ended in `Execution error`, which was recorded as a deviation rather than
approval. No commit or push was made.

### Iteration 15 — UNIT E2E-14 T9/T10 Mutt real-sheet seed complete

Added Mutt as character 2. The only direct write is the root character row,
because no create-character command exists. Legacy enablement runs through
`update_character_rules`; all six class-level/source pairs run through the real
`add_source` command; the grant DSL creates all 34 class slots; and all 34
selections run through `set_slot`. The persisted operation ledger is exactly 41
operations: one rules update, six source additions, and 34 slot selections. A
temporary direct-selection bypass reduced the revision to 40 and T10 failed
waiting for `revision 41`, proving the command-path assertions are sensitive.

`add_source` now supports a validated `class` branch: level 1–20, total level at
most 20, non-repeatable class identity, derived starting class/acquisition level
and spellcasting ability, and Wizard-only spellbook acquisition config. The
executor transaction includes DSL generation. A malformed `[[]]` acquisition
was proven to return the exact DSL error, preserve byte-identical character
state, leave the revision unchanged, and create no Wizard source or class row.

The sheet facts are explicit in the seed notes: max HP 43 and milestone are sheet
data; CHA 17 / INT 13 / WIS 13 are marked **INFERRED** because the PDF contains no
ability scores; STR/DEX/CON 10 are marked planner defaults, not sheet data. Every
leveled-spell attribution is likewise marked inferred. The exact attribution is:

- Sorcerer: Chromatic Orb, Shield.
- Bard: Bane, Cure Wounds, Dissonant Whispers, Unseen Servant.
- Cleric: Bane, Create or Destroy Water, Healing Word, Sanctuary.
- Druid: Goodberry, Healing Word, Jump, Speak with Animals.
- Paladin: Thunderous Smite, Wrathful Smite.
- Wizard spellbook: Comprehend Languages, Feather Fall, Find Familiar, Shield,
  Tenser's Floating Disk, Thunderwave. The prepared four are Feather Fall, Find
  Familiar, Shield, and Tenser's Floating Disk; Comprehend Languages and
  Thunderwave remain unprepared book entries.

The task text says 33 spells, but its enumerated lists contain **35 distinct
names**: 14 cantrips plus 21 leveled spells. The honest resulting counts are:

- 34/34 slot rows are filled and valid, representing 31 distinct spell names;
  the three extra selections are the intended Bane, Healing Word, and Shield
  duplicates.
- Comprehend Languages and Thunderwave are additional Wizard spellbook-only
  names, so 33 of the 35 supplied names are represented somewhere in Mutt's
  character state.
- Ray of Sickness could not fit after both eligible Sorcerer/Wizard preparation
  capacities were consumed. Sleep could not fit after all eligible
  Bard/Sorcerer/Wizard capacities were consumed. Neither was forced into an
  ineligible source. There is no empty choice row: the real duplicate selections
  legitimately consume the remaining capacity.

`Mold Earth` remains the confirmed 2014 version with legacy enabled. The imported
Xanathar record now carries the user-confirmed Wizard membership so production
eligibility accepts it. Local catalog inspection also found Shape Water only as
2014 with no membership; its user-confirmed Druid attribution was added to the
same catalog ingress. T10 pins both exact 2014 version IDs, source lists, and
valid selection states.

Mutt's final report is caster level 6 with shared slots 4/3/3 and no Pact Magic.
All six classes simultaneously report class level 1 and maximum preparable level
1. The exact wasteful findings are Bane (Bard + Cleric), Healing Word (Cleric +
Druid), and Shield (Sorcerer + Wizard), spanning five classes. T10 asserts the
raw database selections independently of the report and also checks the visible
warning copy.

Sensitivity checks (all production changes restored and T10 passed after each):

- Changing wasteful classification from two counting routes to three made Bane
  report `redundant_intentional` instead of `wasteful`, with the explanation
  changing from `Bane consumes limits in more than one selection.` to `Bane has
  overlapping access, but fewer than two routes consume limits.` T10 failed at
  its exact assessment assertion.
- Raising the full-caster preparation formula by one made Bard, Cleric, Druid,
  Sorcerer, and Wizard report maximum 2 while Paladin remained 1. T10 failed with
  the complete six-row expected/received diff.
- Bypassing `set_slot` for Chill Touch made Mutt revision 40. T10 failed at the
  visible `revision 41` assertion; restoration returned the 41-operation ledger.

The first full Pest pass exposed two old single-character assumptions. The exact
character-list contract omitted Mutt, and one Wizard rollback query did not scope
prepared entries by character, receiving 8 instead of 4. The list contract now
pins both cards and their warning counts; the rollback query is character-scoped.
Both focused tests and the subsequent full suite passed.

Final verification output observed:

```text
Dropping all tables ............................................ 4.02ms DONE
INFO  Preparing database.
Creating migration table ....................................... 2.77ms DONE
INFO  Running migrations.
0001_01_01_000000_create_users_table ........................... 0.88ms DONE
0001_01_01_000001_create_cache_table ........................... 0.33ms DONE
0001_01_01_000002_create_jobs_table ............................ 0.66ms DONE
2026_07_21_000100_create_catalog_tables ........................ 6.59ms DONE
2026_07_21_000200_create_character_tables ...................... 3.40ms DONE
2026_07_21_000300_add_spell_selection_eligibility .............. 1.90ms DONE
2026_07_21_000400_create_subclass_progressions ................. 0.27ms DONE
2026_07_21_000500_create_character_operations .................. 0.37ms DONE
INFO  Seeding database.
Database\Seeders\ClassProgressionSeeder ......................... 45 ms DONE
Database\Seeders\ContentDefinitionSeeder ........................ 1 ms DONE
Database\Seeders\SeedCharacterSeeder ........................... 334 ms DONE

Tests:    440 passed (12885 assertions)
Duration: 70.25s

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
✓ built in 653ms

> test:e2e
> playwright test
Running 18 tests using 1 worker
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
  ✓ T10
18 passed (59.5s)
```

The final direct A6 extraction remains caster level 6, slots 4/3/3,
proficiency +3, all six class maxima 1, Mage Hand wasteful, Entangle none, and
Detect Magic capability/ritual-only/non-selection/non-counting. T10 reasserts
those values from a database-level report after Mutt is present.

Independent review required multiple long bounded attempts. The initial plan
attempts timed out without content and were not treated as approval. The
implementation review raised malformed acquisition atomicity as a proof gap;
the rollback test above was added. BuildReport existence and sensitivity
documentation findings were rejected with local execution evidence. On
resubmission, the reviewer verified the nested/outer transaction rollback and
reported no remaining medium/high defect. Targeted Pint, all changed PHP syntax
checks, JSON parsing, and `git diff --check` passed. No commit or push was made.

### Iteration 16 — UNIT E2E-16 authoritative Mutt attribution and corpus parser complete

Mutt now follows the confirmed sheet attribution exactly; eligibility is not
used to invent provenance. The 35 selected slots contain 35 distinct spell
identities, and the duplicate assessment list is empty:

- Sorcerer cantrips: Chill Touch, Ray of Frost, Shocking Grasp, True Strike.
  Level 1: Chromatic Orb, Ray of Sickness.
- Bard cantrips: Thunderclap, Vicious Mockery. Level 1: Bane, Dissonant
  Whispers, Sleep, Thunderwave.
- Cleric cantrips: Light, Spare the Dying, Thaumaturgy, Guidance. Guidance is
  the separate Divine Order slot. Level 1: Create or Destroy Water, Cure
  Wounds, Healing Word, Sanctuary.
- Druid cantrips: Shape Water, Shillelagh. Level 1: Absorb Elements, Goodberry,
  Jump, Speak with Animals.
- Paladin level 1: Thunderous Smite, Wrathful Smite.
- Wizard cantrips: Mage Hand, Minor Illusion, Mold Earth. Spellbook:
  Comprehend Languages, Feather Fall, Find Familiar, Shield, Tenser's Floating
  Disk, Unseen Servant. The prepared four are Feather Fall, Find Familiar,
  Shield, and Unseen Servant; Comprehend Languages and Tenser's Floating Disk
  are unprepared book entries.
- Duplicate list: `[]`. Bane is Bard-only, Healing Word is Cleric-only, and
  Shield is Wizard-only.

The Mutt operation ledger is now exactly 42 commands: one character-rules
update, six class-source additions, and 35 slot selections. The Wizard book has
six entries and four preparations. Including the two unprepared book-only
identities, Mutt has 37 distinct selected-or-book spell identities.

Divine Order and Primal Order are now independent level-1 class-progression
`choice_from_list` grant rules. Their choices are validated and persisted in
class source config. `Thaumaturge` activates one Cleric cantrip selected from the
configured Cleric list; `Magician` does the corresponding thing for Druid.
`Protector` and `Warden` activate no bonus cantrip. Mutt records Divine Order:
Thaumaturge with Cleric as the chosen list, and Primal Order: Warden. The base
level-1 Cleric progression remains 3 cantrips and 4 prepared spells before the
Order bonus; the bonus makes Mutt's Cleric total four cantrips. The base Druid
progression remains 2 cantrips and 4 prepared spells.

`parseSpellLists()` now accepts colon and period labels without depending on
capitalisation, recognizes flattened legacy and modern DOM boundaries, and
uses an explicit class-name grammar. Qualifiers are preserved as exact catalog
keys such as `Sorcerer (Optional)`, `Wizard (Dunamancy)`, and `Wizard
(Graviturgy)`. They are deliberately not promoted to the unqualified base-class
key, so retaining source data cannot make an optional or subclass-qualified
spell selectable by the ordinary class rule. `None` is rejected as a sentinel.

The corpus test loads all 993 cached detail pages (574 legacy and 419 modern),
not fixtures. For the 524 published non-UA legacy pages, the old parser emitted
1,257 memberships, including the bogus `None`; the new parser emits 1,401 valid
memberships. That is 145 recovered stated memberships and one removed sentinel,
a net increase of 144. All 28 published pages that were previously empty now
resolve; the only raw parser result empty across the entire cache is Encode
Thoughts, whose page says `None`. The audited defect groups are 17 ordinary
memberships recovered across 8 boundary/punctuation/capitalisation pages, 122
preserved Optional memberships across 75 pages, and 6 preserved Wizard
subclass-qualified memberships. Fast Friends resolves to Bard, Cleric, Wizard;
Green-Flame Blade preserves Artificer plus the three Optional labels; and the
five Dunamancy spells plus Immovable Object's Graviturgy label are exact.

The false-positive guard has three layers: every emitted base name is from the
explicit class allowlist; qualifiers remain non-base keys; and every one of the
419 modern detail-page parses independently equals its cached index membership
list. Catalog import pins 122 Optional and 6 qualified memberships, rejects
`None`, and proves Green-Flame Blade does not acquire ordinary Sorcerer
eligibility.

Sensitivity checks were run with production changes restored afterward:

- Replacing the parser body with `return []` made the corpus audit fail first on
  modern Acid Splash, expected Artificer/Sorcerer/Wizard but received an empty
  list.
- Reassigning Shield to Sorcerer in place of Ray of Sickness made the Mutt
  report test fail because the raw attribution contained Sorcerer + Wizard
  Shield routes. No duplicate suppression was introduced.
- Changing the Divine Order activation predicate from Thaumaturge to Protector
  made fresh seeding fail with `Unable to seed Guidance into
  cleric-divine-order-cantrip:1.`

All mutations were reverted before the clean verification. A6's seven golden
contracts remain unchanged: caster level 6; slots 4/3/3; proficiency +3; every
class maximum preparable level 1; Mage Hand wasteful; Entangle none; and Detect
Magic capability `ritual_only`, not a selection, and not counting against a
limit.

Final verification output observed (terminal colour/control codes removed;
text otherwise verbatim):

```text
Dropping all tables ............................................ 4.07ms DONE

INFO  Preparing database.

Creating migration table ....................................... 3.06ms DONE

INFO  Running migrations.

0001_01_01_000000_create_users_table ........................... 0.91ms DONE
0001_01_01_000001_create_cache_table ........................... 0.35ms DONE
0001_01_01_000002_create_jobs_table ............................ 0.71ms DONE
2026_07_21_000100_create_catalog_tables ........................ 6.81ms DONE
2026_07_21_000200_create_character_tables ...................... 3.35ms DONE
2026_07_21_000300_add_spell_selection_eligibility .............. 1.94ms DONE
2026_07_21_000400_create_subclass_progressions ................. 0.29ms DONE
2026_07_21_000500_create_character_operations .................. 0.29ms DONE

INFO  Seeding database.

Database\Seeders\ClassProgressionSeeder ......................... 37 ms DONE
Database\Seeders\ContentDefinitionSeeder ........................ 1 ms DONE
Database\Seeders\SeedCharacterSeeder ........................... 336 ms DONE

Tests:    452 passed (12986 assertions)
Duration: 82.53s

> test:scripts
> node --test 'scripts/*.test.mjs'

▶ parseSpellLists audits every cached detail page
  ✔ recovers every stated membership conservatively across all 993 pages (719.352551ms)
✔ parseSpellLists audits every cached detail page (719.47635ms)
ℹ tests 24
ℹ suites 8
ℹ pass 24
ℹ fail 0
ℹ cancelled 0
ℹ skipped 0
ℹ todo 0
ℹ duration_ms 778.959805

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
✓ built in 354ms

> test:e2e
> playwright test

Running 18 tests using 1 worker
  ✓ T10: Mutt matches the authoritative sheet attribution with zero duplicates (3.4s)

18 passed (1.0m)
```

Targeted Pint passed on both changed files it identified. The repository-wide
Pint check still reports four pre-existing style findings in untouched files:
`CasterContribution.php`, `SchemaConstraintsTest.php`, `tests/Pest.php`, and
`MulticlassSlotsTest.php`. `git diff --check` passed. The required Claude plan
and implementation reviews were attempted with bounded waits; they returned no
review content and were recorded as a tooling deviation, not approval. The
implementation was instead subjected to the explicit self-review, corpus
oracle, three sensitivity mutations, and complete verification above. No commit
or push was made.

## Iteration 17 — printable spell lists

Added per-character reference and full-reference print variants, opt-in Tier 2
description importing, exact source-specific spellcasting math, separate 2024
Cleric/Druid long-rest swap sections, preserved Wizard print states, accessible
controls, and print-specific layout rules. Mutt has 12 unprepared Cleric spells
and 16 unprepared Druid spells; full mode reports unavailable descriptions
cleanly when Tier 2 has not been imported.

Final verification: fresh migration/seed passed; Pest reported 460 tests and
13,112 assertions passing; typecheck and production build passed; Playwright
reported 19/19 passing (the original 18 plus E2E-17); A6's seven golden values
were unchanged and Mutt retained zero duplicate warnings. Eleven temporary
production mutations each caused its focused assertion to fail and were then
restored. Full command output and sensitivity observations are recorded in
`docs/E2E-17-PRINTABLE-SPELL-LISTS-REPORT.md`. No commit or push was made.

---

# State as of 2026-07-22 — public repo, PR workflow, four parallel branches

Repository: https://github.com/derrickschoen/dnd-2024-multiclass-spell-planner

## Suite

| | |
|---|---|
| Pest | **469** passed |
| Playwright | **23** scenarios |
| Scraper tests | 30 |
| Dice maths tests | 7 |
| PHPStan | level 5 committed, 9 errors, unbaselined |

The seven A6 golden values are re-verified against the live app after every
production change and have never regressed: caster level 6, slots 4/3/3, PB +3,
every class max preparable 1st, Mage Hand wasteful, Entangle none, Detect Magic
origin=capability / ritual_only / not a selection / no limit consumed.

## Redistribution

Only the **CC-BY SRD 5.2.1 subset (339 records)** is committed, in `data/srd/`
with the required attribution. The full scraped catalog (~943 records across ~20
books, most not Creative Commons) is gitignored and built with `npm run scrape`.
`CatalogSource` prefers the full catalog when present so a fresh clone still boots.

The seeded character is named **"Mutt (SRD)"** because six of its spells are
same-class, same-level SRD substitutes for non-SRD content. It is not a faithful
copy of the user's sheet and the name says so.

## Landed via PR

- **#1 dice roller** — Sorcerous Burst bounded exploding dice, Chromatic Orb
  bounces, advantage, Halfling Luck, Elemental Adept, Lucky, crits, AC input.
  Legacy feats such as Elven Accuracy are offered but badged, mirroring legacy
  spell handling.
- **#2 corner-case characters** — Thirds Company, Iron Arcana, Pact Apex, Ceiling
  Split. Each chosen so a plausible wrong implementation is VISIBLY different.
  A Wizard 20 was deliberately NOT built: its own table equals multiclass caster
  level 20, so that test could not fail.
- **Divine/Primal Order UI** — closes one instance of the seeded-state
  reachability gap: the suite could display these states but no user could create
  them.

## In flight

`feat/domain-types` — backed enums for the 7 progression types, 5 buckets, 4
duplicate categories, 6 rule kinds and the rest; value objects only where they
earn it; a typed and validated Inertia boundary; PHPStan **level 8** (74 errors
currently) with level-10 reduction as a secondary goal.

The `data_get()` convention has been withdrawn by the user as inappropriate for a
greenfield project, which is what makes the level-10 `mixed` errors addressable.

## Blind spot still open

**Seeded-state reachability.** Wizard spellbook acquisition still has no UI, so no
user can build a Wizard spellbook from a blank character. Divine/Primal Order was
the other half and is now closed.

The proposed next instrument is **stateful command-sequence testing**: generate
legal sequences of class/config/legacy/override/undo operations and check
invariants after every step. One hand-authored messy character cannot explore
order-dependent failures such as "subclass switch -> level down -> keep override
-> undo -> catalog removal".

## Stateful command-sequence testing — design verdict (2026-07-22)

An independent design pass says **build it**, but scoped tightly.

**Shape:** a bounded HTTP-level Pest state machine. Explicitly NOT Eris, and NOT a
reusable property-testing framework — dependencies on server-issued inverses
dominate ordinary value shrinking, so a custom bounded machine fits better.

**Cost:** 3-5 days, ~500-800 lines of fixture, adapters, oracle, trace and
shrinker. CI target 5-8 fixed seeds of 20-30 actions under ~30s; nightly 25-50
seeds of 40 actions.

**Why it beats more reading:** nine command families give 81 ordered pairs and 729
triples before state distinctions are considered. Adversarial reading finds local
inconsistencies; it cannot systematically exercise that interaction space.

**A bug shape nothing existing would catch:** source-list regeneration combined
with a DELAYED slot inverse. Both commands pass their own tests while their
composition restores a spell against changed slot constraints. Mutation testing
cannot invent an ordering.

**Stop condition, worth honouring:** abandon or narrow it if the legality adapter
starts duplicating grant-rule or spell-eligibility logic. Its job is to consume
public choices and server-issued tokens, not to become a second domain engine —
a generator that reimplements the rules ends up testing the test.

Caveat: the design ran read-only without Docker access, so schema claims were
verified against migrations rather than a live database. It stated that rather
than presenting them as checked. Its own independent critique attempts failed
twice and it explicitly did not treat silence as approval.

## Fan-out procedure — a ddev project per worktree (VERIFIED end to end)

Worktrees isolate the filesystem but not the runtime. Sharing one ddev instance
throttles a four-way fan-out to roughly one effective writer, because only one
agent can run migrations or the browser suite.

A ddev project per worktree fixes that and is cheap: this project omits the db
container, so each instance is a SINGLE web container, and ddev runs projects
side by side happily.

**I ran this procedure on a throwaway worktree rather than assuming it, and it
exposed three gaps in my first draft.**

```bash
git worktree add .worktrees/<name> feat/<name>
cd .worktrees/<name>
cp ../../.env .env
sed -i 's|^DB_DATABASE=.*|DB_DATABASE=/var/www/html/database/<name>.sqlite|' .env

ddev config --project-name=dnd-wt-<name> --project-type=laravel --docroot=public \
  --php-version=8.4 --omit-containers=db --nodejs-version=24
ddev start

# GAP 1: symlinking vendor/node_modules to the main checkout DOES NOT WORK here.
# The symlink targets an absolute HOST path, and each ddev project mounts its own
# worktree at /var/www/html, so vendor/autoload.php is unreachable and artisan
# dies immediately. Install real dependencies:
ddev composer install
ddev exec npm install

# GAP 2: data/index is gitignored, so a fresh worktree has NO scraped catalog and
# silently runs SRD-only. Several tests then fail. Copy it (or run npm run scrape):
cp -r ../../data/index data/

# GAP 3: public/build does not exist in a fresh worktree, so Inertia tests fail on
# a missing Vite manifest:
ddev exec npm run build

ddev exec php artisan migrate:fresh --seed --force
ddev exec vendor/bin/pest
E2E_BASE_URL=https://dnd-wt-<name>.ddev.site ddev exec npm run test:e2e
```

Tear down: `ddev delete -Oy dnd-wt-<name>` then
`git worktree remove .worktrees/<name> --force`.

The symlink shortcut used earlier in this session only worked because those
worktrees shared the MAIN project's container. It breaks the moment each worktree
gets its own, which is exactly the kind of assumption worth paying one throwaway
run to falsify.

`playwright.config.ts` reads `E2E_BASE_URL`, defaulting to the main site, so each
worktree targets its own instance and browser suites run in parallel too.
