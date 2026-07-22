export type RollMode = 'normal' | 'advantage' | 'disadvantage';
export type DamageProfile = 'basic' | 'sorcerous-burst' | 'chromatic-orb';

export interface DiceConfig {
    profile: DamageProfile;
    armorClass: number;
    attackBonus: number;
    rollMode: RollMode;
    halflingLuck: boolean;
    luckyFeat: boolean;
    elvenAccuracy: boolean;
    bless: boolean;
    bane: boolean;
    elementalAdept: boolean;
    resistance: boolean;
    vulnerability: boolean;
    basicDice: number;
    basicDieSize: number;
    damageModifier: number;
    sorcerousBaseDice: number;
    explosionCap: number;
    chromaticSlotLevel: number;
}

export interface AttackProbabilities {
    miss: number;
    normalHit: number;
    criticalHit: number;
    totalHit: number;
}

export interface ExactResult extends AttackProbabilities {
    expectedDamage: number;
    expectedDamageOnAnyHit: number;
    expectedTargetsHit: number;
    chanceToLeap: number;
    normalDamage: number;
    criticalDamage: number;
    normalLeapChance: number;
    criticalLeapChance: number;
    expectedSorcerousExtraDice: number;
}

export interface DieTrace {
    initial: number;
    reroll: number | null;
    final: number;
}

export interface DamageDieTrace {
    raw: number;
    value: number;
    added: boolean;
}

export interface RolledAttack {
    d20: DieTrace[];
    chosenD20: number;
    bless: number | null;
    bane: number | null;
    total: number;
    outcome: 'miss' | 'hit' | 'critical';
    damageDice: DamageDieTrace[];
    damageModifier: number;
    damageBeforeDefense: number;
    damage: number;
    triggeredLeap: boolean;
}

export interface SeededRollResult {
    token: string;
    attacks: RolledAttack[];
    totalDamage: number;
    stopReason: 'miss' | 'no-leap' | 'leap-limit' | 'single-attack';
}

interface DamageStats {
    expected: number;
}

type Distribution = Map<number, number>;

function addProbability(distribution: Distribution, value: number, probability: number): void {
    distribution.set(value, (distribution.get(value) ?? 0) + probability);
}

function effectiveMode(config: DiceConfig): RollMode {
    const circumstance = config.rollMode === 'advantage' ? 1 : config.rollMode === 'disadvantage' ? -1 : 0;
    const withLucky = circumstance + (config.luckyFeat ? 1 : 0);

    return withLucky > 0 ? 'advantage' : withLucky < 0 ? 'disadvantage' : 'normal';
}

function singleD20(halflingLuck: boolean): Distribution {
    const result = new Map<number, number>();
    if (!halflingLuck) {
        for (let face = 1; face <= 20; face++) result.set(face, 1 / 20);
        return result;
    }

    result.set(1, 1 / 400);
    for (let face = 2; face <= 20; face++) result.set(face, 21 / 400);
    return result;
}

function selectedD20(config: DiceConfig): Distribution {
    const oneDie = singleD20(config.halflingLuck);
    const mode = effectiveMode(config);
    const diceCount = mode === 'normal' ? 1 : mode === 'advantage' && config.elvenAccuracy ? 3 : 2;
    let states: Array<{ faces: number[]; probability: number }> = [{ faces: [], probability: 1 }];

    for (let die = 0; die < diceCount; die++) {
        const next: typeof states = [];
        for (const state of states) {
            for (const [face, probability] of oneDie) {
                next.push({ faces: [...state.faces, face], probability: state.probability * probability });
            }
        }
        states = next;
    }

    const result = new Map<number, number>();
    for (const state of states) {
        const selected = mode === 'disadvantage' ? Math.min(...state.faces) : Math.max(...state.faces);
        addProbability(result, selected, state.probability);
    }
    return result;
}

function d4Modifier(config: DiceConfig): Distribution {
    let result = new Map<number, number>([[0, 1]]);
    for (const sign of [config.bless ? 1 : 0, config.bane ? -1 : 0]) {
        if (sign === 0) continue;
        const next = new Map<number, number>();
        for (const [current, currentProbability] of result) {
            for (let face = 1; face <= 4; face++) {
                addProbability(next, current + sign * face, currentProbability / 4);
            }
        }
        result = next;
    }
    return result;
}

