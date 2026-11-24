<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Authorization\AccessDeniedHandlerInterface;

final class ForbiddenResponse implements AccessDeniedHandlerInterface
{
    public function handle(Request $request, AccessDeniedException $accessDeniedException): Response
    {
        $route = $request->attributes->get('_route');
        if ($route === 'dashboard_ai') {
            return new JsonResponse(['ok'=>false, 'message'=>'ไม่มีสิทธิ์ใช้งานผู้ช่วย AI'], Response::HTTP_FORBIDDEN);
        }
        $message = match ($route) {
            'case_detail', 'case_detail_clean' => 'ไม่มีสิทธิ์เข้าดูสำนวนนี้',
            'subject_detail', 'subject_detail_clean' => 'ไม่มีสิทธิ์เข้าดูสำนวน',
            'comment2_save' => 'ไม่มีสิทธิ์แก้ไขสำนวน',
            default => 'ไม่มีสิทธิ์',
        };
        return new Response($message, Response::HTTP_FORBIDDEN);
    }
}
