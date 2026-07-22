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
    'whitespace immutable key' => [[
        'kind' => 'fixed_spell', 'rule_key' => '   ', 'bucket' => 'automatic',
        'spell_version_key' => '2024:mage-hand',
    ], "Grant rule field 'rule_key' must be a non-empty string."],
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

it('normalizes an exact source-config activation predicate', function () {
    $rule = GrantRule::fromArray([
        'kind' => 'choice_from_list', 'rule_key' => 'divine-order-cantrip', 'count' => 1,
        'bucket' => 'cantrip_known', 'list' => '$config.divine_order.chosen_list',
        'level_min' => 0, 'level_max' => 0,
        'active_if_config' => [
            'equals' => 'Thaumaturge',
            'key' => 'divine_order.chosen_option',
        ],
    ]);

    expect($rule->activeIfConfig)->toBe([
        'key' => 'divine_order.chosen_option',
        'equals' => 'Thaumaturge',
    ])->and(data_get($rule->toArray(), 'active_if_config'))->toBe($rule->activeIfConfig);
});

it('rejects malformed source-config activation predicates', function (mixed $predicate, string $message) {
    expect(fn () => GrantRule::fromArray([
        'kind' => 'choice_from_list', 'rule_key' => 'conditional', 'count' => 1,
        'bucket' => 'cantrip_known', 'list' => 'Cleric',
        'active_if_config' => $predicate,
    ]))->toThrow(InvalidArgumentException::class, $message);
})->with([
    'scalar' => ['Thaumaturge', "Grant rule 'conditional' field 'active_if_config' must contain exactly key and equals."],
    'extra field' => [[
        'key' => 'divine_order.chosen_option', 'equals' => 'Thaumaturge', 'or' => 'Protector',
    ], "Grant rule 'conditional' field 'active_if_config' must contain exactly key and equals."],
    'empty key' => [[
        'key' => ' ', 'equals' => 'Thaumaturge',
    ], "Grant rule 'conditional' active_if_config key and equals must be non-empty strings."],
]);

it('normalizes documented defaults for every kind', function (array $input, array $expected) {
    $rule = GrantRule::fromArray($input);

    expect($rule->toArray())->toBe($expected)
        ->and($rule->count)->toBe(data_get($expected, 'count'))
        ->and($rule->bucket)->toBe(data_get($expected, 'bucket'))
        ->and($rule->alwaysPrepared)->toBeFalse()
        ->and($rule->withSlots)->toBeTrue()
        ->and($rule->freeCast)->toBeNull();
})->with([
    'fixed spell' => [[
        'kind' => 'fixed_spell', 'rule_key' => 'fixed', 'bucket' => 'automatic',
        'spell_version_id' => 1,
    ], [
        'kind' => 'fixed_spell', 'rule_key' => 'fixed', 'bucket' => 'automatic',
        'spell_version_id' => 1, 'count' => 1, 'always_prepared' => false,
        'with_slots' => true, 'free_cast' => null,
    ]],
    'list choice' => [[
        'kind' => 'choice_from_list', 'rule_key' => 'list', 'count' => 2,
        'bucket' => 'known', 'list' => 'Wizard',
    ], [
        'kind' => 'choice_from_list', 'rule_key' => 'list', 'count' => 2,
        'bucket' => 'known', 'list' => 'Wizard', 'always_prepared' => false,
        'with_slots' => true, 'free_cast' => null, 'level_min' => 0, 'level_max' => 9,
    ]],
    'query choice' => [[
        'kind' => 'choice_from_query', 'rule_key' => 'query', 'count' => 1,
        'bucket' => 'known', 'schools' => ['Illusion'],
    ], [
        'kind' => 'choice_from_query', 'rule_key' => 'query', 'count' => 1,
        'bucket' => 'known', 'schools' => ['Illusion'], 'always_prepared' => false,
        'with_slots' => true, 'free_cast' => null, 'level_min' => 0, 'level_max' => 9,
    ]],
    'granted source' => [[
        'kind' => 'grant_source', 'rule_key' => 'source', 'source_type' => 'feat',
        'source_definition_id' => 1,
    ], [
        'kind' => 'grant_source', 'rule_key' => 'source', 'source_type' => 'feat',
        'source_definition_id' => 1, 'count' => 1, 'always_prepared' => false,
        'with_slots' => true, 'free_cast' => null,
    ]],
    'capability' => [[
        'kind' => 'capability', 'rule_key' => 'capability',
        'capability_key' => 'ritual', 'collection' => 'spellbook',
        'access_mode' => 'ritual_only', 'tags' => ['ritual'],
    ], [
        'kind' => 'capability', 'rule_key' => 'capability',
        'capability_key' => 'ritual', 'collection' => 'spellbook',
        'access_mode' => 'ritual_only', 'tags' => ['ritual'],
        'always_prepared' => false, 'with_slots' => true, 'free_cast' => null,
    ]],
    'spellbook acquisition' => [[
        'kind' => 'spellbook_acquisition', 'rule_key' => 'book',
        'bucket' => 'spellbook', 'list' => 'Wizard', 'acquisitions_config' => 'spells',
    ], [
        'kind' => 'spellbook_acquisition', 'rule_key' => 'book',
        'bucket' => 'spellbook', 'list' => 'Wizard', 'acquisitions_config' => 'spells',
        'always_prepared' => false, 'with_slots' => true, 'free_cast' => null,
    ]],
]);

