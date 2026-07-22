<?php

declare(strict_types=1);

namespace App\Domain\Grants;

use InvalidArgumentException;
use JsonException;

final readonly class GrantRule
{
    public const FIXED_SPELL = GrantRuleKind::FixedSpell;

    public const CHOICE_FROM_LIST = GrantRuleKind::ChoiceFromList;

    public const CHOICE_FROM_QUERY = GrantRuleKind::ChoiceFromQuery;

    public const GRANT_SOURCE = GrantRuleKind::GrantSource;

    public const CAPABILITY = GrantRuleKind::Capability;

    public const SPELLBOOK_ACQUISITION = GrantRuleKind::SpellbookAcquisition;

    /** @param array<string, mixed> $data */
    private function __construct(
        public GrantRuleKind $kind,
        public string $ruleKey,
        public ?int $count,
        public ?SlotBucket $bucket,
        public bool $alwaysPrepared,
        public bool $withSlots,
        public ?FreeCast $freeCast,
        public ?int $activeFromClassLevel,
        /** @var array{key: string, equals: string}|null */
        public ?array $activeIfConfig,
        public ?string $distinctConfigBy,
        private array $data,
    ) {}

    /** @param array<string, mixed> $input */
    public static function fromArray(array $input): self
    {
        $rawKind = data_get($input, 'kind');
        $kind = is_string($rawKind) ? GrantRuleKind::tryFrom($rawKind) : null;
        if ($kind === null) {
            $shown = is_scalar($rawKind) ? (string) $rawKind : get_debug_type($rawKind);
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
        $rawBucket = data_get($input, 'bucket');
        $bucket = null;
        if ($kind->requiresBucket()) {
            $rawBucket = self::nonEmptyString($input, 'bucket');
            $bucket = SlotBucket::tryFrom($rawBucket);
            if ($bucket === null) {
                throw new InvalidArgumentException("Grant rule '{$ruleKey}' has invalid bucket '{$rawBucket}'.");
            }
        } elseif ($rawBucket !== null) {
            throw new InvalidArgumentException("Grant rule '{$ruleKey}' must not define a bucket.");
        }

        $alwaysPrepared = self::boolean($input, 'always_prepared', false, $ruleKey);
        $withSlots = self::boolean($input, 'with_slots', true, $ruleKey);
        $freeCast = self::freeCast($input, $ruleKey);
        $activeFromClassLevel = self::positiveInteger($input, 'active_from_class_level', null, $ruleKey, false);
        $activeIfConfig = self::activeIfConfig($input, $ruleKey);
        $distinctConfigBy = self::optionalNonEmptyString($input, 'distinct_config_by', $ruleKey);

        $levelMin = self::level($input, 'level_min', 0, $ruleKey);
        $levelMax = self::level($input, 'level_max', 9, $ruleKey);
        if ($levelMin > $levelMax) {
            throw new InvalidArgumentException("Grant rule '{$ruleKey}' has level_min greater than level_max.");
        }

        self::validateKindFields($kind, $ruleKey, $input);

        $normalized = $input;
        $normalized['kind'] = $kind->value;
        $normalized['rule_key'] = $ruleKey;
        if ($count !== null) {
            $normalized['count'] = $count;
        } else {
            unset($normalized['count']);
        }
        if ($bucket !== null) {
            $normalized['bucket'] = $bucket->value;
        }
        $normalized['always_prepared'] = $alwaysPrepared;
        $normalized['with_slots'] = $withSlots;
        $normalized['free_cast'] = $freeCast?->toArray();
        if ($activeFromClassLevel !== null) {
            $normalized['active_from_class_level'] = $activeFromClassLevel;
        }
        if ($activeIfConfig !== null) {
            $normalized['active_if_config'] = $activeIfConfig;
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
            $bucket,
            $alwaysPrepared,
            $withSlots,
            $freeCast,
            $activeFromClassLevel,
            $activeIfConfig,
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
    private static function validateKindFields(GrantRuleKind $kind, string $ruleKey, array $input): void
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

        $selectionCollection = data_get($input, 'selection_collection');
        if ($selectionCollection !== null) {
            if (! in_array($kind, [self::CHOICE_FROM_LIST, self::CHOICE_FROM_QUERY], true)) {
                throw new InvalidArgumentException(
                    "Grant rule '{$ruleKey}' may constrain a selection collection only for a choice rule."
                );
            }
            if ($selectionCollection !== 'wizard_spellbook') {
                throw new InvalidArgumentException(
                    "Grant rule '{$ruleKey}' has unsupported selection_collection '{$selectionCollection}'."
                );
            }
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
    private static function freeCast(array $input, string $ruleKey): ?FreeCast
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
        $recoveryType = is_string($recovery) ? FreeCastRecovery::tryFrom($recovery) : null;
        if ($recoveryType === null) {
            $shown = is_scalar($recovery) ? (string) $recovery : get_debug_type($recovery);
            throw new InvalidArgumentException("Grant rule '{$ruleKey}' has invalid free_cast.recovery '{$shown}'.");
        }
        $poolScopeType = is_string($poolScope) ? FreeCastPoolScope::tryFrom($poolScope) : null;
        if ($poolScopeType === null) {
            $shown = is_scalar($poolScope) ? (string) $poolScope : get_debug_type($poolScope);
            throw new InvalidArgumentException("Grant rule '{$ruleKey}' has invalid free_cast.pool_scope '{$shown}'.");
        }

        return new FreeCast($uses, $recoveryType, $poolScopeType);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{key: string, equals: string}|null
     */
    private static function activeIfConfig(array $input, string $ruleKey): ?array
    {
        $value = data_get($input, 'active_if_config');
        if ($value === null) {
            return null;
        }
        $keys = is_array($value) ? array_keys($value) : [];
        sort($keys);
        if (! is_array($value) || $keys !== ['equals', 'key']) {
            throw new InvalidArgumentException(
                "Grant rule '{$ruleKey}' field 'active_if_config' must contain exactly key and equals."
            );
        }
        $key = data_get($value, 'key');
        $equals = data_get($value, 'equals');
        if (! is_string($key) || trim($key) === '' || ! is_string($equals) || trim($equals) === '') {
            throw new InvalidArgumentException(
                "Grant rule '{$ruleKey}' active_if_config key and equals must be non-empty strings."
            );
        }

        return ['key' => trim($key), 'equals' => trim($equals)];
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
