<?php
declare(strict_types=1);

namespace Picklers\Models;

use Picklers\Services\BookingService;

class Booking {
    private BookingService $bookingService;

    public function __construct(?BookingService $bookingService = null) {
        $this->bookingService = $bookingService ?? new BookingService();
    }

    public function getForUser(string $userId, ?string $status = null): array {
        return $this->bookingService->getBookings($userId, $status);
    }

    public function all(?string $status = null): array {
        return $this->bookingService->getBookings(null, $status);
    }

    public function create(
        string $userId,
        int $facilityId,
        string $courtName,
        string $date,
        string $time,
        int $duration,
        float $price,
        string $paymentMethod
    ): array {
        return $this->bookingService->createBooking(
            $userId,
            $facilityId,
            $courtName,
            $date,
            $time,
            $duration,
            $price,
            $paymentMethod
        );
    }

    public function cancel(string $bookingId, string $userId): bool {
        return $this->bookingService->cancelBooking($bookingId, $userId);
    }
}
