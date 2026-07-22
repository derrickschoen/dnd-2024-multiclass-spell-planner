<?php

declare(strict_types=1);

use App\Domain\Spells\DuplicateWarningDetector;

it('classifies wasteful intentional conflicting and unique access', function () {
    $route = static fn (
        int $identity,
        int $version,
        string $name,
        string $source,
        string $slot,
        bool $counts = true,
        bool $selection = true,
    ): array => [
        'spell_identity_id' => $identity,
        'spell_version_id' => $version,
        'identity_name' => $name,
        'spell_name' => $name,
        'source_name' => $source,
        'selection_key' => $slot,
        'counts_against_limit' => $counts,
        'is_selection' => $selection,
    ];
    $routes = [
        $route(1, 101, 'Wasteful', 'Wizard', 'wizard:1'),
        $route(1, 101, 'Wasteful', 'Feat', 'feat:1'),
        $route(2, 102, 'Intentional', 'Class', 'class:1'),
        $route(2, 102, 'Intentional', 'Automatic grant', 'automatic:1', false),
        $route(3, 103, 'Conflict', 'Legacy', 'legacy:1'),
        $route(3, 203, 'Conflict', 'Current', 'current:1'),
        $route(4, 104, 'Unique', 'Wizard', 'unique:1'),
        $route(4, 104, 'Unique', 'Ritual Adept', '', false, false),
    ];

    $byName = collect((new DuplicateWarningDetector)->classify($routes))->keyBy('spell_name');

    expect(data_get($byName, 'Wasteful.category'))->toBe('wasteful')
        ->and(data_get($byName, 'Intentional.category'))->toBe('redundant_intentional')
        ->and(data_get($byName, 'Conflict.category'))->toBe('conflicting_version')
        ->and(data_get($byName, 'Unique.category'))->toBe('none')
        ->and(data_get($byName, 'Unique.selection_count'))->toBe(1);
});

it('returns the complete sorted duplicate assessment contract', function () {
    $route = static fn (
        int $identity,
        int $version,
        string $identityName,
        string $spellName,
        string $contentKey,
        string $edition,
        string $source,
        ?string $slot,
        bool $counts = true,
        bool $selection = true,
    ): array => [
        'spell_identity_id' => $identity,
        'spell_version_id' => $version,
        'identity_name' => $identityName,
        'spell_name' => $spellName,
        'spell_content_key' => $contentKey,
        'rules_edition' => $edition,
        'source_name' => $source,
        'selection_key' => $slot,
        'counts_against_limit' => $counts,
        'is_selection' => $selection,
    ];

    $routes = [
        $route(4, 104, 'Unique', 'Unique', '2024:unique', '2024', 'Ritual Adept', null, false, false),
        $route(1, 101, 'Wasteful', 'Wasteful', '2024:wasteful', '2024', 'Wizard', 'wizard:1'),
        $route(3, 203, 'Conflict', 'Conflict', '2024:conflict', '2024', 'Current', 'current:1'),
        $route(2, 102, 'Intentional', 'Intentional', '2024:intentional', '2024', 'Class', 'class:1'),
        $route(4, 104, 'Unique', 'Unique', '2024:unique', '2024', 'Wizard', 'unique:1'),
        $route(1, 101, 'Wasteful', 'Wasteful', '2024:wasteful', '2024', 'Feat', 'feat:1'),
        $route(3, 103, 'Conflict', 'Conflict Legacy', '2014:conflict', '2014', 'Legacy', 'legacy:1'),
        $route(2, 102, 'Intentional', 'Intentional', '2024:intentional', '2024', 'Class', 'automatic:1', false),
    ];

    expect((new DuplicateWarningDetector)->classify($routes))->toBe([
        [
            'spell_identity_id' => 3,
            'spell_name' => 'Conflict',
            'category' => 'conflicting_version',
            'selection_count' => 2,
            'sources' => ['Current', 'Legacy'],
            'slots' => ['current:1', 'legacy:1'],
            'versions' => [
                [
                    'spell_version_id' => 103,
                    'content_key' => '2014:conflict',
                    'edition' => '2014',
                    'label' => 'Conflict Legacy (2014)',
                ],
                [
                    'spell_version_id' => 203,
                    'content_key' => '2024:conflict',
                    'edition' => '2024',
                    'label' => 'Conflict (2024)',
                ],
            ],
            'warning_fingerprint' => 'conflicting_versions:bb212b4bcbe6696bf60a50897cd59a90e89cbaad0b910d605aff6101af43bed5',
            'explanation' => 'Conflict has conflicting versions selected: Conflict Legacy (2014) and Conflict (2024).',
        ],
        [
            'spell_identity_id' => 2,
            'spell_name' => 'Intentional',
            'category' => 'redundant_intentional',
            'selection_count' => 2,
            'sources' => ['Class'],
            'slots' => ['class:1', 'automatic:1'],
            'versions' => [[
                'spell_version_id' => 102,
                'content_key' => '2024:intentional',
                'edition' => '2024',
                'label' => 'Intentional (2024)',
            ]],
            'warning_fingerprint' => null,
            'explanation' => 'Intentional has overlapping access, but fewer than two routes consume limits.',
        ],
        [
            'spell_identity_id' => 4,
            'spell_name' => 'Unique',
            'category' => 'none',
            'selection_count' => 1,
            'sources' => ['Ritual Adept', 'Wizard'],
            'slots' => ['unique:1'],
            'versions' => [[
                'spell_version_id' => 104,
                'content_key' => '2024:unique',
                'edition' => '2024',
                'label' => 'Unique (2024)',
            ]],
            'warning_fingerprint' => null,
            'explanation' => 'Unique has no duplicate selection.',
        ],
        [
            'spell_identity_id' => 1,
            'spell_name' => 'Wasteful',
            'category' => 'wasteful',
            'selection_count' => 2,
            'sources' => ['Wizard', 'Feat'],
            'slots' => ['wizard:1', 'feat:1'],
            'versions' => [[
                'spell_version_id' => 101,
                'content_key' => '2024:wasteful',
                'edition' => '2024',
                'label' => 'Wasteful (2024)',
            ]],
            'warning_fingerprint' => null,
            'explanation' => 'Wasteful consumes limits in more than one selection.',
        ],
    ]);
});

it('returns duplicate source names as a compact list', function () {
    $route = static fn (string $source): array => [
        'spell_identity_id' => 1,
        'spell_version_id' => 101,
        'identity_name' => 'Duplicate source',
        'spell_name' => 'Duplicate source',
        'spell_content_key' => '2024:duplicate-source',
        'rules_edition' => '2024',
        'source_name' => $source,
        'selection_key' => strtolower($source),
        'counts_against_limit' => true,
        'is_selection' => true,
    ];

    $assessment = (new DuplicateWarningDetector)->classify([
        $route('Wizard'), $route('Wizard'), $route('Feat'),
    ]);

    expect(data_get($assessment, '0.sources'))->toBe(['Wizard', 'Feat']);
});
