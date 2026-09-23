<?php

declare(strict_types=1);

namespace App\Domain\Payment\Dto;

use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Mock payment request for a reservation.
 *
 * `approved=true` simulates a successful payment authorization/capture and
 * transitions reservation status `pending -> paid`.
 * `approved=false` simulates a processor rejection and transitions
 * `pending -> rejected`.
 */
final class ProcessPaymentRequest
{
    #[Assert\NotBlank]
    #[Assert\Uuid(message: 'reservationID must be a valid UUID.')]
    #[SerializedName('reservationID')]
    public string $reservationId;

    #[Assert\NotNull]
    #[Assert\Type(type: 'bool', message: 'approved must be a boolean.')]
    public bool $approved;
}
