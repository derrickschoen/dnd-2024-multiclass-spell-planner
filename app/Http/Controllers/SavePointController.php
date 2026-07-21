<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Characters\CharacterState;
use App\Domain\Characters\CharacterWorkspaceBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class SavePointController extends Controller
{
    public function store(
        int $character,
        Request $request,
        CharacterState $state,
        CharacterWorkspaceBuilder $workspace,
    ): JsonResponse {
        $validated = $request->validate(['label' => ['required', 'string', 'max:120']]);
        DB::transaction(function () use ($character, $validated, $state): void {
            abort_unless(DB::table('characters')->where('id', $character)->exists(), 404);
            DB::table('character_save_points')->insert([
                'character_id' => $character,
                'label' => data_get($validated, 'label'),
                'snapshot' => json_encode($state->capture($character), JSON_THROW_ON_ERROR),
                'schema_version' => 'a7-v1',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        return response()->json(['workspace' => $workspace->build($character)], 201);
    }

    public function command(int $character, int $savePoint): JsonResponse
    {
        $point = DB::table('character_save_points')
            ->where('character_id', $character)
            ->where('id', $savePoint)
            ->first();
        abort_if($point === null, 404);

        return response()->json([
            'command' => [
                'type' => 'restore_snapshot',
                'snapshot' => json_decode((string) data_get($point, 'snapshot'), true, 512, JSON_THROW_ON_ERROR),
            ],
        ]);
    }
}
