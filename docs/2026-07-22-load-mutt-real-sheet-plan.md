# Load Mutt from the real character sheet

## Goal

Keep the existing `seed:a6` character byte-for-byte compatible while adding
Mutt as character 2. Materialize Mutt's six class sources and every selected
spell through `CharacterCommandExecutor`, using the `add_source` and `set_slot`
commands so the grant DSL creates the slots and production eligibility decides
every selection. Add the eighteenth Playwright scenario with independent
database assertions for five-class duplicates and all six preparation ceilings.

## Proven assumptions and visible input discrepancy

- The supplied lists contain 35 distinct names, not 33: 14 cantrips and 21
  leveled spells. This must be reported rather than silently normalized.
- A level-1 source for each requested class generates 34 selection slots:
  14 cantrip slots and 20 prepared/known slots. Wizard also materializes its
  configured spellbook acquisitions separately from those slots.
- `add_source` currently accepts only feat/species/background, while
  `update_class` creates class levels and class sources. Because the task
  explicitly requires the real `add_source` command, extend `add_source` with a
  validated class-source branch instead of relabelling a direct insert or
  pretending `update_class` is the requested command.
- `Mold Earth` and `Shape Water` are both catalogued only as 2014 versions and
  currently have no list memberships. `allow_legacy` alone therefore still
  makes the real eligibility service reject them. Add only the user-confirmed
  sheet attributions needed here (`Mold Earth` on Wizard, `Shape Water` on
  Druid) to the catalog source data so the normal importer creates their
  memberships and `set_slot` can validate them.
- The schema has no HP or advancement columns. Preserve max HP 43, milestone
  advancement, and the inference provenance for CHA 17 / INT 13 / WIS 13 in
  Mutt's notes. The seeder will also use an explicit comment/constant name that
  prevents those scores from being mistaken for PDF data.
- The current Playwright suite has 17 scenarios and assumes character 1 in its
  shared database helpers. Parameterize the helpers with a default of 1 so all
  existing scenarios remain unchanged and T10 can inspect character 2.

## Implementation

1. Extend `add_source` payload/domain validation for `source_type=class` with a
   required class level, the total-level cap, non-repeatability, derived starting
   class and spellcasting ability, and optional Wizard spellbook acquisitions.
   Insert the class-level row and source inside the existing executor transaction,
   then call `GrantRuleSlotGenerator`; keep feat/species/background behavior and
   API validation unchanged.
2. Refactor `SeedCharacterSeeder` into independently guarded A6 and Mutt paths.
   Do not alter A6's source or selection mechanics. Insert only Mutt's root
   character row directly because no create-character command exists; send the
   six source additions and all 34 slot selections through the executor with
   deterministic UUIDs/revisions and explicit seeding reasons.
3. Record every leveled-spell source assignment as inferred. Use three real
   duplicate pairs across exactly five classes: Bane (Bard/Cleric), Healing Word
   (Cleric/Druid), and Shield (Sorcerer/Wizard). Fill the remaining eligible
   class slots with supplied spells. Configure six eligible Wizard spellbook
   entries through the class source DSL, including two unprepared entries.
4. Maximize supplied-name coverage under those duplicate requirements: 31
   distinct supplied names occupy selection slots, two more are Wizard
   spellbook-only, and Ray of Sickness plus Sleep cannot be represented after
   every eligible Sorcerer/Wizard capacity is consumed. Report all three counts
   against the actual 35-name input and explain the task's stated 33 count.
5. Strengthen existing feature seed coverage without adding a separate Pest
   scenario: assert Mutt's metadata/provenance, six `add_source` operations,
   34 `set_slot` operations, all-valid filled slots, imported legacy
   memberships, inferred assignments, Wizard spellbook, and unchanged A6
   golden report.
6. Parameterize the E2E database bridge by character ID and add T10. Navigate to
   Mutt, but assert from the database that the selected rows form the exact
   Bane/Healing Word/Shield source pairs, all selections are valid, and both
   legacy cantrips use their 2014 versions. Assert the returned/report data has
   caster level 6, shared slots 4/3/3, and max preparable level 1 for Bard,
   Cleric, Druid, Paladin, Sorcerer, and Wizard simultaneously.
7. Sensitivity-check T10 twice: temporarily suppress wasteful duplicate
   classification and capture the exact failing duplicate assertion; restore and
   pass. Temporarily break the level-1 preparation ceiling and capture the exact
   failing six-class assertion; restore and pass. Also verify command-path
   sensitivity through exact operation/action counts so a direct-insert seeder
   cannot satisfy the test.
8. Run fresh seed plus full Pest, typecheck, build, all 18 E2E scenarios, an
   explicit A6 golden extraction, PHP syntax/diff checks, and the required Claude
   review loop. Preserve legitimate review fixes and document rejected findings.
   Update `docs/E2E-PROGRESS.md` only after every implementation and verification
   step is complete. Do not commit.

## Verification evidence to retain

- Verbatim migration/seed, Pest, typecheck, build, and Playwright output.
- Exact Mutt slot/spellbook counts, omitted names/reasons, inferred attribution
  map, caster/slot values, and duplicate rows/sources.
- Exact T10 sensitivity failures and passing reruns after restoration.
- The seven unchanged A6 golden values and final `git diff --check` result.
