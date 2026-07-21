<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;
use RuntimeException;
use Throwable;

final class CatalogImporter
{
    /**
     * @return array{created: int, updated: int, tombstoned: int, identities_created: int, identities_updated: int, publications_created: int, memberships_created: int, tags_created: int, attack_modes_created: int, save_abilities_created: int}
     */
    public function importDirectory(string $directory, bool $dryRun = false): array
    {
        $records = $this->loadRecords($directory);
        DB::beginTransaction();

        try {
            $summary = $this->importRecords($records);
            if ($dryRun) {
                DB::rollBack();
            } else {
                DB::commit();
            }

            return $summary;
        } catch (Throwable $exception) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            throw $exception;
        }
    }

    /** @return list<array<string, mixed>> */
    private function loadRecords(string $directory): array
    {
        $files = glob(rtrim($directory, '/').'/*.json');
        if ($files === false || $files === []) {
            throw new InvalidArgumentException("No JSON catalog files found in {$directory}.");
        }
        sort($files);

        $byVersion = [];
        foreach ($files as $file) {
            try {
                $decoded = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new InvalidArgumentException("Invalid catalog JSON in {$file}: {$exception->getMessage()}", 0, $exception);
            }
            if (! is_array($decoded) || ! array_is_list($decoded)) {
                throw new InvalidArgumentException("Catalog file {$file} must contain a JSON list.");
            }
            foreach ($decoded as $record) {
                if (! is_array($record)) {
                    throw new InvalidArgumentException("Catalog file {$file} contains a non-object record.");
                }
                $this->validateRecord($record);
                $versionKey = (string) data_get($record, 'versionKey');
                $record['_publications'] = [];
                foreach (data_get($record, 'sourceBooks', []) as $book) {
                    $record['_publications'][(string) $book] = [
                        'source_page' => data_get($record, 'sourcePage'),
                        'source_reference' => data_get($record, 'sourceSlug'),
                    ];
                }
                if (! isset($byVersion[$versionKey])) {
                    $byVersion[$versionKey] = $record;

                    continue;
                }
                foreach (['sourceBooks', 'spellLists', 'attackModes', 'saveAbilities', 'tags'] as $field) {
                    $byVersion[$versionKey][$field] = array_values(array_unique([
                        ...data_get($byVersion[$versionKey], $field, []),
                        ...data_get($record, $field, []),
                    ]));
                }
                $byVersion[$versionKey]['_publications'] = array_merge(
                    data_get($byVersion[$versionKey], '_publications', []),
                    data_get($record, '_publications', []),
                );
            }
        }

        $canonicalByIdentity = [];
        $priorityByIdentity = [];
        foreach ($byVersion as $record) {
            $identityKey = (string) data_get($record, 'identityKey');
            $edition = (string) data_get($record, 'edition');
            $priority = match ($edition) {
                '2024' => 3,
                'expanded' => 2,
                default => 1,
            };
            if ($priority > data_get($priorityByIdentity, $identityKey, 0)) {
                $priorityByIdentity[$identityKey] = $priority;
                $canonicalByIdentity[$identityKey] = (string) data_get($record, 'name');
            }
        }
        foreach ($byVersion as &$record) {
            $record['_canonical_name'] = data_get($canonicalByIdentity, (string) data_get($record, 'identityKey'));
        }
        unset($record);
        ksort($byVersion);

        return array_values($byVersion);
    }

    /** @param array<string, mixed> $record */
    private function validateRecord(array $record): void
    {
        foreach (['identityKey', 'versionKey', 'name', 'edition', 'school'] as $field) {
            $value = data_get($record, $field);
            if (! is_string($value) || trim($value) === '') {
                throw new InvalidArgumentException("Catalog field '{$field}' must be a non-empty string.");
            }
        }
        if (! is_int(data_get($record, 'level'))) {
            throw new InvalidArgumentException("Catalog field 'level' must be an integer.");
        }
        foreach (['concentration', 'ritual'] as $field) {
            if (! is_bool(data_get($record, $field))) {
                throw new InvalidArgumentException("Catalog field '{$field}' must be boolean.");
            }
        }
        foreach (['attackModes', 'saveAbilities', 'spellLists', 'sourceBooks'] as $field) {
            $value = data_get($record, $field);
            if (! is_array($value)) {
                throw new InvalidArgumentException("Catalog field '{$field}' must be a list.");
            }
            foreach ($value as $item) {
                if (! is_string($item) || trim($item) === '') {
                    throw new InvalidArgumentException("Catalog field '{$field}' must contain non-empty strings.");
                }
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $records
     * @return array{created: int, updated: int, tombstoned: int, identities_created: int, identities_updated: int, publications_created: int, memberships_created: int, tags_created: int, attack_modes_created: int, save_abilities_created: int}
     */
    private function importRecords(array $records): array
    {
        $summary = [
            'created' => 0,
            'updated' => 0,
            'tombstoned' => 0,
            'identities_created' => 0,
            'identities_updated' => 0,
            'publications_created' => 0,
            'memberships_created' => 0,
            'tags_created' => 0,
            'attack_modes_created' => 0,
            'save_abilities_created' => 0,
        ];
        $seenVersionKeys = [];

        foreach ($records as $record) {
            $identityId = $this->resolveIdentity($record, $summary);
            $versionKey = (string) data_get($record, 'versionKey');
            $seenVersionKeys[] = $versionKey;
            $version = DB::table('spell_versions')->where('content_key', $versionKey)->first();
            if ($version === null) {
                $versionId = DB::table('spell_versions')->insertGetId(array_merge(
                    $this->versionAttributes($record, $identityId),
                    ['created_at' => now(), 'updated_at' => now()],
                ));
                $summary['created']++;
                $referenced = false;
                $versionChanged = false;
            } else {
                $versionId = (int) data_get($version, 'id');
                $referenced = $this->isReferenced($versionId);
                $changes = [];
                if (! (bool) data_get($version, 'is_active')) {
                    $changes['is_active'] = true;
                }
                if (! $referenced) {
                    foreach ($this->versionAttributes($record, $identityId) as $column => $value) {
                        if (data_get($version, $column) != $value) {
                            $changes[$column] = $value;
                        }
                    }
                }
                if ($changes !== []) {
                    $changes['updated_at'] = now();
                    DB::table('spell_versions')->where('id', $versionId)->update($changes);
                }
                $versionChanged = $changes !== [];
            }

            if (! $referenced) {
                $versionChanged = $this->syncPublications(
                    $versionId,
                    data_get($record, '_publications', []),
                    $summary,
                ) || $versionChanged;
                $versionChanged = $this->syncSimplePivot(
                    'spell_list_memberships', 'spell_list_key', $versionId,
                    data_get($record, 'spellLists', []), 'memberships_created', $summary, true,
                ) || $versionChanged;
                $tags = data_get($record, 'tags', []);
                if ((bool) data_get($record, 'ritual')) {
                    $tags[] = 'ritual';
                }
                if (preg_match('/(?:^|\s)(?:or\s+)?R(?:$|\s)/i', (string) data_get($record, 'castingTime')) === 1) {
                    // The supplied index preserves the source table's explicit R
                    // notation even where its derived ritual boolean is false.
                    $tags[] = 'ritual';
                }
                if ((bool) data_get($record, 'concentration')) {
                    $tags[] = 'concentration';
                }
                if (preg_match('/^C(?:,|\s)/i', (string) data_get($record, 'duration')) === 1) {
                    $tags[] = 'concentration';
                }
                $versionChanged = $this->syncSimplePivot(
                    'spell_version_tags', 'tag', $versionId,
                    array_values(array_unique($tags)), 'tags_created', $summary,
                ) || $versionChanged;
                $versionChanged = $this->syncSimplePivot(
                    'spell_version_attack_modes', 'attack_mode', $versionId,
                    data_get($record, 'attackModes', []), 'attack_modes_created', $summary,
                ) || $versionChanged;
                $versionChanged = $this->syncSimplePivot(
                    'spell_version_save_abilities', 'save_ability', $versionId,
                    data_get($record, 'saveAbilities', []), 'save_abilities_created', $summary,
                ) || $versionChanged;
            }

            if ($version !== null && $versionChanged) {
                $summary['updated']++;
            }
        }

        $tombstones = DB::table('spell_versions')
            ->where('provenance', 'import')
            ->where('is_active', true)
            ->when(
                $seenVersionKeys !== [],
                fn ($query) => $query->whereNotIn('content_key', $seenVersionKeys),
            )
            ->get();
        foreach ($tombstones as $version) {
            DB::table('spell_versions')->where('id', data_get($version, 'id'))->update([
                'is_active' => false,
                'updated_at' => now(),
            ]);
            $summary['tombstoned']++;
        }

        return $summary;
    }

    /** @param array<string, mixed> $record @param array<string, int> $summary */
    private function resolveIdentity(array $record, array &$summary): int
    {
        $contentKey = (string) data_get($record, 'identityKey');
        $canonicalName = (string) data_get($record, '_canonical_name', data_get($record, 'name'));
        $normalizedName = $this->normalizeName($canonicalName);
        $identity = DB::table('spell_identities')->where('content_key', $contentKey)->first();
        if ($identity === null) {
            $identity = DB::table('spell_identities')->where('normalized_name', $normalizedName)->first();
        }
        if ($identity === null) {
            $identityId = DB::table('spell_identity_aliases')
                ->where('normalized_alias', $normalizedName)
                ->value('spell_identity_id');
            $identity = $identityId === null ? null : DB::table('spell_identities')->find((int) $identityId);
        }

        if ($identity === null) {
            $identityId = DB::table('spell_identities')->insertGetId([
                'content_key' => $contentKey,
                'canonical_name' => $canonicalName,
                'normalized_name' => $normalizedName,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $summary['identities_created']++;

            return $identityId;
        }

        $identityId = (int) data_get($identity, 'id');
        if (data_get($identity, 'canonical_name') !== $canonicalName) {
            $this->insertAlias($identityId, (string) data_get($identity, 'canonical_name'));
            DB::table('spell_identities')->where('id', $identityId)->update([
                'canonical_name' => $canonicalName,
                'normalized_name' => $normalizedName,
                'updated_at' => now(),
            ]);
            $summary['identities_updated']++;
        }

        return $identityId;
    }

    private function insertAlias(int $identityId, string $alias): void
    {
        DB::table('spell_identity_aliases')->insertOrIgnore([
            'spell_identity_id' => $identityId,
            'alias' => $alias,
            'normalized_alias' => $this->normalizeName($alias),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $record @return array<string, mixed> */
    private function versionAttributes(array $record, int $identityId): array
    {
        return [
            'content_key' => (string) data_get($record, 'versionKey'),
            'spell_identity_id' => $identityId,
            'display_name' => (string) data_get($record, 'name'),
            'rules_edition' => (string) data_get($record, 'edition'),
            'level' => (int) data_get($record, 'level'),
            'school' => (string) data_get($record, 'school'),
            'ritual' => (bool) data_get($record, 'ritual'),
            'concentration' => (bool) data_get($record, 'concentration'),
            'casting_time' => data_get($record, 'castingTime'),
            'range' => data_get($record, 'range'),
            'duration' => data_get($record, 'duration'),
            'components' => data_get($record, 'components'),
            'healing' => (bool) data_get($record, 'healing', false),
            'effect_reliability_category' => (string) data_get($record, 'effectReliabilityCategory', 'fixed_effect'),
            'provenance' => 'import',
            'is_active' => true,
        ];
    }

    private function isReferenced(int $versionId): bool
    {
        return DB::table('spell_selection_slots')
            ->where('fixed_spell_version_id', $versionId)
            ->orWhere('current_spell_version_id', $versionId)
            ->exists()
            || DB::table('wizard_spellbook_entries')->where('spell_version_id', $versionId)->exists()
            || DB::table('spell_loadout_entries')->where('spell_version_id', $versionId)->exists()
            || DB::table('character_spell_preferences')->where('spell_version_id', $versionId)->exists();
    }

    /** @param array<string, array<string, mixed>> $desired @param array<string, int> $summary */
    private function syncPublications(int $versionId, array $desired, array &$summary): bool
    {
        $existing = DB::table('spell_version_publications')
            ->where('spell_version_id', $versionId)
            ->get()
            ->keyBy('source_book');
        $changed = false;
        foreach ($desired as $book => $publication) {
            $row = $existing->get($book);
            if ($row === null) {
                DB::table('spell_version_publications')->insert([
                    'spell_version_id' => $versionId,
                    'source_book' => $book,
                    'source_page' => data_get($publication, 'source_page'),
                    'source_reference' => data_get($publication, 'source_reference'),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $summary['publications_created']++;
                $changed = true;

                continue;
            }
            $updates = [];
            foreach (['source_page', 'source_reference'] as $column) {
                if (data_get($row, $column) != data_get($publication, $column)) {
                    $updates[$column] = data_get($publication, $column);
                }
            }
            if ($updates !== []) {
                $updates['updated_at'] = now();
                DB::table('spell_version_publications')->where('id', data_get($row, 'id'))->update($updates);
                $changed = true;
            }
        }
        $removed = $existing->keys()->diff(array_keys($desired))->all();
        if ($removed !== []) {
            DB::table('spell_version_publications')
                ->where('spell_version_id', $versionId)
                ->whereIn('source_book', $removed)
                ->delete();
            $changed = true;
        }

        return $changed;
    }

    /**
     * @param  list<string>  $desired
     * @param  array<string, int>  $summary
     */
    private function syncSimplePivot(
        string $table,
        string $column,
        int $versionId,
        array $desired,
        string $createdCounter,
        array &$summary,
        bool $timestamps = false,
    ): bool {
        $desired = array_values(array_unique($desired));
        sort($desired);
        $existing = DB::table($table)
            ->where('spell_version_id', $versionId)
            ->pluck($column)
            ->all();
        sort($existing);
        if ($existing === $desired) {
            return false;
        }

        $toInsert = array_values(array_diff($desired, $existing));
        foreach ($toInsert as $value) {
            $row = ['spell_version_id' => $versionId, $column => $value];
            if ($timestamps) {
                $row['created_at'] = now();
                $row['updated_at'] = now();
            }
            DB::table($table)->insert($row);
            $summary[$createdCounter]++;
        }
        $toDelete = array_values(array_diff($existing, $desired));
        if ($toDelete !== []) {
            DB::table($table)
                ->where('spell_version_id', $versionId)
                ->whereIn($column, $toDelete)
                ->delete();
        }

        return true;
    }

    private function normalizeName(string $name): string
    {
        $collapsed = preg_replace('/\s+/u', ' ', trim($name));
        if ($collapsed === null) {
            throw new RuntimeException("Unable to normalize catalog name '{$name}'.");
        }

        return Str::lower($collapsed);
    }
}
