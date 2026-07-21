<?php

declare(strict_types=1);

namespace App\Domain\Spells;

use App\Domain\Grants\GrantRule;
use App\Domain\Grants\GrantRuleSlotGenerator;
use App\Domain\Rules\SpellSlots;
use Illuminate\Support\Facades\DB;

final readonly class SpellAccessBuilder
{
    public function __construct(private GrantRuleSlotGenerator $rules) {}

    /** @return list<array<string, mixed>> */
    public function buildForCharacter(int $characterId): array
    {
        $character = DB::table('characters')->find($characterId);
        if ($character === null) {
            return [];
        }

        $routes = $this->slotRoutes($character);
        $routes = [...$routes, ...$this->wizardRoutes($character)];
        usort($routes, static fn (array $left, array $right): int => [
            data_get($left, 'spell_name'), data_get($left, 'origin'), data_get($left, 'selection_key'),
        ] <=> [
            data_get($right, 'spell_name'), data_get($right, 'origin'), data_get($right, 'selection_key'),
        ]);

        return $routes;
    }

    /** @return list<array<string, mixed>> */
    private function slotRoutes(object $character): array
    {
        $rows = DB::table('spell_selection_slots as slot')
            ->join('character_source_instances as source', 'source.id', '=', 'slot.source_instance_id')
            ->join('spell_versions as version', function ($join): void {
                $join->on('version.id', '=', DB::raw('COALESCE(slot.fixed_spell_version_id, slot.current_spell_version_id)'));
            })
            ->join('spell_identities as identity', 'identity.id', '=', 'version.spell_identity_id')
            ->where('slot.character_id', data_get($character, 'id'))
            ->where('slot.state', 'active')
            ->where('source.state', 'active')
            ->select([
                'slot.*',
                'source.display_name as source_name',
                'source.source_type',
                'source.source_definition_id',
                'source.config as source_config',
                'version.id as route_spell_version_id',
                'version.spell_identity_id',
                'version.display_name as spell_name',
                'version.level as spell_level',
                'identity.canonical_name as identity_name',
            ])
            ->get();

        $routes = [];
        foreach ($rows as $row) {
            $ability = $this->spellcastingAbility($row);
            $freeCast = data_get($row, 'free_cast');
            $withSlots = (bool) data_get($row, 'with_slots');
            $spellLevel = (int) data_get($row, 'spell_level');
            $mode = match (true) {
                $spellLevel === 0 => 'at_will',
                $withSlots && $freeCast !== null => 'slots_and_free_cast',
                $withSlots => 'with_slots',
                $freeCast !== null => 'free_cast_only',
                default => 'granted',
            };
            $routes[] = $this->route($character, $row, [
                'origin' => 'slot',
                'casting_mode' => $mode,
                'spell_version_id' => (int) data_get($row, 'route_spell_version_id'),
                'source_instance_id' => (int) data_get($row, 'source_instance_id'),
                'source_name' => (string) data_get($row, 'source_name'),
                'slot_id' => (int) data_get($row, 'id'),
                'slot_key' => (string) data_get($row, 'slot_key'),
                'selection_key' => (string) data_get($row, 'slot_key'),
                'bucket' => (string) data_get($row, 'bucket'),
                'is_selection' => true,
                'counts_against_limit' => (bool) data_get($row, 'counts_against_limit'),
                'free_cast' => $freeCast === null ? null : json_decode((string) $freeCast, true, 512, JSON_THROW_ON_ERROR),
                'spellcasting_ability' => $ability,
            ]);
        }

        return $routes;
    }

    /** @return list<array<string, mixed>> */
    private function wizardRoutes(object $character): array
    {
        $entries = DB::table('wizard_spellbook_entries as entry')
            ->join('spell_versions as version', 'version.id', '=', 'entry.spell_version_id')
            ->join('spell_identities as identity', 'identity.id', '=', 'version.spell_identity_id')
            ->leftJoin('wizard_prepared_entries as prepared', function ($join): void {
                $join->on('prepared.wizard_spellbook_entry_id', '=', 'entry.id')
                    ->on('prepared.character_id', '=', 'entry.character_id');
            })
            ->leftJoin('character_source_instances as source', 'source.id', '=', 'entry.source_instance_id')
            ->where('entry.character_id', data_get($character, 'id'))
            ->select([
                'entry.*',
                'prepared.id as prepared_entry_id',
                'version.spell_identity_id',
                'version.display_name as spell_name',
                'version.level as spell_level',
                'version.ritual',
                'identity.canonical_name as identity_name',
                'source.display_name as source_name',
                'source.source_type',
                'source.source_definition_id',
                'source.config as source_config',
            ])
            ->orderBy('version.display_name')
            ->get();

        $capabilities = $this->ritualCapabilities((int) data_get($character, 'id'));
        $routes = [];
        foreach ($entries as $entry) {
            $prepared = data_get($entry, 'prepared_entry_id') !== null;
            $ritual = (bool) data_get($entry, 'ritual') || DB::table('spell_version_tags')
                ->where('spell_version_id', data_get($entry, 'spell_version_id'))
                ->where('tag', 'ritual')
                ->exists();
            if (! $prepared && (! $ritual || $capabilities === [])) {
                continue;
            }

            $capability = $prepared ? null : $capabilities[0];
            $sourceName = $prepared
                ? (string) data_get($entry, 'source_name', 'Wizard spellbook')
                : (string) data_get($capability, 'source_name');
            $sourceId = $prepared
                ? data_get($entry, 'source_instance_id')
                : data_get($capability, 'source_instance_id');
            $ability = $prepared
                ? $this->spellcastingAbility($entry)
                : (string) data_get($capability, 'spellcasting_ability');

            $routes[] = $this->route($character, $entry, [
                'origin' => $prepared ? 'wizard_prepared' : 'capability',
                'casting_mode' => $prepared ? 'with_slots' : 'ritual_only',
                'spell_version_id' => (int) data_get($entry, 'spell_version_id'),
                'source_instance_id' => $sourceId === null ? null : (int) $sourceId,
                'source_name' => $sourceName,
                'slot_id' => null,
                'slot_key' => null,
                'selection_key' => $prepared ? 'wizard-prepared:'.data_get($entry, 'id') : null,
                'bucket' => $prepared ? 'prepared' : null,
                'is_selection' => $prepared,
                'counts_against_limit' => $prepared,
                'free_cast' => null,
                'spellcasting_ability' => $ability,
                'spellbook_entry_id' => (int) data_get($entry, 'id'),
            ]);
        }

        return $routes;
    }

    /** @return list<array<string, mixed>> */
    private function ritualCapabilities(int $characterId): array
    {
        $sources = DB::table('character_source_instances')
            ->where('character_id', $characterId)
            ->where('state', 'active')
            ->get();
        $capabilities = [];
        foreach ($sources as $source) {
            foreach ($this->rules->activeRulesForSource((int) data_get($source, 'id')) as $rule) {
                $data = $rule->toArray();
                if ($rule->kind !== GrantRule::CAPABILITY
                    || data_get($data, 'collection') !== 'wizard_spellbook'
                    || data_get($data, 'access_mode') !== 'ritual_only'
                    || ! in_array('ritual', data_get($data, 'tags', []), true)) {
                    continue;
                }
                $capabilities[] = [
                    'source_instance_id' => (int) data_get($source, 'id'),
                    'source_name' => (string) data_get($source, 'display_name'),
                    'spellcasting_ability' => $this->spellcastingAbility($source),
                ];
            }
        }

        return $capabilities;
    }

    /** @param array<string, mixed> $specific */
    private function route(object $character, object $spell, array $specific): array
    {
        $ability = data_get($specific, 'spellcasting_ability');
        $modifier = is_string($ability) ? $this->abilityModifier($character, $ability) : null;
        $characterLevel = max(1, (int) DB::table('character_class_levels')
            ->where('character_id', data_get($character, 'id'))
            ->sum('level'));
        $proficiency = data_get($character, 'proficiency_bonus_override');
        $proficiency = $proficiency === null
            ? SpellSlots::proficiencyBonus($characterLevel)
            : (int) $proficiency;

        return array_merge([
            'spell_identity_id' => (int) data_get($spell, 'spell_identity_id'),
            'identity_name' => (string) data_get($spell, 'identity_name'),
            'spell_name' => (string) data_get($spell, 'spell_name'),
            'spell_level' => (int) data_get($spell, 'spell_level'),
            'ability_modifier' => $modifier,
            'attack_bonus' => $modifier === null ? null : $proficiency + $modifier,
            'save_dc' => $modifier === null ? null : 8 + $proficiency + $modifier,
        ], $specific);
    }

    private function spellcastingAbility(object $source): ?string
    {
        $configJson = data_get($source, 'source_config', data_get($source, 'config'));
        $config = ($configJson === null || $configJson === '')
            ? []
            : json_decode((string) $configJson, true, 512, JSON_THROW_ON_ERROR);
        $configured = data_get($config, 'spellcasting_ability');
        if (is_string($configured) && $configured !== '') {
            return strtolower($configured);
        }

        $sourceType = data_get($source, 'source_type');
        $table = match ($sourceType) {
            'class' => 'class_definitions',
            'subclass' => 'subclass_definitions',
            default => null,
        };
        if ($table === null) {
            return null;
        }
        $ability = DB::table($table)
            ->where('id', data_get($source, 'source_definition_id'))
            ->value('spellcasting_ability');

        return is_string($ability) ? strtolower($ability) : null;
    }

    private function abilityModifier(object $character, string $ability): int
    {
        return (int) floor(((int) data_get($character, strtolower($ability), 10) - 10) / 2);
    }
}
