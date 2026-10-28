<?php

declare(strict_types=1);

namespace App\Domain\User\Service;

use App\Domain\User\Entity\User;
use App\Domain\User\Exception\RoleChangeNotAllowedException;
use App\Domain\User\Exception\UserNotFoundException;
use App\Domain\User\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;

final class UserRoleService
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $entityManager,
    ) {}

    /** @return list<User> */
    public function list(?string $search, int $limit, int $offset): array
    {
        return $this->users->search($search, $limit, $offset);
    }

    /** @param list<string> $roles */
    public function updateRoles(int $targetId, array $roles, User $actor): User
    {
        $roles = array_values(array_unique($roles));

        // Locking admin rows serialises concurrent demotions so the "last admin" check cannot race.
        return $this->entityManager->wrapInTransaction(function () use ($targetId, $roles, $actor): User {
            $target = $this->users->find($targetId) ?? throw UserNotFoundException::forId($targetId);

            $losesAdmin = in_array('ROLE_ADMIN', $target->getRoles(), true) && !in_array('ROLE_ADMIN', $roles, true);
            if ($losesAdmin) {
                if ($target->getId() === $actor->getId()) {
                    throw RoleChangeNotAllowedException::cannotRemoveOwnAdmin();
                }
                if ($this->users->countAdminsForUpdate() <= 1) {
                    throw RoleChangeNotAllowedException::lastAdmin();
                }
            }

            $target->setRoles($roles);

            return $target;
        });
    }

    /** @param list<string> $roles */
    public function setRolesByEmail(string $email, array $roles): User
    {
        $user = $this->users->findOneBy(['email' => mb_strtolower($email)]);
        if (null === $user) {
            throw new UserNotFoundException(sprintf('User "%s" was not found.', $email));
        }
        $user->setRoles(array_values(array_unique($roles)));
        $this->entityManager->flush();

        return $user;
    }
}
