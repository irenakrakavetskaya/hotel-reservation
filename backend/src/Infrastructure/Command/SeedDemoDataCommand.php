<?php

declare(strict_types=1);

namespace App\Infrastructure\Command;

use App\Domain\Hotel\Entity\Hotel;
use App\Domain\Hotel\Entity\Room;
use App\Domain\Hotel\Entity\RoomType;
use App\Domain\Hotel\Repository\HotelRepository;
use App\Domain\Hotel\Repository\RoomRepository;
use App\Domain\Hotel\Repository\RoomTypeRepository;
use App\Domain\Payment\Service\PaymentService;
use App\Domain\Rate\Service\RateRecomputeJob;
use App\Domain\Reservation\Dto\CreateReservationRequest;
use App\Domain\Reservation\Service\ReservationService;
use App\Domain\Reservation\Service\InventoryPrepopulationJob;
use App\Domain\User\Entity\User;
use App\Domain\User\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

#[AsCommand(
    name: 'app:demo:seed',
    description: 'Seeds deterministic demo users, hotels, rooms, inventory, rates, and reservations.',
)]
final class SeedDemoDataCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $userRepository,
        private readonly HotelRepository $hotelRepository,
        private readonly RoomTypeRepository $roomTypeRepository,
        private readonly RoomRepository $roomRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly InventoryPrepopulationJob $inventoryJob,
        private readonly RateRecomputeJob $rateJob,
        private readonly ReservationService $reservationService,
        private readonly PaymentService $paymentService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $demoUser = $this->seedUser('demo@example.com', 'demo-password', []);
        $this->seedUser('staff@example.com', 'staff-password', ['ROLE_STAFF']);

        $northline = $this->seedHotel(
            'Northline House',
            '14 Cedar Avenue',
            'Portland',
            'United States',
            4,
            'A calm city hotel with warm timber, generous light, and an easy walk to the river.',
            ['Wi-Fi', 'Breakfast', 'Fitness room', '24-hour reception'],
        );
        $morrowBay = $this->seedHotel(
            'Morrow Bay Hotel',
            '8 Harbor Street',
            'Brighton',
            'United Kingdom',
            5,
            'A sea-facing stay shaped around slow mornings, local food, and open skies.',
            ['Wi-Fi', 'Sea view', 'Restaurant', 'Room service'],
        );

        $cedarKing = $this->seedRoomType($northline, 'Cedar King', 2, 18900, 6, 'A quiet king room with a reading chair and city-facing windows.', ['King bed', 'Rain shower', 'Desk']);
        $northlineSuite = $this->seedRoomType($northline, 'Northline Suite', 3, 27900, 2, 'A generous suite with a separate sitting area for longer stays.', ['King bed', 'Sofa bed', 'Living area']);
        $harborDouble = $this->seedRoomType($morrowBay, 'Harbor Double', 2, 22500, 8, 'A bright double room with a glimpse of the water and room to unwind.', ['Two beds', 'Sea glimpse', 'Tea service']);
        $this->seedRoomType($morrowBay, 'Morrow Bay Suite', 4, 36500, 3, 'The signature suite with a lounge, soaking tub, and broad bay views.', ['King bed', 'Lounge', 'Soaking tub']);

        $this->entityManager->flush();
        $this->inventoryJob->backfillWindow();
        $this->rateJob->run();

        $this->seedReservations($demoUser, [
            ['00000000-0000-7000-8000-000000000001', $cedarKing, 10, 2, 1, 'paid'],
            ['00000000-0000-7000-8000-000000000002', $northlineSuite, 20, 3, 1, 'pending'],
            ['00000000-0000-7000-8000-000000000003', $harborDouble, 15, 2, 2, 'rejected'],
            ['00000000-0000-7000-8000-000000000004', $harborDouble, 30, 4, 1, 'canceled'],
        ]);

        $io->success([
            'Demo data is ready.',
            'Customer login: demo@example.com / demo-password',
            'Staff login: staff@example.com / staff-password',
            'Reservations (paid, pending, rejected, canceled) were created for the demo customer.',
            'The command is idempotent and does not remove existing records.',
        ]);

        return Command::SUCCESS;
    }

    /** @param list<string> $roles */
    private function seedUser(string $email, string $plainPassword, array $roles): User
    {
        $existing = $this->userRepository->findOneBy(['email' => $email]);
        if ($existing instanceof User) {
            return $existing;
        }

        $user = new User($email, 'temporary-password', $roles);
        $passwordHash = $this->passwordHasher->hashPassword($user, $plainPassword);
        $user = new User($email, $passwordHash, $roles);
        $this->entityManager->persist($user);

        return $user;
    }

    /** @param list<string> $amenities */
    private function seedHotel(
        string $name,
        string $address,
        string $city,
        string $country,
        int $starRating,
        string $description,
        array $amenities,
    ): Hotel {
        $hotel = $this->hotelRepository->findOneBy(['name' => $name]);
        if ($hotel instanceof Hotel) {
            return $hotel;
        }

        $hotel = new Hotel($name, $address, $city, $country, $starRating, $description, $amenities);
        $this->entityManager->persist($hotel);

        return $hotel;
    }

    /** @param list<string> $amenities */
    private function seedRoomType(
        Hotel $hotel,
        string $name,
        int $maxOccupancy,
        int $basePrice,
        int $roomCount,
        string $description,
        array $amenities,
    ): RoomType {
        $roomType = $this->roomTypeRepository->findOneBy(['hotel' => $hotel, 'name' => $name]);
        if (!$roomType instanceof RoomType) {
            $roomType = new RoomType($hotel, $name, $maxOccupancy, $basePrice, $description, $amenities);
            $this->entityManager->persist($roomType);
        }

        for ($roomNumber = 1; $roomNumber <= $roomCount; ++$roomNumber) {
            $number = sprintf('%s-%02d', strtoupper(substr($name, 0, 2)), $roomNumber);
            if (null === $this->roomRepository->findOneBy(['hotel' => $hotel, 'roomNumber' => $number])) {
                $this->entityManager->persist(new Room($hotel, $roomType, $number, $roomNumber));
            }
        }

        return $roomType;
    }

    /**
     * Goes through ReservationService/PaymentService so inventory counters and
     * the check_room_count constraint stay consistent. Fixed UUIDs make reruns no-ops.
     *
     * @param list<array{string, RoomType, int, int, int, string}> $reservations [id, roomType, daysAhead, nights, roomCount, finalStatus]
     */
    private function seedReservations(User $user, array $reservations): void
    {
        $today = new \DateTimeImmutable('today');

        foreach ($reservations as [$id, $roomType, $daysAhead, $nights, $roomCount, $finalStatus]) {
            $reservationId = Uuid::fromString($id);
            if (null !== $this->reservationService->getReservation($reservationId)) {
                continue;
            }

            $request = new CreateReservationRequest();
            $request->reservationId = $id;
            $request->hotelId = (int) $roomType->getHotel()->getId();
            $request->roomTypeId = (int) $roomType->getId();
            $request->startDate = $today->modify(sprintf('+%d days', $daysAhead))->format('Y-m-d');
            $request->endDate = $today->modify(sprintf('+%d days', $daysAhead + $nights))->format('Y-m-d');
            $request->roomCount = $roomCount;

            $this->reservationService->createReservation($request, $user->getId());

            match ($finalStatus) {
                'paid' => $this->paymentService->processReservationPayment($reservationId, true),
                'rejected' => $this->paymentService->processReservationPayment($reservationId, false),
                'canceled' => $this->reservationService->cancelReservation($reservationId),
                default => null,
            };
        }
    }
}
