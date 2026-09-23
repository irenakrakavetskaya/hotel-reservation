<?php

declare(strict_types=1);

namespace App\Infrastructure\EventListener;

use App\Domain\Payment\Exception\ReservationPaymentStateException;
use App\Domain\Hotel\Exception\DuplicateRoomNumberException;
use App\Domain\Hotel\Exception\HotelNotFoundException;
use App\Domain\Hotel\Exception\RoomNotFoundException;
use App\Domain\Hotel\Exception\RoomTypeNotFoundException;
use App\Domain\Hotel\Exception\RoomTypeResourceNotFoundException;
use App\Domain\Reservation\Exception\ReservationCancellationConflictException;
use App\Domain\Reservation\Exception\InsufficientInventoryException;
use App\Domain\Reservation\Exception\ReservationNotFoundException;
use App\Domain\User\Exception\UserAlreadyExistsException;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Maps domain exceptions to HTTP status codes in one place, per
 * `.claude/rules/backend-symfony.md` step 7 ("map domain exceptions to HTTP
 * status codes explicitly ... via an exception listener"). Add a new
 * `match` arm here rather than catching-and-converting inside individual
 * controllers as more domains get their own exceptions.
 */
#[AsEventListener(event: KernelEvents::EXCEPTION)]
final class DomainExceptionListener
{
    public function __invoke(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();

        $status = match (true) {
            $exception instanceof InsufficientInventoryException => Response::HTTP_CONFLICT,
            $exception instanceof ReservationCancellationConflictException => Response::HTTP_CONFLICT,
            $exception instanceof ReservationNotFoundException => Response::HTTP_NOT_FOUND,
            $exception instanceof ReservationPaymentStateException => Response::HTTP_CONFLICT,
            $exception instanceof HotelNotFoundException => Response::HTTP_NOT_FOUND,
            $exception instanceof RoomNotFoundException => Response::HTTP_NOT_FOUND,
            $exception instanceof RoomTypeResourceNotFoundException => Response::HTTP_NOT_FOUND,
            $exception instanceof RoomTypeNotFoundException => Response::HTTP_UNPROCESSABLE_ENTITY,
            $exception instanceof DuplicateRoomNumberException => Response::HTTP_CONFLICT,
            $exception instanceof UserAlreadyExistsException => Response::HTTP_CONFLICT,
            default => null,
        };

        if (null === $status) {
            return;
        }

        $event->setResponse(new JsonResponse(
            ['error' => $exception->getMessage()],
            $status,
        ));
    }
}
