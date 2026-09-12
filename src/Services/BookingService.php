<?php
declare(strict_types=1);

namespace Picklers\Services;

use Picklers\Repositories\BookingRepository;

class BookingService {
    private BookingRepository $bookingRepo;

    public function __construct(?BookingRepository $bookingRepo = null) {
        $this->bookingRepo = $bookingRepo ?? new BookingRepository();
    }

    public function getBookings(?string $userId = null, ?string $status = null): array {
        return $this->bookingRepo->getBookings($userId, $status);
    }

    public function getSlotAvailability(int|string $facilityId, string $courtId, string $date): array {
        return $this->bookingRepo->getSlotAvailability($facilityId, $courtId, $date);
    }

    public function createBooking(
        string $userId,
        int|string $facilityId,
        string $courtName,
        string $date,
        string $time,
        int $duration,
        float $price,
        string $paymentMethod,
        string $courtId = '',
        ?string $promoCode = null,
        float $promoDiscount = 0.0
    ): array {
        return $this->bookingRepo->createBooking(
            $userId, $facilityId, $courtName, $date, $time, $duration, $price, $paymentMethod,
            $courtId, $promoCode, $promoDiscount
        );
    }

    public function cancelBooking(string $bookingId, string $userId): array {
        return $this->bookingRepo->cancelBooking($bookingId, $userId);
    }

    public function updateBookingStatus(string $bookingId, string $status): bool {
        return $this->bookingRepo->updateBookingStatus($bookingId, $status);
    }
}
