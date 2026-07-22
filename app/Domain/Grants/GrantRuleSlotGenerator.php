<?php

declare(strict_types=1);

namespace App\Domain\Grants;

use App\Domain\Spells\SpellSelectionEligibility;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

final class GrantRuleSlotGenerator
{
    public function __construct(private readonly SpellSelectionEligibility $eligibility) {}

    /** @return list<GrantRule> */
    public function activeRulesForSource(int $sourceInstanceId): array
    {
        $source = DB::table('character_source_instances')->find($sourceInstanceId);
        if ($source === null || data_get($source, 'state') !== 'active') {
            return [];
        }

        $rules = [];
        foreach ($this->rulesForSource($source) as $ruleData) {
            $rule = GrantRule::fromArray($ruleData);
            if ($rule->activeFromClassLevel !== null
                && $this->classLevelForSource($source) < $rule->activeFromClassLevel) {
                continue;
            }
            $rules[] = $rule;
        }

        return $rules;
    }

    public function generateForSource(int $sourceInstanceId): void
    {
        DB::transaction(function () use ($sourceInstanceId): void {
            $source = DB::table('character_source_instances')->find($sourceInstanceId);
            if ($source === null) {
                throw new InvalidArgumentException("Source instance {$sourceInstanceId} does not exist.");
            }

            if (data_get($source, 'state') !== 'active') {
                $this->deactivateSourceTree($sourceInstanceId);

                return;
            }

            $desiredSlotKeys = [];
            $desiredChildMarkers = [];
            foreach ($this->rulesForSource($source) as $ruleData) {
                $rule = GrantRule::fromArray($ruleData);
                $this->assertDistinctConfiguration($source, $rule);
                if ($rule->activeFromClassLevel !== null
                    && $this->classLevelForSource($source) < $rule->activeFromClassLevel) {
                    continue;
                }
                if ($rule->kind === GrantRule::FIXED_SPELL) {
                    $this->materializeFixedSpell($source, $rule);
                    $desiredSlotKeys[] = $this->slotKey($source, $rule, 1);
                }
                if ($rule->kind === GrantRule::CHOICE_FROM_LIST) {
                    $this->materializeListChoices($source, $rule);
                    for ($ordinal = 1; $ordinal <= (int) $rule->count; $ordinal++) {
                        $desiredSlotKeys[] = $this->slotKey($source, $rule, $ordinal);
                    }
                }
                if ($rule->kind === GrantRule::CHOICE_FROM_QUERY) {
                    $this->materializeQueryChoices($source, $rule);
                    for ($ordinal = 1; $ordinal <= (int) $rule->count; $ordinal++) {
                        $desiredSlotKeys[] = $this->slotKey($source, $rule, $ordinal);
                    }
                }
                if ($rule->kind === GrantRule::GRANT_SOURCE) {
                    array_push($desiredChildMarkers, ...$this->materializeGrantedSources($source, $rule));
                }
                if ($rule->kind === GrantRule::SPELLBOOK_ACQUISITION) {
                    $this->materializeSpellbookAcquisitions($source, $rule);
                }
            }

            $this->reconcileSlots($source, $desiredSlotKeys);
            $this->reconcileGrantedChildren($source, $desiredChildMarkers);
        });
    }

    /** @return list<array<string, mixed>> */
    private function rulesForSource(object $source): array
    {
        if (data_get($source, 'source_type') === 'class') {
            return $this->rulesForClassSource($source);
        }
        if (data_get($source, 'source_type') === 'subclass') {
            return $this->rulesForSubclassSource($source);
        }

        $table = match (data_get($source, 'source_type')) {
            'feat' => 'feat_definitions',
            'species' => 'species_definitions',
            'background' => 'background_definitions',
            'subclass' => 'subclass_definitions',
            default => throw new InvalidArgumentException(
                "Unsupported grant source type '".data_get($source, 'source_type')."'."
            ),
        };
        $definition = DB::table($table)->find((int) data_get($source, 'source_definition_id'));
        if ($definition === null) {
            throw new RuntimeException('Definition for source instance '.data_get($source, 'id').' does not exist.');
        }

        $rules = $this->decodeJsonArray(data_get($definition, 'grant_rules'));
        if (! is_array($rules)) {
            throw new InvalidArgumentException('Grant rules for source instance '.data_get($source, 'id').' must be a list.');
        }

        return array_values($rules);
    }

