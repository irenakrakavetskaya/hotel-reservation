<?php

declare(strict_types=1);

namespace App\Domain\Hotel\Repository;

use App\Domain\Hotel\Entity\RoomType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RoomType>
 */
class RoomTypeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RoomType::class);
    }

    public function save(RoomType $roomType, bool $flush = true): void
    {
        $this->getEntityManager()->persist($roomType);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(RoomType $roomType, bool $flush = true): void
    {
        $this->getEntityManager()->remove($roomType);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /** @return list<RoomType> */
    public function findByHotel(int $hotelId): array
    {
        return $this->createQueryBuilder('rt')
            ->andWhere('rt.hotel = :hotelId')
            ->setParameter('hotelId', $hotelId)
            ->orderBy('rt.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Lightweight, memory-efficient iteration over every room type, used by
     * the fan-out handlers for the daily inventory pre-population and rate
     * recompute jobs (docs/IMPLEMENTATION_PLAN.md §1, §4, §8 step 2).
     * Deliberately bypasses entity hydration — these jobs may run across a
     * large number of room types and only need three scalars from each.
     *
     * @return iterable<array{hotelId: int, roomTypeId: int, basePrice: int}>
     */
    public function iterateAllForScheduledJobs(): iterable
    {
        $result = $this->getEntityManager()->getConnection()->executeQuery(
            'SELECT hotel_id, id AS room_type_id, base_price FROM room_type ORDER BY hotel_id, id',
        );

        foreach ($result->iterateAssociative() as $row) {
            yield [
                'hotelId' => (int) $row['hotel_id'],
                'roomTypeId' => (int) $row['room_type_id'],
                'basePrice' => (int) $row['base_price'],
            ];
        }
    }
}
