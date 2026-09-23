<?php

declare(strict_types=1);

namespace App\Domain\User\Controller;

use App\Domain\User\Dto\RegisterRequest;
use App\Domain\User\Service\RegistrationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

final class AuthController extends AbstractController
{
    public function __construct(private readonly RegistrationService $registrationService)
    {
    }

    #[Route('/v1/auth/login', name: 'auth_login', methods: ['POST'])]
    public function login(): JsonResponse
    {
        throw new \LogicException('This route is handled by Symfony Security before the controller is invoked.');
    }

    #[Route('/v1/auth/register', name: 'auth_register', methods: ['POST'])]
    public function register(#[MapRequestPayload] RegisterRequest $request): JsonResponse
    {
        $result = $this->registrationService->register($request);

        return $this->json([
            'token' => $result->token,
            'user' => [
                'id' => $result->user->getId(),
                'email' => $result->user->getUserIdentifier(),
                'roles' => $result->user->getRoles(),
            ],
        ], Response::HTTP_CREATED);
    }
}
