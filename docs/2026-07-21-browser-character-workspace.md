# Browser character workspace implementation plan

## Goal

Replace the health screen with a playable character list and add a dense, responsive character workspace that edits the existing rules-engine state without weakening its stable-slot, eligibility, capability-route, or catalog guarantees.

## Backend approach

1. Add a workspace read model that composes the existing `BuildReportBuilder` output with editable class rows, source-aware slot rows, available definitions, save points, invalid/orphaned selections, and summary counts.
2. Add a searchable eligible-spell endpoint. Eligibility is evaluated with the existing `SpellSelectionEligibility`; results are limited and query-filtered so the initial page does not duplicate the 943-version catalog for every slot.
3. Add a `CharacterCommand` contract with `apply()` and `inverse()`, concrete commands for abilities, slot selection/state, class structure, and snapshot restoration, plus a factory.
4. Run commands through one transactional executor which:
   - returns the cached inverse for a repeated `operation_uuid`;
   - locks and checks the character `revision`;
   - captures before/after state, applies the command, increments revision once;
   - writes row-level changes under one change-log group;
   - persists the inverse for idempotent retry;
   - returns the fresh workspace and inverse command.
5. Use narrow field-level inverses for ability and slot edits. Use state snapshots for class add/change/remove and save-point restore, where reconciliation can touch multiple stable rows.
6. Add character create/delete and save-point create/list/restore endpoints. Save-point restore is a normal restore-snapshot command through the executor.

## Frontend approach

1. Add Pinia and a mutation store whose undo and redo stacks contain inverse command payloads only. Successful mutations replace the workspace from the server and push only the returned inverse; reload clears both stacks by design.
2. Replace `/` with a character list showing level, classes, and warnings, with create/open/delete and useful empty/error states.
3. Build `/characters/{id}` as a two-column desktop/tablet workspace: editable/filterable/sortable slot grid on the left and sticky live report on the right. At narrower widths the report follows the grid.
4. Implement a keyboard-operable inline spell combobox that requests only eligible candidates. Preserve invalid/orphaned selections visibly with replace, keep-override-with-note, and clear actions.
5. Add autosaving labelled controls, manual/system dark mode, visible focus styles, text/icon warning signals, undo/redo buttons and keyboard shortcuts, and save-point controls. Avoid animations.

## Verification

1. Feature tests prove one-slot isolation, undo, save-point round trip, ability-driven save DC, class-level slot reconciliation without disturbing existing rows, stale revision rejection, and operation idempotency.
2. Re-run all seeded golden-value assertions and the complete backend suite after `migrate:fresh --seed`.
3. Run frontend typecheck and production build.
4. Review the complete uncommitted implementation with the second agent, address valid findings, and re-run focused/full verification.

## Locally verified assumptions

- The existing schema already has character `revision`, stable slot keys, orphan/override state columns, `change_log`, and save-point storage.
- `GrantRuleSlotGenerator` reconciles class/subclass sources while retaining selected spell IDs and stable slot rows.
- `SpellSelectionEligibility` is the authoritative reusable check for the combobox and mutation validation.
- `BuildReportBuilder` already provides the seeded caster/slot/preparation/duplicate/Wizard golden values and recalculates route attack/DC from current ability scores.
- Pinia is not currently installed and must be added as a runtime dependency.

