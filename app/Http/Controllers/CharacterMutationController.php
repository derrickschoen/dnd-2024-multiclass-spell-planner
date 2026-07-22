<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Characters\CharacterCommandExecutor;
use App\Domain\Characters\RevisionConflict;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class CharacterMutationController extends Controller
{
    public function __invoke(int $character, Request $request, CharacterCommandExecutor $executor): JsonResponse
    {
        $validated = $request->validate([
            'operation_uuid' => ['required', 'uuid'],
            'expected_revision' => ['required', 'integer', 'min:0'],
            'command' => ['required', 'array'],
            'command.type' => ['required', 'string'],
        ]);
        if (! is_int($request->input('expected_revision'))) {
            throw ValidationException::withMessages([
                'expected_revision' => 'The expected revision must be a JSON integer.',
            ]);
        }

        try {
            return response()->json($executor->execute(
                $character,
                is_array($request->input('command')) ? $request->input('command') : [],
                (string) data_get($validated, 'operation_uuid'),
                (int) data_get($validated, 'expected_revision'),
            ));
        } catch (RevisionConflict $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'current_revision' => $exception->currentRevision,
            ], 409);
        } catch (Throwable $exception) {
            if ($exception instanceof HttpExceptionInterface) {
                throw $exception;
            }

            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }
}
