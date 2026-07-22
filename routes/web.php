<?php

use App\Http\Controllers\BuildReportController;
use App\Http\Controllers\CharacterController;
use App\Http\Controllers\CharacterMutationController;
use App\Http\Controllers\CharacterPrintController;
use App\Http\Controllers\EligibleSpellController;
use App\Http\Controllers\SavePointController;
use Illuminate\Support\Facades\Route;

Route::get('/', [CharacterController::class, 'index'])->name('characters.index');
Route::post('/characters', [CharacterController::class, 'store'])->name('characters.store');
Route::get('/characters/{character}/print', CharacterPrintController::class)
    ->whereNumber('character')->name('characters.print');
Route::get('/characters/{character}', [CharacterController::class, 'show'])->whereNumber('character')->name('characters.show');
Route::delete('/characters/{character}', [CharacterController::class, 'destroy'])->whereNumber('character')->name('characters.destroy');
Route::post('/characters/{character}/mutations', CharacterMutationController::class)->whereNumber('character')->name('characters.mutate');
Route::get('/characters/{character}/slots/{slot}/eligible-spells', EligibleSpellController::class)
    ->whereNumber(['character', 'slot'])->name('characters.slots.eligible');
Route::post('/characters/{character}/save-points', [SavePointController::class, 'store'])
    ->whereNumber('character')->name('characters.save-points.store');
Route::get('/characters/{character}/save-points/{savePoint}/command', [SavePointController::class, 'command'])
    ->whereNumber(['character', 'savePoint'])->name('characters.save-points.command');

Route::get('/build-report', BuildReportController::class)->name('build-report');
