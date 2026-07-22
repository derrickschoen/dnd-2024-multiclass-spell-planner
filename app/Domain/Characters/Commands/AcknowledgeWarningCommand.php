<?php

declare(strict_types=1);

namespace App\Domain\Characters\Commands;

use App\Domain\Spells\DuplicateWarningDetector;
use App\Domain\Spells\SpellAccessBuilder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class AcknowledgeWarningCommand implements CharacterCommand
{
    /** @var array<string, mixed>|null */
    private ?array $previous = null;

    /** @param array<string, mixed> $payload */
    public function __construct(
        private readonly array $payload,
        private readonly SpellAccessBuilder $access,
        private readonly DuplicateWarningDetector $duplicates,
    ) {}

    public function apply(int $characterId): void
    {
        $fingerprint = trim((string) data_get($this->payload, 'warning_fingerprint'));
        if (! str_starts_with($fingerprint, 'conflicting_versions:')) {
            throw new InvalidArgumentException('Unknown warning fingerprint.');
        }
        $mode = (string) data_get($this->payload, 'mode', 'acknowledge');
        if ($mode === 'delete') {
            $existing = DB::table('warning_acknowledgements')
                ->where('character_id', $characterId)
                ->where('warning_fingerprint', $fingerprint)
                ->first();
            $this->previous = $existing === null ? null : (array) $existing;
            DB::table('warning_acknowledgements')
                ->where('character_id', $characterId)
                ->where('warning_fingerprint', $fingerprint)
                ->delete();

            return;
        }
        $warningExists = collect($this->duplicates->classify($this->access->buildForCharacter($characterId)))
            ->contains(static fn (array $warning): bool => data_get($warning, 'category') === 'conflicting_version'
                && data_get($warning, 'warning_fingerprint') === $fingerprint);
        if (! $warningExists) {
            throw new InvalidArgumentException('The conflicting-version warning is no longer active.');
        }
        $note = trim((string) data_get($this->payload, 'note'));
        if ($note === '') {
            throw new InvalidArgumentException('An acknowledgement note is required.');
        }

        $existing = DB::table('warning_acknowledgements')
            ->where('character_id', $characterId)
            ->where('warning_fingerprint', $fingerprint)
            ->first();
        $this->previous = $existing === null ? null : (array) $existing;
        DB::table('warning_acknowledgements')->updateOrInsert(
            ['character_id' => $characterId, 'warning_fingerprint' => $fingerprint],
            ['note' => $note, 'invalidated_at' => null, 'updated_at' => now(), 'created_at' => data_get($existing, 'created_at', now())],
        );
    }

    public function inverse(): array
    {
        if ($this->previous === null) {
            return [
                'type' => 'acknowledge_warning',
                'mode' => 'delete',
                'warning_fingerprint' => (string) data_get($this->payload, 'warning_fingerprint'),
            ];
        }

        return [
            'type' => 'acknowledge_warning',
            'warning_fingerprint' => (string) data_get($this->payload, 'warning_fingerprint'),
            'note' => (string) data_get($this->previous, 'note'),
        ];
    }

    public function actionType(): string
    {
        return 'acknowledge_warning';
    }
}