    /** @return list<array<string, mixed>> */
    private function rulesForClassSource(object $source): array
    {
        $classLevel = DB::table('character_class_levels')
            ->where('character_id', data_get($source, 'character_id'))
            ->where('class_definition_id', data_get($source, 'source_definition_id'))
            ->value('level');
        if ($classLevel === null) {
            throw new RuntimeException('Class source instance '.data_get($source, 'id').' has no character class level.');
        }

        $byRuleKey = [];
        $progressions = DB::table('class_progressions')
            ->where('class_definition_id', data_get($source, 'source_definition_id'))
            ->where('class_level', '<=', $classLevel)
            ->orderBy('class_level')
            ->get();
        foreach ($progressions as $progression) {
            $rules = $this->decodeJsonArray(data_get($progression, 'grant_rules'));
            foreach (is_array($rules) ? $rules : [] as $rule) {
                if (! is_array($rule)) {
                    throw new InvalidArgumentException('Class progression grant rules must be objects.');
                }
                $key = data_get($rule, 'rule_key');
                if (! is_string($key) || $key === '') {
                    GrantRule::fromArray($rule);
                }
                $byRuleKey[(string) $key] = $rule;
            }
        }

        return array_values($byRuleKey);
    }

    /** @return list<array<string, mixed>> */
    private function rulesForSubclassSource(object $source): array
    {
        $classLevel = $this->classLevelForSource($source);
        $byRuleKey = [];
        $definition = DB::table('subclass_definitions')
            ->find((int) data_get($source, 'source_definition_id'));
        if ($definition === null) {
            throw new RuntimeException(
                'Definition for subclass source instance '.data_get($source, 'id').' does not exist.'
            );
        }
        foreach ($this->decodeJsonArray(data_get($definition, 'grant_rules')) as $rule) {
            if (! is_array($rule)) {
                throw new InvalidArgumentException('Static subclass grant rules must be objects.');
            }
            $validated = GrantRule::fromArray($rule);
            $byRuleKey[$validated->ruleKey] = $rule;
        }

        $progressions = DB::table('subclass_progressions')
            ->where('subclass_definition_id', data_get($source, 'source_definition_id'))
            ->where('class_level', '<=', $classLevel)
            ->orderBy('class_level')
            ->get();
        foreach ($progressions as $progression) {
            foreach ($this->decodeJsonArray(data_get($progression, 'grant_rules')) as $rule) {
                if (! is_array($rule)) {
                    throw new InvalidArgumentException('Subclass progression grant rules must be objects.');
                }
                $validated = GrantRule::fromArray($rule);
                $byRuleKey[$validated->ruleKey] = $rule;
            }
        }

        return array_values($byRuleKey);
    }

    private function materializeFixedSpell(object $source, GrantRule $rule): void
    {
        $data = $rule->toArray();
        $spellVersionId = data_get($data, 'spell_version_id');
        if ($spellVersionId === null) {
            $spellVersionId = DB::table('spell_versions')
                ->where('content_key', data_get($data, 'spell_version_key'))
                ->value('id');
        }
        $version = $spellVersionId === null
            ? null
            : DB::table('spell_versions')->find((int) $spellVersionId);
        if ($version === null) {
            throw new RuntimeException(
                "Grant rule '{$rule->ruleKey}' references a spell version that does not exist."
            );
        }
        $existingReference = DB::table('spell_selection_slots')
            ->where('character_id', data_get($source, 'character_id'))
            ->where('slot_key', $this->slotKey($source, $rule, 1))
            ->where('fixed_spell_version_id', $spellVersionId)
            ->exists();
        if (! (bool) data_get($version, 'is_active', true) && ! $existingReference) {
            throw new RuntimeException(
                "Grant rule '{$rule->ruleKey}' references an inactive spell version."
            );
        }

        $this->syncSlot($source, $rule, 1, [
            'fixed_spell_version_id' => $spellVersionId,
            'current_spell_version_id' => null,
            'spell_level_min' => 0,
            'spell_level_max' => 9,
            'allowed_spell_lists' => null,
            'allowed_schools' => null,
            'allowed_tags' => null,
            'selection_collection' => null,
            'counts_against_limit' => (bool) data_get($data, 'counts_against_limit', false),
            'required' => true,
            'is_locked' => true,
        ]);
    }

