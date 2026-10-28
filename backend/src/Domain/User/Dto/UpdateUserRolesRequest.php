<?php

declare(strict_types=1);

namespace App\Domain\User\Dto;

use App\Domain\User\Entity\User;
use Symfony\Component\Validator\Constraints as Assert;

final class UpdateUserRolesRequest
{
    /** @var list<string> */
    #[Assert\NotNull]
    #[Assert\Type('array')]
    #[Assert\All([new Assert\Choice(choices: User::ASSIGNABLE_ROLES)])]
    public array $roles = [];
}
