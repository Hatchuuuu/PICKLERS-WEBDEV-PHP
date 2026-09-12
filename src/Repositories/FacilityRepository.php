<?php
declare(strict_types=1);

namespace Picklers\Repositories;

use Picklers\Core\Database;

class FacilityRepository {
    private Database $db;

    public function __construct(?Database $db = null) {
        $this->db = $db ?? Database::get();
    }

    public function getFacilities(string $search = '', string $type = 'All', string $sort = 'recommended'): array {
        return $this->db->getFacilities($search, $type, $sort);
    }

    public function findById(int|string $id): ?array {
        return $this->db->getFacility($id);
    }

    public function updateFacility(int|string $facilityId, array $fields): bool {
        return $this->db->updateFacility($facilityId, $fields);
    }

    public function getCourtsByFacility(int|string $facilityId): array {
        return $this->db->getCourtsByFacility($facilityId);
    }

    public function getCourtsWithAttributes(int|string $facilityId): array {
        return $this->db->getCourtsWithAttributes($facilityId);
    }

    public function getAllCourts(): array {
        return $this->db->getAllCourts();
    }

    public function updateCourtStatus(int|string $courtId, string $status): bool {
        return $this->db->updateCourtStatus($courtId, $status);
    }

    public function getCourtImagesByFacility(int|string $facilityId): array {
        return $this->db->getCourtImagesByFacility($facilityId);
    }

    public function getFacilityAmenities(int|string $facilityId): array {
        return $this->db->getFacilityAmenities($facilityId);
    }
}
