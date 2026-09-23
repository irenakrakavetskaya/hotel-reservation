<?php

declare(strict_types=1);

namespace App\Domain\Reservation\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Query string contract for the customer-facing availability/rate endpoint.
 *
 * Stay range is half-open: [startDate, endDate).
 */
final class AvailabilityQueryRequest
{
    #[Assert\NotBlank]
    #[Assert\Date]
    public string $startDate;

    #[Assert\NotBlank]
    #[Assert\Date]
    public string $endDate;

    #[Assert\NotBlank]
    #[Assert\Regex(pattern: '/^[1-9]\d*$/', message: 'roomCount must be a positive integer.')]
    public string $roomCount = '1';

    public function getStartDate(): \DateTimeImmutable
    {
        return new \DateTimeImmutable($this->startDate);
    }

    public function getEndDate(): \DateTimeImmutable
    {
        return new \DateTimeImmutable($this->endDate);
    }

    public function getRoomCount(): int
    {
        return (int) $this->roomCount;
    }

    #[Assert\IsTrue(message: 'endDate must be after startDate.')]
    public function isDateRangeValid(): bool
    {
        return $this->getEndDate() > $this->getStartDate();
    }
}
