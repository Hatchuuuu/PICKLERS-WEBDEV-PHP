<?php
declare(strict_types=1);

namespace Picklers\Admin\Service;

use Doctrine\DBAL\Connection;
use Picklers\Admin\Http\AdminActionException;
use Picklers\Admin\Repository\AuditRepository;
use Picklers\Admin\Security\AdminUser;
use Picklers\Domain\Notifier;

/**
 * Facilities & courts administration.
 *
 * Counts, rates and statuses come from the `courts` rows themselves — never a
 * default (the old panel showed 4 courts / ★4.9 / ₱180 when data was missing).
 * Suspension and court maintenance block NEW bookings in every channel (see
 * Picklers\Domain\BookingRules::blockReason()); existing bookings are listed for explicit
 * handling and are never cancelled or refunded implicitly.
 */
final class FacilityAdminService
{
    public const STATUSES = ['active', 'suspended'];
    public const SORTS = ['name', 'courts', 'upcoming'];
    public const COURT_STATUSES = ['available', 'maintenance'];

    private const UPCOMING = "b.status IN ('pending','upcoming','confirmed') AND (b.booking_date IS NULL OR b.booking_date >= CURDATE())";

    public function __construct(
        private readonly Connection $db,
        private readonly Notifier $notifier,
        private readonly AuditLog $audit,
        private readonly AuditRepository $auditRepository,
    ) {
    }

    /** @return Page<array<string,mixed>> */
    public function search(ListQuery $query): Page
    {
        $where = ['1=1'];
        $params = [];
        if ($query->q !== '') {
            $where[] = '(f.name LIKE ? OR f.location LIKE ? OR u.name LIKE ? OR u.email LIKE ? OR CAST(f.id AS CHAR) = ?)';
            array_push($params, $query->likePattern(), $query->likePattern(), $query->likePattern(), $query->likePattern(), $query->q);
        }
        if (($status = $query->filter('status')) !== null) {
            $where[] = 'f.operating_status = ?';
            $params[] = $status;
        }
        if ($query->filter('maintenance') === '1') {
            $where[] = "EXISTS (SELECT 1 FROM courts cm WHERE cm.facility_id = f.id AND LOWER(cm.status) IN ('maintenance','unavailable'))";
        }
        $sql = implode(' AND ', $where);
        $total = (int)$this->db->fetchOne("SELECT COUNT(*) FROM facilities f LEFT JOIN users u ON u.id = f.owner_id WHERE {$sql}", $params);
        if ($total > 0 && $query->offset() >= $total) {
            $query = $query->withPage((int)ceil($total / $query->perPage));
        }
        $order = match ($query->sort) {
            'courts' => 'court_count',
            'upcoming' => 'upcoming_bookings',
            default => 'f.name',
        };
        $dir = $query->dir === 'desc' ? 'DESC' : 'ASC';
        $rows = $this->db->fetchAllAssociative(
            "SELECT f.id, f.name, f.location, f.image, f.rating, f.reviews, f.hours, f.is_verified, f.operating_status,
                    f.status_reason, f.status_changed_at, f.owner_id, u.name AS owner_name, u.email AS owner_email,
                    (SELECT COUNT(*) FROM courts c WHERE c.facility_id = f.id) AS court_count,
                    (SELECT COUNT(*) FROM courts c WHERE c.facility_id = f.id AND LOWER(c.status) IN ('maintenance','unavailable')) AS courts_down,
                    (SELECT MIN(c.price) FROM courts c WHERE c.facility_id = f.id) AS min_rate,
                    (SELECT MAX(c.price) FROM courts c WHERE c.facility_id = f.id) AS max_rate,
                    (SELECT COUNT(*) FROM bookings b WHERE b.facility_id = f.id AND " . self::UPCOMING . ") AS upcoming_bookings
               FROM facilities f LEFT JOIN users u ON u.id = f.owner_id
              WHERE {$sql} ORDER BY {$order} {$dir}, f.id ASC LIMIT {$query->perPage} OFFSET {$query->offset()}",
            $params
        );

        return new Page($rows, $total, $query);
    }

