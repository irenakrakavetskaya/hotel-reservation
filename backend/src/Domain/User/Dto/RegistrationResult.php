<?php

declare(strict_types=1);

namespace App\Domain\User\Dto;

use App\Domain\User\Entity\User;

final class RegistrationResult
{
    public function __construct(
        public readonly User $user,
        public readonly string $token,
    ) {
    }
}