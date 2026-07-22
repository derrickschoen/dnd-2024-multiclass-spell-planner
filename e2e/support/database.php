<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$action = data_get($argv, 1);
$characterId = (int) data_get($argv, 2, 1);

$result = match ($action) {
    'slots' => DB::table('spell_selection_slots')
        ->where('character_id', $characterId)
        ->orderBy('id')
        ->get()
        ->map(static fn (object $row): array => (array) $row)
        ->all(),
    'slot-fixtures' => DB::table('spell_selection_slots as slot')
        ->join('character_source_instances as source', 'source.id', '=', 'slot.source_instance_id')
        ->leftJoin(
            'spell_versions as selected',
            'selected.id',
            '=',
            DB::raw('COALESCE(slot.fixed_spell_version_id, slot.current_spell_version_id)'),
        )
        ->where('slot.character_id', $characterId)
        ->orderBy('slot.id')
        ->get([
            'slot.*',
            'source.display_name as source_name',
            'source.config as source_config',
            'selected.display_name as spell_name',
        ])
        ->map(static fn (object $row): array => (array) $row)
        ->all(),
    'character' => (array) DB::table('characters')->where('id', $characterId)->sole(),
    'audit' => DB::table('change_log')
        ->where('character_id', $characterId)
        ->orderBy('sequence')
        ->get()
        ->map(static fn (object $row): array => (array) $row)
        ->all(),
    default => throw new InvalidArgumentException("Unknown database action: {$action}"),
};

echo json_encode($result, JSON_THROW_ON_ERROR);
