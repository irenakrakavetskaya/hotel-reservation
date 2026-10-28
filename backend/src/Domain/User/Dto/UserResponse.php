<?php

declare(strict_types=1);

namespace App\Domain\User\Dto;

use App\Domain\User\Entity\User;

final readonly class UserResponse
{
    /** @param list<string> $roles */
    private function __construct(
        public int $id,
        public string $email,
        public array $roles,
    ) {}

    public static function fromEntity(User $user): self
    {
        return new self($user->getId(), $user->getUserIdentifier(), $user->getRoles());
    }
}
