<?php

declare(strict_types=1);

namespace App\Domain\Grants;

use InvalidArgumentException;

final readonly class FreeCast
{
    public function __construct(
        public int $uses,
        public FreeCastRecovery $recovery,
        public FreeCastPoolScope $poolScope,
    ) {
        if ($uses < 1) {
            throw new InvalidArgumentException('Free-cast uses must be positive.');
        }
    }

    /** @return array{uses: int, recovery: string, pool_scope: string} */
    public function toArray(): array
    {
        return [
            'uses' => $this->uses,
            'recovery' => $this->recovery->value,
            'pool_scope' => $this->poolScope->value,
        ];
    }
}
