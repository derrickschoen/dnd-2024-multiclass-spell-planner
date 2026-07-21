<?php

declare(strict_types=1);

namespace App\Domain\Characters;

use RuntimeException;

final class RevisionConflict extends RuntimeException
{
    public function __construct(public readonly int $currentRevision)
    {
        parent::__construct('This character changed in another tab. Reload before trying again.');
    }
}
