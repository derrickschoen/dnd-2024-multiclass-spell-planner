<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { exactResult, seededRoll } from '@/lib/dice';
import type { DamageProfile, DiceConfig, RollMode, SeededRollResult } from '@/lib/dice';
import type { WorkspaceSlot } from '@/types';

const props = defineProps<{
    slots: WorkspaceSlot[];
    characterLevel: number;
    abilities: Record<string, number>;
}>();

const preset = ref('manual-sorcerous-burst');
const armorClass = ref(15);
const manualAttackBonus = ref(5);
const manualExplosionCap = ref(3);
const chromaticSlotLevel = ref(1);
const basicDice = ref(1);
const basicDieSize = ref(8);
const damageModifier = ref(0);
const basicAttackAbility = ref('dexterity');
const rollMode = ref<RollMode>('normal');
const halflingLuck = ref(false);
const luckyFeat = ref(false);
const elvenAccuracy = ref(false);
const bless = ref(false);
const bane = ref(false);
const elementalAdept = ref(false);
const resistance = ref(false);
const vulnerability = ref(false);
const seed = ref('table-night');
const sequence = ref(1);
const rolled = ref<SeededRollResult | null>(null);

const selectedSpellOptions = computed(() => props.slots.filter((slot) =>
    slot.spell_edition === '2024'
    && slot.attack_bonus !== null
    && ['Sorcerous Burst', 'Chromatic Orb'].includes(slot.spell_name ?? ''),
));
const selectedSlot = computed(() => {
    if (!preset.value.startsWith('slot:')) return null;
    const id = Number(preset.value.slice(5));
    return selectedSpellOptions.value.find((slot) => slot.id === id) ?? null;
});
const profile = computed<DamageProfile>(() => {
    if (selectedSlot.value?.spell_name === 'Sorcerous Burst' || preset.value === 'manual-sorcerous-burst') {
        return 'sorcerous-burst';
    }
    if (selectedSlot.value?.spell_name === 'Chromatic Orb' || preset.value === 'manual-chromatic-orb') {
        return 'chromatic-orb';
    }
    return 'basic';
});
const sorcerousBaseDice = computed(() => props.characterLevel >= 17 ? 4 : props.characterLevel >= 11 ? 3 : props.characterLevel >= 5 ? 2 : 1);
const selectedAbilityModifier = computed(() => {
    const ability = selectedSlot.value?.ability;
    if (!ability) return null;
    return Math.floor(((props.abilities[ability] ?? 10) - 10) / 2);
});
const netAdvantage = computed(() => {
    const score = (rollMode.value === 'advantage' ? 1 : rollMode.value === 'disadvantage' ? -1 : 0)
        + (luckyFeat.value ? 1 : 0);
    return score > 0;
});
const elvenAccuracyEligible = computed(() => profile.value !== 'basic'
    || ['dexterity', 'intelligence', 'wisdom', 'charisma'].includes(basicAttackAbility.value));

function integer(value: number, minimum: number, maximum: number): number {
    return Math.min(maximum, Math.max(minimum, Math.trunc(Number.isFinite(value) ? value : minimum)));
}

const config = computed<DiceConfig>(() => ({
    profile: profile.value,
    armorClass: integer(armorClass.value, 1, 40),
    attackBonus: selectedSlot.value?.attack_bonus ?? integer(manualAttackBonus.value, -10, 30),
    rollMode: rollMode.value,
    halflingLuck: halflingLuck.value,
    luckyFeat: luckyFeat.value,
    elvenAccuracy: elvenAccuracy.value && netAdvantage.value && elvenAccuracyEligible.value,
    bless: bless.value,
    bane: bane.value,
    elementalAdept: elementalAdept.value,
    resistance: resistance.value,
    vulnerability: vulnerability.value,
    basicDice: integer(basicDice.value, 1, 20),
    basicDieSize: integer(basicDieSize.value, 2, 100),
    damageModifier: integer(damageModifier.value, -20, 40),
    sorcerousBaseDice: sorcerousBaseDice.value,
    explosionCap: integer(selectedAbilityModifier.value ?? manualExplosionCap.value, 0, 10),
    chromaticSlotLevel: integer(chromaticSlotLevel.value, 1, 9),
}));
const exact = computed(() => exactResult(config.value));

