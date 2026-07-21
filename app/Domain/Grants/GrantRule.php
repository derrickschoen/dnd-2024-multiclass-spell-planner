<?php

declare(strict_types=1);

namespace App\Domain\Grants;

use InvalidArgumentException;
use JsonException;

final readonly class GrantRule
{
    public const FIXED_SPELL = 'fixed_spell';

    public const CHOICE_FROM_LIST = 'choice_from_list';

    public const CHOICE_FROM_QUERY = 'choice_from_query';

    public const GRANT_SOURCE = 'grant_source';

    public const CAPABILITY = 'capability';

    public const SPELLBOOK_ACQUISITION = 'spellbook_acquisition';

    private const KINDS = [
        self::FIXED_SPELL,
        self::CHOICE_FROM_LIST,
        self::CHOICE_FROM_QUERY,
        self::GRANT_SOURCE,
        self::CAPABILITY,
        self::SPELLBOOK_ACQUISITION,
    ];

    private const BUCKETS = [
        'cantrip_known', 'prepared', 'known', 'spellbook', 'automatic',
    ];

    private const RECOVERIES = ['long_rest', 'short_rest', 'dawn', 'at_will'];

    private const POOL_SCOPES = ['per_spell', 'shared'];

    /** @param array<string, mixed> $data */
    private function __construct(
        public string $kind,
        public string $ruleKey,
        public ?int $count,
        public ?string $bucket,
        public bool $alwaysPrepared,
        public bool $withSlots,
        /** @var array{uses: int, recovery: string, pool_scope: string}|null */
        public ?array $freeCast,
        public ?int $activeFromClassLevel,
        public ?string $distinctConfigBy,
        private array $data,
    ) {}

    /** @param array<string, mixed> $input */
    public static function fromArray(array $input): self
    {
        $kind = data_get($input, 'kind');
        if (! is_string($kind) || ! in_array($kind, self::KINDS, true)) {
            $shown = is_scalar($kind) ? (string) $kind : get_debug_type($kind);
            throw new InvalidArgumentException("Unknown grant rule kind '{$shown}'.");
        }

        $ruleKey = self::nonEmptyString($input, 'rule_key');

        if ($kind === self::CAPABILITY && array_key_exists('count', $input)) {
            throw new InvalidArgumentException(
                "Capability rule '{$ruleKey}' must not define count; capabilities do not mint slots."
            );
        }

        $count = match ($kind) {
            self::FIXED_SPELL, self::GRANT_SOURCE => self::positiveInteger($input, 'count', 1, $ruleKey),
            self::CHOICE_FROM_LIST, self::CHOICE_FROM_QUERY => self::positiveInteger($input, 'count', null, $ruleKey),
            default => null,
        };

        if ($kind === self::FIXED_SPELL && $count !== 1) {
            throw new InvalidArgumentException("Fixed-spell rule '{$ruleKey}' must have count 1.");
        }

        $slotKind = in_array($kind, [self::FIXED_SPELL, self::CHOICE_FROM_LIST, self::CHOICE_FROM_QUERY], true);
        $bucket = data_get($input, 'bucket');
        if ($slotKind || $kind === self::SPELLBOOK_ACQUISITION) {
            $bucket = self::nonEmptyString($input, 'bucket');
            if (! in_array($bucket, self::BUCKETS, true)) {
                throw new InvalidArgumentException("Grant rule '{$ruleKey}' has invalid bucket '{$bucket}'.");
            }
        } elseif ($bucket !== null) {
            throw new InvalidArgumentException("Grant rule '{$ruleKey}' must not define a bucket.");
        }

        $alwaysPrepared = self::boolean($input, 'always_prepared', false, $ruleKey);
        $withSlots = self::boolean($input, 'with_slots', true, $ruleKey);
        $freeCast = self::freeCast($input, $ruleKey);
        $activeFromClassLevel = self::positiveInteger($input, 'active_from_class_level', null, $ruleKey, false);
        $distinctConfigBy = self::optionalNonEmptyString($input, 'distinct_config_by', $ruleKey);

        $levelMin = self::level($input, 'level_min', 0, $ruleKey);
        $levelMax = self::level($input, 'level_max', 9, $ruleKey);
        if ($levelMin > $levelMax) {
            throw new InvalidArgumentException("Grant rule '{$ruleKey}' has level_min greater than level_max.");
        }

        self::validateKindFields($kind, $ruleKey, $input);

        $normalized = $input;
        $normalized['kind'] = $kind;
        $normalized['rule_key'] = $ruleKey;
        if ($count !== null) {
            $normalized['count'] = $count;
        } else {
            unset($normalized['count']);
        }
        if ($bucket !== null) {
            $normalized['bucket'] = $bucket;
        }
        $normalized['always_prepared'] = $alwaysPrepared;
        $normalized['with_slots'] = $withSlots;
        $normalized['free_cast'] = $freeCast;
        if ($activeFromClassLevel !== null) {
            $normalized['active_from_class_level'] = $activeFromClassLevel;
        }
        if ($distinctConfigBy !== null) {
            $normalized['distinct_config_by'] = $distinctConfigBy;
        }
        if (in_array($kind, [self::CHOICE_FROM_LIST, self::CHOICE_FROM_QUERY], true)) {
            $normalized['level_min'] = $levelMin;
            $normalized['level_max'] = $levelMax;
        }

        return new self(
            $kind,
            $ruleKey,
            $count,
            is_string($bucket) ? $bucket : null,
            $alwaysPrepared,
            $withSlots,
            $freeCast,
            $activeFromClassLevel,
            $distinctConfigBy,
            $normalized,
        );
    }

    public static function fromJson(string $json): self
    {
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException("Grant rule JSON is invalid: {$exception->getMessage()}", 0, $exception);
        }

        if (! is_array($data)) {
            throw new InvalidArgumentException('Grant rule JSON must decode to an object.');
        }

        return self::fromArray($data);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->data;
    }

    public function toJson(): string
    {
        return json_encode($this->data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /** @param array<string, mixed> $input */
    private static function validateKindFields(string $kind, string $ruleKey, array $input): void
    {
        if ($kind === self::FIXED_SPELL) {
            $id = data_get($input, 'spell_version_id');
            $key = data_get($input, 'spell_version_key');
            if ((! is_int($id) || $id < 1) && (! is_string($key) || trim($key) === '')) {
                throw new InvalidArgumentException(
                    "Fixed-spell rule '{$ruleKey}' requires spell_version_id or spell_version_key."
                );
            }
        }

        if ($kind === self::CHOICE_FROM_LIST || $kind === self::SPELLBOOK_ACQUISITION) {
            self::nonEmptyString($input, 'list');
        }

        if ($kind === self::CHOICE_FROM_QUERY) {
            $hasPredicate = data_get($input, 'schools') !== null
                || data_get($input, 'tags') !== null
                || array_key_exists('level_min', $input)
                || array_key_exists('level_max', $input);
            if (! $hasPredicate) {
                throw new InvalidArgumentException("Query rule '{$ruleKey}' requires at least one predicate.");
            }
            self::stringList($input, 'schools', $ruleKey);
            self::stringList($input, 'tags', $ruleKey);
        }

        if ($kind === self::GRANT_SOURCE) {
            self::nonEmptyString($input, 'source_type');
            $hasDefinition = (is_int(data_get($input, 'source_definition_id')) && data_get($input, 'source_definition_id') > 0)
                || (is_string(data_get($input, 'source_definition_key')) && trim((string) data_get($input, 'source_definition_key')) !== '')
                || (is_string(data_get($input, 'definition_key_config')) && trim((string) data_get($input, 'definition_key_config')) !== '');
            if (! $hasDefinition) {
                throw new InvalidArgumentException("Grant-source rule '{$ruleKey}' requires a source definition reference.");
            }
        }

        if ($kind === self::CAPABILITY) {
            self::nonEmptyString($input, 'capability_key');
            self::nonEmptyString($input, 'collection');
            self::nonEmptyString($input, 'access_mode');
            self::stringList($input, 'tags', $ruleKey, true);
        }

        if ($kind === self::SPELLBOOK_ACQUISITION) {
            self::nonEmptyString($input, 'acquisitions_config');
        }
    }

    /** @param array<string, mixed> $input */
    private static function nonEmptyString(array $input, string $field): string
    {
        $value = data_get($input, $field);
        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException("Grant rule field '{$field}' must be a non-empty string.");
        }

        return trim($value);
    }

    /** @param array<string, mixed> $input */
    private static function optionalNonEmptyString(array $input, string $field, string $ruleKey): ?string
    {
        $value = data_get($input, $field);
        if ($value === null) {
            return null;
        }
        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException("Grant rule '{$ruleKey}' field '{$field}' must be a non-empty string.");
        }

        return trim($value);
    }

    /** @param array<string, mixed> $input */
    private static function positiveInteger(
        array $input,
        string $field,
        ?int $default,
        string $ruleKey,
        bool $required = true,
    ): ?int {
        $value = data_get($input, $field, $default);
        if ($value === null && ! $required) {
            return null;
        }
        if (! is_int($value) || $value < 1) {
            throw new InvalidArgumentException("Grant rule '{$ruleKey}' field '{$field}' must be a positive integer.");
        }

        return $value;
    }

    /** @param array<string, mixed> $input */
    private static function boolean(array $input, string $field, bool $default, string $ruleKey): bool
    {
        $value = data_get($input, $field, $default);
        if (! is_bool($value)) {
            throw new InvalidArgumentException("Grant rule '{$ruleKey}' field '{$field}' must be boolean.");
        }

        return $value;
    }

    /** @param array<string, mixed> $input */
    private static function level(array $input, string $field, int $default, string $ruleKey): int
    {
        $value = data_get($input, $field, $default);
        if (! is_int($value) || $value < 0 || $value > 9) {
            throw new InvalidArgumentException("Grant rule '{$ruleKey}' field '{$field}' must be between 0 and 9.");
        }

        return $value;
    }

    /** @param array<string, mixed> $input */
    private static function freeCast(array $input, string $ruleKey): ?array
    {
        $value = data_get($input, 'free_cast');
        if ($value === null) {
            return null;
        }
        if (! is_array($value)) {
            throw new InvalidArgumentException("Grant rule '{$ruleKey}' field 'free_cast' must be an object or null.");
        }

        $uses = data_get($value, 'uses');
        $recovery = data_get($value, 'recovery');
        $poolScope = data_get($value, 'pool_scope');
        if (! is_int($uses) || $uses < 1) {
            throw new InvalidArgumentException("Grant rule '{$ruleKey}' free_cast.uses must be a positive integer.");
        }
        if (! is_string($recovery) || ! in_array($recovery, self::RECOVERIES, true)) {
            $shown = is_scalar($recovery) ? (string) $recovery : get_debug_type($recovery);
            throw new InvalidArgumentException("Grant rule '{$ruleKey}' has invalid free_cast.recovery '{$shown}'.");
        }
        if (! is_string($poolScope) || ! in_array($poolScope, self::POOL_SCOPES, true)) {
            $shown = is_scalar($poolScope) ? (string) $poolScope : get_debug_type($poolScope);
            throw new InvalidArgumentException("Grant rule '{$ruleKey}' has invalid free_cast.pool_scope '{$shown}'.");
        }

        return ['uses' => $uses, 'recovery' => $recovery, 'pool_scope' => $poolScope];
    }

    /** @param array<string, mixed> $input */
    private static function stringList(
        array $input,
        string $field,
        string $ruleKey,
        bool $required = false,
    ): void {
        $value = data_get($input, $field);
        if ($value === null && ! $required) {
            return;
        }
        if (! is_array($value) || ($required && $value === [])) {
            throw new InvalidArgumentException("Grant rule '{$ruleKey}' field '{$field}' must be a non-empty string list.");
        }
        foreach ($value as $item) {
            if (! is_string($item) || trim($item) === '') {
                throw new InvalidArgumentException("Grant rule '{$ruleKey}' field '{$field}' must contain only strings.");
            }
        }
    }
}
