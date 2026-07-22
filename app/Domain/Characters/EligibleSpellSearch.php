<?php

declare(strict_types=1);

namespace App\Domain\Characters;

use App\Domain\Spells\SpellSelectionEligibility;
use Illuminate\Support\Facades\DB;

final readonly class EligibleSpellSearch
{
    public function __construct(private SpellSelectionEligibility $eligibility) {}

    /** @return list<array<string, mixed>> */
    public function search(int $characterId, int $slotId, string $query): array
    {
        $slot = DB::table('spell_selection_slots')
            ->where('character_id', $characterId)
            ->where('id', $slotId)
            ->first();
        abort_if($slot === null, 404);
        $allowLegacy = (bool) DB::table('characters')->where('id', $characterId)->value('allow_legacy');

        $lists = $this->jsonList(data_get($slot, 'allowed_spell_lists'));
        $schools = $this->jsonList(data_get($slot, 'allowed_schools'));
        $tags = $this->jsonList(data_get($slot, 'allowed_tags'));

        $candidates = DB::table('spell_versions')
            ->where('is_active', true)
            ->when(! $allowLegacy, fn ($builder) => $builder->where('rules_edition', '!=', '2014'))
            ->whereBetween('level', [
                (int) data_get($slot, 'spell_level_min'),
                (int) data_get($slot, 'spell_level_max'),
            ])
            ->when($query !== '', function ($builder) use ($query): void {
                $builder->where('display_name', 'like', '%'.str_replace(['%', '_'], ['\\%', '\\_'], $query).'%');
            })
            ->when($lists !== [], function ($builder) use ($lists): void {
                $builder->where(function ($eligibleList) use ($lists): void {
                    $eligibleList->whereExists(function ($membership) use ($lists): void {
                        $membership->selectRaw('1')
                            ->from('spell_list_memberships')
                            ->whereColumn('spell_list_memberships.spell_version_id', 'spell_versions.id')
                            ->whereIn('spell_list_memberships.spell_list_key', $lists);
                    })->orWhere(function ($legacy) use ($lists): void {
                        $legacy->where('spell_versions.rules_edition', '2014')
                            ->whereExists(function ($membership) use ($lists): void {
                                $membership->selectRaw('1')
                                    ->from('spell_list_memberships')
                                    ->join('spell_versions as listed_version', 'listed_version.id', '=', 'spell_list_memberships.spell_version_id')
                                    ->whereColumn('listed_version.spell_identity_id', 'spell_versions.spell_identity_id')
                                    ->whereIn('spell_list_memberships.spell_list_key', $lists);
                            });
                    });
                });
            })
            ->when($schools !== [], fn ($builder) => $builder->whereIn('school', $schools))
            ->when($tags !== [], function ($builder) use ($tags): void {
                foreach ($tags as $tag) {
                    $builder->whereExists(function ($tagQuery) use ($tag): void {
                        $tagQuery->selectRaw('1')
                            ->from('spell_version_tags')
                            ->whereColumn('spell_version_tags.spell_version_id', 'spell_versions.id')
                            ->where('spell_version_tags.tag', $tag);
                    });
                }
            })
            ->orderBy('level')
            ->orderBy('display_name')
            ->limit(50)
            ->get()
            ->filter(fn (object $version): bool => data_get(
                $this->eligibility->evaluate($slot, (int) data_get($version, 'id')),
                'status',
            ) === 'valid')
            ->take(50);

        return array_values($candidates->map(static fn (object $version): array => [
            'id' => (int) data_get($version, 'id'),
            'name' => (string) data_get($version, 'display_name'),
            'level' => (int) data_get($version, 'level'),
            'school' => (string) data_get($version, 'school'),
            'ritual' => (bool) data_get($version, 'ritual'),
            'concentration' => (bool) data_get($version, 'concentration'),
            'edition' => (string) data_get($version, 'rules_edition'),
        ])->all());
    }

    /** @return list<string> */
    private function jsonList(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        if (is_array($value)) {
            return array_values($value);
        }
        $decoded = json_decode((string) $value, true, 512, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? array_values($decoded) : [];
    }
}
