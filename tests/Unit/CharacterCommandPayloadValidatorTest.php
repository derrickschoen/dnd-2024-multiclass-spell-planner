<?php

declare(strict_types=1);

use App\Domain\Characters\Commands\CharacterCommandPayloadValidator;

function commandPayloadValidator(): CharacterCommandPayloadValidator
{
    return new CharacterCommandPayloadValidator;
}

/** @return array<string, mixed> */
function validSlotRestoreState(): array
{
    return [
        'current_spell_version_id' => 1,
        'selection_eligibility' => 'valid',
        'selection_invalid_reason' => 'Still selected.',
        'state' => 'active',
        'override_note' => 'Allowed.',
    ];
}

it('accepts every complete command payload and preserves every allowed field', function (array $payload): void {
    expect(commandPayloadValidator()->validate($payload))->toBe($payload);
})->with([
    'ability' => [[
        'type' => 'update_ability', 'ability' => str_repeat('é', 40), 'score' => 1,
        'reason' => str_repeat('r', 255),
    ]],
    'slot select' => [[
        'type' => 'set_slot', 'slot_id' => 1, 'mode' => 'select', 'spell_version_id' => 1,
        'note' => str_repeat('n', 2000), 'state' => validSlotRestoreState(),
        'integrity' => str_repeat('a', 64), 'reason' => str_repeat('r', 255),
    ]],
    'character rules' => [[
        'type' => 'update_character_rules', 'allow_legacy' => false, 'reason' => str_repeat('r', 255),
    ]],
    'source config' => [[
        'type' => 'update_source_config', 'source_instance_id' => 1,
        'chosen_list' => str_repeat('l', 80), 'reason' => str_repeat('r', 255),
    ]],
    'add source' => [[
        'type' => 'add_source', 'source_type' => 'background', 'source_definition_id' => 1,
        'config' => ['key' => 'value'], 'reason' => str_repeat('r', 255),
    ]],
    'remove source' => [[
        'type' => 'remove_source', 'source_instance_id' => 1, 'reason' => str_repeat('r', 255),
    ]],
    'acknowledge warning' => [[
        'type' => 'acknowledge_warning', 'mode' => 'acknowledge',
        'warning_fingerprint' => str_repeat('f', 255), 'note' => str_repeat('n', 2000),
        'integrity' => str_repeat('a', 64), 'reason' => str_repeat('r', 255),
    ]],
    'class' => [[
        'type' => 'update_class', 'class_definition_id' => 1, 'level' => 1,
        'subclass_definition_id' => 1, 'reason' => str_repeat('r', 255),
    ]],
    'snapshot' => [[
        'type' => 'restore_snapshot', 'snapshot' => ['schema_version' => 'a7-v1'],
        'integrity' => str_repeat('a', 64), 'reason' => str_repeat('r', 255),
    ]],
]);

it('accepts each command enum branch', function (array $payload): void {
    expect(commandPayloadValidator()->validate($payload))->toBeArray();
})->with([
    'clear slot' => [['type' => 'set_slot', 'slot_id' => 1, 'mode' => 'clear']],
    'keep override slot' => [[
        'type' => 'set_slot', 'slot_id' => 1, 'mode' => 'keep_override', 'note' => str_repeat('n', 2000),
    ]],
    'restore slot' => [[
        'type' => 'set_slot', 'slot_id' => 1, 'mode' => 'restore',
        'state' => validSlotRestoreState(), 'integrity' => str_repeat('a', 64),
    ]],
    'feat source' => [[
        'type' => 'add_source', 'source_type' => 'feat', 'source_definition_id' => 1, 'config' => [],
    ]],
    'species source' => [[
        'type' => 'add_source', 'source_type' => 'species', 'source_definition_id' => 1, 'config' => [],
    ]],
    'class source' => [[
        'type' => 'add_source', 'source_type' => 'class', 'source_definition_id' => 1,
        'config' => [
            'level' => 1,
            'wizard_spellbook_acquisitions' => [],
            'divine_order' => ['chosen_option' => 'Thaumaturge', 'chosen_list' => 'Cleric'],
            'primal_order' => ['chosen_option' => 'Magician', 'chosen_list' => 'Druid'],
        ],
    ]],
    'warning delete' => [[
        'type' => 'acknowledge_warning', 'mode' => 'delete', 'warning_fingerprint' => 'fingerprint',
        'integrity' => str_repeat('a', 64),
    ]],
    'warning acknowledge default mode' => [[
        'type' => 'acknowledge_warning', 'warning_fingerprint' => 'fingerprint', 'note' => 'note',
    ]],
    'class removal' => [['type' => 'update_class', 'class_definition_id' => 1, 'level' => null]],
    'class nullable subclass' => [[
        'type' => 'update_class', 'class_definition_id' => 1, 'level' => 1,
        'subclass_definition_id' => null,
    ]],
]);

