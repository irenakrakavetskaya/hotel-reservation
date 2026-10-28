<?php

declare(strict_types=1);

namespace App\Domain\User\Exception;

final class UserNotFoundException extends \RuntimeException
{
    public static function forId(int $id): self
    {
        return new self(sprintf('User %d was not found.', $id));
    }
}
