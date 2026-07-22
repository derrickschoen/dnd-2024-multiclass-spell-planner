<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Reports\PrintableSpellListBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

final class CharacterPrintController extends Controller
{
    public function __invoke(int $character, Request $request, PrintableSpellListBuilder $printable): Response
    {
        abort_unless(DB::table('characters')->where('id', $character)->exists(), 404);
        $variant = $request->query('variant') === 'full' ? 'full' : 'reference';

        return Inertia::render('Characters/Print', [
            'spellList' => $printable->build($character, $variant === 'full'),
        ]);
    }
}
