<?php

declare(strict_types=1);

namespace App\Domain\Reservation\Security;

use App\Domain\Reservation\Entity\Reservation;
use App\Domain\User\Security\AppUserInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Authorization for a single reservation: its own user may view/cancel it,
 * staff may view/cancel any. Used via #[IsGranted(...)] in
 * ReservationController — see `.claude/rules/backend-symfony.md`
 * ("staff-only endpoints use voters, not inline role checks").
 */
final class ReservationVoter extends Voter
{
    public const VIEW = 'RESERVATION_VIEW';
    public const CANCEL = 'RESERVATION_CANCEL';
    public const PAY = 'RESERVATION_PAY';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof Reservation
            && in_array($attribute, [self::VIEW, self::CANCEL, self::PAY], true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        /** @var Reservation $reservation */
        $reservation = $subject;
        $user = $token->getUser();

        if (null === $user) {
            return false;
        }

        if (in_array('ROLE_STAFF', $token->getRoleNames(), true) || in_array('ROLE_ADMIN', $token->getRoleNames(), true)) {
            return true;
        }

        return $user instanceof AppUserInterface && $user->getId() === $reservation->getUserId();
    }
}
