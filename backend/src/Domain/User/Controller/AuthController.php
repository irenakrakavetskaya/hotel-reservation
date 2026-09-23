<?php

declare(strict_types=1);

namespace App\Domain\User\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class AuthController
{
    #[Route('/v1/auth/login', name: 'auth_login', methods: ['POST'])]
    public function login(): JsonResponse
    {
        throw new \LogicException('This route is handled by Symfony Security before the controller is invoked.');
    }
}