    private function materializeListChoices(object $source, GrantRule $rule): void
    {
        $data = $rule->toArray();
        $list = (string) data_get($data, 'list');
        if (str_starts_with($list, '$config.')) {
            $config = $this->decodeJsonArray(data_get($source, 'config'));
            $list = (string) data_get($config, substr($list, strlen('$config.')), '');
        }
        if ($list === '') {
            throw new InvalidArgumentException("Grant rule '{$rule->ruleKey}' could not resolve its spell list.");
        }

        for ($ordinal = 1; $ordinal <= (int) $rule->count; $ordinal++) {
            $this->syncSlot($source, $rule, $ordinal, [
                'fixed_spell_version_id' => null,
                'spell_level_min' => (int) data_get($data, 'level_min', 0),
                'spell_level_max' => (int) data_get($data, 'level_max', 9),
                'allowed_spell_lists' => json_encode([$list], JSON_THROW_ON_ERROR),
                'allowed_schools' => null,
                'allowed_tags' => null,
                'selection_collection' => data_get($data, 'selection_collection'),
                'counts_against_limit' => (bool) data_get($data, 'counts_against_limit', true),
                'required' => (bool) data_get($data, 'required', true),
                'is_locked' => false,
            ]);
        }
    }

    private function materializeQueryChoices(object $source, GrantRule $rule): void
    {
        $data = $rule->toArray();
        for ($ordinal = 1; $ordinal <= (int) $rule->count; $ordinal++) {
            $this->syncSlot($source, $rule, $ordinal, [
                'fixed_spell_version_id' => null,
                'spell_level_min' => (int) data_get($data, 'level_min', 0),
                'spell_level_max' => (int) data_get($data, 'level_max', 9),
                'allowed_spell_lists' => null,
                'allowed_schools' => $this->nullableJsonList(data_get($data, 'schools')),
                'allowed_tags' => $this->nullableJsonList(data_get($data, 'tags')),
                'selection_collection' => data_get($data, 'selection_collection'),
                'counts_against_limit' => (bool) data_get($data, 'counts_against_limit', true),
                'required' => (bool) data_get($data, 'required', true),
                'is_locked' => false,
            ]);
        }
    }

    private function nullableJsonList(mixed $value): ?string
    {
        return is_array($value) ? json_encode(array_values($value), JSON_THROW_ON_ERROR) : null;
    }

    /** @return list<string> */
    private function materializeGrantedSources(object $source, GrantRule $rule): array
    {
        $data = $rule->toArray();
        $sourceType = (string) data_get($data, 'source_type');
        $definitionTable = $this->definitionTable($sourceType);
        $parentConfig = $this->decodeJsonArray(data_get($source, 'config'));

        $definitionId = data_get($data, 'source_definition_id');
        $definitionKey = data_get($data, 'source_definition_key');
        $definitionConfigKey = data_get($data, 'definition_key_config');
        if (is_string($definitionConfigKey)) {
            $definitionKey = data_get($parentConfig, $definitionConfigKey);
        }
        if ($definitionId === null && is_string($definitionKey)) {
            $definitionId = DB::table($definitionTable)->where('content_key', $definitionKey)->value('id');
        }
        $definition = $definitionId === null ? null : DB::table($definitionTable)->find((int) $definitionId);
        if ($definition === null) {
            throw new RuntimeException("Grant-source rule '{$rule->ruleKey}' could not resolve its definition.");
        }

        $childConfig = data_get($data, 'child_config', []);
        $childConfigConfig = data_get($data, 'child_config_config');
        if (is_string($childConfigConfig)) {
            $childConfig = data_get($parentConfig, $childConfigConfig, []);
        }
        if (! is_array($childConfig)) {
            throw new InvalidArgumentException("Grant-source rule '{$rule->ruleKey}' child config must be an object.");
        }

        $markers = [];
        for ($ordinal = 1; $ordinal <= (int) $rule->count; $ordinal++) {
            $marker = 'grant_rule:'.$rule->ruleKey.':'.$ordinal;
            $markers[] = $marker;
            $child = DB::table('character_source_instances')
                ->where('parent_source_instance_id', data_get($source, 'id'))
                ->where('notes', $marker)
                ->first();
            $attributes = [
                'character_id' => (int) data_get($source, 'character_id'),
                'parent_source_instance_id' => (int) data_get($source, 'id'),
                'source_type' => $sourceType,
                'source_definition_id' => (int) data_get($definition, 'id'),
                'display_name' => $this->grantedSourceDisplayName($definition, $childConfig),
                'config' => json_encode($childConfig, JSON_THROW_ON_ERROR),
                'acquired_at_character_level' => data_get($source, 'acquired_at_character_level'),
                'state' => 'active',
                'notes' => $marker,
            ];
            if ($child === null) {
                $childId = DB::table('character_source_instances')->insertGetId(array_merge(
                    ['instance_uuid' => Str::uuid()->toString()],
                    $attributes,
                    ['created_at' => now(), 'updated_at' => now()],
                ));
            } else {
                $childId = (int) data_get($child, 'id');
                $changes = [];
                foreach ($attributes as $column => $value) {
                    if (data_get($child, $column) != $value) {
                        $changes[$column] = $value;
                    }
                }
                if ($changes !== []) {
                    $changes['updated_at'] = now();
                    DB::table('character_source_instances')->where('id', $childId)->update($changes);
                }
            }

            $this->generateForSource($childId);
        }

        return $markers;
    }

