# E2E-11 mutation sweep and property-test plan

## Goal

Complete mutation testing for `Characters`, `Catalog`, and `Reports`; kill every
real escaped mutant without weakening existing coverage; justify each surviving
equivalent mutant; then add reproducible randomized rules-engine invariants and
run the full verification contract.

## Verified starting assumptions

- The ddev project is running on PHP 8.4, and Infection 0.34.0 is already wired
  to Pest 4 through `bin/pest-infection` with a 60-second timeout.
- The existing tree has 268 Pest tests, and focused tests already exist for all
  three mutation areas: `CharacterWorkspaceTest`,
  `CharacterWriteSurfaceAbuseTest`, `GuardCoverageTest`,
  `SubclassProgressionTest`, `CatalogImportTest`, and `BuildReportTest`.
- Infection accepts both source and test paths, plus
  `--only-covering-test-cases`; this supports explicit per-file or per-component
  scoping if a whole-directory run skips mutants or is impractically slow.
- The only starting worktree change is the user's tier-6 addition to
  `docs/E2E-PROGRESS.md`; it must be preserved and that file updated last.
- The rules engine is pure PHP. `CasterContribution` and `SpellSlots` expose all
  six requested properties without database fixtures.

## Implementation sequence

1. Run focused baseline Infection sweeps for `Characters`, `Catalog`, and
   `Reports`, retaining JSON output and recording killed, escaped, error,
   timeout, and uncovered counts. Split explicitly by source file/component if
   broad coverage makes a directory impractically slow or produces skipped
   mutants.
2. Build a one-row-per-escape ledger from the mutation JSON. Inspect every diff
   and classify it as a real gap or an equivalent mutant with an individually
   checkable rationale.
3. Add focused tests for real gaps only. Prefer observable domain/API/database
   contracts over assertions that mirror implementation details. Re-run the
   relevant mutant IDs while iterating, then run complete after-sweeps with the
   same scope used for each baseline.
4. Add a seeded rules property suite that generates legal class-level
   partitions totaling 1-20, includes every progression type, prints seed,
   iteration, and build on failure, and checks all six requested invariants.
   Preserve and prominently report any genuine failing property.
5. Run targeted tests, self-critique assumptions and sensitivity, then obtain a
   Claude review of the uncommitted implementation. Fix legitimate findings and
   repeat once, or up to three total rounds if necessary.
6. Run the verification contract in order: fresh seeded database, full Pest,
   typecheck, build, all 15 E2E tests, and a direct golden-value extraction.
   Capture the command output verbatim.
7. Write `docs/E2E-11-MUTATION-PROPERTY-REPORT.md` with before/after MSIs,
   complete ledgers, scope, properties, findings, new tests, and verification.
   Update `docs/E2E-PROGRESS.md` last without disturbing the user's existing
   backlog edit.

## Risks and controls

- DB-backed mutants may be slow. A run is accepted only when its scope and
  skipped/timeout/error counts are explicit; invalid headline MSIs are discarded.
- New coverage can expose additional mutants, changing the denominator. Both
  numerator and denominator will be reported, as in tier 5.
- Random generation can accidentally omit an invariant's load-bearing cases.
  The generator will be deterministic and broad, while each property will force
  the relevant class kind (Warlock, non-caster, or single caster) when needed.
- The slot-count sequence in the published table is not globally
  non-increasing after omitted zero levels are removed unless missing levels are
  interpreted as zero. The property will compare the possessed slot counts in
  level order exactly as returned by the engine and report a failure rather than
  weakening the requested statement.
