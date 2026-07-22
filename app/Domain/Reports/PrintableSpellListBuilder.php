<?php

declare(strict_types=1);

namespace App\Domain\Reports;

use App\Domain\Spells\SpellAccessBuilder;
use Illuminate\Support\Facades\DB;

final readonly class PrintableSpellListBuilder
{
    public function __construct(
        private BuildReportBuilder $reports,
        private SpellAccessBuilder $access,
    ) {}

    /** @return array<string, mixed> */
    public function build(int $characterId, bool $withText = false): array
    {
        $report = $this->reports->build($characterId);
        $routes = $this->access->buildForCharacter($characterId);
        $unprepared = $this->unpreparedClassSpells($characterId, $report, $routes);
        $versionIds = array_values(array_unique([
            ...array_map(
                static fn (array $route): int => (int) data_get($route, 'spell_version_id'),
                $routes,
            ),
            ...collect($unprepared)->flatMap(
                static fn (array $section): array => array_map(
                    static fn (array $spell): int => (int) data_get($spell, 'spell_version_id'),
                    data_get($section, 'spells', []),
                ),
            )->all(),
        ]));
        $facts = $this->spellFacts($versionIds);

        $groups = collect($routes)
            ->groupBy('source_name')
            ->map(function ($sourceRoutes, string $sourceName) use ($facts, $withText): array {
                $spells = $sourceRoutes->unique(
                    static fn (array $route): string => data_get($route, 'spell_version_id')
                        .':'.data_get($route, 'casting_mode'),
                )->map(
                    fn (array $route): array => $this->spellEntry(
                        $route,
                        data_get($facts, (string) data_get($route, 'spell_version_id'), []),
                        $withText,
                    ),
                )->sortBy([
                    ['level', 'asc'],
                    ['name', 'asc'],
                    ['casting_mode', 'asc'],
                ])->values()->all();

                return [
                    'source' => $sourceName,
                    'ability' => data_get($sourceRoutes->first(), 'spellcasting_ability'),
                    'attack_bonus' => data_get($sourceRoutes->first(), 'attack_bonus'),
                    'save_dc' => data_get($sourceRoutes->first(), 'save_dc'),
                    'spells' => $spells,
                ];
            })
            ->sortBy('source', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();

        $unprepared = array_map(function (array $section) use ($facts, $withText): array {
            $section['spells'] = collect($this->arrayList($section, 'spells'))->map(
                fn (array $spell): array => $this->spellEntry(
                    $spell,
                    data_get($facts, (string) data_get($spell, 'spell_version_id'), []),
                    $withText,
                ),
            )->sortBy([
                ['level', 'asc'],
                ['name', 'asc'],
            ])->values()->all();

            return $section;
        }, $unprepared);

        $displayedSpells = [
            ...collect($groups)->flatMap(
                static fn (array $group): array => data_get($group, 'spells', []),
            )->all(),
            ...collect($unprepared)->flatMap(
                static fn (array $section): array => data_get($section, 'spells', []),
            )->all(),
        ];
        $described = count(array_filter(
            $displayedSpells,
            static fn (array $spell): bool => is_string(data_get($spell, 'description'))
                && trim((string) data_get($spell, 'description')) !== '',
        ));
        $textStatus = match (true) {
            ! $withText => 'not_requested',
            $described === 0 => 'unavailable',
            $described === count($displayedSpells) => 'available',
            default => 'partial',
        };

        return [
            'variant' => $withText ? 'full' : 'reference',
            'text_status' => $textStatus,
            'character' => data_get($report, 'character'),
            'source_groups' => $groups,
            'unprepared_sections' => $unprepared,
            'wizard' => data_get($report, 'wizard'),
        ];
    }

    /**
     * @param  array<string, mixed>  $report
     * @param  list<array<string, mixed>>  $routes
     * @return list<array<string, mixed>>
     */
    private function unpreparedClassSpells(int $characterId, array $report, array $routes): array
    {
        $sections = [];
        $classes = $this->reportList($report, 'classes');
        foreach (['Cleric', 'Druid'] as $className) {
            $class = collect($classes)->firstWhere('name', $className);
            if (! is_array($class) || (int) data_get($class, 'max_preparable_level') < 1) {
                continue;
            }
            $source = DB::table('character_source_instances as source')
                ->join('class_definitions as class', 'class.id', '=', 'source.source_definition_id')
                ->where('source.character_id', $characterId)
                ->where('source.source_type', 'class')
                ->where('source.state', 'active')
                ->where('class.name', $className)
                ->first(['source.id', 'source.display_name']);
            if ($source === null) {
                continue;
            }

            $preparedIdentityIds = collect($routes)
                ->where('source_instance_id', (int) data_get($source, 'id'))
                ->where('bucket', 'prepared')
                ->pluck('spell_identity_id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->unique()
                ->values()
                ->all();
            $maxLevel = (int) data_get($class, 'max_preparable_level');
            $candidates = DB::table('spell_versions as version')
                ->join('spell_list_memberships as membership', 'membership.spell_version_id', '=', 'version.id')
                ->where('membership.spell_list_key', $className)
                ->where('version.rules_edition', '2024')
                ->where('version.is_active', true)
                ->whereBetween('version.level', [1, $maxLevel])
                ->when(
                    $preparedIdentityIds !== [],
                    fn ($query) => $query->whereNotIn('version.spell_identity_id', $preparedIdentityIds),
                )
                ->orderBy('version.level')
                ->orderBy('version.display_name')
                ->get(['version.id', 'version.spell_identity_id'])
                ->map(function (object $version) use ($class, $report): array {
                    $ability = data_get($class, 'spellcasting_ability');
                    $modifier = is_string($ability) ? (int) floor(
                        ((int) data_get($report, "character.abilities.{$ability}", 10) - 10) / 2,
                    ) : null;
                    $proficiency = (int) data_get($report, 'character.proficiency_bonus');

                    return [
                        'spell_version_id' => (int) data_get($version, 'id'),
                        'spell_identity_id' => (int) data_get($version, 'spell_identity_id'),
                        'casting_mode' => 'available_on_long_rest',
                        'spellcasting_ability' => $ability,
                        'attack_bonus' => $modifier === null ? null : $proficiency + $modifier,
                        'save_dc' => $modifier === null ? null : 8 + $proficiency + $modifier,
                    ];
                })->all();

            $sections[] = [
                'class_name' => $className,
                'title' => "{$className} — not prepared (available to swap in on a long rest)",
                'ability' => data_get($class, 'spellcasting_ability'),
                'max_level' => $maxLevel,
                'cantrip_note' => 'Unprepared cantrips are not listed because cantrips cannot be swapped on a long rest.',
                'spells' => $candidates,
            ];
        }

        return $sections;
    }

    /**
     * @param  array<string, mixed>  $report
     * @return list<array<string, mixed>>
     */
    private function reportList(array $report, string $key): array
    {
        $value = $report[$key] ?? null;
        if (! is_array($value) || ! array_is_list($value)) {
            throw new \UnexpectedValueException("Build report {$key} must be a list.");
        }
        foreach ($value as $item) {
            if (! is_array($item)) {
                throw new \UnexpectedValueException("Build report {$key} contains a non-object item.");
            }
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $source
     * @return list<array<string, mixed>>
     */
    private function arrayList(array $source, string $key): array
    {
        $value = $source[$key] ?? null;
        if (! is_array($value) || ! array_is_list($value)) {
            throw new \UnexpectedValueException("{$key} must be a list.");
        }
        foreach ($value as $item) {
            if (! is_array($item)) {
                throw new \UnexpectedValueException("{$key} contains a non-object item.");
            }
        }

        return $value;
    }

    /**
     * @param  list<int>  $versionIds
     * @return array<int, array<string, mixed>>
     */
    private function spellFacts(array $versionIds): array
    {
        if ($versionIds === []) {
            return [];
        }
        $attackModes = DB::table('spell_version_attack_modes')
            ->whereIn('spell_version_id', $versionIds)
            ->orderBy('attack_mode')
            ->get()
            ->groupBy('spell_version_id');
        $saveAbilities = DB::table('spell_version_save_abilities')
            ->whereIn('spell_version_id', $versionIds)
            ->orderBy('save_ability')
            ->get()
            ->groupBy('spell_version_id');
        $tags = DB::table('spell_version_tags')
            ->whereIn('spell_version_id', $versionIds)
            ->get()
            ->groupBy('spell_version_id');

        return DB::table('spell_versions')
            ->whereIn('id', $versionIds)
            ->get([
                'id', 'display_name', 'rules_edition', 'level', 'school', 'casting_time',
                'action_type', 'range', 'duration', 'concentration', 'ritual', 'components',
                'short_summary',
            ])
            ->mapWithKeys(function (object $version) use ($attackModes, $saveAbilities, $tags): array {
                $id = (int) data_get($version, 'id');
                $versionTags = $tags->get($id, collect())->pluck('tag')->all();

                return [$id => [
                    'spell_version_id' => $id,
                    'name' => (string) data_get($version, 'display_name'),
                    'edition' => (string) data_get($version, 'rules_edition'),
                    'level' => (int) data_get($version, 'level'),
                    'school' => (string) data_get($version, 'school'),
                    'casting_time' => data_get($version, 'casting_time'),
                    'action_type' => data_get($version, 'action_type')
                        ?? $this->actionType(data_get($version, 'casting_time')),
                    'range' => data_get($version, 'range'),
                    'duration' => data_get($version, 'duration'),
                    'concentration' => (bool) data_get($version, 'concentration')
                        || in_array('concentration', $versionTags, true),
                    'ritual' => (bool) data_get($version, 'ritual')
                        || in_array('ritual', $versionTags, true),
                    'components' => data_get($version, 'components'),
                    'description' => data_get($version, 'short_summary'),
                    'attack_modes' => $attackModes->get($id, collect())->pluck('attack_mode')->all(),
                    'save_abilities' => $saveAbilities->get($id, collect())->pluck('save_ability')->all(),
                ]];
            })->all();
    }

    /**
     * @param  array<string, mixed>  $route
     * @param  array<string, mixed>  $facts
     * @return array<string, mixed>
     */
    private function spellEntry(array $route, array $facts, bool $withText): array
    {
        $attacks = data_get($facts, 'attack_modes', []);
        $saves = data_get($facts, 'save_abilities', []);

        return [
            ...$facts,
            'spell_identity_id' => (int) data_get($route, 'spell_identity_id'),
            'casting_mode' => (string) data_get($route, 'casting_mode'),
            'spellcasting_ability' => data_get($route, 'spellcasting_ability'),
            'attack_bonus' => $attacks === [] ? null : data_get($route, 'attack_bonus'),
            'save_dc' => $saves === [] ? null : data_get($route, 'save_dc'),
            'description' => $withText ? data_get($facts, 'description') : null,
        ];
    }

    private function actionType(mixed $castingTime): ?string
    {
        if (! is_string($castingTime)) {
            return null;
        }
        foreach (['Bonus Action', 'Reaction', 'Action'] as $action) {
            if (preg_match('/\b'.preg_quote($action, '/').'\b/i', $castingTime) === 1) {
                return $action;
            }
        }

        return null;
    }
}
