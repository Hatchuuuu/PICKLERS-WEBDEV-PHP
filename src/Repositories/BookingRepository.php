<?php
declare(strict_types=1);

namespace Picklers\Repositories;

use Picklers\Core\Database;

class BookingRepository {
    private Database $db;

    public function __construct(?Database $db = null) {
        $this->db = $db ?? Database::get();
    }

    public function getBookings(?string $userId = null, ?string $status = null): array {
        return $this->db->getBookings($userId, $status);
    }

    public function getSlotAvailability(int|string $facilityId, string $courtId, string $date): array {
        return $this->db->getSlotAvailability($facilityId, $courtId, $date);
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
        return $this->db->createBooking(
            $userId, $facilityId, $courtName, $date, $time, $duration, $price, $paymentMethod,
            $courtId, $promoCode, $promoDiscount
        );
    }

    public function cancelBooking(string $bookingId, string $userId): array {
        $res = $this->db->cancelBooking($bookingId, $userId);
        if (is_array($res)) {
            return $res;
        }
        return ['success' => (bool)$res, 'message' => $res ? 'Booking cancelled' : 'Could not cancel booking'];
    }

    public function updateBookingStatus(string $bookingId, string $status): bool {
        return $this->db->updateBookingStatus($bookingId, $status);
    }
}
