<?php

declare(strict_types=1);

namespace App\Domain\Spells;

final class DuplicateWarningDetector
{
    /**
     * @param  list<array<string, mixed>>  $routes
     * @return list<array<string, mixed>>
     */
    public function classify(array $routes): array
    {
        $groups = [];
        foreach ($routes as $route) {
            $groups[(int) data_get($route, 'spell_identity_id')][] = $route;
        }

        $results = [];
        foreach ($groups as $identityId => $identityRoutes) {
            $selections = array_values(array_filter(
                $identityRoutes,
                static fn (array $route): bool => (bool) data_get($route, 'is_selection'),
            ));
            $counting = array_values(array_filter(
                $selections,
                static fn (array $route): bool => (bool) data_get($route, 'counts_against_limit'),
            ));
            $versionIds = array_values(array_unique(array_map(
                static fn (array $route): int => (int) data_get($route, 'spell_version_id'),
                $selections,
            )));

            $category = match (true) {
                count($selections) < 2 => 'none',
                count($versionIds) > 1 => 'conflicting_version',
                count($counting) > 1 => 'wasteful',
                default => 'redundant_intentional',
            };
            $sourceNames = array_values(array_unique(array_map(
                static fn (array $route): string => (string) data_get($route, 'source_name'),
                $identityRoutes,
            )));
            $selectionKeys = array_values(array_filter(array_map(
                static fn (array $route): mixed => data_get($route, 'selection_key'),
                $selections,
            )));
            $name = (string) data_get($identityRoutes[0], 'identity_name');
            $explanation = match ($category) {
                'wasteful' => "{$name} consumes limits in more than one selection.",
                'redundant_intentional' => "{$name} has overlapping access, but fewer than two routes consume limits.",
                'conflicting_version' => "{$name} uses different rules versions across selections.",
                default => "{$name} has no duplicate selection.",
            };

            $results[] = [
                'spell_identity_id' => $identityId,
                'spell_name' => $name,
                'category' => $category,
                'selection_count' => count($selections),
                'sources' => $sourceNames,
                'slots' => $selectionKeys,
                'explanation' => $explanation,
            ];
        }

        usort($results, static fn (array $left, array $right): int => data_get($left, 'spell_name') <=> data_get($right, 'spell_name'));

        return $results;
    }
}