it('accepts every restored slot state and eligibility enum', function (string $eligibility, string $state): void {
    $restored = validSlotRestoreState();
    $restored['selection_eligibility'] = $eligibility;
    $restored['state'] = $state;
    $restored['current_spell_version_id'] = null;
    $restored['selection_invalid_reason'] = null;
    $restored['override_note'] = null;

    expect(commandPayloadValidator()->validate([
        'type' => 'set_slot', 'slot_id' => 1, 'mode' => 'restore',
        'state' => $restored, 'integrity' => str_repeat('a', 64),
    ]))->toBeArray();
})->with([
    ['valid', 'active'],
    ['invalid', 'orphaned'],
    ['unselected', 'discarded'],
    ['valid', 'kept_override'],
]);

it('rejects exact string and scalar boundaries', function (array $payload, string $message): void {
    expect(fn () => commandPayloadValidator()->validate($payload))
        ->toThrow(InvalidArgumentException::class, $message);
})->with([
    'type above longest command' => [
        ['type' => str_repeat('x', 23)], 'type must not exceed 22 characters.',
    ],
    'reason above maximum' => [[
        'type' => 'remove_source', 'source_instance_id' => 1, 'reason' => str_repeat('r', 256),
    ], 'reason must not exceed 255 characters.'],
    'ability above maximum' => [[
        'type' => 'update_ability', 'ability' => str_repeat('a', 41), 'score' => 1,
    ], 'ability must not exceed 40 characters.'],
    'mode above longest enum' => [[
        'type' => 'set_slot', 'slot_id' => 1, 'mode' => str_repeat('m', 14),
    ], 'mode must not exceed 13 characters.'],
    'override note above maximum' => [[
        'type' => 'set_slot', 'slot_id' => 1, 'mode' => 'keep_override', 'note' => str_repeat('n', 2001),
    ], 'note must not exceed 2000 characters.'],
    'chosen list above maximum' => [[
        'type' => 'update_source_config', 'source_instance_id' => 1, 'chosen_list' => str_repeat('l', 81),
    ], 'chosen_list must not exceed 80 characters.'],
    'source type above longest enum' => [[
        'type' => 'add_source', 'source_type' => str_repeat('s', 11),
        'source_definition_id' => 1, 'config' => [],
    ], 'source_type must not exceed 10 characters.'],
    'fingerprint above maximum' => [[
        'type' => 'acknowledge_warning', 'warning_fingerprint' => str_repeat('f', 256), 'note' => 'note',
    ], 'warning_fingerprint must not exceed 255 characters.'],
    'warning mode above longest enum' => [[
        'type' => 'acknowledge_warning', 'warning_fingerprint' => 'fingerprint',
        'mode' => str_repeat('m', 12),
    ], 'mode must not exceed 11 characters.'],
    'integrity too short' => [[
        'type' => 'restore_snapshot', 'snapshot' => [], 'integrity' => str_repeat('a', 63),
    ], 'integrity must be a 64-character hexadecimal signature.'],
    'integrity too long' => [[
        'type' => 'restore_snapshot', 'snapshot' => [], 'integrity' => str_repeat('a', 65),
    ], 'integrity must not exceed 64 characters.'],
    'integrity non hexadecimal' => [[
        'type' => 'restore_snapshot', 'snapshot' => [], 'integrity' => str_repeat('g', 64),
    ], 'integrity must be a 64-character hexadecimal signature.'],
    'whitespace non-empty string' => [[
        'type' => 'update_source_config', 'source_instance_id' => 1, 'chosen_list' => ' ',
    ], 'chosen_list must not be empty.'],
    'zero positive integer' => [[
        'type' => 'remove_source', 'source_instance_id' => 0,
    ], 'source_instance_id must be a positive integer.'],
    'non integer' => [[
        'type' => 'remove_source', 'source_instance_id' => '1',
    ], 'source_instance_id must be an integer.'],
]);

