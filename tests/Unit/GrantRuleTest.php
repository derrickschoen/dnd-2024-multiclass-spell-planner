<?php

declare(strict_types=1);

use App\Domain\Grants\GrantRule;

it('rejects an unknown grant-rule kind', function () {
    expect(fn () => GrantRule::fromArray([
        'kind' => 'wishful_thinking',
        'rule_key' => 'mystery',
    ]))->toThrow(InvalidArgumentException::class, "Unknown grant rule kind 'wishful_thinking'.");
});

it('rejects malformed rules with a clear field-specific message', function (array $rule, string $message) {
    expect(fn () => GrantRule::fromArray($rule))
        ->toThrow(InvalidArgumentException::class, $message);
})->with([
    'missing immutable key' => [
        ['kind' => 'fixed_spell', 'bucket' => 'automatic', 'spell_version_key' => '2024:mage-hand'],
        "Grant rule field 'rule_key' must be a non-empty string.",
    ],
    'bad bucket' => [[
        'kind' => 'choice_from_list', 'rule_key' => 'pick', 'count' => 1,
        'bucket' => 'sometimes', 'list' => 'Wizard',
    ], "Grant rule 'pick' has invalid bucket 'sometimes'."],
    'bad free cast recovery' => [[
        'kind' => 'fixed_spell', 'rule_key' => 'gift', 'bucket' => 'automatic',
        'spell_version_key' => '2024:mage-hand',
        'free_cast' => ['uses' => 1, 'recovery' => 'lunchtime', 'pool_scope' => 'per_spell'],
    ], "Grant rule 'gift' has invalid free_cast.recovery 'lunchtime'."],
    'inverted levels' => [[
        'kind' => 'choice_from_query', 'rule_key' => 'pick', 'count' => 1,
        'bucket' => 'known', 'level_min' => 3, 'level_max' => 1, 'schools' => ['Evocation'],
    ], "Grant rule 'pick' has level_min greater than level_max."],
]);

it('normalizes a valid fixed spell rule', function () {
    $rule = GrantRule::fromArray([
        'kind' => 'fixed_spell',
        'rule_key' => 'always-mage-hand',
        'bucket' => 'automatic',
        'spell_version_key' => '2024:mage-hand',
        'always_prepared' => true,
        'with_slots' => false,
        'free_cast' => null,
    ]);

    expect($rule->kind)->toBe(GrantRule::FIXED_SPELL)
        ->and($rule->ruleKey)->toBe('always-mage-hand')
        ->and($rule->count)->toBe(1)
        ->and($rule->bucket)->toBe('automatic')
        ->and($rule->alwaysPrepared)->toBeTrue()
        ->and($rule->withSlots)->toBeFalse();
});

it('accepts all six documented rule kinds', function (array $rule) {
    expect(GrantRule::fromArray($rule))->toBeInstanceOf(GrantRule::class);
})->with([
    'fixed' => [[
        'kind' => 'fixed_spell', 'rule_key' => 'fixed', 'bucket' => 'automatic',
        'spell_version_id' => 42,
    ]],
    'list' => [[
        'kind' => 'choice_from_list', 'rule_key' => 'list', 'count' => 2,
        'bucket' => 'cantrip_known', 'list' => '$config.chosen_list',
        'level_min' => 0, 'level_max' => 0,
    ]],
    'query' => [[
        'kind' => 'choice_from_query', 'rule_key' => 'query', 'count' => 1,
        'bucket' => 'known', 'schools' => ['Illusion'], 'tags' => ['ritual'],
    ]],
    'source' => [[
        'kind' => 'grant_source', 'rule_key' => 'origin-feat', 'source_type' => 'feat',
        'definition_key_config' => 'origin_feat_key', 'child_config_config' => 'origin_feat_config',
    ]],
    'capability' => [[
        'kind' => 'capability', 'rule_key' => 'ritual-adept',
        'capability_key' => 'wizard-ritual-adept', 'collection' => 'wizard_spellbook',
        'tags' => ['ritual'], 'access_mode' => 'ritual_only',
    ]],
    'spellbook' => [[
        'kind' => 'spellbook_acquisition', 'rule_key' => 'wizard-spellbook',
        'bucket' => 'spellbook', 'list' => 'Wizard',
        'acquisitions_config' => 'wizard_spellbook_acquisitions',
    ]],
]);

it('forbids capability counts because capabilities never mint slots', function () {
    expect(fn () => GrantRule::fromArray([
        'kind' => 'capability', 'rule_key' => 'ritual-adept', 'count' => 1,
        'capability_key' => 'wizard-ritual-adept', 'collection' => 'wizard_spellbook',
        'tags' => ['ritual'], 'access_mode' => 'ritual_only',
    ]))->toThrow(
        InvalidArgumentException::class,
        "Capability rule 'ritual-adept' must not define count; capabilities do not mint slots.",
    );
});

it('round-trips validated JSON for storage', function () {
    $rule = GrantRule::fromJson(json_encode([
        'kind' => 'choice_from_list', 'rule_key' => 'initiate-cantrips', 'count' => 2,
        'bucket' => 'cantrip_known', 'list' => '$config.chosen_list',
        'with_slots' => false, 'distinct_config_by' => 'chosen_list',
    ], JSON_THROW_ON_ERROR));

    expect(GrantRule::fromJson($rule->toJson())->toArray())->toBe($rule->toArray());
});
