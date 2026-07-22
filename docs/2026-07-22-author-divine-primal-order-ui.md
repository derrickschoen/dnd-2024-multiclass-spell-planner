# Author Divine and Primal Order in the workspace

## Goal

Let users choose Divine Order for an active Cleric class source and Primal Order
for an active Druid class source. Use the existing `update_source_config`
mutation so conditional grant rules create, orphan, reactivate, and undo the
bonus cantrip row through the existing cascade machinery.

## Verified assumptions

- `ClassProgressionSeeder` already defines one conditional
  `choice_from_list` slot for `divine_order.chosen_option = Thaumaturge` and one
  for `primal_order.chosen_option = Magician`.
- `GrantRuleSlotGenerator` derives a stable slot key from source UUID, rule key,
  and ordinal. `syncSlot()` reuses that row, while `reconcileSlots()` marks an
  inactive rule's row `orphaned` and preserves its spell reference.
- `UpdateSourceConfigCommand` already captures the complete character state and
  returns an integrity-protected `restore_snapshot` inverse, but currently only
  accepts Magic Initiate list changes.
- The workspace exposes Magic Initiate sources but no configurable class-source
  metadata. Its shared `.field` styles provide visible keyboard focus and its
  controls use native labels.
- S11 counts controls only inside the `Spell choice slots` section. The new
  order controls belong in `Source configuration`, so the grid's three sort
  buttons plus four filters remain `GRID_CHROME_CONTROLS = 7`.

## Implementation

1. Extend `update_source_config` payload validation to accept exactly one of
   `chosen_list` (the existing Magic Initiate shape) or `chosen_option` (the new
   class Order shape).
2. Extend `UpdateSourceConfigCommand` to identify active Cleric and Druid class
   sources, validate their two legal options, normalize the nested config, add
   the class spell list only for Thaumaturge/Magician, persist it, and regenerate
   the source. Preserve the existing Magic Initiate behavior and messages.
3. Add deterministic `order_sources` metadata to `CharacterWorkspaceBuilder`
   and the TypeScript workspace contract, including the current nullable choice,
   option names, order label, and bonus option. Each array item has the exact
   shape `{ id, class_name, display_name, order_name, chosen_option, options,
   bonus_option }`.
4. Render a labelled native select for each order source in the Source
   configuration panel. Include text explaining which option adds a cantrip and
   submit `update_source_config` with `chosen_option`.
5. Add Pest coverage for bonus materialization, selected-row orphaning, and an
   exact row restore through the returned inverse. Update payload-validator and
   workspace-contract assertions as needed.

## Verification

- Run each new Pest test once passing.
- Sensitivity-check each new test by temporarily breaking its production
  behavior, record the observed failure, and restore the implementation.
- Run the relevant Pest files, then the full Pest suite using SQLite memory.
- Run `npm run typecheck` and `npm run build`.
- Do not run migrations, seed commands, or the browser suite against the shared
  ddev database.

## Review decisions

- Class sources are non-repeatable in `AddSourceCommand` and are paired with one
  `character_class_levels` row, so a character cannot have ambiguous active
  Cleric or Druid sources. The command always updates the supplied, character-
  scoped source instance ID.
- The four option names stay explicit in the command, matching the existing
  validation in `AddSourceCommand`: Cleric has Protector/Thaumaturge and Druid
  has Warden/Magician. Thaumaturge and Magician each activate exactly their
  class's one conditional cantrip rule; Protector and Warden activate none.
- The command calls `generateForSource()` after saving normalized config. It
  does not call private slot methods directly; the generator evaluates the
  conditional rule and performs stable-row synchronization/reconciliation.
- `CharacterState` captures source config and every spell-selection row, so the
  inverse restores both the prior order choice and the complete selected slot
  row. The feature test compares the entire database row before switching with
  the row after executing the returned inverse.
- There is no component-unit harness in this repository. Inertia/workspace Pest
  assertions prove the hydration DTO, validator/command Pest assertions prove
  the submitted payload contract and regenerated response, and Vue typecheck
  plus production build validate the template binding. The supervisor will run
  the browser suite serially as required by the concurrency constraint.
