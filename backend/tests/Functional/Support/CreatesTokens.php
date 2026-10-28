<?php

declare(strict_types=1);

namespace App\Tests\Functional\Support;

use App\Domain\User\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** For WebTestCase classes: persists a user with the given roles and returns a JWT for it. */
trait CreatesTokens
{
    /**
     * @param list<string> $roles
     */
    private function tokenFor(string $email, array $roles): string
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        /** @var UserPasswordHasherInterface $passwordHasher */
        $passwordHasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        /** @var JWTTokenManagerInterface $tokenManager */
        $tokenManager = self::getContainer()->get(JWTTokenManagerInterface::class);

        $hashed = $passwordHasher->hashPassword(new User($email, 'placeholder', $roles), 'secret-password');
        $user = new User($email, $hashed, $roles);
        $entityManager->persist($user);
        $entityManager->flush();

        return $tokenManager->create($user);
    }
}
