# Tier 8 mutation remeasurement plan

## Objective

Produce an honest per-file mutation baseline and after-result for every PHP file
under `app/Domain/Characters`, `app/Domain/Catalog`, and `app/Domain/Reports`.
Kill each non-equivalent escape with behavior-focused tests, justify every
equivalent escape individually, and leave skipped or otherwise unreliable files
explicitly unmeasured.

## Approach

1. Inventory every PHP source file in the three directories, including interfaces
   and exception-only files that may generate zero mutants. Preserve the existing
   dirty `docs/E2E-PROGRESS.md` tier-8 addition as user-owned context.
2. Run Infection once per source file through the corrected Pest launcher. Start
   with Reports, then Catalog, then Characters. Use the full suite's coverage with
   `--only-covering-test-cases`, a single source `--filter`, and a unique JSON log
   copied immediately after each run. Do not combine source files into a score.
3. Parse each JSON log independently and record total generated, killed, escaped,
   uncovered, skipped, timeout, error, and syntax-error counts. A result is valid
   only if every generated mutant has a terminal result and the skip/timeout/error/
   syntax/uncovered counts are zero. Report a zero-mutant file explicitly.
4. For an unreliable file, retry with directly relevant whole test files and/or a
   larger per-mutant timeout while retaining per-source isolation. If it still
   skips or cannot reproduce the complete mutant denominator, mark it unmeasured
   with the exact reason and counts.
5. Build a one-row-per-escape baseline ledger from stable Infection IDs and exact
   diffs. Inspect the production branch and existing behavioral tests. Classify
   each escape as a real observable gap or an equivalent mutation, with an
   individual explanation that can be checked by hand.
6. Add the smallest behavior-focused Pest tests needed to kill real gaps. Do not
   alter or weaken existing assertions, and do not assert private implementation
   structure merely to mirror the source. Run focused tests while iterating and
   rerun the affected mutant IDs where useful.
7. Repeat every per-file Infection run after test changes using the same accepted
   scope. Reconcile denominators and all terminal statuses; document any newly
   covered mutants or persistent equivalents individually.
8. Write a dedicated tier-8 report containing the complete per-file before/after
   table, skip counts, escaped-mutant ledger, new-test-to-mutant mapping, and
   verbatim verification output. Obtain a Claude review of the complete
   uncommitted implementation and address legitimate findings, up to three rounds.
9. Run `migrate:fresh --seed`, the full Pest suite, typecheck, production build,
   all 17 browser tests, and a direct extraction of the seven golden values.
   Update `docs/E2E-PROGRESS.md` only after all other work and verification.

## Locally verified assumptions

- Infection 0.34 accepts a source path or `--filter` and supports
  `--only-covering-test-cases`; `infection.json5` writes detailed JSON.
- `bin/pest-infection` now resolves the real project root after Infection copies
  the launcher and translates generated Pest method filters to descriptions.
- The source inventory currently contains 19 Characters PHP files, one Catalog
  file, and one Reports file. `CharacterCommand.php` and `RevisionConflict.php`
  may legitimately generate zero mutants, but will still receive explicit rows.
- The worktree already contains the user's uncommitted tier-8 backlog addition in
  `docs/E2E-PROGRESS.md`; it must be preserved and completed only at the end.

## Verification and evidence

- Per-file Infection JSON logs plus a checked aggregate table and complete escape
  ledger.
- Focused Pest tests for each new behavior and affected per-file mutation reruns.
- Final commands and outputs required by the task, including the golden-value JSON.
- `git diff --check`, PHP syntax checks for changed PHP files, and independent
  Claude critique of the plan and implementation.
