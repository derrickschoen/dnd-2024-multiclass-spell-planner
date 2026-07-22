<?php

declare(strict_types=1);

namespace App\Domain\Characters\Commands;

use InvalidArgumentException;
use RuntimeException;

final class CharacterCommandIntegrity
{
    /**
     * @param  array<string, mixed>  $command
     * @return array<string, mixed>
     */
    public function attach(int $characterId, array $command): array
    {
        $command['integrity'] = $this->signature($characterId, $command);

        return $command;
    }

    /** @param array<string, mixed> $command */
    public function assertValid(int $characterId, array $command): void
    {
        $provided = data_get($command, 'integrity');
        if (! is_string($provided) || ! hash_equals($this->signature($characterId, $command), $provided)) {
            throw new InvalidArgumentException('This internal character command is invalid or belongs to another character.');
        }
    }

    /** @param array<string, mixed> $command */
    private function signature(int $characterId, array $command): string
    {
        unset($command['integrity']);
        $encoded = json_encode(
            $this->canonicalize($command),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        return hash_hmac('sha256', $characterId."\n".$encoded, $this->key());
    }

    private function key(): string
    {
        $key = config('app.key');
        if (! is_string($key) || $key === '') {
            throw new RuntimeException('APP_KEY is required to sign internal character commands.');
        }

        return $key;
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }

        ksort($value);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }
}
