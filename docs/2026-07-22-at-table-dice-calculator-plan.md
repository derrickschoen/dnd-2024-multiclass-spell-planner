# At-the-table dice calculator plan

## Outcome

Add a fast, reproducible attack-and-damage calculator to the character workspace. It will support a generic attack plus first-class 2024 Sorcerous Burst and Chromatic Orb profiles, reuse selected spells' attack bonuses, and expose the requested d20 and damage modifiers without changing the database.

## Locally verified assumptions

- `CharacterWorkspaceBuilder` already calculates `attack_bonus` and `ability` per selected attack spell. The workspace only needs the selected spell edition added to its slot DTO so the UI can distinguish legacy spell versions.
- The character report already contains character level and ability scores, so Sorcerous Burst cantrip scaling and its spellcasting-modifier explosion cap require no new input for a selected slot.
- This repo uses strict TypeScript but has no browser unit-test dependency. Node 24 can load type-stripped `.ts` modules from native `.mjs` tests, so exact math tests can use `node:test` without adding a package.
- Existing frontend conventions are Vue single-file components and Tailwind component classes. A self-contained `DiceRoller.vue` component can be embedded near the top of `Workspace.vue`.
- Pest uses SQLite `:memory:`. No migration, seeding, E2E, or shared development database operation is needed.

## Rule model and composition order

1. Resolve net Advantage/Disadvantage. A 2024 Lucky feat point supplies Advantage; opposing Advantage and Disadvantage cancel. Elven Accuracy applies only when net Advantage remains and is visibly badged `LEGACY (Xanathar's)`.
2. Roll the d20 pool. Halfling Luck replaces each natural 1 once and the replacement must stand. Elven Accuracy is modeled as optimally rerolling the lower Advantage die, equivalent to the maximum of three independent post-Halfling-Luck dice.
3. Roll Bless and Bane d4s and apply them to the selected natural d20. Natural 1 always misses and natural 20 always hits critically.
4. Critical hits double the attack's initial damage dice before special spell processing. For Sorcerous Burst, doubled initial d8s can trigger added d8s but the spellcasting-modifier cap on added dice is unchanged.
5. Roll damage dice. Elemental Adept maps each raw damage-die result of 1 to 2. This does not affect Sorcerous Burst's 8 trigger; it does affect Chromatic Orb matching because matching is checked after a die is treated as 2.
6. Resolve bounded Sorcerous Burst additions or Chromatic Orb matching/leaps.
7. Sum each target's damage, then apply Resistance (floor half) and Vulnerability (double) in 2024 order. Elemental Adept removes matching-type Resistance before this step.

The critical/Sorcerous Burst sequencing and Elemental Adept/Chromatic Orb matching interaction are explicit interpretations because the free official wording does not settle those cross-feature timings.

## Exact math

- Build the final natural-d20 probability distribution directly, including Halfling Luck, Advantage/Disadvantage, Lucky, Elven Accuracy, and Bless/Bane convolution.
- For ordinary dice, use finite sum-distribution dynamic programming so Resistance's per-instance rounding remains exact.
- For bounded Sorcerous Burst, use the negative-binomial tail identity for hand-checkable raw expectation: `E[extra] = sum(k=1..cap) P(total geometric successes >= k)`. Use finite state dynamic programming for exact post-Resistance expectation.
- For Chromatic Orb, calculate exact damage distributions and exact duplicate-face probability. Fold those into the finite leap recurrence, with maximum leap count equal to the slot level (including one leap at level 1).
- Use a small deterministic seeded PRNG only for the displayed live roll. Expected values never use sampling.

## Implementation

1. Add a pure TypeScript dice engine with validation, exact probability functions, seeded rolling, and structured roll traces.
2. Add native Node tests with hand-checkable d20 probabilities, closed-form Sorcerous Burst expectations, Chromatic Orb matching/chain checks, composition-order checks, defense rounding, and seeded reproducibility.
3. Expose selected spell edition in the workspace slot contract and add one Pest contract test proving selected attack spell metadata reaches the workspace.
4. Build the responsive calculator component with presets, compact toggles, visible legacy badge, exact result cards, reproducibility token, and concise rule/interpretation notes.
5. Embed it in the workspace, add the Node test script, then run per-test sensitivity checks by deliberately changing the production branch each test protects, observing the targeted failure, and restoring it.
6. Run safe verification only: `ddev exec vendor/bin/pest`, `npm run test:dice`, `npm run typecheck`, and `npm run build`.
7. Run a Claude review of the uncommitted implementation, address legitimate findings, and repeat once if necessary.

## Deliberate limits

- The calculator handles attacks, not saving-throw damage spells; AC is therefore meaningful for every profile.
- Lucky represents spending a 2024 Luck Point to gain Advantage. The same settings are used for every Chromatic Orb attack in a chain; the UI warns that this can consume a Luck Point per attack.
- Each Chromatic Orb target uses the same entered AC and defenses. Different targets can be rolled separately by changing those values.
- Elemental Adept is opt-in for the chosen damage type; the calculator does not infer a character's chosen Elemental Adept type from persisted feats.