export function attackProbabilities(config: DiceConfig): AttackProbabilities {
    let miss = 0;
    let normalHit = 0;
    let criticalHit = 0;
    const modifiers = d4Modifier(config);

    for (const [natural, naturalProbability] of selectedD20(config)) {
        for (const [d4, modifierProbability] of modifiers) {
            const probability = naturalProbability * modifierProbability;
            if (natural === 1) miss += probability;
            else if (natural === 20) criticalHit += probability;
            else if (natural + config.attackBonus + d4 >= config.armorClass) normalHit += probability;
            else miss += probability;
        }
    }

    return { miss, normalHit, criticalHit, totalHit: normalHit + criticalHit };
}

function dieValue(raw: number, elementalAdept: boolean): number {
    return elementalAdept && raw === 1 ? 2 : raw;
}

function adjustedDamage(total: number, config: DiceConfig): number {
    let result = Math.max(0, total);
    if (config.resistance && !config.elementalAdept) result = Math.floor(result / 2);
    if (config.vulnerability) result *= 2;
    return result;
}

function ordinaryDamageStats(
    count: number,
    size: number,
    modifier: number,
    config: DiceConfig,
): DamageStats {
    let sums = new Map<number, number>([[0, 1]]);
    for (let die = 0; die < count; die++) {
        const next = new Map<number, number>();
        for (const [sum, probability] of sums) {
            for (let face = 1; face <= size; face++) {
                addProbability(next, sum + dieValue(face, config.elementalAdept), probability / size);
            }
        }
        sums = next;
    }

    let expected = 0;
    for (const [sum, probability] of sums) {
        expected += adjustedDamage(sum + modifier, config) * probability;
    }
    return { expected };
}

function combination(n: number, k: number): number {
    if (k < 0 || k > n) return 0;
    let result = 1;
    for (let i = 1; i <= k; i++) result = result * (n - k + i) / i;
    return result;
}

export function sorcerousExpectedExtraDice(baseDice: number, cap: number): number {
    if (baseDice <= 0 || cap <= 0) return 0;
    const success = 1 / 8;
    const failure = 7 / 8;
    let expected = 0;
    let cumulativeBelow = 0;

    for (let k = 1; k <= cap; k++) {
        const successes = k - 1;
        cumulativeBelow += combination(successes + baseDice - 1, successes)
            * failure ** baseDice
            * success ** successes;
        expected += 1 - cumulativeBelow;
    }
    return expected;
}

export function sorcerousExpectedRawDamage(
    baseDice: number,
    cap: number,
    elementalAdept: boolean,
): number {
    const meanD8 = elementalAdept ? 37 / 8 : 9 / 2;
    return (baseDice + sorcerousExpectedExtraDice(baseDice, cap)) * meanD8;
}

function sorcerousDamageStats(baseDice: number, cap: number, config: DiceConfig): DamageStats {
    type State = { remaining: number; extras: number; sum: number; probability: number };
    let states: State[] = [{ remaining: baseDice, extras: 0, sum: 0, probability: 1 }];
    const finished = new Map<number, number>();

    while (states.length > 0) {
        const nextByKey = new Map<string, State>();
        for (const state of states) {
            if (state.remaining === 0) {
                addProbability(finished, state.sum, state.probability);
                continue;
            }
            for (let face = 1; face <= 8; face++) {
                const addsDie = face === 8 && state.extras < cap;
                const next: State = {
                    remaining: state.remaining - 1 + (addsDie ? 1 : 0),
                    extras: state.extras + (addsDie ? 1 : 0),
                    sum: state.sum + dieValue(face, config.elementalAdept),
                    probability: state.probability / 8,
                };
                const key = `${next.remaining}:${next.extras}:${next.sum}`;
                const existing = nextByKey.get(key);
                if (existing) existing.probability += next.probability;
                else nextByKey.set(key, next);
            }
        }
        states = [...nextByKey.values()];
    }

    let expected = 0;
    for (const [sum, probability] of finished) expected += adjustedDamage(sum, config) * probability;
    return { expected };
}

export function chromaticLeapChance(dice: number, elementalAdept: boolean): number {
    let uniqueStates = new Map<number, number>([[0, 1]]);
    for (let die = 0; die < dice; die++) {
        const next = new Map<number, number>();
        for (const [mask, probability] of uniqueStates) {
            for (let face = 1; face <= 8; face++) {
                const value = dieValue(face, elementalAdept);
                const bit = 1 << value;
                if ((mask & bit) !== 0) continue;
                addProbability(next, mask | bit, probability / 8);
            }
        }
        uniqueStates = next;
    }
    return 1 - [...uniqueStates.values()].reduce((sum, probability) => sum + probability, 0);
}

