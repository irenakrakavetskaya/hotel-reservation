<?php

declare(strict_types=1);

namespace App\Domain\User\Service;

use App\Domain\User\Dto\RegisterRequest;
use App\Domain\User\Dto\RegistrationResult;
use App\Domain\User\Entity\User;
use App\Domain\User\Exception\UserAlreadyExistsException;
use App\Domain\User\Repository\UserRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class RegistrationService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $userRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly JWTTokenManagerInterface $tokenManager,
    ) {
    }

    public function register(RegisterRequest $request): RegistrationResult
    {
        $email = mb_strtolower(trim($request->email));
        if (null !== $this->userRepository->findOneBy(['email' => $email])) {
            throw UserAlreadyExistsException::forEmail($email);
        }

        $passwordPrototype = new User($email, 'password-placeholder');
        $passwordHash = $this->passwordHasher->hashPassword($passwordPrototype, $request->password);
        $user = new User($email, $passwordHash, ['ROLE_USER']);
        $this->entityManager->persist($user);

        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException $exception) {
            throw UserAlreadyExistsException::forEmail($email);
        }

        return new RegistrationResult($user, $this->tokenManager->create($user));
    }
}