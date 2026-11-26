<?php

declare(strict_types=1);

namespace App\Domain\Report\Repository;

use App\Domain\Report\Entity\ReservationReport;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<ReservationReport>
 */
final class ReportRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ReservationReport::class);
    }

    /** @return list<ReservationReport> */
    public function findRecent(int $limit = 50): array
    {
        return $this->createQueryBuilder('report')
            ->orderBy('report.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function findByUuid(Uuid $id): ?ReservationReport
    {
        return $this->find($id);
    }
}
