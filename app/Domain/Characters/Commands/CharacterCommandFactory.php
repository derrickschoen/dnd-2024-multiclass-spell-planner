<?php

declare(strict_types=1);

namespace App\Domain\Characters\Commands;

use App\Domain\Characters\CharacterState;
use App\Domain\Grants\GrantRuleSlotGenerator;
use App\Domain\Spells\DuplicateWarningDetector;
use App\Domain\Spells\SpellAccessBuilder;
use App\Domain\Spells\SpellSelectionEligibility;
use InvalidArgumentException;

final readonly class CharacterCommandFactory
{
    public function __construct(
        private CharacterState $state,
        private GrantRuleSlotGenerator $generator,
        private SpellSelectionEligibility $eligibility,
        private SpellAccessBuilder $access,
        private DuplicateWarningDetector $duplicates,
    ) {}

    /** @param array<string, mixed> $payload */
    public function make(array $payload): CharacterCommand
    {
        return match (data_get($payload, 'type')) {
            'update_ability' => new UpdateAbilityCommand(
                (string) data_get($payload, 'ability'),
                (int) data_get($payload, 'score'),
            ),
            'set_slot' => new SetSlotCommand($payload, $this->eligibility),
            'update_character_rules' => new UpdateCharacterRulesCommand(
                data_get($payload, 'allow_legacy'),
                $this->eligibility,
            ),
            'update_source_config' => new UpdateSourceConfigCommand(
                $payload,
                $this->state,
                $this->generator,
            ),
            'acknowledge_warning' => new AcknowledgeWarningCommand(
                $payload,
                $this->access,
                $this->duplicates,
            ),
            'update_class' => new UpdateClassCommand($payload, $this->state, $this->generator),
            'restore_snapshot' => new RestoreSnapshotCommand(
                is_array(data_get($payload, 'snapshot')) ? data_get($payload, 'snapshot') : [],
                $this->state,
            ),
            default => throw new InvalidArgumentException('Unknown character command type.'),
        };
    }
}
