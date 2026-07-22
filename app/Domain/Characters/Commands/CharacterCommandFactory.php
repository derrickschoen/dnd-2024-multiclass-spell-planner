<?php

declare(strict_types=1);

namespace App\Domain\Characters\Commands;

use App\Domain\Characters\CharacterState;
use App\Domain\Grants\GrantRuleSlotGenerator;
use App\Domain\Spells\DuplicateWarningDetector;
use App\Domain\Spells\SpellAccessBuilder;
use App\Domain\Spells\SpellSelectionEligibility;
use LogicException;

final readonly class CharacterCommandFactory
{
    public function __construct(
        private CharacterState $state,
        private GrantRuleSlotGenerator $generator,
        private SpellSelectionEligibility $eligibility,
        private SpellAccessBuilder $access,
        private DuplicateWarningDetector $duplicates,
        private CharacterCommandPayloadValidator $validator,
        private CharacterCommandIntegrity $integrity,
    ) {}

    /** @param array<string, mixed> $payload */
    public function make(int $characterId, array $payload): CharacterCommand
    {
        $payload = $this->validator->validate($payload);
        $type = $payload['type'] ?? null;
        if (! is_string($type)) {
            throw new LogicException('Validated command payload has no command type.');
        }
        if (($type === 'set_slot' && data_get($payload, 'mode') === 'restore')
            || ($type === 'acknowledge_warning' && data_get($payload, 'mode') === 'delete')
            || $type === 'restore_snapshot') {
            $this->integrity->assertValid($characterId, $payload);
        }

        return match ($type) {
            'update_ability' => new UpdateAbilityCommand(
                data_get($payload, 'ability'),
                data_get($payload, 'score'),
            ),
            'set_slot' => new SetSlotCommand($payload, $this->eligibility, $this->integrity),
            'update_character_rules' => new UpdateCharacterRulesCommand(
                data_get($payload, 'allow_legacy'),
                $this->eligibility,
            ),
            'update_source_config' => new UpdateSourceConfigCommand(
                $payload,
                $this->state,
                $this->generator,
                $this->integrity,
            ),
            'add_source' => new AddSourceCommand(
                $payload,
                $this->state,
                $this->generator,
                $this->integrity,
            ),
            'remove_source' => new RemoveSourceCommand(
                $payload,
                $this->state,
                $this->generator,
                $this->integrity,
            ),
            'acknowledge_warning' => new AcknowledgeWarningCommand(
                $payload,
                $this->access,
                $this->duplicates,
                $this->integrity,
            ),
            'update_class' => new UpdateClassCommand($payload, $this->state, $this->generator, $this->integrity),
            'restore_snapshot' => new RestoreSnapshotCommand(
                data_get($payload, 'snapshot'),
                $this->state,
                $this->integrity,
            ),
            default => throw new LogicException("Validated command type {$type} is not implemented."),
        };
    }
}
