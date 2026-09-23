<?php

declare(strict_types=1);

namespace App\Domain\Reservation\Repository;

use App\Domain\Payment\Exception\ReservationPaymentStateException;
use App\Domain\Reservation\Entity\Reservation;
use App\Domain\Reservation\Entity\ReservationStatus;
use App\Domain\Reservation\Exception\InsufficientInventoryException;
use App\Domain\Reservation\Exception\ReservationCancellationConflictException;
use App\Domain\Reservation\Exception\ReservationNotFoundException;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Reservation>
 *
 * This is where the system's core correctness guarantee lives. Read
 * `.claude/skills/reservation-domain/SKILL.md` and
 * docs/IMPLEMENTATION_PLAN.md §3 before changing anything below.
 */
class ReservationRepository extends ServiceEntityRepository
{
    /** Postgres SQLSTATE for a CHECK constraint violation. */
    private const SQLSTATE_CHECK_VIOLATION = '23514';

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Reservation::class);
    }

    public function find(mixed $id, LockMode|int|null $lockMode = LockMode::NONE, ?int $lockVersion = null): ?Reservation
    {
        return parent::find($id, $lockMode, $lockVersion);
    }

    /** @return list<Reservation> */
    public function findForUser(int $userId): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.userId = :userId')
            ->setParameter('userId', $userId)
            ->orderBy('r.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Creates a reservation and holds the corresponding inventory in one
     * transaction. Safe to call twice with a Reservation carrying the same
     * `id` (the idempotency key) — the second call is a no-op that returns
     * the reservation created by the first call.
     *
     * Write order is deliberate and differs slightly from the simplified
     * pseudocode in docs/IMPLEMENTATION_PLAN.md §3: the reservation INSERT
     * happens FIRST (via `ON CONFLICT (id) DO NOTHING`), and the inventory
     * UPDATE only runs if that insert actually created a new row (1, not 0,
     * rows affected). This ordering is what makes retries safe against
     * *double-incrementing* inventory, not just against duplicate
     * reservation rows — checking availability before inserting (the plan's
     * original order) does not, on its own, protect against a network-retry
     * of an already-succeeded booking incrementing total_reserved twice.
     *
     * If the inventory UPDATE violates `check_room_count`, Postgres raises
     * and the whole transaction — including the INSERT — rolls back, so a
     * request that would overbook never leaves a stray reservation row
     * behind either.
     *
     * @throws InsufficientInventoryException if fulfilling this reservation
     *                                         would exceed the 10% overbooking ceiling
     */
    public function create(Reservation $reservation): Reservation
    {
        $conn = $this->getEntityManager()->getConnection();
        $conn->beginTransaction();

        try {
            $inserted = $conn->executeStatement(
                <<<'SQL'
                    INSERT INTO reservation
                        (id, user_id, hotel_id, room_type_id, start_date, end_date, room_count, status, total_price, created_at, updated_at)
                    VALUES
                        (:id, :userId, :hotelId, :roomTypeId, :startDate, :endDate, :roomCount, :status, :totalPrice, CURRENT_TIMESTAMP(0), CURRENT_TIMESTAMP(0))
                    ON CONFLICT (id) DO NOTHING
                    SQL,
                [
                    'id' => $reservation->getId()->toRfc4122(),
                    'userId' => $reservation->getUserId(),
                    'hotelId' => $reservation->getHotelId(),
                    'roomTypeId' => $reservation->getRoomTypeId(),
                    'startDate' => $reservation->getStartDate()->format('Y-m-d'),
                    'endDate' => $reservation->getEndDate()->format('Y-m-d'),
                    'roomCount' => $reservation->getRoomCount(),
                    'status' => $reservation->getStatus()->value,
                    'totalPrice' => $reservation->getTotalPrice(),
                ],
            );

            if (1 === $inserted) {
                // Newly created — this is the only branch that should ever
                // touch total_reserved. A retry of an already-processed
                // reservationID hits 0-rows-affected above and skips this,
                // so total_reserved is incremented exactly once per
                // reservation no matter how many times the client retries.
                $updatedInventoryRows = $conn->executeStatement(
                    <<<'SQL'
                        UPDATE room_type_inventory
                        SET total_reserved = total_reserved + :roomCount, updated_at = now()
                        WHERE hotel_id = :hotelId
                          AND room_type_id = :roomTypeId
                          AND date >= :startDate
                          AND date < :endDate
                        SQL,
                    [
                        'roomCount' => $reservation->getRoomCount(),
                        'hotelId' => $reservation->getHotelId(),
                        'roomTypeId' => $reservation->getRoomTypeId(),
                        'startDate' => $reservation->getStartDate()->format('Y-m-d'),
                        'endDate' => $reservation->getEndDate()->format('Y-m-d'),
                    ],
                );
                if ($reservation->getStartDate()->diff($reservation->getEndDate())->days !== $updatedInventoryRows) {
                    throw InsufficientInventoryException::forRequest(
                        $reservation->getHotelId(),
                        $reservation->getRoomTypeId(),
                        $reservation->getStartDate(),
                        $reservation->getEndDate(),
                        $reservation->getRoomCount(),
                    );
                }
                // If any row in that date range would push total_reserved
                // past (total_inventory * 1.1), Postgres throws here and the
                // catch block below rolls back the INSERT too.
            }

            $conn->commit();
        } catch (DbalException $e) {
            $conn->rollBack();

            if (self::SQLSTATE_CHECK_VIOLATION === $e->getSQLState()) {
                throw InsufficientInventoryException::forRequest(
                    $reservation->getHotelId(),
                    $reservation->getRoomTypeId(),
                    $reservation->getStartDate(),
                    $reservation->getEndDate(),
                    $reservation->getRoomCount(),
                    previous: $e,
                );
            }

            throw $e;
        } catch (\Throwable $e) {
            $conn->rollBack();
            throw $e;
        }

        // Either we just created it, or a prior call with the same
        // reservationID already did — either way, return the row that's
        // actually in the database now rather than the in-memory object,
        // so a retry gets back exactly what the first call produced.
        $this->getEntityManager()->clear(Reservation::class);

        return $this->find($reservation->getId())
            ?? throw new \LogicException('Reservation should exist immediately after create().');
    }

    /**
     * Cancels a reservation and releases its held inventory in one
     * transaction. Idempotent: canceling an already-canceled reservation is
     * a no-op rather than double-releasing inventory.
     *
     * Uses SELECT ... FOR UPDATE rather than the constraint-only approach
     * used in create() — cancellation isn't the hot contention path that
     * booking is, so the simpler locked-read-then-write pattern is fine
     * here. See docs/IMPLEMENTATION_PLAN.md §3.
     *
     * @throws ReservationNotFoundException
     */
    public function cancel(Uuid $id): Reservation
    {
        $conn = $this->getEntityManager()->getConnection();
        $conn->beginTransaction();
        $row = null;

        try {
            $row = $conn->fetchAssociative(
                'SELECT * FROM reservation WHERE id = :id FOR UPDATE',
                ['id' => $id->toRfc4122()],
            );

            if (false === $row) {
                throw ReservationNotFoundException::forId($id);
            }

            if (ReservationStatus::Canceled->value !== $row['status']) {
                $conn->executeStatement(
                    'UPDATE reservation SET status = :status, updated_at = CURRENT_TIMESTAMP(0) WHERE id = :id',
                    ['status' => ReservationStatus::Canceled->value, 'id' => $id->toRfc4122()],
                );

                $updatedInventoryRows = $conn->executeStatement(
                    <<<'SQL'
                        UPDATE room_type_inventory
                        SET total_reserved = total_reserved - :roomCount, updated_at = now()
                        WHERE hotel_id = :hotelId
                          AND room_type_id = :roomTypeId
                          AND date >= :startDate
                          AND date < :endDate
                        SQL,
                    [
                        'roomCount' => (int) $row['room_count'],
                        'hotelId' => (int) $row['hotel_id'],
                        'roomTypeId' => (int) $row['room_type_id'],
                        'startDate' => $row['start_date'],
                        'endDate' => $row['end_date'],
                    ],
                );

                $expectedNights = (new \DateTimeImmutable((string) $row['start_date']))
                    ->diff(new \DateTimeImmutable((string) $row['end_date']))
                    ->days;

                if ($expectedNights !== $updatedInventoryRows) {
                    throw ReservationCancellationConflictException::forRequest(
                        (int) $row['hotel_id'],
                        (int) $row['room_type_id'],
                        new \DateTimeImmutable((string) $row['start_date']),
                        new \DateTimeImmutable((string) $row['end_date']),
                        (int) $row['room_count'],
                    );
                }
            }
            // else: already canceled — commit as a no-op, don't release
            // inventory twice.

            $conn->commit();
        } catch (DbalException $e) {
            $conn->rollBack();

            if (self::SQLSTATE_CHECK_VIOLATION === $e->getSQLState() && is_array($row)) {
                throw ReservationCancellationConflictException::forRequest(
                    (int) $row['hotel_id'],
                    (int) $row['room_type_id'],
                    new \DateTimeImmutable((string) $row['start_date']),
                    new \DateTimeImmutable((string) $row['end_date']),
                    (int) $row['room_count'],
                    previous: $e,
                );
            }

            throw $e;
        } catch (\Throwable $e) {
            $conn->rollBack();
            throw $e;
        }

        $this->getEntityManager()->clear(Reservation::class);

        return $this->find($id) ?? throw ReservationNotFoundException::forId($id);
    }

    /**
     * Settles payment for a reservation in a single transaction.
     *
     * Allowed transitions:
     * - pending -> paid      (approved=true)
     * - pending -> rejected  (approved=false)
     *
     * Idempotent when the reservation already has the same final status.
     *
     * @throws ReservationNotFoundException
     * @throws ReservationPaymentStateException when current status cannot
     *                                          transition to requested final status
     */
    public function settlePayment(Uuid $id, bool $approved): Reservation
    {
        $requestedStatus = $approved ? ReservationStatus::Paid : ReservationStatus::Rejected;
        $conn = $this->getEntityManager()->getConnection();
        $conn->beginTransaction();

        try {
            $row = $conn->fetchAssociative(
                'SELECT status FROM reservation WHERE id = :id FOR UPDATE',
                ['id' => $id->toRfc4122()],
            );

            if (false === $row) {
                throw ReservationNotFoundException::forId($id);
            }

            $currentStatus = ReservationStatus::from((string) $row['status']);

            if (ReservationStatus::Pending === $currentStatus) {
                $conn->executeStatement(
                    'UPDATE reservation SET status = :status, updated_at = CURRENT_TIMESTAMP(0) WHERE id = :id',
                    ['status' => $requestedStatus->value, 'id' => $id->toRfc4122()],
                );
            } elseif ($currentStatus !== $requestedStatus) {
                throw ReservationPaymentStateException::forStatus($id, $currentStatus, $requestedStatus);
            }

            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollBack();
            throw $e;
        }

        $this->getEntityManager()->clear(Reservation::class);

        return $this->find($id) ?? throw ReservationNotFoundException::forId($id);
    }
}