it('rejects malformed add and remove source payload shapes directly', function (array $payload, string $message): void {
    expect(fn () => commandPayloadValidator()->validate($payload))
        ->toThrow(InvalidArgumentException::class, $message);
})->with([
    'unknown add field after allowed keys' => [[
        'type' => 'add_source', 'source_type' => 'feat', 'source_definition_id' => 1,
        'config' => [], 'reason' => 'reason', 'unexpected' => true,
    ], 'Unknown command field: unexpected.'],
    'unknown remove field after allowed keys' => [[
        'type' => 'remove_source', 'source_instance_id' => 1, 'reason' => 'reason', 'unexpected' => true,
    ], 'Unknown command field: unexpected.'],
    'unknown source type' => [[
        'type' => 'add_source', 'source_type' => 'subclass', 'source_definition_id' => 1, 'config' => [],
    ], 'Source type must be class, feat, species, or background.'],
    'missing config' => [[
        'type' => 'add_source', 'source_type' => 'feat', 'source_definition_id' => 1,
    ], 'Source config must be an object.'],
    'scalar config' => [[
        'type' => 'add_source', 'source_type' => 'feat', 'source_definition_id' => 1, 'config' => 'config',
    ], 'Source config must be an object.'],
    'list config' => [[
        'type' => 'add_source', 'source_type' => 'feat', 'source_definition_id' => 1, 'config' => [1],
    ], 'Source config must be an object.'],
    'class config missing level' => [[
        'type' => 'add_source', 'source_type' => 'class', 'source_definition_id' => 1, 'config' => [],
    ], 'level must be an integer.'],
    'class config level above maximum' => [[
        'type' => 'add_source', 'source_type' => 'class', 'source_definition_id' => 1,
        'config' => ['level' => 21],
    ], 'Class source level must be between 1 and 20.'],
    'class config acquisitions must be a list' => [[
        'type' => 'add_source', 'source_type' => 'class', 'source_definition_id' => 1,
        'config' => ['level' => 1, 'wizard_spellbook_acquisitions' => ['spell' => '2024:shield']],
    ], 'Wizard spellbook acquisitions must be a list.'],
    'class config unknown field' => [[
        'type' => 'add_source', 'source_type' => 'class', 'source_definition_id' => 1,
        'config' => ['level' => 1, 'spellcasting_ability' => 'charisma'],
    ], 'Unknown class source config field: spellcasting_ability.'],
]);