watch(config, () => { rolled.value = null; });

function percent(value: number): string {
    return `${(value * 100).toFixed(1)}%`;
}

function decimal(value: number): string {
    return value.toFixed(2);
}

function signed(value: number): string {
    return value >= 0 ? `+${value}` : String(value);
}

function roll(): void {
    const token = `${seed.value.trim() || 'table'}:${sequence.value}`;
    rolled.value = seededRoll(config.value, token);
    sequence.value++;
}

function d20Label(initial: number, reroll: number | null): string {
    return reroll === null ? String(initial) : `${initial}→${reroll}`;
}

function damageLabel(raw: number, value: number, added: boolean): string {
    return `${added ? '+' : ''}${raw === value ? raw : `${raw}→${value}`}`;
}
</script>

<template>
    <section class="panel border-violet-300 dark:border-violet-900" aria-labelledby="dice-calculator-title">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 id="dice-calculator-title" class="section-title">At-the-table dice calculator</h2>
                <p class="mt-1 text-sm text-stone-600 dark:text-stone-400">Exact odds, quick live rolls, and a replay token for every result.</p>
            </div>
            <span class="status-badge status-ok">No simulation</span>
        </div>

        <div class="mt-4 grid gap-4 xl:grid-cols-[minmax(0,1fr)_minmax(20rem,0.75fr)]">
            <div class="space-y-4">
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <label class="filter-label sm:col-span-2">Attack profile
                        <select v-model="preset" class="field mt-1 w-full">
                            <option value="manual-sorcerous-burst">Sorcerous Burst (2024)</option>
                            <option value="manual-chromatic-orb">Chromatic Orb (2024)</option>
                            <option value="basic">Basic attack</option>
                            <optgroup v-if="selectedSpellOptions.length" label="Selected spells — uses character bonus">
                                <option v-for="slot in selectedSpellOptions" :key="slot.id" :value="`slot:${slot.id}`">
                                    {{ slot.spell_name }} · {{ slot.source }} · {{ signed(slot.attack_bonus ?? 0) }}
                                </option>
                            </optgroup>
                        </select>
                    </label>
                    <label class="filter-label">Target AC<input v-model.number="armorClass" class="field mt-1 w-full" type="number" min="1" max="40" /></label>
                    <label class="filter-label">Attack bonus
                        <input v-model.number="manualAttackBonus" class="field mt-1 w-full" type="number" min="-10" max="30" :disabled="selectedSlot !== null" />
                        <span v-if="selectedSlot" class="mt-1 block normal-case text-stone-500">From {{ selectedSlot.source }}</span>
                    </label>

                    <label class="filter-label">d20 mode
                        <select v-model="rollMode" class="field mt-1 w-full"><option value="normal">Normal</option><option value="advantage">Advantage</option><option value="disadvantage">Disadvantage</option></select>
                    </label>
                    <label v-if="profile === 'chromatic-orb'" class="filter-label">Spell slot level<input v-model.number="chromaticSlotLevel" class="field mt-1 w-full" type="number" min="1" max="9" /></label>
                    <label v-if="profile === 'sorcerous-burst'" class="filter-label">Added-d8 cap
                        <input v-model.number="manualExplosionCap" class="field mt-1 w-full" type="number" min="0" max="10" :disabled="selectedAbilityModifier !== null" />
                        <span class="mt-1 block normal-case text-stone-500">{{ sorcerousBaseDice }} base d8<template v-if="selectedAbilityModifier !== null"> · {{ selectedSlot?.ability?.slice(0, 3).toUpperCase() }} modifier</template></span>
                    </label>
                    <template v-if="profile === 'basic'">
                        <label class="filter-label">Attack ability<select v-model="basicAttackAbility" class="field mt-1 w-full"><option value="strength">Strength</option><option value="dexterity">Dexterity</option><option value="intelligence">Intelligence</option><option value="wisdom">Wisdom</option><option value="charisma">Charisma</option></select></label>
                        <label class="filter-label">Damage dice<input v-model.number="basicDice" class="field mt-1 w-full" type="number" min="1" max="20" /></label>
                        <label class="filter-label">Die size<select v-model.number="basicDieSize" class="field mt-1 w-full"><option v-for="size in [4, 6, 8, 10, 12, 20, 100]" :key="size" :value="size">d{{ size }}</option></select></label>
                        <label class="filter-label">Damage modifier<input v-model.number="damageModifier" class="field mt-1 w-full" type="number" min="-20" max="40" /></label>
                    </template>
                </div>

                <div class="grid gap-3 rounded-md border border-stone-200 p-3 sm:grid-cols-2 lg:grid-cols-3 dark:border-stone-800">
                    <label class="flex items-start gap-2 text-sm"><input v-model="halflingLuck" class="mt-1" type="checkbox" /><span><strong>Halfling Luck</strong><span class="block text-xs text-stone-500">Reroll each d20 natural 1 once.</span></span></label>
                    <label class="flex items-start gap-2 text-sm"><input v-model="luckyFeat" class="mt-1" type="checkbox" /><span><strong>Lucky feat: spend point</strong><span class="block text-xs text-stone-500">Adds Advantage to the roll.</span></span></label>
                    <label class="flex items-start gap-2 text-sm"><input v-model="elvenAccuracy" class="mt-1" type="checkbox" :disabled="!netAdvantage || !elvenAccuracyEligible" /><span><strong>Elven Accuracy</strong> <span class="status-badge status-warning align-middle">Legacy · Xanathar's</span><span class="block text-xs text-stone-500">Rerolls the lower Advantage die; requires Dex/Int/Wis/Cha.</span></span></label>
                    <label class="flex items-start gap-2 text-sm"><input v-model="bless" class="mt-1" type="checkbox" /><span><strong>Bless +d4</strong><span class="block text-xs text-stone-500">Rolled on every attack.</span></span></label>
                    <label class="flex items-start gap-2 text-sm"><input v-model="bane" class="mt-1" type="checkbox" /><span><strong>Bane −d4</strong><span class="block text-xs text-stone-500">Rolled on every attack.</span></span></label>
                    <label class="flex items-start gap-2 text-sm"><input v-model="elementalAdept" class="mt-1" type="checkbox" /><span><strong>Elemental Adept</strong><span class="block text-xs text-stone-500">Chosen type: 1→2 and ignore Resistance.</span></span></label>
                    <label class="flex items-start gap-2 text-sm"><input v-model="resistance" class="mt-1" type="checkbox" /><span><strong>Resistance</strong><span class="block text-xs text-stone-500">Floor half after totaling.</span></span></label>
                    <label class="flex items-start gap-2 text-sm"><input v-model="vulnerability" class="mt-1" type="checkbox" /><span><strong>Vulnerability</strong><span class="block text-xs text-stone-500">Double after Resistance.</span></span></label>
                </div>
            </div>

            <div class="space-y-3">
                <dl class="grid grid-cols-2 gap-2">
                    <div class="metric"><dt>Hit chance</dt><dd>{{ percent(exact.totalHit) }}</dd></div>
                    <div class="metric"><dt>Critical chance</dt><dd>{{ percent(exact.criticalHit) }}</dd></div>
                    <div class="metric"><dt>Expected damage / cast</dt><dd>{{ decimal(exact.expectedDamage) }}</dd></div>
                    <div class="metric"><dt>{{ profile === 'chromatic-orb' ? 'Expected targets hit' : 'Damage / hit' }}</dt><dd>{{ decimal(profile === 'chromatic-orb' ? exact.expectedTargetsHit : exact.expectedDamageOnAnyHit) }}</dd></div>
                </dl>
                <div v-if="profile === 'sorcerous-burst'" class="rounded-md bg-stone-100 p-3 text-xs dark:bg-stone-800">
                    {{ sorcerousBaseDice }}d8 base · {{ decimal(exact.expectedSorcerousExtraDice) }} expected added d8s · critical doubles base dice, not the cap.
                </div>
                <div v-if="profile === 'chromatic-orb'" class="rounded-md bg-stone-100 p-3 text-xs dark:bg-stone-800">
                    {{ chromaticSlotLevel + 2 }}d8 · {{ percent(exact.chanceToLeap) }} chance the first attack both hits and leaps · at most {{ chromaticSlotLevel }} leap{{ chromaticSlotLevel === 1 ? '' : 's' }}.
                </div>
                <div class="grid grid-cols-[1fr_5rem] gap-2">
                    <label class="filter-label">Seed<input v-model="seed" class="field mt-1 w-full" maxlength="80" /></label>
                    <label class="filter-label">Next #<input v-model.number="sequence" class="field mt-1 w-full" type="number" min="1" /></label>
                </div>
                <button type="button" class="button-primary w-full py-3 text-base" @click="roll">Roll attack + damage</button>
            </div>
        </div>

        <div v-if="rolled" class="mt-4 rounded-lg border-2 border-violet-300 bg-violet-50 p-4 dark:border-violet-900 dark:bg-violet-950/30" aria-live="polite">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h3 class="font-semibold">{{ rolled.totalDamage }} total damage · {{ rolled.attacks.filter((attack) => attack.outcome !== 'miss').length }} target{{ rolled.attacks.filter((attack) => attack.outcome !== 'miss').length === 1 ? '' : 's' }} hit</h3>
                <code class="rounded bg-white px-2 py-1 text-xs dark:bg-stone-950">Replay {{ rolled.token }}</code>
            </div>
            <ol class="mt-3 grid gap-2 lg:grid-cols-2">
                <li v-for="(attack, index) in rolled.attacks" :key="index" class="rounded-md border border-stone-300 bg-white p-3 text-sm dark:border-stone-700 dark:bg-stone-900">
                    <div class="flex items-center justify-between gap-2"><strong>Target {{ index + 1 }}</strong><span class="status-badge" :class="attack.outcome === 'miss' ? 'status-warning' : 'status-ok'">{{ attack.outcome }}</span></div>
                    <p class="mt-2">d20 {{ attack.d20.map((die) => d20Label(die.initial, die.reroll)).join(', ') }} → <strong>{{ attack.chosenD20 }}</strong> {{ signed(config.attackBonus) }}<template v-if="attack.bless"> + {{ attack.bless }}</template><template v-if="attack.bane"> − {{ attack.bane }}</template> = {{ attack.total }}</p>
                    <p v-if="attack.outcome !== 'miss'" class="mt-1">Damage [{{ attack.damageDice.map((die) => damageLabel(die.raw, die.value, die.added)).join(', ') }}]<template v-if="attack.damageModifier"> {{ signed(attack.damageModifier) }}</template> = {{ attack.damageBeforeDefense }} → <strong>{{ attack.damage }}</strong><template v-if="attack.triggeredLeap"> · leaps</template></p>
                </li>
            </ol>
            <p class="mt-2 text-xs text-stone-600 dark:text-stone-400">Stopped: {{ rolled.stopReason.replaceAll('-', ' ') }}. To replay, enter the token's text before the final colon as Seed and its final number as Next #. A 1→2 entry is Elemental Adept; a leading + is a Sorcerous Burst added die.</p>
        </div>

        <details class="mt-4 text-xs text-stone-600 dark:text-stone-400">
            <summary class="cursor-pointer font-semibold text-stone-800 dark:text-stone-200">Composition and table assumptions</summary>
            <p class="mt-2">Order: net Advantage/Disadvantage → Halfling/Elven rerolls → Bless/Bane → critical doubles initial dice → Elemental Adept changes damage-die 1s to 2 → spell triggers → total → Resistance → Vulnerability.</p>
            <p class="mt-1">Chromatic Orb uses the same AC and checked attack effects for every new target, never retargets a creature, and stops after the slot-level leap limit. Checking Lucky assumes a Luck Point is available for each attack in a chain. Treating 1 as 2 before Chromatic Orb matching, and letting critical Sorcerous Burst base dice trigger additions under one unchanged cap, are explicit cross-feature interpretations.</p>
        </details>
    </section>
</template>
