<?php

declare(strict_types=1);

namespace App\Domain\Characters\Commands;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class UpdateAbilityCommand implements CharacterCommand
{
    private int $previousScore;

    public function __construct(private readonly string $ability, private readonly int $score) {}

    public function apply(int $characterId): void
    {
        $abilities = ['strength', 'dexterity', 'constitution', 'intelligence', 'wisdom', 'charisma'];
        if (! in_array($this->ability, $abilities, true)) {
            throw new InvalidArgumentException('Unknown ability score.');
        }
        if ($this->score < 1 || $this->score > 30) {
            throw new InvalidArgumentException('Ability scores must be between 1 and 30.');
        }
        $this->previousScore = (int) DB::table('characters')->where('id', $characterId)->value($this->ability);
        DB::table('characters')->where('id', $characterId)->update([
            $this->ability => $this->score,
            'updated_at' => now(),
        ]);
    }

    public function inverse(): array
    {
        return ['type' => 'update_ability', 'ability' => $this->ability, 'score' => $this->previousScore];
    }

    public function actionType(): string
    {
        return 'update_ability';
    }
}
