<?php
declare(strict_types=1);

namespace Picklers\Domain;

use Doctrine\DBAL\Connection;

/** Creates the facility (and its declared courts) for an approved owner application. */
final class FacilityProvisioner
{
    use SharedTransaction;

    public function __construct(
        private readonly Connection $db,
        private readonly Notifier $notifier,
    ) {
    }

    /**
     * Idempotent per owner + facility name. Joins the caller's transaction when
     * there is one (the admin approval commits the facility, the application
     * status and its audit record together); otherwise it is atomic on its own.
     */
    public function createFromApplication(array $app): int
    {
        $ownerId = (string)($app['user_id'] ?? '');
        $name = trim((string)($app['facility_name'] ?? ''));
        if ($ownerId === '' || $name === '') {
            throw new \InvalidArgumentException('Application is missing a user_id or facility_name.');
        }
        $existing = $this->db->fetchOne('SELECT id FROM facilities WHERE owner_id = ? AND name = ? LIMIT 1', [$ownerId, $name]);
        if ($existing !== false) {
            return (int)$existing;
        }

        $courtCount = max(1, (int)($app['courts_count'] ?? 1));
        $owns = $this->beginOwnTransaction();
        try {
            // No invented rating, review count or price: a brand-new venue has none yet.
            $this->db->insert('facilities', [
                'owner_id' => $ownerId,
                'name' => $name,
                'location' => (string)($app['address'] ?? ''),
                'rating' => 0.0,
                'reviews' => 0,
                'price' => '₱0',
                'price_numeric' => 0.00,
                'type' => 'Pickleball Venue',
                'hours' => (string)($app['operating_hours'] ?? '6:00 AM – 11:00 PM'),
                'transit' => '',
                'image' => trim((string)($app['facility_image'] ?? $app['logo'] ?? '')) ?: 'assets/images/facilities/acourts_dumaguete.jpg',
                'courts_count' => $courtCount,
                'is_verified' => 1,
            ]);
            $facilityId = (int)$this->db->lastInsertId();

            // Scaffold the declared courts so the portal is never an empty shell, at a
            // neutral default price the owner is expected to correct.
            for ($i = 1; $i <= $courtCount; $i++) {
                $this->db->insert('courts', ['id' => "crt_{$facilityId}_{$i}", 'facility_id' => $facilityId, 'name' => "Court {$i}", 'price' => 400.00, 'status' => 'available']);
            }
            if ($owns) {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            $this->rollBackOwn($owns);
            throw $e;
        }
        $this->notifier->bumpSync('facilities', 'courts');

        return $facilityId;
    }
}