    private function definitionTable(string $sourceType): string
    {
        return match ($sourceType) {
            'feat' => 'feat_definitions',
            'species' => 'species_definitions',
            'background' => 'background_definitions',
            'subclass' => 'subclass_definitions',
            'class' => 'class_definitions',
            default => throw new InvalidArgumentException("Unsupported grant source type '{$sourceType}'."),
        };
    }

    /** @param array<string, mixed> $config */
    private function grantedSourceDisplayName(object $definition, array $config): string
    {
        $name = (string) data_get($definition, 'name');
        $chosenList = data_get($config, 'chosen_list');

        return is_string($chosenList) && $chosenList !== '' ? "{$name}: {$chosenList}" : $name;
    }

    /** @param array<string, mixed> $eligibility */
    private function syncSlot(object $source, GrantRule $rule, int $ordinal, array $eligibility): void
    {
        $data = $rule->toArray();
        $identity = [
            'character_id' => (int) data_get($source, 'character_id'),
            'slot_key' => $this->slotKey($source, $rule, $ordinal),
        ];
        $attributes = array_merge([
            'source_instance_id' => (int) data_get($source, 'id'),
            'rule_key' => $rule->ruleKey,
            'ordinal' => $ordinal,
            'bucket' => $rule->bucket,
            'eligibility_kind' => $rule->kind,
            'label' => data_get($data, 'label'),
            'always_prepared' => $rule->alwaysPrepared,
            'with_slots' => $rule->withSlots,
            'free_cast' => $rule->freeCast === null ? null : json_encode($rule->freeCast, JSON_THROW_ON_ERROR),
            'state' => 'active',
            'orphan_reason_code' => null,
            'orphaned_at' => null,
            'sort_order' => $ordinal,
        ], $eligibility);

        $existing = DB::table('spell_selection_slots')->where($identity)->first();
        if ($existing === null) {
            $slotId = DB::table('spell_selection_slots')->insertGetId(array_merge(
                $identity,
                ['current_spell_version_id' => null],
                $attributes,
                ['created_at' => now(), 'updated_at' => now()],
            ));
            $this->eligibility->refresh($slotId);

            return;
        }

        // An explicit user override survives unrelated source regeneration. The
        // slot can be returned to ordinary eligibility only by a slot command.
        if (data_get($existing, 'state') === 'kept_override') {
            $attributes['state'] = 'kept_override';
        }

        $changes = [];
        foreach ($attributes as $column => $value) {
            if (data_get($existing, $column) != $value) {
                $changes[$column] = $value;
            }
        }
        if ($changes !== []) {
            $changes['updated_at'] = now();
            DB::table('spell_selection_slots')->where('id', data_get($existing, 'id'))->update($changes);
        }
        $this->eligibility->refresh((int) data_get($existing, 'id'));
    }

    private function slotKey(object $source, GrantRule $rule, int $ordinal): string
    {
        return data_get($source, 'instance_uuid').':'.$rule->ruleKey.':'.$ordinal;
    }

