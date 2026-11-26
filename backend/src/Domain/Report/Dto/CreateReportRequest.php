<?php

declare(strict_types=1);

namespace App\Domain\Report\Dto;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

final class CreateReportRequest
{
    #[Assert\NotBlank]
    #[Assert\Date]
    public string $from = '';

    #[Assert\NotBlank]
    #[Assert\Date]
    public string $to = '';

    #[Assert\Callback]
    public function validatePeriod(ExecutionContextInterface $context): void
    {
        $utc = new \DateTimeZone('UTC');
        $from = \DateTimeImmutable::createFromFormat('!Y-m-d', $this->from, $utc);
        $to = \DateTimeImmutable::createFromFormat('!Y-m-d', $this->to, $utc);

        if (false === $from || false === $to || $from->format('Y-m-d') !== $this->from || $to->format('Y-m-d') !== $this->to) {
            return;
        }

        if ($to < $from) {
            $context->buildViolation('to must be on or after from.')
                ->atPath('to')
                ->addViolation();

            return;
        }

        if ($to->modify('+1 day') > $from->modify('+1 year')) {
            $context->buildViolation('The report period cannot exceed one year.')
                ->atPath('to')
                ->addViolation();
        }

        $today = new \DateTimeImmutable('today', $utc);
        if ($to > $today) {
            $context->buildViolation('to cannot be in the future.')
                ->atPath('to')
                ->addViolation();
        }
    }

    public function fromDate(): \DateTimeImmutable
    {
        return new \DateTimeImmutable($this->from, new \DateTimeZone('UTC'));
    }

    public function toDate(): \DateTimeImmutable
    {
        return new \DateTimeImmutable($this->to, new \DateTimeZone('UTC'));
    }
}
