# Add and remove character sources

## Goal

Add first-class `add_source` and `remove_source` mutations for feats, species,
and backgrounds; expose them through an accessible workspace UI; replace the S4
fixture path; and add browser scenarios S16 and S17 without weakening the
existing command, audit, undo, golden-value, or mutation guarantees.

## Proven assumptions

- `CharacterCommandExecutor` already owns the outer transaction, character row
  lock, expected-revision check, operation UUID replay, grouped audit diff, and
  workspace rebuild. New commands only need to implement validated domain state
  changes and inverses.
- `GrantRuleSlotGenerator::generateForSource()` is the sole slot-materialization
  path and recursively follows `grant_source`. Its inactive path tombstones the
  source tree and orphans active slots without clearing selections.
- The actual seeded schema was checked with `php artisan db:table`: source rows
  have stable UUIDs, parent links, JSON config, and active/tombstoned state;
  catalog definitions expose `repeatable` and JSON grant rules.
- The current catalog has Magic Initiate, Human, and Custom Background. Human and
  Custom Background require nested origin-feat config; Magic Initiate requires a
  Cleric/Druid/Wizard list and an independently chosen INT/WIS/CHA casting
  ability.
- `CharacterState` snapshots include source and slot IDs/timestamps, so a signed
  snapshot inverse can restore removal byte-for-byte and can make add/undo/redo
  round-trip without inventing replacement identities.
- The pre-existing `docs/E2E-PROGRESS.md` tier-7 edit is user-owned and will be
  preserved, then extended only with completion/report material.

## Implementation

1. Extend `CharacterCommandPayloadValidator` and `CharacterCommandFactory` for
   `add_source` and `remove_source`. Validate source type, definition ID, config
   object shape, and remove ownership ID before domain execution. Treat only the
   signed inverse commands as internal/destructive integrity paths.
2. Implement `AddSourceCommand`:
   - resolve the definition from the requested feat/species/background table;
   - reject unsupported/malformed config, duplicate non-repeatable active
     definitions, illegal Magic Initiate lists/abilities, and repeated active
     Magic Initiate lists;
   - insert only the root source instance, then call the DSL generator so direct
     and nested slots/sources are materialized from catalog rules;
   - return an HMAC-signed snapshot restore inverse.
3. Implement `RemoveSourceCommand`:
   - scope the active feat/species/background source to the URL character;
   - capture state, tombstone the selected source, and invoke the generator so
     all descendant sources are tombstoned and their slots orphaned with
     selections retained;
   - return an HMAC-signed snapshot restore inverse. Assert removal plus undo
     restores identical source/slot rows, including IDs, keys, selections, and
     timestamps.
4. Expand the workspace DTO with catalog choices and active removable source
   instances. Provide enough configuration metadata for the UI to construct the
   current catalog's direct or nested Magic Initiate config without generating
   slots in frontend code.
5. Add labelled add controls for source type, definition, Magic Initiate list,
   and casting ability, plus labelled remove buttons. Use the existing visible
   focus classes and a native confirmation dialog for removal. Keep status/error
   text explicit so no state is conveyed by color alone.
6. Add focused Pest coverage for payload abuse, ownership, definition/type
   matching, non-repeatable sources, Magic Initiate list/ability/distinct-list
   rules, nested DSL generation, audit grouping/idempotency/revision behavior,
   destructive HMAC enforcement, and exact add/remove inverse round trips.
7. Update browser coverage:
   - S4 removes through the real mutation command and undoes through the returned
     inverse, removing its fixture-trigger deviation;
   - S15 adds through the UI, proving duplicate list refusal and different-list
     acceptance;
   - S16 adds Magic Initiate through the browser and asserts DB-level per-slot
     `with_slots` and `free_cast` values plus chosen list/ability;
   - S17 removes through the UI, confirms orphaning and preserved selections,
     then uses Undo and compares identical source/slot rows.
   Update S11's independently derived control count only if controls are placed
   inside the spell grid (the planned source controls remain outside it).
8. Sensitivity-check S16 and S17 by temporarily mutating one production behavior
   per covered guarantee, recording the observed failing assertion, restoring
   the production code, and rerunning. Also sensitivity-check S4's new real
   command path.
9. Run fresh seed + full Pest, typecheck, build, all 17 E2E scenarios, golden
   extraction, and Infection for `app/Domain/Characters/`. Fix every real escape
   until the accepted run reports 100% MSI with no skipped/uncovered mutants.
   Run PHP syntax/style/diff checks and the required Claude implementation
   critique before reporting. Do not commit.

## Verification evidence to retain

- Verbatim output for migration/seed, Pest, typecheck, build, E2E, golden JSON,
  and the accepted Characters Infection totals.
- S4/S16/S17 sensitivity break, exact failure, restoration, and passing rerun.
- Audit group/action types and operation replay counts for both commands.
- Any review or environment deviation; otherwise explicitly report `none`.