it('rejects every documented malformed field shape', function (array $rule) {
    expect(fn () => GrantRule::fromArray($rule))->toThrow(InvalidArgumentException::class);
})->with([
    'fixed count is not one' => [[
        'kind' => 'fixed_spell', 'rule_key' => 'fixed', 'count' => 2,
        'bucket' => 'automatic', 'spell_version_id' => 1,
    ]],
    'fixed reference is zero' => [[
        'kind' => 'fixed_spell', 'rule_key' => 'fixed', 'bucket' => 'automatic',
        'spell_version_id' => 0,
    ]],
    'fixed key is whitespace' => [[
        'kind' => 'fixed_spell', 'rule_key' => 'fixed', 'bucket' => 'automatic',
        'spell_version_key' => '   ',
    ]],
    'list count is missing' => [[
        'kind' => 'choice_from_list', 'rule_key' => 'list',
        'bucket' => 'known', 'list' => 'Wizard',
    ]],
    'query count is missing' => [[
        'kind' => 'choice_from_query', 'rule_key' => 'query',
        'bucket' => 'known', 'schools' => ['Illusion'],
    ]],
    'grant count is zero' => [[
        'kind' => 'grant_source', 'rule_key' => 'source', 'count' => 0,
        'source_type' => 'feat', 'source_definition_id' => 1,
    ]],
    'capability defines a bucket' => [[
        'kind' => 'capability', 'rule_key' => 'capability', 'bucket' => 'known',
        'capability_key' => 'ritual', 'collection' => 'spellbook',
        'access_mode' => 'ritual_only', 'tags' => ['ritual'],
    ]],
    'boolean option is not boolean' => [[
        'kind' => 'fixed_spell', 'rule_key' => 'fixed', 'bucket' => 'automatic',
        'spell_version_id' => 1, 'always_prepared' => 1,
    ]],
    'active level is zero' => [[
        'kind' => 'fixed_spell', 'rule_key' => 'fixed', 'bucket' => 'automatic',
        'spell_version_id' => 1, 'active_from_class_level' => 0,
    ]],
    'distinct config is whitespace' => [[
        'kind' => 'fixed_spell', 'rule_key' => 'fixed', 'bucket' => 'automatic',
        'spell_version_id' => 1, 'distinct_config_by' => '   ',
    ]],
    'negative minimum level' => [[
        'kind' => 'choice_from_list', 'rule_key' => 'list', 'count' => 1,
        'bucket' => 'known', 'list' => 'Wizard', 'level_min' => -1,
    ]],
    'maximum level above nine' => [[
        'kind' => 'choice_from_list', 'rule_key' => 'list', 'count' => 1,
        'bucket' => 'known', 'list' => 'Wizard', 'level_max' => 10,
    ]],
    'level is not an integer' => [[
        'kind' => 'choice_from_list', 'rule_key' => 'list', 'count' => 1,
        'bucket' => 'known', 'list' => 'Wizard', 'level_min' => '1',
    ]],
    'list name is missing' => [[
        'kind' => 'choice_from_list', 'rule_key' => 'list', 'count' => 1,
        'bucket' => 'known',
    ]],
    'selection collection on non-choice' => [[
        'kind' => 'fixed_spell', 'rule_key' => 'fixed', 'bucket' => 'automatic',
        'spell_version_id' => 1, 'selection_collection' => 'wizard_spellbook',
    ]],
    'unsupported selection collection' => [[
        'kind' => 'choice_from_list', 'rule_key' => 'list', 'count' => 1,
        'bucket' => 'known', 'list' => 'Wizard', 'selection_collection' => 'other',
    ]],
    'query has no predicate' => [[
        'kind' => 'choice_from_query', 'rule_key' => 'query', 'count' => 1,
        'bucket' => 'known',
    ]],
    'query schools is not a list' => [[
        'kind' => 'choice_from_query', 'rule_key' => 'query', 'count' => 1,
        'bucket' => 'known', 'schools' => 'Illusion',
    ]],
    'query tags contain whitespace' => [[
        'kind' => 'choice_from_query', 'rule_key' => 'query', 'count' => 1,
        'bucket' => 'known', 'tags' => ['   '],
    ]],
    'grant source type is missing' => [[
        'kind' => 'grant_source', 'rule_key' => 'source', 'source_definition_id' => 1,
    ]],
    'grant source definition id is zero' => [[
        'kind' => 'grant_source', 'rule_key' => 'source', 'source_type' => 'feat',
        'source_definition_id' => 0,
    ]],
    'grant source definition key is whitespace' => [[
        'kind' => 'grant_source', 'rule_key' => 'source', 'source_type' => 'feat',
        'source_definition_key' => '   ',
    ]],
    'grant source configured key is whitespace' => [[
        'kind' => 'grant_source', 'rule_key' => 'source', 'source_type' => 'feat',
        'definition_key_config' => '   ',
    ]],
    'capability key is missing' => [[
        'kind' => 'capability', 'rule_key' => 'capability',
        'collection' => 'spellbook', 'access_mode' => 'ritual_only', 'tags' => ['ritual'],
    ]],
    'capability collection is missing' => [[
        'kind' => 'capability', 'rule_key' => 'capability',
        'capability_key' => 'ritual', 'access_mode' => 'ritual_only', 'tags' => ['ritual'],
    ]],
    'capability mode is missing' => [[
        'kind' => 'capability', 'rule_key' => 'capability',
        'capability_key' => 'ritual', 'collection' => 'spellbook', 'tags' => ['ritual'],
    ]],
    'capability tags are missing' => [[
        'kind' => 'capability', 'rule_key' => 'capability',
        'capability_key' => 'ritual', 'collection' => 'spellbook', 'access_mode' => 'ritual_only',
    ]],
    'capability tags are empty' => [[
        'kind' => 'capability', 'rule_key' => 'capability',
        'capability_key' => 'ritual', 'collection' => 'spellbook',
        'access_mode' => 'ritual_only', 'tags' => [],
    ]],
    'spellbook config is missing' => [[
        'kind' => 'spellbook_acquisition', 'rule_key' => 'book',
        'bucket' => 'spellbook', 'list' => 'Wizard',
    ]],
    'free cast is not an object' => [[
        'kind' => 'fixed_spell', 'rule_key' => 'fixed', 'bucket' => 'automatic',
        'spell_version_id' => 1, 'free_cast' => 'once',
    ]],
    'free cast uses is zero' => [[
        'kind' => 'fixed_spell', 'rule_key' => 'fixed', 'bucket' => 'automatic',
        'spell_version_id' => 1,
        'free_cast' => ['uses' => 0, 'recovery' => 'long_rest', 'pool_scope' => 'per_spell'],
    ]],
    'free cast recovery is not a string' => [[
        'kind' => 'fixed_spell', 'rule_key' => 'fixed', 'bucket' => 'automatic',
        'spell_version_id' => 1,
        'free_cast' => ['uses' => 1, 'recovery' => 1, 'pool_scope' => 'per_spell'],
    ]],
    'free cast pool scope is invalid' => [[
        'kind' => 'fixed_spell', 'rule_key' => 'fixed', 'bucket' => 'automatic',
        'spell_version_id' => 1,
        'free_cast' => ['uses' => 1, 'recovery' => 'dawn', 'pool_scope' => 'global'],
    ]],
]);

