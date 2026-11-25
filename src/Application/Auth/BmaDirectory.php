<?php

declare(strict_types=1);

namespace App\Application\Auth;

interface BmaDirectory
{
    public function lookup(string $username): array;
}
