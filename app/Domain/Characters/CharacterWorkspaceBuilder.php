<?php

declare(strict_types=1);

namespace App\Domain\Characters;

use App\Domain\Reports\BuildReportBuilder;
use Illuminate\Support\Facades\DB;

final readonly class CharacterWorkspaceBuilder
{
    public function __construct(private BuildReportBuilder $reports) {}

    /** @return array<string, mixed> */
    public function build(int $characterId): array
    {
        $report = $this->reports->build($characterId);
        $routesBySlot = collect(data_get($report, 'access_routes'))->whereNotNull('slot_id')->keyBy('slot_id');
        $duplicatesByIdentity = collect(data_get($report, 'duplicate_assessments'))->keyBy('spell_identity_id');
        $classes = DB::table('character_class_levels as level')
            ->join('class_definitions as class', 'class.id', '=', 'level.class_definition_id')
            ->leftJoin('subclass_definitions as subclass', 'subclass.id', '=', 'level.subclass_definition_id')
            ->where('level.character_id', $characterId)
            ->orderBy('class.name')
            ->select([
                'level.id', 'level.class_definition_id', 'level.subclass_definition_id', 'level.level',
                'class.name', 'subclass.name as subclass_name',
            ])
            ->get()
            ->map(fn (object $row): array => [
                'id' => (int) data_get($row, 'id'),
                'class_definition_id' => (int) data_get($row, 'class_definition_id'),
                'subclass_definition_id' => data_get($row, 'subclass_definition_id') === null
                    ? null : (int) data_get($row, 'subclass_definition_id'),
                'level' => (int) data_get($row, 'level'),
                'name' => (string) data_get($row, 'name'),
                'subclass_name' => data_get($row, 'subclass_name'),
                'subclasses' => $this->subclasses((int) data_get($row, 'class_definition_id')),
            ])
            ->all();

        $slotRows = DB::table('spell_selection_slots as slot')
            ->join('character_source_instances as source', 'source.id', '=', 'slot.source_instance_id')
            ->leftJoin('spell_versions as selected', 'selected.id', '=', DB::raw('COALESCE(slot.fixed_spell_version_id, slot.current_spell_version_id)'))
            ->where('slot.character_id', $characterId)
            ->whereIn('slot.state', ['active', 'orphaned', 'kept_override'])
            ->select([
                'slot.*', 'source.display_name as source_name', 'source.source_type', 'source.config as source_config',
                'selected.display_name as spell_name', 'selected.level as spell_level',
                'selected.spell_identity_id', 'selected.ritual', 'selected.concentration',
            ])
            ->orderBy('source.display_name')
            ->orderBy('slot.sort_order')
            ->orderBy('slot.id')
            ->get();
        $selectedVersionIds = $slotRows
            ->map(static fn (object $slot): mixed => data_get($slot, 'fixed_spell_version_id')
                ?? data_get($slot, 'current_spell_version_id'))
            ->filter()
            ->unique()
            ->values();
        $attackVersionIds = DB::table('spell_version_attack_modes')
            ->whereIn('spell_version_id', $selectedVersionIds)
            ->pluck('spell_version_id')
            ->flip();
        $saveVersionIds = DB::table('spell_version_save_abilities')
            ->whereIn('spell_version_id', $selectedVersionIds)
            ->pluck('spell_version_id')
            ->flip();

        $slots = $slotRows
            ->map(function (object $slot) use (
                $report,
                $routesBySlot,
                $duplicatesByIdentity,
                $attackVersionIds,
                $saveVersionIds,
            ): array {
                $route = $routesBySlot->get(data_get($slot, 'id'));
                $ability = data_get($route, 'spellcasting_ability') ?? $this->sourceAbility($slot);
                $modifier = $ability === null ? null : $this->abilityModifier(
                    (int) data_get($report, "character.abilities.{$ability}", 10),
                );
                $proficiency = (int) data_get($report, 'character.proficiency_bonus');
                $duplicate = $duplicatesByIdentity->get(data_get($slot, 'spell_identity_id'));
                $selectedVersionId = data_get($slot, 'fixed_spell_version_id')
                    ?? data_get($slot, 'current_spell_version_id');

                return [
                    'id' => (int) data_get($slot, 'id'),
                    'slot_key' => (string) data_get($slot, 'slot_key'),
                    'source' => (string) data_get($slot, 'source_name'),
                    'source_type' => (string) data_get($slot, 'source_type'),
                    'label' => data_get($slot, 'label') ?: $this->slotLabel($slot),
                    'bucket' => (string) data_get($slot, 'bucket'),
                    'level_min' => (int) data_get($slot, 'spell_level_min'),
                    'level_max' => (int) data_get($slot, 'spell_level_max'),
                    'spell_id' => data_get($slot, 'fixed_spell_version_id') ?? data_get($slot, 'current_spell_version_id'),
                    'spell_name' => data_get($slot, 'spell_name'),
                    'spell_level' => data_get($slot, 'spell_level'),
                    'ability' => $ability,
                    'attack_bonus' => $modifier === null || $selectedVersionId === null
                        || ! $attackVersionIds->has($selectedVersionId)
                            ? null : $proficiency + $modifier,
                    'save_dc' => $modifier === null || $selectedVersionId === null
                        || ! $saveVersionIds->has($selectedVersionId)
                            ? null : 8 + $proficiency + $modifier,
                    'ritual' => (bool) data_get($slot, 'ritual'),
                    'concentration' => (bool) data_get($slot, 'concentration'),
                    'duplicate_status' => data_get($duplicate, 'category', 'none'),
                    'state' => (string) data_get($slot, 'state'),
                    'eligibility' => (string) data_get($slot, 'selection_eligibility'),
                    'invalid_reason' => data_get($slot, 'selection_invalid_reason'),
                    'orphan_reason' => data_get($slot, 'orphan_reason_code'),
                    'override_note' => data_get($slot, 'override_note'),
                    'locked' => (bool) data_get($slot, 'is_locked'),
                ];
            })
            ->all();

        $invalid = array_values(array_filter(
            $slots,
            static fn (array $slot): bool => data_get($slot, 'eligibility') === 'invalid'
                || in_array(data_get($slot, 'state'), ['orphaned', 'kept_override'], true),
        ));
        $warningAssessments = array_values(array_filter(
            data_get($report, 'duplicate_assessments'),
            static fn (array $item): bool => data_get($item, 'category') !== 'none',
        ));
        $report['invalid_selections'] = $invalid;
        $report['summary'] = [
            'unique_spells' => collect(data_get($report, 'access_routes'))->pluck('spell_identity_id')->unique()->count(),
            'access_routes' => count(data_get($report, 'access_routes')),
            'warning_count' => count($warningAssessments) + count($invalid),
        ];

        $configurableSources = DB::table('character_source_instances as source')
            ->join('feat_definitions as feat', 'feat.id', '=', 'source.source_definition_id')
            ->where('source.character_id', $characterId)
            ->where('source.source_type', 'feat')
            ->where('source.state', 'active')
            ->where('feat.content_key', '2024:feat:magic-initiate')
            ->orderBy('source.id')
            ->get(['source.id', 'source.display_name', 'source.config'])
            ->map(static function (object $source): array {
                $config = json_decode((string) data_get($source, 'config'), true, 512, JSON_THROW_ON_ERROR);

                return [
                    'id' => (int) data_get($source, 'id'),
                    'display_name' => (string) data_get($source, 'display_name'),
                    'chosen_list' => (string) data_get($config, 'chosen_list'),
                    'spellcasting_ability' => (string) data_get($config, 'spellcasting_ability'),
                ];
            })
            ->all();

        $orderSources = DB::table('character_source_instances as source')
            ->join('class_definitions as class', 'class.id', '=', 'source.source_definition_id')
            ->where('source.character_id', $characterId)
            ->where('source.source_type', 'class')
            ->where('source.state', 'active')
            ->whereIn('class.name', ['Cleric', 'Druid'])
            ->orderBy('class.name')
            ->get(['source.id', 'source.display_name', 'source.config', 'class.name as class_name'])
            ->map(static function (object $source): array {
                $className = (string) data_get($source, 'class_name');
                $definition = $className === 'Cleric'
                    ? [
                        'key' => 'divine_order', 'name' => 'Divine Order',
                        'options' => ['Protector', 'Thaumaturge'], 'bonus' => 'Thaumaturge',
                    ]
                    : [
                        'key' => 'primal_order', 'name' => 'Primal Order',
                        'options' => ['Warden', 'Magician'], 'bonus' => 'Magician',
                    ];
                $config = json_decode((string) data_get($source, 'config'), true, 512, JSON_THROW_ON_ERROR);
                $chosenOption = data_get($config, data_get($definition, 'key').'.chosen_option');

                return [
                    'id' => (int) data_get($source, 'id'),
                    'class_name' => $className,
                    'display_name' => (string) data_get($source, 'display_name'),
                    'order_name' => (string) data_get($definition, 'name'),
                    'chosen_option' => is_string($chosenOption) ? $chosenOption : null,
                    'options' => data_get($definition, 'options'),
                    'bonus_option' => (string) data_get($definition, 'bonus'),
                ];
            })
            ->all();

        $sourceCatalog = collect(['feat', 'species', 'background'])->mapWithKeys(
            function (string $sourceType): array {
                $table = $sourceType.'_definitions';

                return [$sourceType => DB::table($table)->orderBy('name')->get([
                    'id', 'content_key', 'name', 'repeatable', 'grant_rules',
                ])->map(function (object $definition): array {
                    return [
                        'id' => (int) data_get($definition, 'id'),
                        'content_key' => (string) data_get($definition, 'content_key'),
                        'name' => (string) data_get($definition, 'name'),
                        'repeatable' => (bool) data_get($definition, 'repeatable'),
                        'configuration_kind' => $this->sourceConfigurationKind($definition),
                    ];
                })->all()];
            },
        )->all();

        $removableSources = DB::table('character_source_instances')
            ->where('character_id', $characterId)
            ->whereIn('source_type', ['feat', 'species', 'background'])
            ->where('state', 'active')
            ->orderBy('source_type')
            ->orderBy('display_name')
            ->orderBy('id')
            ->get(['id', 'parent_source_instance_id', 'source_type', 'source_definition_id', 'display_name'])
            ->map(static fn (object $source): array => [
                'id' => (int) data_get($source, 'id'),
                'parent_source_instance_id' => data_get($source, 'parent_source_instance_id') === null
                    ? null : (int) data_get($source, 'parent_source_instance_id'),
                'source_type' => (string) data_get($source, 'source_type'),
                'source_definition_id' => (int) data_get($source, 'source_definition_id'),
                'display_name' => (string) data_get($source, 'display_name'),
            ])->all();

        return [
            'revision' => (int) DB::table('characters')->where('id', $characterId)->value('revision'),
            'report' => $report,
            'classes' => $classes,
            'available_classes' => DB::table('class_definitions')->orderBy('name')->get(['id', 'name'])
                ->map(static fn (object $class): array => [
                    'id' => (int) data_get($class, 'id'), 'name' => (string) data_get($class, 'name'),
                ])->all(),
            'allow_legacy' => (bool) DB::table('characters')->where('id', $characterId)->value('allow_legacy'),
            'configurable_sources' => $configurableSources,
            'order_sources' => $orderSources,
            'source_catalog' => $sourceCatalog,
            'removable_sources' => $removableSources,
            'spell_lists' => DB::table('class_definitions')->whereIn('name', ['Cleric', 'Druid', 'Wizard'])
                ->orderBy('name')->pluck('name')->all(),
            'slots' => $slots,
            'save_points' => DB::table('character_save_points')->where('character_id', $characterId)
                ->orderByDesc('id')->get(['id', 'label', 'created_at'])
                ->map(static fn (object $point): array => [
                    'id' => (int) data_get($point, 'id'),
                    'label' => (string) data_get($point, 'label'),
                    'created_at' => (string) data_get($point, 'created_at'),
                ])->all(),
        ];
    }

    /** @return list<array{id: int, name: string}> */
    private function subclasses(int $classDefinitionId): array
    {
        return DB::table('subclass_definitions')->where('class_definition_id', $classDefinitionId)
            ->orderBy('name')->get(['id', 'name'])
            ->map(static fn (object $row): array => [
                'id' => (int) data_get($row, 'id'), 'name' => (string) data_get($row, 'name'),
            ])->all();
    }

    private function sourceAbility(object $slot): ?string
    {
        $config = data_get($slot, 'source_config');
        $decoded = ($config === null || $config === '') ? [] : json_decode((string) $config, true, 512, JSON_THROW_ON_ERROR);
        $ability = data_get($decoded, 'spellcasting_ability');

        return is_string($ability) && $ability !== '' ? strtolower($ability) : null;
    }

    private function sourceConfigurationKind(object $definition): string
    {
        if (data_get($definition, 'content_key') === '2024:feat:magic-initiate') {
            return 'magic_initiate';
        }
        $rules = json_decode((string) data_get($definition, 'grant_rules', '[]'), true, 512, JSON_THROW_ON_ERROR);
        foreach (is_array($rules) ? $rules : [] as $rule) {
            if (is_array($rule)
                && data_get($rule, 'kind') === 'grant_source'
                && data_get($rule, 'source_type') === 'feat') {
                return 'origin_feat_magic_initiate';
            }
        }

        return 'none';
    }

    private function abilityModifier(int $score): int
    {
        return (int) floor(($score - 10) / 2);
    }

    private function slotLabel(object $slot): string
    {
        return str((string) data_get($slot, 'bucket'))->replace('_', ' ')->title()
            .' '.(int) data_get($slot, 'ordinal');
    }
}
