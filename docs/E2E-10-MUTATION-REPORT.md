# UNIT E2E-10 mutation-test report

## Scope and scores

Infection 0.34.0 ran on PHP 8.4 through Pest 4 using `bin/pest-infection`.
The configured source root is all of `app/Domain` and has no exclusions.

| Run | Before | After |
| --- | ---: | ---: |
| M1 pure rules | 56/95 killed, 39 escaped — 58.95% MSI | 120/125 killed, 5 equivalent escapes — 96.00% MSI |
| M1 DB-backed `ClassProgressionLookup` | 3/3 killed — 100% MSI | unchanged |
| M1 combined | 59/98 killed — 60.20% MSI | 123/128 killed, 5 equivalent escapes — 96.09% MSI |
| M2 focused aggregate | 723/854 killed, 131 escaped — 84.66% MSI | 860/885 killed, 25 equivalent escapes — 97.18% MSI |

The after denominators are larger because the new tests cover previously unreached branches, so Infection generated 31 additional M1/M2 mutants.

The first unscoped M2 attempt generated 862 mutants but skipped 715 whose covering-test set exceeded the original 15-second timeout. Its displayed 100% MSI was invalid and is not used above. M2 was therefore split by class with the directly relevant Pest files and a 60-second timeout. Results: `GrantRuleSlotGenerator` 321/321, `SpellAccessBuilder` 157/157, `SpellSelectionEligibility` 88/88, and `SpellSelectionService` 8/8. `DuplicateWarningDetector` and `GrantRule` are reported separately in the aggregate. No source file was omitted.

## New tests and what they kill

- `MulticlassSlotsTest`: zero-level contribution, all progression variants, epic caps, all Pact rows and aggregation, and every maximum-preparable breakpoint. These kill 36 original M1 escapes plus newly covered real mutants.
- `DuplicateWarningDetectorTest`: exact sorted assessment DTOs, version labels/order, hard-coded fingerprint, explanations, sources/slots, and compact duplicate-source lists. These kill 32 original M2 escapes.
- `GrantRuleTest`: normalized defaults for all six kinds, 33 malformed field shapes, independent query predicates/source references, trimming, free-cast diagnostics, and JSON behavior. These kill 77 original M2 escapes.

## Full original escaped-mutant ledger

Each row is one of the 170 original escaped mutants and has an individual verdict. IDs are Infection's stable IDs for the unchanged production source.

