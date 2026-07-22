<?php

declare(strict_types=1);

namespace App\Domain\Spells;

use App\Domain\Grants\GrantRule;
use App\Domain\Grants\GrantRuleSlotGenerator;
use App\Domain\Rules\Ability;
use App\Domain\Rules\AbilityScores;
use App\Domain\Rules\CastingMode;
use App\Domain\Rules\SpellSlots;
use Illuminate\Support\Facades\DB;

final readonly class SpellAccessBuilder
{
    public function __construct(
        private GrantRuleSlotGenerator $rules,
        private SpellSelectionEligibility $eligibility,
    ) {}

    /** @return list<array<string, mixed>> */
    public function buildForCharacter(int $characterId): array
    {
        $character = DB::table('characters')->find($characterId);
        if (! is_object($character)) {
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
            ->where('version.is_active', true)
            ->where(function ($query): void {
                $query->where('slot.state', 'kept_override')
                    ->orWhere(function ($ordinary): void {
                        $ordinary->where('slot.state', 'active')->where('source.state', 'active');
                    });
            })
            ->select([
                'slot.*',
                'source.display_name as source_name',
                'source.source_type',
                'source.source_definition_id',
                'source.config as source_config',
                'version.id as route_spell_version_id',
                'version.spell_identity_id',
                'version.display_name as spell_name',
                'version.content_key as spell_content_key',
                'version.rules_edition as spell_rules_edition',
                'version.level as spell_level',
                'identity.canonical_name as identity_name',
            ])
            ->get();

        $routes = [];
        foreach ($rows as $row) {
            SpellSlotAssignment::fromReferences(
                data_get($row, 'fixed_spell_version_id') === null
                    ? null : (int) data_get($row, 'fixed_spell_version_id'),
                data_get($row, 'current_spell_version_id') === null
                    ? null : (int) data_get($row, 'current_spell_version_id'),
            );
            if (data_get($row, 'state') !== 'kept_override'
                && data_get($this->eligibility->evaluate($row), 'status') !== 'valid') {
                continue;
            }
            $ability = $this->spellcastingAbility($row);
            $freeCast = data_get($row, 'free_cast');
            $withSlots = (bool) data_get($row, 'with_slots');
            $spellLevel = (int) data_get($row, 'spell_level');
            $mode = match (true) {
                $spellLevel === 0 => CastingMode::AtWill,
                $withSlots && $freeCast !== null => CastingMode::SlotsAndFreeCast,
                $withSlots => CastingMode::WithSlots,
                $freeCast !== null => CastingMode::FreeCastOnly,
                default => CastingMode::Granted,
            };
            $routes[] = $this->route($character, $row, [
                'origin' => 'slot',
                'casting_mode' => $mode->value,
                'spell_version_id' => (int) data_get($row, 'route_spell_version_id'),
                'source_instance_id' => (int) data_get($row, 'source_instance_id'),
                'source_name' => (string) data_get($row, 'source_name'),
                'slot_id' => (int) data_get($row, 'id'),
                'slot_key' => (string) data_get($row, 'slot_key'),
                'selection_key' => (string) data_get($row, 'slot_key'),
                'bucket' => (string) data_get($row, 'bucket'),
                'selection_collection' => data_get($row, 'selection_collection'),
                'is_selection' => true,
                'counts_against_limit' => (bool) data_get($row, 'counts_against_limit'),
                'free_cast' => $freeCast === null ? null : json_decode((string) $freeCast, true, 512, JSON_THROW_ON_ERROR),
                'spellcasting_ability' => $ability?->value,
            ]);
        }

        return $routes;
    }

    /** @return list<array<string, mixed>> */
    private function wizardRoutes(object $character): array
    {
        $preparedVersionIds = DB::table('spell_selection_slots as slot')
            ->join('character_source_instances as source', 'source.id', '=', 'slot.source_instance_id')
            ->where('slot.character_id', data_get($character, 'id'))
            ->where(function ($query): void {
                $query->where('slot.state', 'kept_override')
                    ->orWhere(function ($ordinary): void {
                        $ordinary->where('slot.state', 'active')->where('source.state', 'active');
                    });
            })
            ->where('slot.bucket', 'prepared')
            ->where('slot.selection_collection', 'wizard_spellbook')
            ->whereNotNull('slot.current_spell_version_id')
            ->select('slot.*')
            ->get()
            ->filter(fn (object $slot): bool => data_get($slot, 'state') === 'kept_override'
                || data_get($this->eligibility->evaluate($slot), 'status') === 'valid')
            ->pluck('current_spell_version_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        $entries = DB::table('wizard_spellbook_entries as entry')
            ->join('spell_versions as version', 'version.id', '=', 'entry.spell_version_id')
            ->join('spell_identities as identity', 'identity.id', '=', 'version.spell_identity_id')
            ->where('entry.character_id', data_get($character, 'id'))
            ->where('version.is_active', true)
            ->select([
                'entry.*',
                'version.spell_identity_id',
                'version.display_name as spell_name',
                'version.content_key as spell_content_key',
                'version.rules_edition as spell_rules_edition',
                'version.level as spell_level',
                'version.ritual',
                'identity.canonical_name as identity_name',
            ])
            ->orderBy('version.display_name')
            ->get();

        $capabilities = $this->ritualCapabilities((int) data_get($character, 'id'));
        $routes = [];
        foreach ($entries as $entry) {
            $prepared = in_array((int) data_get($entry, 'spell_version_id'), $preparedVersionIds, true);
            $ritual = (bool) data_get($entry, 'ritual') || DB::table('spell_version_tags')
                ->where('spell_version_id', data_get($entry, 'spell_version_id'))
                ->where('tag', 'ritual')
                ->exists();
            if ($prepared || ! $ritual || $capabilities === []) {
                continue;
            }

            $capability = $capabilities[0];

            $routes[] = $this->route($character, $entry, [
                'origin' => 'capability',
                'casting_mode' => CastingMode::RitualOnly->value,
                'spell_version_id' => (int) data_get($entry, 'spell_version_id'),
                'source_instance_id' => (int) data_get($capability, 'source_instance_id'),
                'source_name' => (string) data_get($capability, 'source_name'),
                'slot_id' => null,
                'slot_key' => null,
                'selection_key' => null,
                'bucket' => null,
                'is_selection' => false,
                'counts_against_limit' => false,
                'free_cast' => null,
                'spellcasting_ability' => data_get($capability, 'spellcasting_ability'),
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
                    'spellcasting_ability' => $this->spellcastingAbility($source)?->value,
                ];
            }
        }

        return $capabilities;
    }

    /**
     * @param  array<string, mixed>  $specific
     * @return array<string, mixed>
     */
    private function route(object $character, object $spell, array $specific): array
    {
        $abilityValue = data_get($specific, 'spellcasting_ability');
        $ability = is_string($abilityValue) ? Ability::tryFrom($abilityValue) : null;
        $characterLevel = max(1, (int) DB::table('character_class_levels')
            ->where('character_id', data_get($character, 'id'))
            ->sum('level'));
        $proficiency = data_get($character, 'proficiency_bonus_override');
        $proficiency = $proficiency === null
            ? SpellSlots::proficiencyBonus($characterLevel)
            : (int) $proficiency;
        $abilityScore = $ability === null
            ? null
            : AbilityScores::fromArray(get_object_vars($character))->score($ability);

        return array_merge([
            'spell_identity_id' => (int) data_get($spell, 'spell_identity_id'),
            'identity_name' => (string) data_get($spell, 'identity_name'),
            'spell_name' => (string) data_get($spell, 'spell_name'),
            'spell_content_key' => (string) data_get($spell, 'spell_content_key'),
            'rules_edition' => (string) data_get($spell, 'spell_rules_edition'),
            'spell_level' => (int) data_get($spell, 'spell_level'),
            'ability_modifier' => $abilityScore?->modifier(),
            'attack_bonus' => $abilityScore?->spellAttackBonus($proficiency)->value,
            'save_dc' => $abilityScore?->spellSaveDC($proficiency)->value,
        ], $specific);
    }

    private function spellcastingAbility(object $source): ?Ability
    {
        $configJson = data_get($source, 'source_config', data_get($source, 'config'));
        $config = ($configJson === null || $configJson === '')
            ? []
            : json_decode((string) $configJson, true, 512, JSON_THROW_ON_ERROR);
        $configured = data_get($config, 'spellcasting_ability');
        if (is_string($configured) && $configured !== '') {
            return Ability::tryFrom(strtolower($configured));
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

        return is_string($ability) ? Ability::tryFrom(strtolower($ability)) : null;
    }
}
