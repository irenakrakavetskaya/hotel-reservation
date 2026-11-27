<?php

declare(strict_types=1);

namespace App\Domain\User\Controller;

use App\Domain\User\Dto\RegisterRequest;
use App\Domain\User\Service\RegistrationService;
use OpenApi\Attributes as OA;
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
    #[OA\Tag(name: 'Authentication')]
    #[OA\Post(security: [])]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(required: ['email', 'password'], properties: [
        new OA\Property(property: 'email', type: 'string', format: 'email'),
        new OA\Property(property: 'password', type: 'string', format: 'password'),
    ]))]
    #[OA\Response(response: 200, description: 'JWT', content: new OA\JsonContent(required: ['token'], properties: [new OA\Property(property: 'token', type: 'string')]))]
    #[OA\Response(response: 401, description: 'Invalid credentials or login throttled')]
    public function login(): JsonResponse
    {
        throw new \LogicException('This route is handled by Symfony Security before the controller is invoked.');
    }

    #[Route('/v1/auth/register', name: 'auth_register', methods: ['POST'])]
    #[OA\Tag(name: 'Authentication')]
    #[OA\Post(security: [])]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(required: ['email', 'password', 'passwordConfirmation'], properties: [
        new OA\Property(property: 'email', type: 'string', format: 'email', maxLength: 180),
        new OA\Property(property: 'password', type: 'string', minLength: 8, maxLength: 255, format: 'password'),
        new OA\Property(property: 'passwordConfirmation', type: 'string', minLength: 8, maxLength: 255, format: 'password'),
    ]))]
    #[OA\Response(response: 201, description: 'Registered user and JWT', content: new OA\JsonContent(required: ['token', 'user'], properties: [
        new OA\Property(property: 'token', type: 'string'),
        new OA\Property(property: 'user', ref: '#/components/schemas/UserResponse'),
    ]))]
    #[OA\Response(response: 409, description: 'Email already registered', content: new OA\JsonContent(ref: '#/components/schemas/DomainError'))]
    #[OA\Response(response: 422, description: 'Invalid request; JSON problem details when requested', content: new OA\JsonContent(ref: '#/components/schemas/HttpProblem'))]
    #[OA\Response(response: 429, description: 'Registration rate limit exceeded', headers: [new OA\Header(header: 'Retry-After', schema: new OA\Schema(type: 'integer'))])]
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
