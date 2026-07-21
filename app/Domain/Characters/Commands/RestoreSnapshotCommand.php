<?php

declare(strict_types=1);

namespace App\Domain\Characters\Commands;

use App\Domain\Characters\CharacterState;

final class RestoreSnapshotCommand implements CharacterCommand
{
    /** @var array<string, mixed> */
    private array $before = [];

    /** @param array<string, mixed> $snapshot */
    public function __construct(
        private readonly array $snapshot,
        private readonly CharacterState $state,
    ) {}

    public function apply(int $characterId): void
    {
        $this->before = $this->state->capture($characterId);
        $this->state->restore($characterId, $this->snapshot);
    }

    public function inverse(): array
    {
        return ['type' => 'restore_snapshot', 'snapshot' => $this->before];
    }

    public function actionType(): string
    {
        return 'restore_snapshot';
    }
}
