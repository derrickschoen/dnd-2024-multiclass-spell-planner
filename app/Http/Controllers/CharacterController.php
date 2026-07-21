<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Characters\CharacterListBuilder;
use App\Domain\Characters\CharacterWorkspaceBuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

final class CharacterController extends Controller
{
    public function index(CharacterListBuilder $characters): Response
    {
        return Inertia::render('Characters/Index', ['characters' => $characters->build()]);
    }

    public function show(int $character, CharacterWorkspaceBuilder $workspace): Response
    {
        abort_unless(DB::table('characters')->where('id', $character)->exists(), 404);

        return Inertia::render('Characters/Workspace', ['workspace' => $workspace->build($character)]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate(['name' => ['required', 'string', 'max:120']]);
        $id = DB::transaction(fn (): int => DB::table('characters')->insertGetId([
            'name' => data_get($validated, 'name'),
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        return to_route('characters.show', $id);
    }

    public function destroy(int $character): RedirectResponse
    {
        DB::transaction(fn () => DB::table('characters')->where('id', $character)->delete());

        return to_route('characters.index');
    }
}
