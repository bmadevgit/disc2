<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;

#[AsController]
final class RetiredController
{
    public function __invoke(string $message): Response
    {
        return new Response($message, 410, ['Content-Type'=>'text/plain; charset=utf-8']);
    }
}