function profileDamageStats(config: DiceConfig, critical: boolean): DamageStats {
    const multiplier = critical ? 2 : 1;
    if (config.profile === 'sorcerous-burst') {
        return sorcerousDamageStats(config.sorcerousBaseDice * multiplier, config.explosionCap, config);
    }
    if (config.profile === 'chromatic-orb') {
        return ordinaryDamageStats((config.chromaticSlotLevel + 2) * multiplier, 8, 0, config);
    }
    return ordinaryDamageStats(config.basicDice * multiplier, config.basicDieSize, config.damageModifier, config);
}

export function exactResult(config: DiceConfig): ExactResult {
    const attacks = attackProbabilities(config);
    const normal = profileDamageStats(config, false).expected;
    const critical = profileDamageStats(config, true).expected;
    const oneAttackDamage = attacks.normalHit * normal + attacks.criticalHit * critical;
    const hitChance = attacks.totalHit;
    let expectedDamage = oneAttackDamage;
    let expectedTargetsHit = hitChance;
    let chanceToLeap = 0;
    let normalLeapChance = 0;
    let criticalLeapChance = 0;

    if (config.profile === 'chromatic-orb') {
        normalLeapChance = chromaticLeapChance(config.chromaticSlotLevel + 2, config.elementalAdept);
        criticalLeapChance = chromaticLeapChance((config.chromaticSlotLevel + 2) * 2, config.elementalAdept);
        chanceToLeap = attacks.normalHit * normalLeapChance + attacks.criticalHit * criticalLeapChance;
        let reach = 1;
        expectedDamage = 0;
        expectedTargetsHit = 0;
        for (let attack = 0; attack <= config.chromaticSlotLevel; attack++) {
            expectedDamage += reach * oneAttackDamage;
            expectedTargetsHit += reach * hitChance;
            reach *= chanceToLeap;
        }
    }

    return {
        ...attacks,
        expectedDamage,
        expectedDamageOnAnyHit: hitChance === 0 ? 0 : oneAttackDamage / hitChance,
        expectedTargetsHit,
        chanceToLeap,
        normalDamage: normal,
        criticalDamage: critical,
        normalLeapChance,
        criticalLeapChance,
        expectedSorcerousExtraDice: config.profile === 'sorcerous-burst'
            ? sorcerousExpectedExtraDice(config.sorcerousBaseDice, config.explosionCap)
            : 0,
    };
}

function seedHash(seed: string): number {
    let hash = 2166136261;
    for (let index = 0; index < seed.length; index++) {
        hash ^= seed.charCodeAt(index);
        hash = Math.imul(hash, 16777619);
    }
    return hash >>> 0;
}

function seededRandom(seed: string): () => number {
    let state = seedHash(seed);
    return () => {
        state += 0x6D2B79F5;
        let value = state;
        value = Math.imul(value ^ value >>> 15, value | 1);
        value ^= value + Math.imul(value ^ value >>> 7, value | 61);
        return ((value ^ value >>> 14) >>> 0) / 4294967296;
    };
}

function rollDie(random: () => number, size: number): number {
    return Math.floor(random() * size) + 1;
}

function rollWithHalflingLuck(random: () => number, size: number, halflingLuck: boolean): DieTrace {
    const initial = rollDie(random, size);
    const reroll = halflingLuck && initial === 1 ? rollDie(random, size) : null;
    return { initial, reroll, final: reroll ?? initial };
}

function rollAttack(config: DiceConfig, random: () => number): Omit<RolledAttack, 'damageDice' | 'damageModifier' | 'damageBeforeDefense' | 'damage' | 'triggeredLeap'> {
    const mode = effectiveMode(config);
    const d20: DieTrace[] = [rollWithHalflingLuck(random, 20, config.halflingLuck)];
    if (mode !== 'normal') d20.push(rollWithHalflingLuck(random, 20, config.halflingLuck));

    let chosenD20: number;
    if (mode === 'advantage' && config.elvenAccuracy) {
        const lowerIndex = (d20[0]?.final ?? 0) <= (d20[1]?.final ?? 0) ? 0 : 1;
        const replacement = rollWithHalflingLuck(random, 20, config.halflingLuck);
        d20.push(replacement);
        const keptIndex = lowerIndex === 0 ? 1 : 0;
        chosenD20 = Math.max(d20[keptIndex]?.final ?? 0, replacement.final);
    } else {
        const finals = d20.map((die) => die.final);
        chosenD20 = mode === 'disadvantage' ? Math.min(...finals) : Math.max(...finals);
    }

    const bless = config.bless ? rollDie(random, 4) : null;
    const bane = config.bane ? rollDie(random, 4) : null;
    const total = chosenD20 + config.attackBonus + (bless ?? 0) - (bane ?? 0);
    const outcome = chosenD20 === 1 || (chosenD20 !== 20 && total < config.armorClass)
        ? 'miss'
        : chosenD20 === 20 ? 'critical' : 'hit';
    return { d20, chosenD20, bless, bane, total, outcome };
}

