<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\Command\WriteFailure;
use Symfony\Component\HttpFoundation\Response;

final class WriteErrorResponse
{
    public static function from(WriteFailure $error): Response
    {
        $status = $error->getCode();
        return new Response($error->getMessage(), $status, $status === 503 ? ['Retry-After'=>'1'] : []);
    }
}
