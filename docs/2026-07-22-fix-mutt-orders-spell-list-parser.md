# Fix Mutt attribution, class Orders, and spell-list parsing

## Goal

Correct the linked E2E-16 data defects without changing A6's established golden
contract: seed Mutt from the user's authoritative per-class spell attribution,
model the 2024 Cleric and Druid Order cantrip choices in class progression, and
make spell-list ingestion conservatively preserve every stated membership across
the complete 993-page cache.

## Locally verified assumptions

- The worktree was clean before this unit began.
- `scripts/.cache` contains 993 detail pages and two edition index pages: 574
  legacy and 419 modern detail pages.
- The modern index already supplies ordinary list memberships, while legacy
  records rely on `parseSpellLists()` from the detail page.
- Class progression grant rules are merged by `rule_key`, and list-choice rules
  can resolve their list from class source config via `$config.<key>`.
- Mutt is seeded through one rules command, six `add_source` commands, and one
  `set_slot` command per filled generated slot. Adding Guidance therefore changes
  the expected command/revision/slot totals by one.
- The base level-1 Cleric progression is already three cantrips and four prepared
  spells; the Order bonus must be a separate rule and must not change those base
  progression columns. The corresponding Druid base is two cantrips and four
  prepared spells.
- The relational catalog stores memberships as a string `spell_list_key`. Exact
  labels such as `Sorcerer (Optional)` and `Wizard (Dunamancy)` can therefore be
  retained without promoting them to ordinary class eligibility.

## Representation decision

Preserve qualified memberships as their normalized, title-cased textual labels:
`Class (Optional)`, `Wizard (Dunamancy)`, and `Wizard (Graviturgy)`. Ordinary
class slots continue to require the exact unqualified key. This retains the
source fact for future optional-feature/subclass modeling and deliberately does
not make a qualified spell selectable through the base class list. `None` is a
sentinel, not a list, and is rejected.

## Implementation

1. Add a validated, generic exact-config activation predicate to the grant-rule
   DSL, then extend level-1 Cleric and Druid class progression with separate
   `choice_from_list` cantrip rules for Divine Order and Primal Order. Resolve
   the list through source config and record the chosen option plus chosen list
   there, mirroring Magic Initiate. Materialize one bonus cantrip only for
   Thaumaturge/Magician respectively, leaving the base `cantrips_known` and
   `prepared_count` values unchanged. Validate and retain Order config through
   the class `add_source` command rather than dropping it while normalizing the
   class config.
2. Update Mutt's class-source configs with `divine_order: Thaumaturge` and
   `primal_order: Warden`, select Guidance in the Divine Order slot, and replace
   every inferred leveled assignment with the exact authoritative table. Use the
   exact six-spell Wizard book and explicitly choose four prepared spells.
3. Replace `parseSpellLists()` with a conservative parser that recognizes colon
   or period delimiters case-insensitively, handles collapsed DOM boundaries,
   extracts the modern spell-header list as well as the legacy trailing list,
   validates only known class-name labels with an optional supported qualifier,
   preserves qualifiers, de-duplicates in source order, and drops `None`.
4. Replace narrow parser fixtures with a corpus audit that loads all cached pages
   and both cached indexes. Compare the old effective behavior with the new one,
   pin the reported before/after counts, prove all 28 previously empty published
   records now resolve, and assert Fast Friends, Encode Thoughts, all optional
   memberships, and the Dunamancy/Graviturgy records explicitly.
5. Regenerate the committed catalog from cache through the normal scraper path,
   then assert catalog/import eligibility properties and exact Mutt database and
   report contracts. Update feature and browser expectations from false duplicate
   warnings to zero duplicates and pin every class's selected spells.

## Verification and sensitivity

- Run focused script and Pest tests while implementing.
- Prove the Order rules are sensitive by temporarily removing/changing the
  chosen config or expected bonus and observing the focused contract fail.
- Prove Mutt attribution/duplicate assertions are sensitive by temporarily
  restoring one bad repeated attribution and observing the focused contract fail.
- Prove the corpus audit is sensitive by temporarily restoring the old parser
  behavior and observing the count/specific-spell contracts fail.
- Run exactly: `ddev exec php artisan migrate:fresh --seed`,
  `ddev exec vendor/bin/pest`, `ddev exec npm run test:scripts`,
  `ddev exec npm run typecheck`, `ddev exec npm run build`, and
  `ddev exec npm run test:e2e`.
- Re-query A6's seven golden values and Mutt's per-class selections, Wizard book,
  prepared four, and duplicate report from the fresh database.
- Obtain a Claude plan review before implementation and an uncommitted-code
  review after verification; address legitimate findings, up to three rounds.
- Update `docs/E2E-PROGRESS.md` last with exact outputs and no commit or push.