function rollOrdinaryDamage(
    count: number,
    size: number,
    modifier: number,
    config: DiceConfig,
    random: () => number,
): Pick<RolledAttack, 'damageDice' | 'damageModifier' | 'damageBeforeDefense' | 'damage'> {
    const damageDice: DamageDieTrace[] = [];
    for (let die = 0; die < count; die++) {
        const raw = rollDie(random, size);
        damageDice.push({ raw, value: dieValue(raw, config.elementalAdept), added: false });
    }
    const damageBeforeDefense = Math.max(0, damageDice.reduce((sum, die) => sum + die.value, modifier));
    return {
        damageDice,
        damageModifier: modifier,
        damageBeforeDefense,
        damage: adjustedDamage(damageBeforeDefense, config),
    };
}

function rollSorcerousDamage(
    baseDice: number,
    config: DiceConfig,
    random: () => number,
): Pick<RolledAttack, 'damageDice' | 'damageModifier' | 'damageBeforeDefense' | 'damage'> {
    const damageDice: DamageDieTrace[] = [];
    let remaining = baseDice;
    let extras = 0;
    while (remaining > 0) {
        remaining--;
        const raw = rollDie(random, 8);
        const added = damageDice.length >= baseDice;
        damageDice.push({ raw, value: dieValue(raw, config.elementalAdept), added });
        if (raw === 8 && extras < config.explosionCap) {
            extras++;
            remaining++;
        }
    }
    const damageBeforeDefense = damageDice.reduce((sum, die) => sum + die.value, 0);
    return {
        damageDice,
        damageModifier: 0,
        damageBeforeDefense,
        damage: adjustedDamage(damageBeforeDefense, config),
    };
}

export function seededRoll(config: DiceConfig, token: string): SeededRollResult {
    const random = seededRandom(token);
    const attacks: RolledAttack[] = [];
    const maximumAttacks = config.profile === 'chromatic-orb' ? config.chromaticSlotLevel + 1 : 1;
    let stopReason: SeededRollResult['stopReason'] = config.profile === 'chromatic-orb' ? 'leap-limit' : 'single-attack';

    for (let index = 0; index < maximumAttacks; index++) {
        const attack = rollAttack(config, random);
        if (attack.outcome === 'miss') {
            attacks.push({
                ...attack,
                damageDice: [],
                damageModifier: 0,
                damageBeforeDefense: 0,
                damage: 0,
                triggeredLeap: false,
            });
            stopReason = 'miss';
            break;
        }

        const multiplier = attack.outcome === 'critical' ? 2 : 1;
        const rolledDamage = config.profile === 'sorcerous-burst'
            ? rollSorcerousDamage(config.sorcerousBaseDice * multiplier, config, random)
            : config.profile === 'chromatic-orb'
                ? rollOrdinaryDamage((config.chromaticSlotLevel + 2) * multiplier, 8, 0, config, random)
                : rollOrdinaryDamage(config.basicDice * multiplier, config.basicDieSize, config.damageModifier, config, random);
        const values = rolledDamage.damageDice.map((die) => die.value);
        const matched = config.profile === 'chromatic-orb' && new Set(values).size < values.length;
        const canLeap = index < maximumAttacks - 1;
        const triggeredLeap = matched && canLeap;
        attacks.push({ ...attack, ...rolledDamage, triggeredLeap });

        if (config.profile === 'chromatic-orb' && !matched) {
            stopReason = 'no-leap';
            break;
        }
        if (config.profile === 'chromatic-orb' && !canLeap) break;
    }

    return {
        token,
        attacks,
        totalDamage: attacks.reduce((sum, attack) => sum + attack.damage, 0),
        stopReason,
    };
}
