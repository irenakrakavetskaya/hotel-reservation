<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Domain\User\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class RoomTypeControllerTest extends WebTestCase
{
    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        self::bootKernel();

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $connection->executeStatement('TRUNCATE TABLE room_type, hotel, app_user RESTART IDENTITY CASCADE');
    }

    public function testStaffCanCreateRoomType(): void
    {
        $client = static::createClient();
        ['token' => $token] = $this->createAuthenticatedUser('staff@example.com', ['ROLE_STAFF']);
        $hotelId = $this->seedHotel();

        $client->request(
            'POST',
            sprintf('/v1/hotels/%d/room-types', $hotelId),
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token],
            content: json_encode([
                'name' => 'Deluxe',
                'description' => 'City view',
                'maxOccupancy' => 3,
                'basePrice' => 15000,
                'amenities' => ['wifi', 'tv'],
            ], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(201);
        $payload = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('Deluxe', $payload['name']);
        self::assertSame(3, $payload['maxOccupancy']);
        self::assertSame(15000, $payload['basePrice']);
    }

    public function testNonStaffCannotCreateRoomType(): void
    {
        $client = static::createClient();
        ['token' => $token] = $this->createAuthenticatedUser('user@example.com');
        $hotelId = $this->seedHotel();

        $client->request(
            'POST',
            sprintf('/v1/hotels/%d/room-types', $hotelId),
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token],
            content: json_encode([
                'name' => 'Deluxe',
                'maxOccupancy' => 3,
                'basePrice' => 15000,
                'amenities' => [],
            ], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(403);
    }

    public function testCreateValidationFailureReturns422(): void
    {
        $client = static::createClient();
        ['token' => $token] = $this->createAuthenticatedUser('staff2@example.com', ['ROLE_STAFF']);
        $hotelId = $this->seedHotel();

        $client->request(
            'POST',
            sprintf('/v1/hotels/%d/room-types', $hotelId),
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token],
            content: json_encode([
                'name' => '',
                'maxOccupancy' => 0,
                'basePrice' => -1,
                'amenities' => ['wifi'],
            ], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(422);
    }

    /**
     * @param list<string> $roles
     *
     * @return array{token: string, userId: int}
     */
    private function createAuthenticatedUser(string $email, array $roles = []): array
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        /** @var UserPasswordHasherInterface $passwordHasher */
        $passwordHasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        /** @var JWTTokenManagerInterface $tokenManager */
        $tokenManager = self::getContainer()->get(JWTTokenManagerInterface::class);

        $hashingUser = new User($email, 'placeholder', $roles);
        $hashedPassword = $passwordHasher->hashPassword($hashingUser, 'secret-password');
        $user = new User($email, $hashedPassword, $roles);

        $entityManager->persist($user);
        $entityManager->flush();

        return [
            'token' => $tokenManager->create($user),
            'userId' => $user->getId(),
        ];
    }

    private function seedHotel(): int
    {
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);

        $connection->executeStatement(
            <<<'SQL'
                INSERT INTO hotel (name, address, city, country, star_rating, description, amenities, created_at, updated_at)
                VALUES ('Hotel Beta', 'Street 2', 'City', 'Country', 5, 'desc', '[]'::jsonb, now(), now())
                SQL,
        );

        return (int) $connection->lastInsertId();
    }
}