    /** @return array<string,mixed> */
    public function detail(int $id): array
    {
        $facility = $this->db->fetchAssociative(
            'SELECT f.*, u.name AS owner_name, u.email AS owner_email, u.phone AS owner_phone, u.role AS owner_role,
                    s.name AS status_changed_by_name
               FROM facilities f LEFT JOIN users u ON u.id = f.owner_id LEFT JOIN users s ON s.id = f.status_changed_by
              WHERE f.id = ?',
            [$id]
        );
        if ($facility === false) {
            throw AdminActionException::notFound('Facility');
        }
        $facility['courts'] = $this->db->fetchAllAssociative(
            "SELECT c.id, c.name, c.surface, c.type, c.price, LOWER(c.status) AS status, c.status_reason, c.status_changed_at,
                    (SELECT COUNT(*) FROM bookings b WHERE b.facility_id = c.facility_id AND (b.court_id = c.id OR b.court_name = c.name) AND " . self::UPCOMING . ") AS upcoming_bookings
               FROM courts c WHERE c.facility_id = ? ORDER BY c.name",
            [$id]
        );
        $facility['upcoming_bookings'] = $this->upcomingBookings($id, null, 50);
        $facility['upcoming_total'] = (int)$this->db->fetchOne('SELECT COUNT(*) FROM bookings b WHERE b.facility_id = ? AND ' . self::UPCOMING, [$id]);
        $facility['history'] = $this->auditRepository->historyFor('facility', (string)$id);

        return $facility;
    }

    /** @return list<array<string,mixed>> */
    public function upcomingBookings(int $facilityId, ?array $court, int $limit = 50): array
    {
        $params = [$facilityId];
        $courtSql = '';
        if ($court !== null) {
            $courtSql = ' AND (b.court_id = ? OR b.court_name = ?)';
            array_push($params, $court['id'], $court['name']);
        }

        return $this->db->fetchAllAssociative(
            "SELECT b.id, b.court_name, b.date, b.time, b.booking_date, b.status, b.price, b.payment_method, u.name AS player_name
               FROM bookings b LEFT JOIN users u ON u.id = b.user_id
              WHERE b.facility_id = ? {$courtSql} AND " . self::UPCOMING . "
              ORDER BY b.booking_date ASC, b.start_min ASC LIMIT " . max(1, min(200, $limit)),
            $params
        );
    }

    /** @param array{name?:string,location?:string,hours?:string} $input */
    public function updateDetails(AdminUser $actor, int $id, array $input, string $reason): array
    {
        $reason = $this->requireReason($reason);
        $changes = [];
        $name = isset($input['name']) ? trim($input['name']) : null;
        $location = isset($input['location']) ? trim($input['location']) : null;
        $hours = isset($input['hours']) ? trim($input['hours']) : null;
        if ($name !== null && (mb_strlen($name) < 3 || mb_strlen($name) > 150)) {
            throw AdminActionException::invalid('Facility name must be 3–150 characters.', 'name');
        }
        if ($location !== null && (mb_strlen($location) < 3 || mb_strlen($location) > 200)) {
            throw AdminActionException::invalid('Location must be 3–200 characters.', 'location');
        }
        if ($hours !== null && !preg_match('/^\d{1,2}(:\d{2})?\s?(AM|PM)\s?[-–]\s?\d{1,2}(:\d{2})?\s?(AM|PM)$/i', $hours)) {
            throw AdminActionException::invalid('Use opening hours like "6:00 AM - 10:00 PM".', 'hours');
        }

        return $this->db->transactional(function () use ($actor, $id, $name, $location, $hours, $reason, &$changes): array {
            $current = $this->db->fetchAssociative('SELECT id, name, location, hours FROM facilities WHERE id = ? FOR UPDATE', [$id]);
            if ($current === false) {
                throw AdminActionException::notFound('Facility');
            }
            $update = [];
            foreach (['name' => $name, 'location' => $location, 'hours' => $hours] as $field => $value) {
                if ($value !== null && $value !== (string)$current[$field]) {
                    $update[$field] = $value;
                    $changes[$field] = ['from' => $current[$field], 'to' => $value];
                }
            }
            if ($update === []) {
                throw AdminActionException::invalid('Nothing changed — edit at least one field.');
            }
            $this->db->update('facilities', $update, ['id' => $id]);
            $this->audit->record('facility.update', 'facility', (string)$id, $changes, $reason);
            $this->afterFacilityWrite();

            return ['changed' => array_keys($update)];
        });
    }

