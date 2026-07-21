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