| Mutant | Location | Mutator | Infection ID | Verdict |
| --- | --- | --- | --- | --- |
| M1 rules #1 | `app/Domain/Rules/CasterContribution.php:36` | `LessThan` | `47f34ad7b94d74be2769fb01be764178` | Real gap — killed by published progression, slot, Pact, and preparation boundary vectors. |
| M1 rules #2 | `app/Domain/Rules/CasterContribution.php:50` | `MatchArmRemoval` | `b3af490a66ddc30f72e89058606dd8e6` | Real gap — killed by published progression, slot, Pact, and preparation boundary vectors. |
| M1 rules #3 | `app/Domain/Rules/CasterContribution.php:50` | `MatchArmRemoval` | `0b5f9c1fe815703b55fe673e32595ab6` | Real gap — killed by published progression, slot, Pact, and preparation boundary vectors. |
| M1 rules #4 | `app/Domain/Rules/CasterContribution.php:50` | `MatchArmRemoval` | `210ddada5960bc590afedefcdd5b792a` | Real gap — killed by published progression, slot, Pact, and preparation boundary vectors. |
| M1 rules #5 | `app/Domain/Rules/CasterContribution.php:52` | `RoundingFamily` | `1549122abe7edcec823364e930e9a82e` | Equivalent — for nonnegative integer levels, these `ceil(n/2)` and PHP half-up `round(n/2)` results are identical. |
| M1 rules #6 | `app/Domain/Rules/CasterContribution.php:55` | `IncrementInteger` | `979af7745650620613a8b21e649f35dc` | Real gap — killed by published progression, slot, Pact, and preparation boundary vectors. |
| M1 rules #7 | `app/Domain/Rules/SpellSlots.php:80` | `IncrementInteger` | `f12ddc63ed1c40e5752e3d763ec57e42` | Real gap — killed by published progression, slot, Pact, and preparation boundary vectors. |
| M1 rules #8 | `app/Domain/Rules/SpellSlots.php:112` | `Assignment` | `21634fb4af1f002e54365726bbfa2d85` | Real gap — killed by published progression, slot, Pact, and preparation boundary vectors. |
| M1 rules #9 | `app/Domain/Rules/SpellSlots.php:116` | `LessThan` | `205bd424ffac01e73f1e47b613fdc641` | Real gap — killed by published progression, slot, Pact, and preparation boundary vectors. |
| M1 rules #10 | `app/Domain/Rules/SpellSlots.php:120` | `DecrementInteger` | `258cb36e97a21f25364e4933b1dfcf01` | Equivalent — the adjacent Pact table rows selected by this boundary mutation have identical count/level values. |
| M1 rules #11 | `app/Domain/Rules/SpellSlots.php:120` | `IncrementInteger` | `e795d4d14a9c37f1134d7c6f65b5157a` | Real gap — killed by published progression, slot, Pact, and preparation boundary vectors. |
| M1 rules #12 | `app/Domain/Rules/SpellSlots.php:134` | `MatchArmRemoval` | `c49a1a11f9402fb644c749430c852b35` | Real gap — killed by published progression, slot, Pact, and preparation boundary vectors. |
| M1 rules #13 | `app/Domain/Rules/SpellSlots.php:134` | `MatchArmRemoval` | `a8343270bd9de63c544a46f5d00a5c2f` | Real gap — killed by published progression, slot, Pact, and preparation boundary vectors. |
| M1 rules #14 | `app/Domain/Rules/SpellSlots.php:134` | `MatchArmRemoval` | `caaa2fb4f02cb6ff0e238ecff35d971e` | Real gap — killed by published progression, slot, Pact, and preparation boundary vectors. |
| M1 rules #15 | `app/Domain/Rules/SpellSlots.php:134` | `MatchArmRemoval` | `a3edc83f0dcdfc44ed75fe69dceba86d` | Real gap — killed by published progression, slot, Pact, and preparation boundary vectors. |
| M1 rules #16 | `app/Domain/Rules/SpellSlots.php:134` | `MatchArmRemoval` | `591a35c9e7445ea6d6a7c129ea2b7046` | Real gap — killed by published progression, slot, Pact, and preparation boundary vectors. |
| M1 rules #17 | `app/Domain/Rules/SpellSlots.php:134` | `MatchArmRemoval` | `af93242d2233291883c26c28e5dbb200` | Real gap — killed by published progression, slot, Pact, and preparation boundary vectors. |
| M1 rules #18 | `app/Domain/Rules/SpellSlots.php:134` | `MatchArmRemoval` | `bc60cb84b512bd306df5ae34005a37e9` | Real gap — killed by published progression, slot, Pact, and preparation boundary vectors. |
| M1 rules #19 | `app/Domain/Rules/SpellSlots.php:135` | `IncrementInteger` | `057c62ef85f2cf8b4e440d7d1e1992de` | Real gap — killed by published progression, slot, Pact, and preparation boundary vectors. |
| M1 rules #20 | `app/Domain/Rules/SpellSlots.php:135` | `DecrementInteger` | `adf415020fb5c0e09fe38cc271839277` | Real gap — killed by published progression, slot, Pact, and preparation boundary vectors. |
| M1 rules #21 | `app/Domain/Rules/SpellSlots.php:135` | `IncrementInteger` | `eb3022c57f4d9c23e85cb09d1fa0b0e4` | Real gap — killed by published progression, slot, Pact, and preparation boundary vectors. |
| M1 rules #22 | `app/Domain/Rules/SpellSlots.php:135` | `DecrementInteger` | `8beba7900d0c18fed25709c2a9aa1868` | Real gap — killed by published progression, slot, Pact, and preparation boundary vectors. |
| M1 rules #23 | `app/Domain/Rules/SpellSlots.php:135` | `RoundingFamily` | `5ed9a8ba9f1db385caf15f021924758d` | Real gap — killed by published progression, slot, Pact, and preparation boundary vectors. |
| M1 rules #24 | `app/Domain/Rules/SpellSlots.php:135` | `RoundingFamily` | `62eac5e8ecbbc1d5e813a5949a66aff8` | Equivalent — for nonnegative integer levels, these `ceil(n/2)` and PHP half-up `round(n/2)` results are identical. |
| M1 rules #25 | `app/Domain/Rules/SpellSlots.php:137` | `LessThan` | `f8cb25aa42765040a4c97f8564593b9d` | Real gap — killed by published progression, slot, Pact, and preparation boundary vectors. |
| M1 rules #26 | `app/Domain/Rules/SpellSlots.php:137` | `LessThanNegotiation` | `c6ef31fe763cd0c91913b1c1ef358582` | Real gap — killed by published progression, slot, Pact, and preparation boundary vectors. |
| M1 rules #27 | `app/Domain/Rules/SpellSlots.php:137` | `Identical` | `56db4ee312c70708cc1f01b49d745de2` | Real gap — killed by published progression, slot, Pact, and preparation boundary vectors. |
| M1 rules #28 | `app/Domain/Rules/SpellSlots.php:137` | `LogicalAnd` | `d46b27f27be4615f4890345577098217` | Real gap — killed by published progression, slot, Pact, and preparation boundary vectors. |
| M1 rules #29 | `app/Domain/Rules/SpellSlots.php:137` | `LogicalAndAllSubExprNegation` | `3cad38406de7a652d944c140c0538709` | Real gap — killed by published progression, slot, Pact, and preparation boundary vectors. |
| M1 rules #30 | `app/Domain/Rules/SpellSlots.php:137` | `LogicalAndNegation` | `75ba581dfb00bec8a14203112fbb4478` | Real gap — killed by published progression, slot, Pact, and preparation boundary vectors. |
| M1 rules #31 | `app/Domain/Rules/SpellSlots.php:137` | `Ternary` | `7e3bd952399542c817b3b3e1bed026d9` | Real gap — killed by published progression, slot, Pact, and preparation boundary vectors. |
| M1 rules #32 | `app/Domain/Rules/SpellSlots.php:139` | `DecrementInteger` | `1812536594a2b9b4c1c6e46c7a29efe1` | Real gap — killed by published progression, slot, Pact, and preparation boundary vectors. |
| M1 rules #33 | `app/Domain/Rules/SpellSlots.php:139` | `IncrementInteger` | `32a26a906b4756d75660ba98349d855a` | Real gap — killed by published progression, slot, Pact, and preparation boundary vectors. |
| M1 rules #34 | `app/Domain/Rules/SpellSlots.php:139` | `DecrementInteger` | `2ad00bcf9aaed385356debae8587b41b` | Real gap — killed by published progression, slot, Pact, and preparation boundary vectors. |
| M1 rules #35 | `app/Domain/Rules/SpellSlots.php:139` | `IncrementInteger` | `25f10a13c19d762af8ba5005427d1d5e` | Real gap — killed by published progression, slot, Pact, and preparation boundary vectors. |
| M1 rules #36 | `app/Domain/Rules/SpellSlots.php:139` | `RoundingFamily` | `f6b24a65016dd79ac01788cfb07c10c2` | Real gap — killed by published progression, slot, Pact, and preparation boundary vectors. |
| M1 rules #37 | `app/Domain/Rules/SpellSlots.php:139` | `RoundingFamily` | `d14392abb35b6b03e5b53e0b8ab4b059` | Real gap — killed by published progression, slot, Pact, and preparation boundary vectors. |
| M1 rules #38 | `app/Domain/Rules/SpellSlots.php:143` | `DecrementInteger` | `20379c0adab0472663f9cd035df3fbb2` | Real gap — killed by published progression, slot, Pact, and preparation boundary vectors. |
| M1 rules #39 | `app/Domain/Rules/SpellSlots.php:143` | `IncrementInteger` | `50147e3f3f6be3edbf3b9908984730fa` | Real gap — killed by published progression, slot, Pact, and preparation boundary vectors. |
| M2 duplicate detector #1 | `app/Domain/Spells/DuplicateWarningDetector.php:17` | `CastInt` | `4c33f7a46fbb8ecd6d0472631d3023fd` | Equivalent — `SpellAccessBuilder` supplies this field with the cast target type; removing the defensive cast cannot change a production route. |
| M2 duplicate detector #2 | `app/Domain/Spells/DuplicateWarningDetector.php:22` | `UnwrapArrayValues` | `512b49b9b002ad4b36342b0249996107` | Equivalent — the intermediate keys are not observable: they feed counts/maps or a later explicit reindex; selected production routes also always have a nonempty slot key. |
| M2 duplicate detector #3 | `app/Domain/Spells/DuplicateWarningDetector.php:24` | `CastBool` | `5d59f1c9ed75557322e5eb0b3907fe33` | Equivalent — `SpellAccessBuilder` supplies this field with the cast target type; removing the defensive cast cannot change a production route. |
| M2 duplicate detector #4 | `app/Domain/Spells/DuplicateWarningDetector.php:26` | `UnwrapArrayValues` | `ac4da26eef32f7a89a65722db7d05fcd` | Equivalent — the intermediate keys are not observable: they feed counts/maps or a later explicit reindex; selected production routes also always have a nonempty slot key. |
| M2 duplicate detector #5 | `app/Domain/Spells/DuplicateWarningDetector.php:28` | `CastBool` | `7b5a75e16f074be7d97fcd1c3ea8fc2b` | Equivalent — `SpellAccessBuilder` supplies this field with the cast target type; removing the defensive cast cannot change a production route. |
| M2 duplicate detector #6 | `app/Domain/Spells/DuplicateWarningDetector.php:30` | `UnwrapArrayValues` | `79a43c0dfe6a9b46a36fc3f78d481042` | Equivalent — the intermediate keys are not observable: they feed counts/maps or a later explicit reindex; selected production routes also always have a nonempty slot key. |
| M2 duplicate detector #7 | `app/Domain/Spells/DuplicateWarningDetector.php:31` | `CastInt` | `cc15f685c4bcb5c4a68dd563e81940f0` | Equivalent — `SpellAccessBuilder` supplies this field with the cast target type; removing the defensive cast cannot change a production route. |
| M2 duplicate detector #8 | `app/Domain/Spells/DuplicateWarningDetector.php:34` | `UnwrapArrayValues` | `4d597db419b0c56c09c52884c914d52b` | Real gap — killed by the complete sorted assessment/fingerprint/list contract tests. |
| M2 duplicate detector #9 | `app/Domain/Spells/DuplicateWarningDetector.php:36` | `ArrayItemRemoval` | `4d078fa60aebd3bbc242f79515febbff` | Real gap — killed by the complete sorted assessment/fingerprint/list contract tests. |
| M2 duplicate detector #10 | `app/Domain/Spells/DuplicateWarningDetector.php:37` | `CastInt` | `794e1c5be3ff9c48b8bdbf458ee1f15e` | Equivalent — `SpellAccessBuilder` supplies this field with the cast target type; removing the defensive cast cannot change a production route. |
| M2 duplicate detector #11 | `app/Domain/Spells/DuplicateWarningDetector.php:38` | `CastString` | `916e7e055ae90a71573ec62813ee9cef` | Equivalent — `SpellAccessBuilder` supplies this field with the cast target type; removing the defensive cast cannot change a production route. |
| M2 duplicate detector #12 | `app/Domain/Spells/DuplicateWarningDetector.php:39` | `CastString` | `1c087b0fde1d56c5cbf60e16a8063111` | Equivalent — `SpellAccessBuilder` supplies this field with the cast target type; removing the defensive cast cannot change a production route. |
| M2 duplicate detector #13 | `app/Domain/Spells/DuplicateWarningDetector.php:40` | `CastString` | `1b5b22407ad934b1746ae9d16a13b716` | Equivalent — `SpellAccessBuilder` supplies this field with the cast target type; removing the defensive cast cannot change a production route. |
| M2 duplicate detector #14 | `app/Domain/Spells/DuplicateWarningDetector.php:40` | `Concat` | `7628ab0f4ce3d08100f1cb9fff5e318b` | Real gap — killed by the complete sorted assessment/fingerprint/list contract tests. |
| M2 duplicate detector #15 | `app/Domain/Spells/DuplicateWarningDetector.php:40` | `ConcatOperandRemoval` | `a75870102fdb71d07c460d072350b52c` | Real gap — killed by the complete sorted assessment/fingerprint/list contract tests. |
| M2 duplicate detector #16 | `app/Domain/Spells/DuplicateWarningDetector.php:40` | `Concat` | `c0e7542768e840bbca110359f3bb4c02` | Real gap — killed by the complete sorted assessment/fingerprint/list contract tests. |
| M2 duplicate detector #17 | `app/Domain/Spells/DuplicateWarningDetector.php:40` | `ConcatOperandRemoval` | `8f0aa639e42ec1c3566f77d832fd4218` | Real gap — killed by the complete sorted assessment/fingerprint/list contract tests. |
| M2 duplicate detector #18 | `app/Domain/Spells/DuplicateWarningDetector.php:40` | `ConcatOperandRemoval` | `ad20ae7f2dd0c45469e260d21736684c` | Real gap — killed by the complete sorted assessment/fingerprint/list contract tests. |
| M2 duplicate detector #19 | `app/Domain/Spells/DuplicateWarningDetector.php:40` | `Concat` | `d482edebdc2b9f414d9764abd0a57153` | Real gap — killed by the complete sorted assessment/fingerprint/list contract tests. |
| M2 duplicate detector #20 | `app/Domain/Spells/DuplicateWarningDetector.php:40` | `ConcatOperandRemoval` | `4a7a86b792c106f8a7abdabf41d26dbb` | Real gap — killed by the complete sorted assessment/fingerprint/list contract tests. |
| M2 duplicate detector #21 | `app/Domain/Spells/DuplicateWarningDetector.php:51` | `UnwrapArrayUnique` | `1aa8587ef1a3ad27b0839fff9b300a15` | Real gap — killed by the complete sorted assessment/fingerprint/list contract tests. |
| M2 duplicate detector #22 | `app/Domain/Spells/DuplicateWarningDetector.php:51` | `UnwrapArrayValues` | `a61c4a4ce8fa16f887d0bcf7eb26eed5` | Real gap — killed by the complete sorted assessment/fingerprint/list contract tests. |
| M2 duplicate detector #23 | `app/Domain/Spells/DuplicateWarningDetector.php:52` | `CastString` | `73d21235e9114b35c0d119f2e1af9cc3` | Equivalent — `SpellAccessBuilder` supplies this field with the cast target type; removing the defensive cast cannot change a production route. |
| M2 duplicate detector #24 | `app/Domain/Spells/DuplicateWarningDetector.php:55` | `UnwrapArrayMap` | `9d57ca1aa742c86c1161b7129750ed15` | Real gap — killed by the complete sorted assessment/fingerprint/list contract tests. |
| M2 duplicate detector #25 | `app/Domain/Spells/DuplicateWarningDetector.php:55` | `UnwrapArrayFilter` | `0f0e27e2c282e563990aa0522a2ffca9` | Equivalent — the intermediate keys are not observable: they feed counts/maps or a later explicit reindex; selected production routes also always have a nonempty slot key. |
| M2 duplicate detector #26 | `app/Domain/Spells/DuplicateWarningDetector.php:55` | `UnwrapArrayValues` | `c13b2e8845bd497fa26cc601f1675c4f` | Equivalent — the intermediate keys are not observable: they feed counts/maps or a later explicit reindex; selected production routes also always have a nonempty slot key. |
| M2 duplicate detector #27 | `app/Domain/Spells/DuplicateWarningDetector.php:59` | `IncrementInteger` | `c4205590564bd05966ed1b89ba54c7ff` | Equivalent — every route in an identity group has the same canonical identity name. |
| M2 duplicate detector #28 | `app/Domain/Spells/DuplicateWarningDetector.php:59` | `CastString` | `f1b23b1662d0bc7e075fed98b53dff6b` | Equivalent — `SpellAccessBuilder` supplies this field with the cast target type; removing the defensive cast cannot change a production route. |
| M2 duplicate detector #29 | `app/Domain/Spells/DuplicateWarningDetector.php:60` | `MatchArmRemoval` | `2257f73b4c96b30c7f63fdf14491879d` | Real gap — killed by the complete sorted assessment/fingerprint/list contract tests. |
| M2 duplicate detector #30 | `app/Domain/Spells/DuplicateWarningDetector.php:60` | `MatchArmRemoval` | `0bf7288c474b8a7b7978ccf410c9106f` | Real gap — killed by the complete sorted assessment/fingerprint/list contract tests. |
| M2 duplicate detector #31 | `app/Domain/Spells/DuplicateWarningDetector.php:60` | `MatchArmRemoval` | `4d2025916ebaea97f835639fe592131d` | Real gap — killed by the complete sorted assessment/fingerprint/list contract tests. |
| M2 duplicate detector #32 | `app/Domain/Spells/DuplicateWarningDetector.php:63` | `ConcatOperandRemoval` | `339de9d271f2dbd980a98525ff60a9ce` | Real gap — killed by the complete sorted assessment/fingerprint/list contract tests. |
| M2 duplicate detector #33 | `app/Domain/Spells/DuplicateWarningDetector.php:63` | `Concat` | `0680adf891efce7c79b6453528b0c5e3` | Real gap — killed by the complete sorted assessment/fingerprint/list contract tests. |
| M2 duplicate detector #34 | `app/Domain/Spells/DuplicateWarningDetector.php:63` | `ConcatOperandRemoval` | `2a1010628103ef3e48164a0c06bbbf73` | Real gap — killed by the complete sorted assessment/fingerprint/list contract tests. |
| M2 duplicate detector #35 | `app/Domain/Spells/DuplicateWarningDetector.php:63` | `Concat` | `b6e7501be6d56d8f854ba8f534e5e0f6` | Real gap — killed by the complete sorted assessment/fingerprint/list contract tests. |
| M2 duplicate detector #36 | `app/Domain/Spells/DuplicateWarningDetector.php:63` | `ConcatOperandRemoval` | `825d1d94905743c3523c9f9d48ac117e` | Real gap — killed by the complete sorted assessment/fingerprint/list contract tests. |
| M2 duplicate detector #37 | `app/Domain/Spells/DuplicateWarningDetector.php:65` | `CastString` | `eafd14ebe452e2059d19815706d05961` | Equivalent — `SpellAccessBuilder` supplies this field with the cast target type; removing the defensive cast cannot change a production route. |
| M2 duplicate detector #38 | `app/Domain/Spells/DuplicateWarningDetector.php:70` | `ArrayItemRemoval` | `eb5a99250296bd11b54c206dbbeafa07` | Real gap — killed by the complete sorted assessment/fingerprint/list contract tests. |
| M2 duplicate detector #39 | `app/Domain/Spells/DuplicateWarningDetector.php:78` | `Ternary` | `204edf7029fd9603f0f4a1932d8251f4` | Real gap — killed by the complete sorted assessment/fingerprint/list contract tests. |
| M2 duplicate detector #40 | `app/Domain/Spells/DuplicateWarningDetector.php:79` | `Concat` | `6950a76ca6764d84801efb1ed5e26f2b` | Real gap — killed by the complete sorted assessment/fingerprint/list contract tests. |
| M2 duplicate detector #41 | `app/Domain/Spells/DuplicateWarningDetector.php:79` | `ConcatOperandRemoval` | `9f643192363ae7b89b4872c05edd9a72` | Real gap — killed by the complete sorted assessment/fingerprint/list contract tests. |
| M2 duplicate detector #42 | `app/Domain/Spells/DuplicateWarningDetector.php:79` | `ConcatOperandRemoval` | `ce6e23a6c25f26d1ab78b32641c02e40` | Real gap — killed by the complete sorted assessment/fingerprint/list contract tests. |
| M2 duplicate detector #43 | `app/Domain/Spells/DuplicateWarningDetector.php:79` | `Concat` | `d9f02c5459811e036180900c9dd3299d` | Real gap — killed by the complete sorted assessment/fingerprint/list contract tests. |
| M2 duplicate detector #44 | `app/Domain/Spells/DuplicateWarningDetector.php:79` | `ConcatOperandRemoval` | `e44ddd0254853f1649a40c21300ad07e` | Real gap — killed by the complete sorted assessment/fingerprint/list contract tests. |
| M2 duplicate detector #45 | `app/Domain/Spells/DuplicateWarningDetector.php:79` | `Concat` | `435928b13db380493bef5640de5dc219` | Real gap — killed by the complete sorted assessment/fingerprint/list contract tests. |
| M2 duplicate detector #46 | `app/Domain/Spells/DuplicateWarningDetector.php:79` | `ConcatOperandRemoval` | `e053555a37832592cbb45d9f21198fbd` | Real gap — killed by the complete sorted assessment/fingerprint/list contract tests. |
| M2 duplicate detector #47 | `app/Domain/Spells/DuplicateWarningDetector.php:79` | `ConcatOperandRemoval` | `c2b403c2e41681c1971d4902c6a8c41a` | Real gap — killed by the complete sorted assessment/fingerprint/list contract tests. |
| M2 duplicate detector #48 | `app/Domain/Spells/DuplicateWarningDetector.php:80` | `CastString` | `ebd6216d4e00bb28f339e9ccd41f1473` | Equivalent — `SpellAccessBuilder` supplies this field with the cast target type; removing the defensive cast cannot change a production route. |
| M2 duplicate detector #49 | `app/Domain/Spells/DuplicateWarningDetector.php:88` | `Spaceship` | `e18509a042513ed026c60ca521c77118` | Real gap — killed by the complete sorted assessment/fingerprint/list contract tests. |
| M2 duplicate detector #50 | `app/Domain/Spells/DuplicateWarningDetector.php:88` | `FunctionCallRemoval` | `a00b1763d6ee6b7d92726913e01b8c87` | Real gap — killed by the complete sorted assessment/fingerprint/list contract tests. |
| M2 grant rule #1 | `app/Domain/Grants/GrantRule.php:61` | `CastString` | `d37f55a06cdd16d199271b5952b30410` | Equivalent — interpolating the accepted scalar produces the same diagnostic text with or without the explicit cast. |
| M2 grant rule #2 | `app/Domain/Grants/GrantRule.php:73` | `MatchArmRemoval` | `14491581f742b6234183c52e20c683f5` | Real gap — killed by the defaults, invalid-shape, alternative-reference, and clear-error matrix. |
| M2 grant rule #3 | `app/Domain/Grants/GrantRule.php:73` | `MatchArmRemoval` | `2e3ab678856be4034ee04b3bdc9f1456` | Real gap — killed by the defaults, invalid-shape, alternative-reference, and clear-error matrix. |
| M2 grant rule #4 | `app/Domain/Grants/GrantRule.php:73` | `MatchArmRemoval` | `90e28bc7c645962f12d1d6b4ac055c6c` | Real gap — killed by the defaults, invalid-shape, alternative-reference, and clear-error matrix. |
| M2 grant rule #5 | `app/Domain/Grants/GrantRule.php:94` | `FalseValue` | `24aae6c86dfb2d7bb484635ffd00834e` | Real gap — killed by the defaults, invalid-shape, alternative-reference, and clear-error matrix. |
| M2 grant rule #6 | `app/Domain/Grants/GrantRule.php:95` | `TrueValue` | `1dd1b77f4f07a548e8528ad5b0ecee9d` | Real gap — killed by the defaults, invalid-shape, alternative-reference, and clear-error matrix. |
| M2 grant rule #7 | `app/Domain/Grants/GrantRule.php:100` | `IncrementInteger` | `13c7d20ef0631b7b6112957b89eaab65` | Real gap — killed by the defaults, invalid-shape, alternative-reference, and clear-error matrix. |
| M2 grant rule #8 | `app/Domain/Grants/GrantRule.php:101` | `DecrementInteger` | `799a952e0524855e1131159ca3b086a3` | Real gap — killed by the defaults, invalid-shape, alternative-reference, and clear-error matrix. |
| M2 grant rule #9 | `app/Domain/Grants/GrantRule.php:106` | `MethodCallRemoval` | `3ef946b5779144bcd1f2ac7c271b6cdf` | Real gap — killed by the defaults, invalid-shape, alternative-reference, and clear-error matrix. |
| M2 grant rule #10 | `app/Domain/Grants/GrantRule.php:116` | `NotIdentical` | `f051ad3e7725cee09dc908c8e1615a78` | Real gap — killed by the defaults, invalid-shape, alternative-reference, and clear-error matrix. |
| M2 grant rule #11 | `app/Domain/Grants/GrantRule.php:122` | `NotIdentical` | `ae38a360ed63b23623767247736c633a` | Real gap — killed by the defaults, invalid-shape, alternative-reference, and clear-error matrix. |
| M2 grant rule #12 | `app/Domain/Grants/GrantRule.php:125` | `NotIdentical` | `bac84a2e9f3f8fba70d194550ac82d25` | Real gap — killed by the defaults, invalid-shape, alternative-reference, and clear-error matrix. |
| M2 grant rule #13 | `app/Domain/Grants/GrantRule.php:128` | `IfNegation` | `39dade8810cdf2fbc6e6893626ef40a6` | Real gap — killed by the defaults, invalid-shape, alternative-reference, and clear-error matrix. |
| M2 grant rule #14 | `app/Domain/Grants/GrantRule.php:150` | `DecrementInteger` | `f3aaec728bde34dc73a60488700856bc` | Equivalent — valid grant rules are only a few levels deep; JSON depth 511, 512, and 513 accept exactly the same domain inputs. |
| M2 grant rule #15 | `app/Domain/Grants/GrantRule.php:150` | `IncrementInteger` | `3f0ec30f209471f59da5923eacc96536` | Equivalent — valid grant rules are only a few levels deep; JSON depth 511, 512, and 513 accept exactly the same domain inputs. |
| M2 grant rule #16 | `app/Domain/Grants/GrantRule.php:170` | `BitwiseOr` | `f65fd24f71fd8243a6c1a0e7f13b5d4f` | Real gap — killed by the defaults, invalid-shape, alternative-reference, and clear-error matrix. |
| M2 grant rule #17 | `app/Domain/Grants/GrantRule.php:179` | `LessThan` | `f21d914bd186ddbafdc01fefe265b293` | Real gap — killed by the defaults, invalid-shape, alternative-reference, and clear-error matrix. |
| M2 grant rule #18 | `app/Domain/Grants/GrantRule.php:179` | `LogicalOr` | `99e8054045912b523ca527387357075f` | Real gap — killed by the defaults, invalid-shape, alternative-reference, and clear-error matrix. |
| M2 grant rule #19 | `app/Domain/Grants/GrantRule.php:179` | `UnwrapTrim` | `50864f0efe9a981b1f70a3144cec914f` | Real gap — killed by the defaults, invalid-shape, alternative-reference, and clear-error matrix. |
| M2 grant rule #20 | `app/Domain/Grants/GrantRule.php:179` | `LogicalOr` | `6507804b9290bef78f0e694d2fc914f1` | Real gap — killed by the defaults, invalid-shape, alternative-reference, and clear-error matrix. |
| M2 grant rule #21 | `app/Domain/Grants/GrantRule.php:179` | `LogicalAndAllSubExprNegation` | `13909e56fc1812d51b7e7169b513fa71` | Real gap — killed by the defaults, invalid-shape, alternative-reference, and clear-error matrix. |
| M2 grant rule #22 | `app/Domain/Grants/GrantRule.php:187` | `MethodCallRemoval` | `712e9dd99cbf667cca8bb9fbf02f07fc` | Real gap — killed by the defaults, invalid-shape, alternative-reference, and clear-error matrix. |
| M2 grant rule #23 | `app/Domain/Grants/GrantRule.php:205` | `NotIdentical` | `896a5bcd51941fea6bff5c786c9896f0` | Real gap — killed by the defaults, invalid-shape, alternative-reference, and clear-error matrix. |
| M2 grant rule #24 | `app/Domain/Grants/GrantRule.php:205` | `LogicalOr` | `7f3baf14d863c3e3f0c8faa66ba4e5ee` | Real gap — killed by the defaults, invalid-shape, alternative-reference, and clear-error matrix. |
| M2 grant rule #25 | `app/Domain/Grants/GrantRule.php:205` | `LogicalOr` | `2670f60b5abbccf2952ce0e59e362a41` | Real gap — killed by the defaults, invalid-shape, alternative-reference, and clear-error matrix. |
| M2 grant rule #26 | `app/Domain/Grants/GrantRule.php:205` | `LogicalOr` | `46d4a479679e1bbe91798b5483219f10` | Real gap — killed by the defaults, invalid-shape, alternative-reference, and clear-error matrix. |
| M2 grant rule #27 | `app/Domain/Grants/GrantRule.php:205` | `LogicalOrAllSubExprNegation` | `dd9b5fdebdb4aae88881f6c7ee7330a9` | Real gap — killed by the defaults, invalid-shape, alternative-reference, and clear-error matrix. |
| M2 grant rule #28 | `app/Domain/Grants/GrantRule.php:205` | `LogicalOrSingleSubExprNegation` | `527fffaa400acd672ff17b499d831fee` | Real gap — killed by the defaults, invalid-shape, alternative-reference, and clear-error matrix. |
| M2 grant rule #29 | `app/Domain/Grants/GrantRule.php:205` | `LogicalOrSingleSubExprNegation` | `cc9538cb1ad6162be970b0fdc8647216` | Real gap — killed by the defaults, invalid-shape, alternative-reference, and clear-error matrix. |
| M2 grant rule #30 | `app/Domain/Grants/GrantRule.php:206` | `NotIdentical` | `7bba56ead2e49e0f74426c369dbb8578` | Real gap — killed by the defaults, invalid-shape, alternative-reference, and clear-error matrix. |
| M2 grant rule #31 | `app/Domain/Grants/GrantRule.php:212` | `MethodCallRemoval` | `618f1f1acaba24548eb32255d2d72f15` | Real gap — killed by the defaults, invalid-shape, alternative-reference, and clear-error matrix. |
| M2 grant rule #32 | `app/Domain/Grants/GrantRule.php:213` | `MethodCallRemoval` | `6a52f12d6a3e91183aeb216c09fc1f49` | Real gap — killed by the defaults, invalid-shape, alternative-reference, and clear-error matrix. |
| M2 grant rule #33 | `app/Domain/Grants/GrantRule.php:217` | `MethodCallRemoval` | `82a6fd52fcd1d9d0468c32edd375cbf2` | Real gap — killed by the defaults, invalid-shape, alternative-reference, and clear-error matrix. |
| M2 grant rule #34 | `app/Domain/Grants/GrantRule.php:218` | `GreaterThan` | `e76d3e84b1dfd1856c1a685f335232a9` | Real gap — killed by the defaults, invalid-shape, alternative-reference, and clear-error matrix. |
| M2 grant rule #35 | `app/Domain/Grants/GrantRule.php:218` | `GreaterThanNegotiation` | `0eee74b7ada624bd8c134d8a8448db9f` | Real gap — killed by the defaults, invalid-shape, alternative-reference, and clear-error matrix. |
| M2 grant rule #36 | `app/Domain/Grants/GrantRule.php:218` | `LogicalAnd` | `78e7f5677051e31cf0cd1bfd9dcedfd3` | Real gap — killed by the defaults, invalid-shape, alternative-reference, and clear-error matrix. |
| M2 grant rule #37 | `app/Domain/Grants/GrantRule.php:218` | `LogicalAndAllSubExprNegation` | `51dc69cbc6ae27208c6cb727277b0279` | Real gap — killed by the defaults, invalid-shape, alternative-reference, and clear-error matrix. |
| M2 grant rule #38 | `app/Domain/Grants/GrantRule.php:218` | `LogicalAndNegation` | `817668b131b4fcbbf125bad891ae6604` | Real gap — killed by the defaults, invalid-shape, alternative-reference, and clear-error matrix. |
| M2 grant rule #39 | `app/Domain/Grants/GrantRule.php:218` | `LogicalAndSingleSubExprNegation` | `a8434d325787af10b0e841bfcfed41d3` | Real gap — killed by the defaults, invalid-shape, alternative-reference, and clear-error matrix. |
| M2 grant rule #40 | `app/Domain/Grants/GrantRule.php:218` | `LogicalOr` | `f61480e6fd88a5f37ad606c22f010279` | Real gap — killed by the defaults, invalid-shape, alternative-reference, and clear-error matrix. |
| M2 grant rule #41 | `app/Domain/Grants/GrantRule.php:218` | `LogicalOrAllSubExprNegation` | `1db00bfdd161af9ac2a07a1638500193` | Real gap — killed by the defaults, invalid-shape, alternative-reference, and clear-error matrix. |
| M2 grant rule #42 | `app/Domain/Grants/GrantRule.php:219` | `UnwrapTrim` | `4a80063725aeba7e74a0f0a47083b71f` | Real gap — killed by the defaults, invalid-shape, alternative-reference, and clear-error matrix. |
| M2 grant rule #43 | `app/Domain/Grants/GrantRule.php:219` | `NotIdentical` | `9bc72fd6794281648c481903d89bd854` | Real gap — killed by the defaults, invalid-shape, alternative-reference, and clear-error matrix. |
| M2 grant rule #44 | `app/Domain/Grants/GrantRule.php:219` | `LogicalAnd` | `d91ec7b5ce5a1b6ec590a9b851f49da2` | Real gap — killed by the defaults, invalid-shape, alternative-reference, and clear-error matrix. |
| M2 grant rule #45 | `app/Domain/Grants/GrantRule.php:219` | `LogicalAndAllSubExprNegation` | `8de4bf1c6229597d5974158ce4632852` | Real gap — killed by the defaults, invalid-shape, alternative-reference, and clear-error matrix. |
| M2 grant rule #46 | `app/Domain/Grants/GrantRule.php:219` | `LogicalAndNegation` | `f0b024591882eab8d3995766b61ed9cd` | Real gap — killed by the defaults, invalid-shape, alternative-reference, and clear-error matrix. |
| M2 grant rule #47 | `app/Domain/Grants/GrantRule.php:219` | `LogicalAndSingleSubExprNegation` | `2ec32df261a4ca8fb1d162b16f9f7b3a` | Real gap — killed by the defaults, invalid-shape, alternative-reference, and clear-error matrix. |
| M2 grant rule #48 | `app/Domain/Grants/GrantRule.php:220` | `UnwrapTrim` | `8310c0f291f6e38c42929f82b8ff65fb` | Real gap — killed by the defaults, invalid-shape, alternative-reference, and clear-error matrix. |
| M2 grant rule #49 | `app/Domain/Grants/GrantRule.php:220` | `LogicalAnd` | `eecf758a5200f11110678696e3dd9693` | Real gap — killed by the defaults, invalid-shape, alternative-reference, and clear-error matrix. |
| M2 grant rule #50 | `app/Domain/Grants/GrantRule.php:227` | `MethodCallRemoval` | `a0e1a6fceec875ed03188a57a64fddfe` | Real gap — killed by the defaults, invalid-shape, alternative-reference, and clear-error matrix. |
| M2 grant rule #51 | `app/Domain/Grants/GrantRule.php:228` | `MethodCallRemoval` | `beddd944c138f2ca33850dabcc9aa0db` | Real gap — killed by the defaults, invalid-shape, alternative-reference, and clear-error matrix. |
| M2 grant rule #52 | `app/Domain/Grants/GrantRule.php:229` | `MethodCallRemoval` | `631cc44e75862e4f60d0be5c9a09fa9e` | Real gap — killed by the defaults, invalid-shape, alternative-reference, and clear-error matrix. |
| M2 grant rule #53 | `app/Domain/Grants/GrantRule.php:230` | `TrueValue` | `15c4fddf902693178ee9f2b54807d322` | Real gap — killed by the defaults, invalid-shape, alternative-reference, and clear-error matrix. |
| M2 grant rule #54 | `app/Domain/Grants/GrantRule.php:230` | `MethodCallRemoval` | `9ae8494c24fc0d033b28ed04de8e11f3` | Real gap — killed by the defaults, invalid-shape, alternative-reference, and clear-error matrix. |
| M2 grant rule #55 | `app/Domain/Grants/GrantRule.php:234` | `MethodCallRemoval` | `6006d9c24a4f6f02dee9850f1d97e6ae` | Real gap — killed by the defaults, invalid-shape, alternative-reference, and clear-error matrix. |
| M2 grant rule #56 | `app/Domain/Grants/GrantRule.php:242` | `UnwrapTrim` | `e49ae09115890b0f907c4790b62eca84` | Real gap — killed by the defaults, invalid-shape, alternative-reference, and clear-error matrix. |
| M2 grant rule #57 | `app/Domain/Grants/GrantRule.php:246` | `UnwrapTrim` | `76022acc7cedfd132d88f65a4c97a418` | Real gap — killed by the defaults, invalid-shape, alternative-reference, and clear-error matrix. |
| M2 grant rule #58 | `app/Domain/Grants/GrantRule.php:256` | `UnwrapTrim` | `a3d19e2066852c8b951be3abb3774eee` | Real gap — killed by the defaults, invalid-shape, alternative-reference, and clear-error matrix. |
| M2 grant rule #59 | `app/Domain/Grants/GrantRule.php:256` | `LogicalOr` | `624257c4b99d51b840c67537453fbc15` | Real gap — killed by the defaults, invalid-shape, alternative-reference, and clear-error matrix. |
| M2 grant rule #60 | `app/Domain/Grants/GrantRule.php:260` | `UnwrapTrim` | `34422ab78cf3df3522ccd1ba6b8f6345` | Real gap — killed by the defaults, invalid-shape, alternative-reference, and clear-error matrix. |
| M2 grant rule #61 | `app/Domain/Grants/GrantRule.php:260` | `FunctionCall` | `d2f2aa395efe49d9d4186d01b0d3a5af` | Real gap — killed by the defaults, invalid-shape, alternative-reference, and clear-error matrix. |
| M2 grant rule #62 | `app/Domain/Grants/GrantRule.php:269` | `TrueValue` | `2ddfbbf286efa85098587b3af36c37e1` | Real gap — killed by the defaults, invalid-shape, alternative-reference, and clear-error matrix. |
| M2 grant rule #63 | `app/Domain/Grants/GrantRule.php:272` | `LogicalAnd` | `3f9b483a5bb39d580a0cc89177084072` | Real gap — killed by the defaults, invalid-shape, alternative-reference, and clear-error matrix. |
| M2 grant rule #64 | `app/Domain/Grants/GrantRule.php:275` | `LogicalOr` | `83ed75f4936218fdc58a9f4f82b6bcc9` | Real gap — killed by the defaults, invalid-shape, alternative-reference, and clear-error matrix. |
| M2 grant rule #65 | `app/Domain/Grants/GrantRule.php:297` | `LogicalOr` | `60f66265e4d2d3e37918a56cd92d093b` | Real gap — killed by the defaults, invalid-shape, alternative-reference, and clear-error matrix. |
| M2 grant rule #66 | `app/Domain/Grants/GrantRule.php:297` | `LogicalOr` | `3f39eacf282ad3ad68ee6ef186b34b47` | Real gap — killed by the defaults, invalid-shape, alternative-reference, and clear-error matrix. |
| M2 grant rule #67 | `app/Domain/Grants/GrantRule.php:318` | `LogicalOr` | `247a7a2a802627ef4d8de6ddf926d652` | Real gap — killed by the defaults, invalid-shape, alternative-reference, and clear-error matrix. |
| M2 grant rule #68 | `app/Domain/Grants/GrantRule.php:321` | `LogicalNot` | `150d2dbf35430cf2700f31ea60ce93af` | Real gap — killed by the defaults, invalid-shape, alternative-reference, and clear-error matrix. |
| M2 grant rule #69 | `app/Domain/Grants/GrantRule.php:321` | `LogicalOrAllSubExprNegation` | `09ed1b4e5e148fe9b1cf6da86016fe0f` | Real gap — killed by the defaults, invalid-shape, alternative-reference, and clear-error matrix. |
| M2 grant rule #70 | `app/Domain/Grants/GrantRule.php:322` | `CastString` | `adb884ec3dadfdfd4c9b665c32bba895` | Equivalent — interpolating the accepted scalar produces the same diagnostic text with or without the explicit cast. |
| M2 grant rule #71 | `app/Domain/Grants/GrantRule.php:338` | `FalseValue` | `967df4b7e73a980fd47f88d46e4ce4c7` | Real gap — killed by the defaults, invalid-shape, alternative-reference, and clear-error matrix. |
| M2 grant rule #72 | `app/Domain/Grants/GrantRule.php:341` | `Identical` | `25ba1cbb164ff7f4baf84049214f214a` | Real gap — killed by the defaults, invalid-shape, alternative-reference, and clear-error matrix. |
| M2 grant rule #73 | `app/Domain/Grants/GrantRule.php:341` | `LogicalNot` | `aba38c4ec21e77803854e915c584a4f0` | Real gap — killed by the defaults, invalid-shape, alternative-reference, and clear-error matrix. |
| M2 grant rule #74 | `app/Domain/Grants/GrantRule.php:341` | `LogicalAnd` | `cd5f9b0932e0522f2f1e6b87476f5871` | Real gap — killed by the defaults, invalid-shape, alternative-reference, and clear-error matrix. |
| M2 grant rule #75 | `app/Domain/Grants/GrantRule.php:341` | `LogicalAndAllSubExprNegation` | `652c1dd05d377e78b8135b5efd166935` | Real gap — killed by the defaults, invalid-shape, alternative-reference, and clear-error matrix. |
| M2 grant rule #76 | `app/Domain/Grants/GrantRule.php:341` | `LogicalAndNegation` | `070c1c3f4f65542aaef9347caa1440de` | Real gap — killed by the defaults, invalid-shape, alternative-reference, and clear-error matrix. |
| M2 grant rule #77 | `app/Domain/Grants/GrantRule.php:344` | `LogicalOr` | `8743dec865422c6191d03d0757f08ce2` | Real gap — killed by the defaults, invalid-shape, alternative-reference, and clear-error matrix. |
| M2 grant rule #78 | `app/Domain/Grants/GrantRule.php:344` | `LogicalAndSingleSubExprNegation` | `ef66ce9fd417094d4525cffbf65fe0c3` | Real gap — killed by the defaults, invalid-shape, alternative-reference, and clear-error matrix. |
| M2 grant rule #79 | `app/Domain/Grants/GrantRule.php:347` | `Foreach_` | `3954089cf9c55097f167e7c9719a9658` | Real gap — killed by the defaults, invalid-shape, alternative-reference, and clear-error matrix. |
| M2 grant rule #80 | `app/Domain/Grants/GrantRule.php:348` | `UnwrapTrim` | `138cf024af3a516214a6e14b4c45a003` | Real gap — killed by the defaults, invalid-shape, alternative-reference, and clear-error matrix. |
| M2 grant rule #81 | `app/Domain/Grants/GrantRule.php:348` | `LogicalOr` | `b8067ea7d57d7bd2123d9f80e58d9ae8` | Real gap — killed by the defaults, invalid-shape, alternative-reference, and clear-error matrix. |

