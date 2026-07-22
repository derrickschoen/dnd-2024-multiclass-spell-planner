# Printable spell lists implementation plan

## Outcome

Add a read-only print route for each character with a compact reference variant
and a full-text variant. Both variants group currently castable routes by source,
preserve the Wizard spellbook/prepared/ritual-only distinction, and add separate
2024 Cleric and Druid long-rest swap sections containing leveled spells up to that
class's own preparation ceiling.

## Proven assumptions

- `SpellAccessBuilder` is the established source of currently castable routes and
  already supplies source-specific ability, attack bonus, and save DC values.
- `BuildReportBuilder::wizardSplit()` is the established three-state Wizard
  projection and owns the explanatory text that must remain unchanged.
- Tier 1 and Tier 2 contain 947 publication records representing the same 943
  unique spell versions; every Tier 2 record has `_description`.
- The live SQLite schema matches the checked-in migration. There is no literal
  `description` column; `spell_versions.short_summary` is an existing nullable
  `TEXT` column and is unused everywhere. It will store the complete optional
  description without changing fresh-install schema.
- The exact 2024 class memberships at level 0/1 are Cleric 9/16 and Druid 13/19.
  Mutt prepares four Cleric candidates from the modern list, so 12 remain. Mutt's
  Druid preparations include legacy-only Absorb Elements; only three of the 19
  modern candidates are already prepared, so 16 remain under the literal
  2024-list rule.
- DDEV intentionally omits its database container because this project uses
  SQLite. `ddev mysql` cannot run; schema verification uses `PRAGMA table_info`
  against the application's actual SQLite database instead.

## Implementation

1. Extend `catalog:import` with opt-in `--with-text`. When selected, merge
   `data/local/*.full.json` descriptions by `versionKey`; absent Tier 2 is a
   successful no-description import with an explicit console message. Validate
   malformed/conflicting Tier 2 records. A present Tier 2 corpus must cover every
   imported version exactly; partial and unmatched corpora fail loudly rather
   than silently producing a partly described printout. Update descriptions even for referenced
   versions, while retaining the existing immutability rule for mechanical data.
   Ordinary imports must neither load nor clear descriptions.
2. Populate the existing `action_type` during import from casting-time strings,
   with Action, Bonus Action, Reaction, or null for longer/special times.
3. Add `PrintableSpellListBuilder`, enriching access routes in batches with
   spell facts, attack modes, save abilities, and optional text. Only show to-hit
   when an attack mode exists and only show save DC when a save ability exists.
   Build separate 2024 Cleric/Druid unprepared sections at levels 1 through the
   class ceiling, excluding identities prepared by that class source. State
   explicitly that cantrips cannot be swapped this way.
4. Add `/characters/{id}/print?variant=reference|full`, defaulting invalid/missing
   variants to `reference`, plus an accessible link from the workspace. Render a
   dedicated Inertia page with a labelled variant control, keyboard-reachable
   print action, semantic headings, textual state labels, and no reliance on
   colour. Full mode shows descriptions when present and one clear page-level
   degradation message when none are installed.
5. Add component print CSS: black on white at 10.5pt, control/nav removal,
   sensible section breaks, repeated avoidance of spell-card splitting, and
   compact reference layout.

## Verification and sensitivity

- Feature tests cover Tier 2 opt-in/preservation/absence, referenced-version text
  updates, action-type normalization, print-route validation, exact Mutt source
  mechanics, Wizard states, unprepared names/counts, and full-mode degradation.
- Add one Playwright Mutt scenario covering both variants, exact unprepared
  contents/exclusions, attack-only versus save-only math, Wizard states, labelled
  controls/focusability, print media rules, and no-Tier-2 degradation.
- For each new behavior cluster, make a temporary production mutation, run the
  focused test and record its failure, then restore and re-run clean.
- Finish with fresh seed plus Pest, typecheck, build, the full browser suite,
  direct A6 seven-golden extraction, and Mutt duplicate extraction.
- Run an independent implementation review after the complete diff and address
  legitimate findings, with at most three review rounds.

## Independent plan review disposition

- Accepted: prove ordinary imports preserve populated text at the SQL level,
  because a broad update payload could silently clear it.
- Accepted: descriptions match only exact unique `versionKey` values; duplicates
  must agree, and partial/unmatched Tier 2 input is an error.
- Accepted: prove a default fresh seed and flag-off import leave description text
  empty, and do not commit any local corpus content.
- Rejected: making `--with-text` fail when Tier 2 is wholly absent. The user
  explicitly requires absent Tier 2 to be supported and variant B to degrade
  gracefully, so whole-corpus absence is intentionally distinct from partial
  corpus corruption.