it('accepts each independent query predicate', function (array $predicate) {
    expect(GrantRule::fromArray([
        'kind' => 'choice_from_query', 'rule_key' => 'query', 'count' => 1,
        'bucket' => 'known', ...$predicate,
    ]))->toBeInstanceOf(GrantRule::class);
})->with([
    'schools' => [['schools' => ['Illusion']]],
    'tags' => [['tags' => ['ritual']]],
    'minimum level' => [['level_min' => 1]],
    'maximum level' => [['level_max' => 2]],
]);

it('accepts each independent granted-source reference', function (array $reference) {
    expect(GrantRule::fromArray([
        'kind' => 'grant_source', 'rule_key' => 'source', 'source_type' => 'feat',
        ...$reference,
    ]))->toBeInstanceOf(GrantRule::class);
})->with([
    'numeric id' => [['source_definition_id' => 1]],
    'definition key' => [['source_definition_key' => '2024:feat:magic-initiate']],
    'configured key' => [['definition_key_config' => 'origin_feat_key']],
]);

it('trims normalized identifiers and preserves the complete free-cast contract', function () {
    $rule = GrantRule::fromArray([
        'kind' => 'fixed_spell', 'rule_key' => '  fixed  ', 'bucket' => 'automatic',
        'spell_version_id' => 1, 'distinct_config_by' => '  chosen_list  ',
        'active_from_class_level' => 2,
        'free_cast' => ['uses' => 2, 'recovery' => 'short_rest', 'pool_scope' => 'shared'],
    ]);

    expect($rule->ruleKey)->toBe('fixed')
        ->and($rule->distinctConfigBy)->toBe('chosen_list')
        ->and($rule->activeFromClassLevel)->toBe(2)
        ->and($rule->freeCast)->toBe([
            'uses' => 2, 'recovery' => 'short_rest', 'pool_scope' => 'shared',
        ]);
});

