<?php

declare(strict_types=1);

use App\Domain\Characters\Commands\CharacterCommandIntegrity;
use App\Domain\Characters\RevisionConflict;

it('signs the documented canonical command representation', function (): void {
    config(['app.key' => 'mutation-contract-secret']);
    $integrity = app(CharacterCommandIntegrity::class);
    $command = [
        'z' => 'é/',
        'a' => ['b' => 2, 'a' => [['y' => true, 'x' => null]]],
    ];

    $signed = $integrity->attach(42, $command);

    expect(data_get($signed, 'integrity'))
        ->toBe('018d063fab9a6875c23ef9db44897bdb6938948ae917d5e21b61e92d0e5e6229');
    $integrity->assertValid(42, $signed);

    $reordered = [
        'a' => ['a' => [['x' => null, 'y' => true]], 'b' => 2],
        'z' => 'é/',
        'integrity' => data_get($signed, 'integrity'),
    ];
    $integrity->assertValid(42, $reordered);
});

it('requires an application key before signing', function (): void {
    config(['app.key' => '']);

    expect(fn (): array => app(CharacterCommandIntegrity::class)->attach(42, ['type' => 'test']))
        ->toThrow(RuntimeException::class, 'APP_KEY is required to sign internal character commands.');
});

it('carries the conflicting revision and stable client message', function (): void {
    $conflict = new RevisionConflict(17);

    expect($conflict->currentRevision)->toBe(17)
        ->and($conflict->getMessage())
        ->toBe('This character changed in another tab. Reload before trying again.');
});
