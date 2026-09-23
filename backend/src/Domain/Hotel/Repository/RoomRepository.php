<?php

declare(strict_types=1);

namespace App\Domain\Hotel\Repository;

use App\Domain\Hotel\Entity\Room;
use App\Domain\Hotel\Entity\RoomStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Room>
 */
class RoomRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Room::class);
    }

    public function save(Room $room, bool $flush = true): void
    {
        $this->getEntityManager()->persist($room);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(Room $room, bool $flush = true): void
    {
        $this->getEntityManager()->remove($room);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /** @return list<Room> */
    public function findByHotel(int $hotelId): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.hotel = :hotelId')
            ->setParameter('hotelId', $hotelId)
            ->orderBy('r.roomNumber', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return list<Room> */
    public function findByHotelAndRoomType(int $hotelId, int $roomTypeId): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.hotel = :hotelId')
            ->andWhere('r.roomType = :roomTypeId')
            ->setParameter('hotelId', $hotelId)
            ->setParameter('roomTypeId', $roomTypeId)
            ->orderBy('r.roomNumber', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The physical-room count that backs `total_inventory` for a given
     * (hotel, room type) — used by the inventory pre-population job. Only
     * `active` rooms count; a room under maintenance shouldn't be bookable.
     * See docs/IMPLEMENTATION_PLAN.md §1, `room_type_inventory.total_inventory`.
     */
    public function countActiveByHotelAndRoomType(int $hotelId, int $roomTypeId): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->andWhere('r.hotel = :hotelId')
            ->andWhere('r.roomType = :roomTypeId')
            ->andWhere('r.status = :status')
            ->setParameter('hotelId', $hotelId)
            ->setParameter('roomTypeId', $roomTypeId)
            ->setParameter('status', RoomStatus::Active)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