it('rejects invalid JSON and non-object JSON', function (string $json) {
    expect(fn () => GrantRule::fromJson($json))->toThrow(InvalidArgumentException::class);
})->with(['{', 'null', '[]']);

it('reports the malformed free-cast field that caused validation to fail', function (mixed $freeCast, string $message) {
    expect(fn () => GrantRule::fromArray([
        'kind' => 'fixed_spell', 'rule_key' => 'fixed', 'bucket' => 'automatic',
        'spell_version_id' => 1, 'free_cast' => $freeCast,
    ]))->toThrow(InvalidArgumentException::class, $message);
})->with([
    'non-object value' => [
        'once', "Grant rule 'fixed' field 'free_cast' must be an object or null.",
    ],
    'invalid scalar pool' => [[
        'uses' => 1, 'recovery' => 'dawn', 'pool_scope' => 'global',
    ], "Grant rule 'fixed' has invalid free_cast.pool_scope 'global'."],
    'invalid array pool' => [[
        'uses' => 1, 'recovery' => 'dawn', 'pool_scope' => [],
    ], "Grant rule 'fixed' has invalid free_cast.pool_scope 'array'."],
]);

it('stores JSON without escaping slashes', function () {
    $rule = GrantRule::fromArray([
        'kind' => 'fixed_spell', 'rule_key' => 'fixed', 'bucket' => 'automatic',
        'spell_version_key' => 'https://example.test/spell',
    ]);

    expect($rule->toJson())->toContain('https://example.test/spell')
        ->not->toContain('https:\\/\\/example.test');
});
