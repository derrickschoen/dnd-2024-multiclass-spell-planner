<?php

declare(strict_types=1);

namespace App\Domain\Rules;

use Illuminate\Support\Facades\DB;
use RuntimeException;

final class ClassProgressionLookup
{
    public function preparedCountForCharacterClass(int $characterId, int $classDefinitionId): int
    {
        $classLevel = DB::table('character_class_levels')
            ->where('character_id', $characterId)
            ->where('class_definition_id', $classDefinitionId)
            ->value('level');
        if ($classLevel === null) {
            throw new RuntimeException("Character {$characterId} does not have class {$classDefinitionId}.");
        }

        $prepared = DB::table('class_progressions')
            ->where('class_definition_id', $classDefinitionId)
            ->where('class_level', $classLevel)
            ->value('prepared_count');
        if ($prepared === null) {
            throw new RuntimeException("Class {$classDefinitionId} has no progression row at level {$classLevel}.");
        }

        return (int) $prepared;
    }
}
