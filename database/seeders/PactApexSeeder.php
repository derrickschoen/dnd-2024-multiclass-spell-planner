<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Characters\CharacterCommandExecutor;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Opt-in fixture for exercising Pact Magic and every Mystic Arcanum tier.
 *
 * This character is intentionally excluded from DatabaseSeeder: it is a rules
 * boundary fixture rather than part of the default demonstration roster.
 */
final class PactApexSeeder extends Seeder
{
    public function run(): void
    {
        if (DB::table('characters')->where('notes', 'seed:pact-apex')->exists()) {
            return;
        }

        DB::transaction(function (): void {
            $characterId = DB::table('characters')->insertGetId([
                'name' => 'Pact Apex',
                'charisma' => 20,
                'rules_edition_preference' => '2024',
                'notes' => 'seed:pact-apex',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $revision = 0;
            foreach (['Warlock' => 17, 'Bard' => 3] as $className => $level) {
                $classId = DB::table('class_definitions')->where('name', $className)->value('id');
                if ($classId === null) {
                    throw new RuntimeException("Cannot seed Pact Apex before the {$className} definition exists.");
                }

                $result = app(CharacterCommandExecutor::class)->execute(
                    $characterId,
                    [
                        'type' => 'update_class',
                        'class_definition_id' => (int) $classId,
                        'level' => $level,
                        'subclass_definition_id' => null,
                        'reason' => "Seed Pact Apex's {$className} {$level} through the character command surface.",
                    ],
                    Str::uuid()->toString(),
                    $revision,
                );
                $revision = (int) data_get($result, 'revision');
            }
        });
    }
}
