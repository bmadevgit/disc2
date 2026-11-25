<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\HttpFoundation\Request;

/** Bookmarked procedural URLs used integer casts and allowed empty query values. */
final class ReadParameters
{
    public static function integer(Request $request, string $name, int $default = 0): int
    {
        $value = $request->query->all()[$name] ?? $default;
        return is_scalar($value) ? (int)$value : $default;
    }
}
