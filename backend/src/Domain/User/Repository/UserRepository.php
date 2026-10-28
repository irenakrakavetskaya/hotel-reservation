<?php

declare(strict_types=1);

namespace App\Domain\User\Repository;

use App\Domain\User\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<User>
 */
final class UserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /** @return list<User> */
    public function search(?string $search, int $limit, int $offset): array
    {
        $qb = $this->createQueryBuilder('u')->orderBy('u.id', 'ASC')->setMaxResults($limit)->setFirstResult($offset);
        if (null !== $search) {
            $qb->andWhere('u.email LIKE :search')
                ->setParameter('search', '%' . addcslashes(mb_strtolower($search), '%_\\') . '%');
        }

        /** @var list<User> */
        return $qb->getQuery()->getResult();
    }

    /** Counts admins while row-locking them, so concurrent demotions cannot both see "2 admins". */
    public function countAdminsForUpdate(): int
    {
        $ids = $this->getEntityManager()->getConnection()->fetchFirstColumn(
            "SELECT id FROM app_user WHERE roles::jsonb @> '[\"ROLE_ADMIN\"]'::jsonb FOR UPDATE",
        );

        return count($ids);
    }
}
