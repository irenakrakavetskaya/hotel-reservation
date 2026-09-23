<?php

declare(strict_types=1);

namespace App\Domain\Hotel\Repository;

use App\Domain\Hotel\Entity\Hotel;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Hotel>
 */
class HotelRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Hotel::class);
    }

    public function save(Hotel $hotel, bool $flush = true): void
    {
        $this->getEntityManager()->persist($hotel);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(Hotel $hotel, bool $flush = true): void
    {
        $this->getEntityManager()->remove($hotel);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /** @return list<Hotel> */
    public function findByCity(string $city): array
    {
        return $this->findBy(['city' => $city], ['name' => 'ASC']);
    }
}
