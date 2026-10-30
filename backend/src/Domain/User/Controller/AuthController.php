<?php

declare(strict_types=1);

namespace App\Domain\User\Controller;

use App\Domain\User\Dto\RegisterRequest;
use App\Domain\User\Service\RegistrationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

final class AuthController extends AbstractController
{
    public function __construct(
        private readonly RegistrationService $registrationService,
        private readonly RateLimiterFactoryInterface $registerLimiter,
    ) {}

    #[Route('/v1/auth/login', name: 'auth_login', methods: ['POST'])]
    public function login(): JsonResponse
    {
        throw new \LogicException('This route is handled by Symfony Security before the controller is invoked.');
    }

    #[Route('/v1/auth/register', name: 'auth_register', methods: ['POST'])]
    public function register(Request $httpRequest, #[MapRequestPayload] RegisterRequest $request): JsonResponse
    {
        $limit = $this->registerLimiter->create($httpRequest->getClientIp())->consume();
        if (!$limit->isAccepted()) {
            $retryAfter = $limit->getRetryAfter()->getTimestamp() - time();

            throw new TooManyRequestsHttpException(max(1, $retryAfter), 'Too many registration attempts. Try again later.');
        }

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
