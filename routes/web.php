<?php

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', fn () => Inertia::render('Health', [
    'laravel' => Application::VERSION,
    'php' => PHP_VERSION,
    'journalMode' => DB::select('PRAGMA journal_mode')[0]->journal_mode,
    'foreignKeys' => (int) DB::select('PRAGMA foreign_keys')[0]->foreign_keys,
]))->name('health');
