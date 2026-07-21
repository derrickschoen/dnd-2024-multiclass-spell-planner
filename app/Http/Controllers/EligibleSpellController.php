<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Characters\EligibleSpellSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class EligibleSpellController extends Controller
{
    public function __invoke(int $character, int $slot, Request $request, EligibleSpellSearch $search): JsonResponse
    {
        return response()->json([
            'spells' => $search->search($character, $slot, trim((string) $request->query('q', ''))),
        ]);
    }
}
