<?php

declare(strict_types=1);

namespace App\Domain\Hotel\Exception;

/** Controllers should map this to HTTP 404 (see DomainExceptionListener). */
final class HotelNotFoundException extends \RuntimeException
{
    public static function forId(int $id): self
    {
        return new self(sprintf('Hotel %d not found.', $id));
    }
}
