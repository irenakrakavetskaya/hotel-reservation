<?php

declare(strict_types=1);

namespace App\Domain\User\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/** Request body for POST /v1/auth/register. */
final class RegisterRequest
{
    #[Assert\NotBlank]
    #[Assert\Email]
    #[Assert\Length(max: 180)]
    public string $email;

    #[Assert\NotBlank]
    #[Assert\Length(min: 8, max: 255)]
    public string $password;

    #[Assert\NotBlank]
    #[Assert\Length(min: 8, max: 255)]
    public string $passwordConfirmation;

    #[Assert\IsTrue(message: 'Passwords must match.')]
    public function isPasswordsMatch(): bool
    {
        return $this->password === $this->passwordConfirmation;
    }
}