it('rejects command-specific malformed fields before domain execution', function (array $payload, string $message): void {
    expect(fn () => commandPayloadValidator()->validate($payload))
        ->toThrow(InvalidArgumentException::class, $message);
})->with([
    'unknown command' => [['type' => 'unknown'], 'Unknown character command type.'],
    'ability unknown field' => [[
        'type' => 'update_ability', 'ability' => 'wisdom', 'score' => 1, 'extra' => true,
    ], 'Unknown command field: extra.'],
    'ability missing score' => [[
        'type' => 'update_ability', 'ability' => 'wisdom',
    ], 'score must be an integer.'],
    'slot unknown field' => [[
        'type' => 'set_slot', 'slot_id' => 1, 'mode' => 'clear', 'extra' => true,
    ], 'Unknown command field: extra.'],
    'slot missing id' => [['type' => 'set_slot', 'mode' => 'clear'], 'slot_id must be an integer.'],
    'slot unknown mode' => [[
        'type' => 'set_slot', 'slot_id' => 1, 'mode' => 'unknown',
    ], 'Unknown slot mutation mode.'],
    'select missing spell' => [[
        'type' => 'set_slot', 'slot_id' => 1, 'mode' => 'select',
    ], 'spell_version_id must be an integer.'],
    'restore missing integrity' => [[
        'type' => 'set_slot', 'slot_id' => 1, 'mode' => 'restore', 'state' => validSlotRestoreState(),
    ], 'integrity must be a string.'],
    'rules unknown field' => [[
        'type' => 'update_character_rules', 'allow_legacy' => false, 'extra' => true,
    ], 'Unknown command field: extra.'],
    'rules wrong boolean' => [[
        'type' => 'update_character_rules', 'allow_legacy' => 'false',
    ], 'allow_legacy must be a boolean.'],
    'source config unknown field' => [[
        'type' => 'update_source_config', 'source_instance_id' => 1,
        'chosen_list' => 'Cleric', 'extra' => true,
    ], 'Unknown command field: extra.'],
    'source config missing id' => [[
        'type' => 'update_source_config', 'chosen_list' => 'Cleric',
    ], 'source_instance_id must be an integer.'],
    'add source missing definition' => [[
        'type' => 'add_source', 'source_type' => 'feat', 'config' => [],
    ], 'source_definition_id must be an integer.'],
    'add source type wrong scalar' => [[
        'type' => 'add_source', 'source_type' => [], 'source_definition_id' => 1, 'config' => [],
    ], 'source_type must be a string.'],
    'warning unknown field' => [[
        'type' => 'acknowledge_warning', 'warning_fingerprint' => 'fingerprint',
        'note' => 'note', 'extra' => true,
    ], 'Unknown command field: extra.'],
    'warning missing note' => [[
        'type' => 'acknowledge_warning', 'warning_fingerprint' => 'fingerprint',
    ], 'note must be a string.'],
    'warning note above maximum' => [[
        'type' => 'acknowledge_warning', 'warning_fingerprint' => 'fingerprint',
        'note' => str_repeat('n', 2001),
    ], 'note must not exceed 2000 characters.'],
    'warning delete missing integrity' => [[
        'type' => 'acknowledge_warning', 'mode' => 'delete', 'warning_fingerprint' => 'fingerprint',
    ], 'integrity must be a string.'],
    'class unknown field' => [[
        'type' => 'update_class', 'class_definition_id' => 1, 'level' => 1, 'extra' => true,
    ], 'Unknown command field: extra.'],
    'class missing id' => [['type' => 'update_class', 'level' => 1], 'class_definition_id must be an integer.'],
    'class wrong level type' => [[
        'type' => 'update_class', 'class_definition_id' => 1, 'level' => '1',
    ], 'level must be an integer.'],
    'class wrong subclass type' => [[
        'type' => 'update_class', 'class_definition_id' => 1, 'level' => 1,
        'subclass_definition_id' => '1',
    ], 'subclass_definition_id must be an integer.'],
    'snapshot unknown field' => [[
        'type' => 'restore_snapshot', 'snapshot' => [], 'integrity' => str_repeat('a', 64),
        'extra' => true,
    ], 'Unknown command field: extra.'],
    'snapshot scalar' => [[
        'type' => 'restore_snapshot', 'snapshot' => 'snapshot', 'integrity' => str_repeat('a', 64),
    ], 'Character snapshot must be an object.'],
]);

it('rejects every malformed restored slot field directly', function (array $changes, string $message): void {
    $state = array_replace(validSlotRestoreState(), $changes);
    if (array_key_exists('remove', $changes)) {
        unset($state[$changes['remove']]);
        unset($state['remove']);
    }

    expect(fn () => commandPayloadValidator()->validate([
        'type' => 'set_slot', 'slot_id' => 1, 'mode' => 'restore',
        'state' => $state, 'integrity' => str_repeat('a', 64),
    ]))->toThrow(InvalidArgumentException::class, $message);
})->with([
    'unknown field' => [['extra' => true], 'Unknown slot restore state field: extra.'],
    'missing field' => [['remove' => 'state'], 'Slot restore state is missing state.'],
    'zero spell' => [['current_spell_version_id' => 0], 'Slot restore spell_version_id must be a positive integer or null.'],
    'string spell' => [['current_spell_version_id' => '1'], 'Slot restore spell_version_id must be a positive integer or null.'],
    'non-string eligibility' => [['selection_eligibility' => []], 'Unknown slot selection eligibility.'],
    'unknown eligibility' => [['selection_eligibility' => 'unknown'], 'Unknown slot selection eligibility.'],
    'non-string invalid reason' => [['selection_invalid_reason' => []], 'Slot selection invalid reason must be a string or null.'],
    'non-string state' => [['state' => []], 'Unknown restored slot state.'],
    'unknown state' => [['state' => 'unknown'], 'Unknown restored slot state.'],
    'non-string override' => [['override_note' => []], 'Slot override note must be a string or null.'],
]);
