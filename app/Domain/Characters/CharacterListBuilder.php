<?php

declare(strict_types=1);

namespace App\Domain\Characters;

use App\Domain\Reports\BuildReportBuilder;
use Illuminate\Support\Facades\DB;

final readonly class CharacterListBuilder
{
    public function __construct(private BuildReportBuilder $reports) {}

    /** @return list<array<string, mixed>> */
    public function build(): array
    {
        return DB::table('characters')->orderBy('name')->get()->map(function (object $character): array {
            $id = (int) data_get($character, 'id');
            $report = $this->reports->build($id);
            $invalid = DB::table('spell_selection_slots')
                ->where('character_id', $id)
                ->where(function ($query): void {
                    $query->where('selection_eligibility', 'invalid')
                        ->orWhereIn('state', ['orphaned', 'kept_override']);
                })->count();
            $duplicates = collect(data_get($report, 'duplicate_assessments'))
                ->where('category', '!=', 'none')->count();

            return [
                'id' => $id,
                'name' => (string) data_get($character, 'name'),
                'level' => (int) data_get($report, 'character.character_level'),
                'classes' => collect(data_get($report, 'classes'))->map(
                    static fn (array $class): string => data_get($class, 'name').' '.data_get($class, 'class_level'),
                )->values()->all(),
                'warning_count' => $duplicates + $invalid,
            ];
        })->all();
    }
}
