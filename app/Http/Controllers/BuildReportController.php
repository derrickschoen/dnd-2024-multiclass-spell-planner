<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Reports\BuildReportBuilder;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

final class BuildReportController extends Controller
{
    public function __invoke(BuildReportBuilder $builder): Response
    {
        $characterId = DB::table('characters')->where('notes', 'seed:a6')->value('id');
        abort_if($characterId === null, 404, 'The seed character has not been created.');

        return Inertia::render('BuildReport', [
            'report' => $builder->build((int) $characterId),
        ]);
    }
}
