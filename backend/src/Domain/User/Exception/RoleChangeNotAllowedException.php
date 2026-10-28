<?php

declare(strict_types=1);

namespace App\Domain\User\Exception;

final class RoleChangeNotAllowedException extends \RuntimeException
{
    public static function cannotRemoveOwnAdmin(): self
    {
        return new self('You cannot remove your own admin role.');
    }

    public static function lastAdmin(): self
    {
        return new self('The last remaining admin cannot lose the admin role.');
    }
}
