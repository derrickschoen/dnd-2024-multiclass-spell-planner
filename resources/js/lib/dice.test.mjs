import assert from 'node:assert/strict';
import test from 'node:test';
import {
    attackProbabilities,
    chromaticLeapChance,
    exactResult,
    seededRoll,
    sorcerousExpectedExtraDice,
    sorcerousExpectedRawDamage,
} from './dice.ts';

function config(changes = {}) {
    return {
        profile: 'basic',
        armorClass: 15,
        attackBonus: 5,
        rollMode: 'normal',
        halflingLuck: false,
        luckyFeat: false,
        elvenAccuracy: false,
        bless: false,
        bane: false,
        elementalAdept: false,
        resistance: false,
        vulnerability: false,
        basicDice: 1,
        basicDieSize: 8,
        damageModifier: 0,
        sorcerousBaseDice: 1,
        explosionCap: 3,
        chromaticSlotLevel: 1,
        ...changes,
    };
}

function close(actual, expected, epsilon = 1e-12) {
    assert.ok(Math.abs(actual - expected) <= epsilon, `${actual} should equal ${expected}`);
}

test('d20 modes, criticals, Lucky cancellation, and Elven Accuracy are exact', () => {
    const normal = attackProbabilities(config());
    close(normal.normalHit, 0.5);
    close(normal.criticalHit, 0.05);
    close(normal.totalHit, 0.55);

    const advantage = attackProbabilities(config({ rollMode: 'advantage' }));
    close(advantage.totalHit, 1 - 0.45 ** 2);
    close(advantage.criticalHit, 1 - 0.95 ** 2);

    const disadvantage = attackProbabilities(config({ rollMode: 'disadvantage' }));
    close(disadvantage.totalHit, 0.55 ** 2);
    close(disadvantage.criticalHit, 0.05 ** 2);

    const cancelledByLucky = attackProbabilities(config({ rollMode: 'disadvantage', luckyFeat: true }));
    close(cancelledByLucky.totalHit, normal.totalHit);

    const elvenAccuracy = attackProbabilities(config({ rollMode: 'advantage', elvenAccuracy: true }));
    close(elvenAccuracy.totalHit, 1 - 0.45 ** 3);
    close(elvenAccuracy.criticalHit, 1 - 0.95 ** 3);
});

test('Halfling Luck and Bless/Bane modify the attack distribution without overriding natural 1 or 20', () => {
    const halfling = attackProbabilities(config({ halflingLuck: true }));
    close(halfling.totalHit, 231 / 400);
    close(halfling.criticalHit, 21 / 400);
    close(halfling.miss + halfling.normalHit + halfling.criticalHit, 1);

    const blessed = attackProbabilities(config({ bless: true }));
    close(blessed.totalHit, 0.675);
    close(blessed.criticalHit, 0.05);

    const both = attackProbabilities(config({ bless: true, bane: true }));
    close(both.miss + both.normalHit + both.criticalHit, 1);
    close(both.criticalHit, 0.05);
});

test('Sorcerous Burst uses the hand-checkable bounded geometric expectation', () => {
    close(sorcerousExpectedExtraDice(1, 3), 1 / 8 + 1 / 64 + 1 / 512);
    close(sorcerousExpectedRawDamage(1, 3, false), (1 + 73 / 512) * 4.5);
    close(sorcerousExpectedRawDamage(1, 0, false), 4.5);

    const criticalExtra = 15 / 64 + 11 / 256 + 29 / 4096;
    close(sorcerousExpectedExtraDice(2, 3), criticalExtra);
    close(sorcerousExpectedRawDamage(2, 3, false), (2 + criticalExtra) * 4.5);

    const production = exactResult(config({ profile: 'sorcerous-burst' }));
    close(production.normalDamage, (1 + 73 / 512) * 4.5);
    close(production.criticalDamage, (2 + criticalExtra) * 4.5);
});

test('Elemental Adept is applied per die before exact Resistance and Vulnerability order', () => {
    const resistant = exactResult(config({ basicDice: 1, basicDieSize: 4, resistance: true }));
    close(resistant.normalDamage, 1);

    const bothDefenses = exactResult(config({
        basicDice: 1,
        basicDieSize: 4,
        resistance: true,
        vulnerability: true,
    }));
    close(bothDefenses.normalDamage, 2);

    const adept = exactResult(config({
        basicDice: 1,
        basicDieSize: 4,
        resistance: true,
        elementalAdept: true,
    }));
    close(adept.normalDamage, 2.75);
    close(sorcerousExpectedRawDamage(1, 0, true), 37 / 8);
});

test('Chromatic Orb matching is exact and Elemental Adept makes raw 1 and 2 match', () => {
    close(chromaticLeapChance(3, false), 176 / 512);
    close(chromaticLeapChance(3, true), 212 / 512);
    close(chromaticLeapChance(9, false), 1);
});

test('Chromatic Orb doubles initial dice on a critical and folds one level-1 leap exactly', () => {
    const input = config({ profile: 'chromatic-orb', chromaticSlotLevel: 1 });
    const attacks = attackProbabilities(input);
    const result = exactResult(input);
    const normalLeap = 176 / 512;
    const criticalLeap = 1 - (8 * 7 * 6 * 5 * 4 * 3) / 8 ** 6;
    const oneAttack = attacks.normalHit * 13.5 + attacks.criticalHit * 27;
    const continuation = attacks.normalHit * normalLeap + attacks.criticalHit * criticalLeap;

    close(result.normalDamage, 13.5);
    close(result.criticalDamage, 27);
    close(result.chanceToLeap, continuation);
    close(result.expectedDamage, oneAttack * (1 + continuation));
    close(result.expectedTargetsHit, attacks.totalHit * (1 + continuation));
});

test('seeded live rolls reproduce the complete trace', () => {
    const input = config({
        profile: 'sorcerous-burst',
        rollMode: 'advantage',
        halflingLuck: true,
        elvenAccuracy: true,
        elementalAdept: true,
        resistance: true,
        vulnerability: true,
    });
    const first = seededRoll(input, 'table-night:17');
    const replay = seededRoll(input, 'table-night:17');

    assert.deepEqual(replay, first);
    assert.equal(first.token, 'table-night:17');
    assert.ok(first.attacks.length >= 1);

    const burst = seededRoll(config({ profile: 'sorcerous-burst', armorClass: 1 }), 'burst:3');
    assert.deepEqual(burst.attacks[0]?.damageDice, [
        { raw: 8, value: 8, added: false },
        { raw: 3, value: 3, added: true },
    ]);
    assert.equal(burst.totalDamage, 11);

    const orb = seededRoll(config({ profile: 'chromatic-orb', armorClass: 1 }), 'orb:1');
    assert.equal(orb.attacks.length, 2);
    assert.equal(orb.attacks[0]?.triggeredLeap, true);
    assert.equal(orb.attacks[1]?.triggeredLeap, false);
    assert.equal(orb.totalDamage, 35);
});
