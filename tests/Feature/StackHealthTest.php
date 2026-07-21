<?php

use Illuminate\Support\Facades\DB;

it('serves the Inertia health page with the expected stack', function () {
    $this->get('/')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Health')
            ->where('php', PHP_VERSION)
            ->has('laravel')
            ->has('journalMode')
        );
});

it('enforces foreign keys on the SQLite connection', function () {
    // Cascade-resistant editing leans on composite foreign keys; if FK enforcement
    // is off, every referential guarantee in the schema is silently decorative.
    expect((int) DB::select('PRAGMA foreign_keys')[0]->foreign_keys)->toBe(1);
});

it('runs PHP 8.4 or newer', function () {
    expect(PHP_VERSION_ID)->toBeGreaterThanOrEqual(80400);
});
