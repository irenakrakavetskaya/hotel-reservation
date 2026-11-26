<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Domain\User\Entity\User;
use App\Domain\User\Exception\RoleChangeNotAllowedException;
use App\Domain\User\Exception\UserNotFoundException;
use App\Domain\User\Service\UserRoleService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class UserRoleServiceTest extends KernelTestCase
{
    private UserRoleService $service;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        /** @var Connection $connection */
        $connection = $container->get(Connection::class);
        $connection->executeStatement('TRUNCATE TABLE app_user RESTART IDENTITY CASCADE');

        $this->service = $container->get(UserRoleService::class);
        $this->entityManager = $container->get(EntityManagerInterface::class);
    }

    public function testSetRolesByEmailIsCaseInsensitiveAndDeduplicates(): void
    {
        $this->persist('promote@example.com');

        $user = $this->service->setRolesByEmail('PROMOTE@Example.com', ['ROLE_STAFF', 'ROLE_STAFF']);

        self::assertContains('ROLE_STAFF', $user->getRoles());
        self::assertSame(1, count(array_keys($user->getRoles(), 'ROLE_STAFF', true)));
    }

    public function testSetRolesByEmailFailsForUnknownUser(): void
    {
        $this->expectException(UserNotFoundException::class);

        $this->service->setRolesByEmail('nobody@example.com', ['ROLE_STAFF']);
    }

    public function testUpdateRolesFailsForUnknownTarget(): void
    {
        $actor = $this->persist('actor@example.com', ['ROLE_ADMIN']);

        $this->expectException(UserNotFoundException::class);

        $this->service->updateRoles(999999, ['ROLE_USER'], $actor);
    }

    public function testAdminCannotRemoveTheirOwnAdminRole(): void
    {
        $admin = $this->persist('self@example.com', ['ROLE_ADMIN']);
        $this->persist('second-admin@example.com', ['ROLE_ADMIN']);

        $this->expectException(RoleChangeNotAllowedException::class);
        $this->expectExceptionMessage('own admin');

        $this->service->updateRoles((int) $admin->getId(), ['ROLE_STAFF'], $admin);
    }

    public function testLastAdminCannotBeDemotedByAnotherUser(): void
    {
        $onlyAdmin = $this->persist('only-admin@example.com', ['ROLE_ADMIN']);
        $staff = $this->persist('staff-actor@example.com', ['ROLE_STAFF']);

        $this->expectException(RoleChangeNotAllowedException::class);
        $this->expectExceptionMessage('last remaining admin');

        $this->service->updateRoles((int) $onlyAdmin->getId(), ['ROLE_STAFF'], $staff);
    }

    public function testAdminCanBeDemotedWhenAnotherAdminExists(): void
    {
        $target = $this->persist('target@example.com', ['ROLE_ADMIN']);
        $actor = $this->persist('actor-admin@example.com', ['ROLE_ADMIN']);

        $updated = $this->service->updateRoles((int) $target->getId(), ['ROLE_STAFF'], $actor);

        self::assertNotContains('ROLE_ADMIN', $updated->getRoles());
    }

    /** @param list<string> $roles */
    private function persist(string $email, array $roles = []): User
    {
        $user = new User($email, 'hash', $roles);
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }
}
