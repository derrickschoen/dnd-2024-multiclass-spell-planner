<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed();
});

it('serves the character list as the application home page', function () {
    $this->get('/')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Characters/Index')
            ->where('characters.0.name', 'A6 Sixfold Spellcaster')
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
