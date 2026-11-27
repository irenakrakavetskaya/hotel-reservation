<?php

declare(strict_types=1);

namespace App\Domain\Report\Controller;

use App\Domain\Report\Service\ReportRealtime;
use App\Domain\User\Security\AppUserInterface;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_STAFF')]
#[OA\Response(response: 401, description: 'Authentication required')]
#[OA\Response(response: 403, description: 'Staff access required')]
final class ReportRealtimeController
{
    public function __construct(private readonly ReportRealtime $realtime) {}

    #[Route('/v1/admin/realtime/token', name: 'report_realtime_token', methods: ['GET'])]
    #[OA\Get(security: [['Bearer' => []]])]
    #[OA\Tag(name: 'Reports')]
    #[OA\Response(response: 200, description: 'Short-lived realtime token', content: new OA\JsonContent(required: ['token'], properties: [new OA\Property(property: 'token', type: 'string')]))]
    public function token(#[CurrentUser] AppUserInterface $user): JsonResponse
    {
        return new JsonResponse(['token' => $this->realtime->connectionToken($user->getId())], headers: [
            'Cache-Control' => 'no-store',
        ]);
    }
}
