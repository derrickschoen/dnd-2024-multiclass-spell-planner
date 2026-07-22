# Attribution

This work includes material taken from the System Reference Document 5.2.1
("SRD 5.2.1") by Wizards of the Coast LLC, available at
<https://www.dndbeyond.com/srd>. The SRD 5.2.1 is licensed under the
Creative Commons Attribution 4.0 International License, available at
<https://creativecommons.org/licenses/by/4.0/legalcode>.

## What is in this directory

`2024-PHB.json` contains **339 spell records** limited to SRD 5.2.1 content:
mechanical metadata only — name, level, school, casting time, range, duration,
components, concentration, ritual, attack modes, save abilities, class lists and
source reference.

**No spell descriptions or other prose are included.**

## What is deliberately not here

The scraper this project ships can build a much larger catalog — roughly 943 spell
versions across about twenty books, including Xanathar's Guide to Everything,
Tasha's Cauldron of Everything, Fizban's Treasury of Dragons and others. Those
books are **not** under Creative Commons, so that data is not redistributed here.
It is written to `data/index/`, which is gitignored.

To build the full catalog on your own machine:

```bash
npm run scrape
```

That fetches from public wiki sources, rate-limited and cached, and writes
`data/index/`. The application prefers `data/index/` when present and falls back
to this SRD subset otherwise, so the app works either way — but a character using
non-SRD content (for example a spell from Xanathar's) will only load fully once
you have run the scraper.

## Provenance of this subset

The 339 records were selected by matching our catalog against the SRD 5.2.1 spell
list, restricted to 2024-edition records sourced solely from the 2024 Player's
Handbook. Seventeen spells that the SRD prints under generic names (for example
"Floating Disk" for "Tenser's Floating Disk") were reconciled through an explicit
alias map rather than fuzzy matching. The resulting count independently matches a
separate SRD audit of the same catalog.
