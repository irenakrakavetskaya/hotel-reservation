<?php

declare(strict_types=1);

namespace App\Domain\User\Security;

/**
 * The `user` table/entity isn't part of this data model slice yet (see
 * migrations/Version20260101000006.php's note on `reservation.user_id`).
 * Voters and services that need the current user's numeric ID depend on
 * this interface rather than a concrete User class, so that landing the
 * real auth entity later is a one-line `implements` change, not a rewrite
 * of every consumer.
 */
interface AppUserInterface
{
    public function getId(): int;
}
