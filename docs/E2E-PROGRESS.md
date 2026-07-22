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
- [ ] **S4 Orphan recovery** — remove the Magic Initiate source, confirm its three
      slots become orphaned rather than deleted with their selections intact, then
      restore and confirm the identical rows return.
- [x] **S5 Ability propagation** — INT 13→20 changes every INT route from DC 12 /
      attack +4 to DC 16 / attack +8, and nothing on WIS or CHA routes moves.
- [ ] **S6 Wizard three states** — a spellbook spell that is prepared, one that is
      unprepared+ritual (ritual-only), one unprepared+non-ritual (not castable);
      assert the on-screen explanatory text for each.
- [ ] **S7 Save point round trip** — save, make several destructive edits, restore,
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
