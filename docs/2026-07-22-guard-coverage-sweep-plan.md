# Guard coverage sweep plan

## Decisions

- Existing character references to catalog content survive catalog removal. The
  version row is tombstoned, the character row remains, cached eligibility becomes
  invalid, access routes disappear, and the report labels retained spellbook
  content unavailable.
- A new fixed grant, spellbook acquisition, direct selection, inverse, or snapshot
  restore may not introduce an already inactive version. Regeneration may retain
  an already-materialized inactive reference so unrelated character edits do not
  destroy or block preserved state.
- Orphaning a populated slot because a class level or subclass rule disappeared
  changes cached eligibility to invalid. Reactivating the same stable slot runs the
  normal eligibility evaluator and can return it to valid.
- Character-owned identifiers must be scoped in application queries before a
  write/read. Catalog definition/version IDs are shared catalog IDs, not owned
  character rows. Spellbook and loadout IDs are currently output-only/dormant and
  therefore have no client input path to guard.

## Implementation

1. Add active-version checks to fixed-spell and spellbook-acquisition materializers,
   with an exception for the exact existing character reference being preserved.
2. Make every access-route query reject inactive versions, including explicit
   overrides and Wizard ritual capability routes.
3. Let imports tombstone referenced versions without changing immutable referenced
   metadata, reactivate them when they return, and refresh every affected slot's
   eligibility inside the import transaction.
4. Mark populated slots invalid when level/subclass/source reconciliation orphans
   them, while retaining IDs, version references, and recovery behavior.
5. Include catalog activity in Wizard spellbook report data and visibly label
   retained inactive entries in both report UIs.
6. Add focused Pest coverage for each gap plus proof tests for legacy-toggle
   revalidation and ownership paths not already isolated by A2.
7. Write `docs/GUARD-MATRIX.md` with every discovered client/domain path, its G1/G2/G3
   verdict, and named Pest proof or an explicit no-input/no-reference rationale.

## Verification and sensitivity

- For each new test, temporarily remove or bypass its production guard, run the
  focused test to observe failure, then restore the production code and rerun.
- Run `migrate:fresh --seed`, full Pest, typecheck, build, and all Playwright tests.
- Recheck the seven seeded golden values through the report data.
- Preserve the pre-existing uncommitted `docs/E2E-PROGRESS.md` change and do not
  commit.

## Locally verified assumptions

- The application and test database connection is SQLite; ddev deliberately omits
  its database service, so `ddev mysql` is unavailable. `artisan db:table` confirms
  the live post-migration schema and composite slot/source ownership FK.
- No route or command accepts Wizard spellbook-entry or loadout IDs today.
- `SetSlotCommand`, `UpdateSourceConfigCommand`, save-point lookup, acknowledgement
  lookup, and eligible-spell lookup already scope character-owned identifiers.
- `UpdateCharacterRulesCommand` already refreshes every character slot after an
  `allow_legacy` change.