    /** Suspend / reactivate. Returns the bookings the operator must now handle explicitly. */
    public function setStatus(AdminUser $actor, int $id, string $status, string $reason): array
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw AdminActionException::invalid('Status must be active or suspended.', 'status');
        }
        $reason = $this->requireReason($reason);

        return $this->db->transactional(function () use ($actor, $id, $status, $reason): array {
            $facility = $this->db->fetchAssociative('SELECT id, name, owner_id, operating_status FROM facilities WHERE id = ? FOR UPDATE', [$id]);
            if ($facility === false) {
                throw AdminActionException::notFound('Facility');
            }
            if ($facility['operating_status'] === $status) {
                throw AdminActionException::conflict("This facility is already {$status}.");
            }
            $this->db->update('facilities', [
                'operating_status' => $status,
                'status_reason' => $reason,
                'status_changed_at' => date('Y-m-d H:i:s'),
                'status_changed_by' => $actor->id(),
            ], ['id' => $id]);
            $affected = $this->upcomingBookings($id, null, 200);
            if (!empty($facility['owner_id'])) {
                $this->notifier->notify(
                    (string)$facility['owner_id'],
                    $status === 'suspended' ? 'Facility temporarily suspended' : 'Facility reactivated',
                    $status === 'suspended'
                        ? "{$facility['name']} has been suspended from new bookings by Picklers. Reason: {$reason}. Existing bookings are unchanged; contact support for help."
                        : "{$facility['name']} is accepting new bookings again.",
                    'system'
                );
            }
            $this->audit->record($status === 'suspended' ? 'facility.suspend' : 'facility.reactivate', 'facility', (string)$id, [
                'operating_status' => ['from' => $facility['operating_status'], 'to' => $status],
                'upcoming_bookings_unchanged' => count($affected),
            ], $reason);
            $this->afterFacilityWrite();

            return ['facility' => $facility['name'], 'status' => $status, 'affected_bookings' => $affected];
        });
    }

    public function setCourtStatus(AdminUser $actor, string $courtId, string $status, string $reason): array
    {
        // 'occupied' is accepted for the legacy admin_update_court_status contract
        // but the console only offers available/maintenance.
        if (!in_array($status, ['available', 'maintenance', 'occupied'], true)) {
            throw AdminActionException::invalid('Invalid status. Allowed: available, maintenance.', 'status');
        }
        $reason = trim($reason);
        if ($status === 'maintenance') {
            $reason = $this->requireReason($reason);
        }

        return $this->db->transactional(function () use ($actor, $courtId, $status, $reason): array {
            $court = $this->db->fetchAssociative('SELECT c.*, f.name AS facility_name, f.owner_id FROM courts c LEFT JOIN facilities f ON f.id = c.facility_id WHERE c.id = ? FOR UPDATE', [$courtId]);
            if ($court === false) {
                throw AdminActionException::notFound('Court');
            }
            $from = strtolower((string)$court['status']);
            if ($from === $status) {
                throw AdminActionException::conflict("{$court['name']} is already {$status}.");
            }
            $this->db->update('courts', [
                'status' => $status,
                'status_reason' => $reason !== '' ? mb_substr($reason, 0, 255) : null,
                'status_changed_at' => date('Y-m-d H:i:s'),
                'status_changed_by' => $actor->id(),
            ], ['id' => $courtId]);
            $affected = $this->upcomingBookings((int)$court['facility_id'], $court, 200);
            if ($status === 'maintenance' && !empty($court['owner_id'])) {
                $this->notifier->notify((string)$court['owner_id'], 'Court placed under maintenance',
                    "{$court['name']} at {$court['facility_name']} was placed under maintenance by Picklers. Reason: {$reason}. Existing bookings are unchanged.", 'system');
            }
            $this->audit->record('court.status', 'court', $courtId, [
                'facility_id' => (int)$court['facility_id'],
                'status' => ['from' => $from, 'to' => $status],
                'upcoming_bookings_unchanged' => count($affected),
            ], $reason !== '' ? $reason : null);
            $this->afterFacilityWrite();

            return ['court' => $court['name'], 'facility' => $court['facility_name'], 'status' => $status, 'affected_bookings' => $affected];
        });
    }

    private function requireReason(string $reason): string
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw AdminActionException::invalid('Give a reason (at least 5 characters) — it is recorded and shown to the owner.', 'reason');
        }

        return mb_substr($reason, 0, 1000);
    }

    private function afterFacilityWrite(): void
    {
        $this->notifier->bumpSync('facilities', 'courts');
    }
}
