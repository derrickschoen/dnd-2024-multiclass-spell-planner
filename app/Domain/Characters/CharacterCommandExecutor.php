<?php

declare(strict_types=1);

namespace App\Domain\Characters;

use App\Domain\Characters\Commands\CharacterCommandFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class CharacterCommandExecutor
{
    public function __construct(
        private CharacterCommandFactory $factory,
        private CharacterState $state,
        private CharacterWorkspaceBuilder $workspace,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function execute(int $characterId, array $payload, string $operationUuid, int $expectedRevision): array
    {
        $result = DB::transaction(function () use ($characterId, $payload, $operationUuid, $expectedRevision): array {
            $priorOperation = DB::table('character_operations')
                ->where('operation_uuid', $operationUuid)
                ->first();
            if ($priorOperation !== null) {
                if ((int) data_get($priorOperation, 'character_id') !== $characterId) {
                    throw new RevisionConflict((int) DB::table('characters')->where('id', $characterId)->value('revision'));
                }

                return [
                    'inverse' => json_decode((string) data_get($priorOperation, 'inverse_command'), true, 512, JSON_THROW_ON_ERROR),
                    'revision' => (int) DB::table('characters')->where('id', $characterId)->value('revision'),
                    'idempotent_replay' => true,
                ];
            }

            $character = DB::table('characters')->where('id', $characterId)->lockForUpdate()->first();
            abort_if($character === null, 404);
            $currentRevision = (int) data_get($character, 'revision');
            if ($currentRevision !== $expectedRevision) {
                throw new RevisionConflict($currentRevision);
            }

            $before = $this->state->capture($characterId);
            $command = $this->factory->make($payload);
            $command->apply($characterId);
            $nextRevision = $currentRevision + 1;
            DB::table('characters')->where('id', $characterId)->update([
                'revision' => $nextRevision,
                'updated_at' => now(),
            ]);
            $after = $this->state->capture($characterId);
            $inverse = $command->inverse();
            $groupId = Str::uuid()->toString();
            $sequence = (int) DB::table('change_log')->where('character_id', $characterId)->max('sequence');
            foreach ($this->state->diff($before, $after) as $change) {
                DB::table('change_log')->insert([
                    'character_id' => $characterId,
                    'sequence' => ++$sequence,
                    'group_id' => $groupId,
                    'operation_uuid' => $operationUuid,
                    'entity_type' => data_get($change, 'entity_type'),
                    'entity_id' => data_get($change, 'entity_id'),
                    'previous_value' => json_encode(data_get($change, 'previous_value'), JSON_THROW_ON_ERROR),
                    'new_value' => json_encode(data_get($change, 'new_value'), JSON_THROW_ON_ERROR),
                    'reason' => data_get($payload, 'reason'),
                    'action_type' => $command->actionType(),
                    'reversible' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            DB::table('character_operations')->insert([
                'character_id' => $characterId,
                'operation_uuid' => $operationUuid,
                'expected_revision' => $expectedRevision,
                'resulting_revision' => $nextRevision,
                'inverse_command' => json_encode($inverse, JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return ['inverse' => $inverse, 'revision' => $nextRevision, 'idempotent_replay' => false];
        });

        $result['workspace'] = $this->workspace->build($characterId);

        return $result;
    }
}
