# Open questions

Decisions taken without asking, so the interactive build could proceed. Each lists
the guess actually implemented and what changes if you pick otherwise. Answer any
of them at any time and I will change it — none is expensive to reverse.

## 1. How many characters?

**Guess: a character list, with the seeded six-class build as the default.**
You can open, create and delete characters. There is no import/export UI yet
(Stage 4).

*If you'd rather:* a single fixed character would remove the list page and
simplify routing, but you could not compare two builds side by side — which is
most of what a multiclass planner is for.

## 2. What is editable in the browser?

**Guess: ability scores, class levels (add/remove/change level), subclass, and
every spell choice slot.** Species, background and feats are selectable from the
seeded catalog but you cannot yet author new ones (that was Stage 2D).

*If you'd rather:* if authoring your own feats/subclasses matters more than
tweaking numbers, say so and I'll pull the hand-entry forms forward.

## 3. Spell picker interaction

**Guess: an inline searchable combobox on each slot row**, listing only spells
eligible for that slot, with the duplicate consequence shown before you commit.
Keyboard-navigable; no modal.

*If you'd rather:* a full-screen picker with filters (school, tag, concentration,
ritual) would suit browsing a 943-spell catalog better, but is slower for the
"swap one cantrip" case.

## 4. Layout

**Guess: grid on the left, live build report on the right**, matching the
spreadsheet-first choice. The report recomputes on every change.

*If you'd rather:* separate pages would give the grid more width, at the cost of
losing the immediate "what did that change?" feedback.

## 5. Undo scope

**Guess: session-only undo/redo stack, per character, applied server-side.**
As agreed: the stack dies on reload, the audit log and save points persist.
Ctrl+Z / Ctrl+Shift+Z.

## 6. What happens to an orphaned or now-ineligible selection?

**Guess: it stays visible in its slot, flagged, with explicit actions**
(replace / keep as override / clear). Never silently removed. Ineligible
selections are excluded from access routes but the choice is preserved.

## 7. Theme

**Guess: follows the system light/dark preference, with a manual toggle.**

## 8. Concentration / loadouts

**Guess: not in this pass.** Loadout planning and concentration-conflict warnings
were scoped to Stage 3; the tables exist but there is no UI.

---

# Known gaps (deliberate, not oversights)

- **Migration rollback** — explicitly dropped per your instruction. `down()` is
  best-effort; `migrate:fresh --seed` is the supported path.
- **Warlock 11-20 Mystic Arcanum** — modelled but not exercised by any seeded
  build.
- **`data/index/` is committed (868K)** — could become a `npm run scrape` setup
  step instead if you want a lean repo.
- **Recommendations engine, AI context export, printable summary** — Stage 3/4.
