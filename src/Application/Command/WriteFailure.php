<?php

declare(strict_types=1);

namespace App\Application\Command;

use RuntimeException;

/** A public validation or stale-record message safe to show to the editor. */
final class WriteFailure extends RuntimeException
{
    public function __construct(string $message, int $status = 422)
    {
        parent::__construct($message, $status);
    }
}
