<?php

declare(strict_types=1);

use App\Domain\Characters\Commands\AcknowledgeWarningCommand;
use App\Domain\Characters\Commands\AddSourceCommand;
use App\Domain\Characters\Commands\CharacterCommandFactory;
use App\Domain\Characters\Commands\CharacterCommandIntegrity;
use App\Domain\Characters\Commands\RemoveSourceCommand;
use App\Domain\Characters\Commands\RestoreSnapshotCommand;
use App\Domain\Characters\Commands\SetSlotCommand;
use App\Domain\Characters\Commands\UpdateAbilityCommand;
use App\Domain\Characters\Commands\UpdateCharacterRulesCommand;
use App\Domain\Characters\Commands\UpdateClassCommand;
use App\Domain\Characters\Commands\UpdateSourceConfigCommand;

it('constructs every command and protects every destructive inverse', function (): void {
    $characterId = 41;
    $factory = app(CharacterCommandFactory::class);
    $integrity = app(CharacterCommandIntegrity::class);
    $slotState = [
        'current_spell_version_id' => null,
        'selection_eligibility' => 'unselected',
        'selection_invalid_reason' => null,
        'state' => 'active',
        'override_note' => null,
    ];

    $commands = [
        UpdateAbilityCommand::class => ['type' => 'update_ability', 'ability' => 'wisdom', 'score' => 16],
        SetSlotCommand::class => ['type' => 'set_slot', 'slot_id' => 1, 'mode' => 'clear'],
        UpdateCharacterRulesCommand::class => ['type' => 'update_character_rules', 'allow_legacy' => true],
        UpdateSourceConfigCommand::class => ['type' => 'update_source_config', 'source_instance_id' => 1, 'chosen_list' => 'Cleric'],
        AddSourceCommand::class => ['type' => 'add_source', 'source_type' => 'feat', 'source_definition_id' => 1, 'config' => []],
        RemoveSourceCommand::class => ['type' => 'remove_source', 'source_instance_id' => 1],
        AcknowledgeWarningCommand::class => [
            'type' => 'acknowledge_warning', 'warning_fingerprint' => 'fingerprint', 'note' => 'reviewed',
        ],
        UpdateClassCommand::class => ['type' => 'update_class', 'class_definition_id' => 1, 'level' => 1],
    ];

    foreach ($commands as $expectedClass => $payload) {
        expect($factory->make($characterId, $payload))->toBeInstanceOf($expectedClass);
    }

    $protected = [
        SetSlotCommand::class => [
            'type' => 'set_slot', 'slot_id' => 1, 'mode' => 'restore', 'state' => $slotState,
        ],
        AcknowledgeWarningCommand::class => [
            'type' => 'acknowledge_warning', 'mode' => 'delete', 'warning_fingerprint' => 'fingerprint',
        ],
        RestoreSnapshotCommand::class => [
            'type' => 'restore_snapshot', 'snapshot' => ['schema_version' => 'a7-v1'],
        ],
    ];

    foreach ($protected as $expectedClass => $payload) {
        expect(fn () => $factory->make($characterId, [...$payload, 'integrity' => str_repeat('0', 64)]))
            ->toThrow(InvalidArgumentException::class, 'This internal character command is invalid');
        expect($factory->make($characterId, $integrity->attach($characterId, $payload)))
            ->toBeInstanceOf($expectedClass);
    }
});
