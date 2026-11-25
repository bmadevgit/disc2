<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class AuthorizationProbeController
{
    #[IsGranted('CASE_WRITE', subject: 'caseId')]
    public function __invoke(int $caseId): Response
    {
        return new Response('Authorized');
    }
}
