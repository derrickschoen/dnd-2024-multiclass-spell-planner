<?php

declare(strict_types=1);

namespace App\Domain\Characters\Commands;

interface CharacterCommand
{
    public function apply(int $characterId): void;

    /** @return array<string, mixed> */
    public function inverse(): array;

    public function actionType(): string;
}