    /** @param list<string> $desiredSlotKeys */
    private function reconcileSlots(object $source, array $desiredSlotKeys): void
    {
        $existing = DB::table('spell_selection_slots')
            ->where('source_instance_id', data_get($source, 'id'))
            ->whereIn('state', ['active', 'kept_override'])
            ->get();

        foreach ($existing as $slot) {
            if (in_array(data_get($slot, 'slot_key'), $desiredSlotKeys, true)) {
                continue;
            }
            DB::table('spell_selection_slots')->where('id', data_get($slot, 'id'))->update([
                'state' => 'orphaned',
                'orphan_reason_code' => 'rule_no_longer_active',
                'orphaned_at' => now(),
                'prior_config' => data_get($source, 'config'),
                'selection_eligibility' => $this->hasSpellReference($slot) ? 'invalid' : 'unselected',
                'selection_invalid_reason' => $this->hasSpellReference($slot)
                    ? 'Selection preserved because its grant rule is no longer active.'
                    : null,
                'updated_at' => now(),
            ]);
        }
    }

    /** @param list<string> $desiredMarkers */
    private function reconcileGrantedChildren(object $source, array $desiredMarkers): void
    {
        $children = DB::table('character_source_instances')
            ->where('parent_source_instance_id', data_get($source, 'id'))
            ->where('notes', 'like', 'grant_rule:%')
            ->get();

        foreach ($children as $child) {
            if (in_array(data_get($child, 'notes'), $desiredMarkers, true)) {
                continue;
            }
            $this->deactivateSourceTree((int) data_get($child, 'id'));
        }
    }

    private function deactivateSourceTree(int $sourceInstanceId): void
    {
        $source = DB::table('character_source_instances')->find($sourceInstanceId);
        if ($source === null) {
            return;
        }

        foreach (DB::table('character_source_instances')->where('parent_source_instance_id', $sourceInstanceId)->get() as $child) {
            $this->deactivateSourceTree((int) data_get($child, 'id'));
        }

        $slots = DB::table('spell_selection_slots')
            ->where('source_instance_id', $sourceInstanceId)
            ->whereIn('state', ['active', 'kept_override'])
            ->get();
        foreach ($slots as $slot) {
            DB::table('spell_selection_slots')->where('id', data_get($slot, 'id'))->update([
                'state' => 'orphaned',
                'orphan_reason_code' => 'parent_rule_removed',
                'orphaned_at' => now(),
                'prior_config' => data_get($source, 'config'),
                'selection_eligibility' => $this->hasSpellReference($slot) ? 'invalid' : 'unselected',
                'selection_invalid_reason' => $this->hasSpellReference($slot)
                    ? 'Selection preserved because its source is no longer active.'
                    : null,
                'updated_at' => now(),
            ]);
        }

        if (data_get($source, 'state') !== 'tombstoned') {
            DB::table('character_source_instances')->where('id', $sourceInstanceId)->update([
                'state' => 'tombstoned',
                'updated_at' => now(),
            ]);
        }
    }

    private function hasSpellReference(object $slot): bool
    {
        return data_get($slot, 'fixed_spell_version_id') !== null
            || data_get($slot, 'current_spell_version_id') !== null;
    }

    private function classLevelForSource(object $source): int
    {
        $classDefinitionId = data_get($source, 'source_definition_id');
        if (data_get($source, 'source_type') === 'subclass') {
            $classDefinitionId = DB::table('subclass_definitions')
                ->where('id', $classDefinitionId)
                ->value('class_definition_id');
        }
        if (! in_array(data_get($source, 'source_type'), ['class', 'subclass'], true)) {
            $configuredLevel = data_get(
                $this->decodeJsonArray(data_get($source, 'config')),
                'class_level',
            );
            if (is_int($configuredLevel)) {
                return $configuredLevel;
            }

            throw new InvalidArgumentException(
                'Rule active_from_class_level requires a class, subclass, or configured class_level source.'
            );
        }

        $level = DB::table('character_class_levels')
            ->where('character_id', data_get($source, 'character_id'))
            ->where('class_definition_id', $classDefinitionId)
            ->value('level');
        if ($level === null) {
            throw new RuntimeException('Source instance '.data_get($source, 'id').' has no matching class level.');
        }

        return (int) $level;
    }