## Additional equivalents exposed by broader after-coverage

These were not in the original 170 because their lines were previously uncovered. They are included so the final escaped list is complete.

| Target | Location | Mutator | Infection ID | Verdict |
| --- | --- | --- | --- | --- |
| M1 rules | `app/Domain/Rules/SpellSlots.php:142` | `IncrementInteger` | `c0228a2112d6ae30a9df61eb70d18465` | Equivalent — the adjacent Pact table rows selected by this boundary mutation have identical count/level values. |
| M1 rules | `app/Domain/Rules/SpellSlots.php:142` | `DecrementInteger` | `ab253b63f2bec840be9dee55496f8f2d` | Equivalent — the adjacent Pact table rows selected by this boundary mutation have identical count/level values. |
| M2 grant rule | `app/Domain/Grants/GrantRule.php:152` | `DecrementInteger` | `7c0ae4ce3761163eaf8cf614c8b253f9` | Equivalent — exception type/message/cause are unchanged and no domain or API consumer reads this internal exception code. |
| M2 grant rule | `app/Domain/Grants/GrantRule.php:152` | `IncrementInteger` | `060528c9be07c26810e6e7a028d9029a` | Equivalent — exception type/message/cause are unchanged and no domain or API consumer reads this internal exception code. |
| M2 grant rule | `app/Domain/Grants/GrantRule.php:326` | `CastString` | `26d666e30b563367fef827d979e84f45` | Equivalent — interpolating the accepted scalar produces the same diagnostic text with or without the explicit cast. |

## Verification

The final verification outputs are recorded in `docs/E2E-PROGRESS.md`. The direct seeded report confirmed caster level 6, slots 4/3/3, PB +3, every class maximum 1, Mage Hand wasteful, Entangle none, and Detect Magic capability/ritual-only/non-selection/non-counting.

