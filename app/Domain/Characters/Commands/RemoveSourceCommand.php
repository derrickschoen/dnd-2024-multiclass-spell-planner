<?php

declare(strict_types=1);

namespace App\Domain\Characters\Commands;

use App\Domain\Characters\CharacterState;
use App\Domain\Grants\GrantRuleSlotGenerator;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class RemoveSourceCommand implements CharacterCommand
{
    /** @var array<string, mixed> */
    private array $before = [];

    /** @param array<string, mixed> $payload */
    public function __construct(
        private readonly array $payload,
        private readonly CharacterState $state,
        private readonly GrantRuleSlotGenerator $generator,
        private readonly CharacterCommandIntegrity $integrity,
    ) {}

    private int $characterId;

    public function apply(int $characterId): void
    {
        $this->characterId = $characterId;
        /** @var int $sourceId */
        $sourceId = data_get($this->payload, 'source_instance_id');
        $source = DB::table('character_source_instances')
            ->where('character_id', $characterId)
            ->where('id', $sourceId)
            ->whereIn('source_type', ['feat', 'species', 'background'])
            ->where('state', 'active')
            ->first();
        if ($source === null) {
            throw new InvalidArgumentException('Removable source does not belong to this character.');
        }

        $this->before = $this->state->capture($characterId);
        DB::table('character_source_instances')->where('id', $sourceId)->update([
            'state' => 'tombstoned',
            'updated_at' => now(),
        ]);
        $this->generator->generateForSource($sourceId);
    }

    public function inverse(): array
    {
        return $this->integrity->attach($this->characterId, [
            'type' => 'restore_snapshot',
            'snapshot' => $this->before,
        ]);
    }

    public function actionType(): string
    {
        return 'remove_source';
    }
}