    private function assertDistinctConfiguration(object $source, GrantRule $rule): void
    {
        if ($rule->distinctConfigBy === null) {
            return;
        }

        $config = $this->decodeJsonArray(data_get($source, 'config'));
        $value = data_get($config, $rule->distinctConfigBy);
        if ($value === null) {
            throw new InvalidArgumentException(
                "Grant rule '{$rule->ruleKey}' requires config '{$rule->distinctConfigBy}'."
            );
        }

        $others = DB::table('character_source_instances')
            ->where('character_id', data_get($source, 'character_id'))
            ->where('source_type', data_get($source, 'source_type'))
            ->where('source_definition_id', data_get($source, 'source_definition_id'))
            ->where('state', 'active')
            ->where('id', '!=', data_get($source, 'id'))
            ->get();
        foreach ($others as $other) {
            $otherConfig = $this->decodeJsonArray(data_get($other, 'config'));
            if (data_get($otherConfig, $rule->distinctConfigBy) === $value) {
                $definition = DB::table($this->definitionTable((string) data_get($source, 'source_type')))
                    ->find((int) data_get($source, 'source_definition_id'));
                $name = (string) data_get($definition, 'name', data_get($source, 'display_name'));
                $shown = is_scalar($value) ? (string) $value : json_encode($value, JSON_THROW_ON_ERROR);
                throw new InvalidArgumentException(
                    "{$name} already uses {$rule->distinctConfigBy} '{$shown}' for this character."
                );
            }
        }
    }

    private function materializeSpellbookAcquisitions(object $source, GrantRule $rule): void
    {
        $data = $rule->toArray();
        $config = $this->decodeJsonArray(data_get($source, 'config'));
        $configKey = (string) data_get($data, 'acquisitions_config');
        $acquisitions = data_get($config, $configKey, []);
        if (! is_array($acquisitions)) {
            throw new InvalidArgumentException(
                "Spellbook rule '{$rule->ruleKey}' config '{$configKey}' must be a list."
            );
        }

        foreach ($acquisitions as $index => $acquisition) {
            if (! is_array($acquisition)) {
                throw new InvalidArgumentException(
                    "Spellbook rule '{$rule->ruleKey}' acquisition {$index} must be an object."
                );
            }
            $spellVersionId = data_get($acquisition, 'spell_version_id');
            if ($spellVersionId === null && is_string(data_get($acquisition, 'spell_version_key'))) {
                $spellVersionId = DB::table('spell_versions')
                    ->where('content_key', data_get($acquisition, 'spell_version_key'))
                    ->value('id');
            }
            if ($spellVersionId === null) {
                throw new RuntimeException(
                    "Spellbook rule '{$rule->ruleKey}' acquisition {$index} could not resolve its spell."
                );
            }

            $version = DB::table('spell_versions')->find((int) $spellVersionId);
            if ($version === null) {
                throw new RuntimeException(
                    "Spellbook rule '{$rule->ruleKey}' acquisition {$index} could not resolve its spell."
                );
            }
            $existingEntry = DB::table('wizard_spellbook_entries')
                ->where('character_id', data_get($source, 'character_id'))
                ->where('spell_version_id', $spellVersionId)
                ->exists();
            if (! (bool) data_get($version, 'is_active', true) && ! $existingEntry) {
                throw new RuntimeException(
                    "Spellbook rule '{$rule->ruleKey}' acquisition {$index} references an inactive spell version."
                );
            }

            $acquisitionType = data_get($acquisition, 'acquisition');
            if (! in_array($acquisitionType, ['starting', 'level_up', 'copied', 'granted'], true)) {
                throw new InvalidArgumentException(
                    "Spellbook rule '{$rule->ruleKey}' acquisition {$index} has invalid provenance."
                );
            }
            $list = (string) data_get($data, 'list');
            $eligible = DB::table('spell_list_memberships')
                ->where('spell_version_id', $spellVersionId)
                ->where('spell_list_key', $list)
                ->exists();
            if (! $eligible) {
                throw new InvalidArgumentException(
                    "Spellbook rule '{$rule->ruleKey}' acquisition {$index} is not on the {$list} list."
                );
            }

            $identity = [
                'character_id' => (int) data_get($source, 'character_id'),
                'spell_version_id' => (int) $spellVersionId,
            ];
            if (! $existingEntry) {
                DB::table('wizard_spellbook_entries')->insert(array_merge($identity, [
                    'acquisition' => $acquisitionType,
                    'copy_cost_gp' => data_get($acquisition, 'copy_cost_gp'),
                    'copy_time_hours' => data_get($acquisition, 'copy_time_hours'),
                    'source_instance_id' => (int) data_get($source, 'id'),
                    'notes' => data_get($acquisition, 'notes'),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]));
            }
        }
    }

    /** @return array<string|int, mixed> */
    private function decodeJsonArray(mixed $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }
        $decoded = json_decode((string) $json, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw new InvalidArgumentException('Grant-rule JSON values must decode to arrays or objects.');
        }

        return $decoded;
    }
}
