<?php
declare(strict_types=1);

namespace Picklers\Services;

use Picklers\Repositories\FacilityRepository;

class FacilityService {
    private FacilityRepository $facilityRepo;

    public function __construct(?FacilityRepository $facilityRepo = null) {
        $this->facilityRepo = $facilityRepo ?? new FacilityRepository();
    }

    public function getFacilities(string $search = '', string $type = 'All', string $sort = 'recommended'): array {
        return $this->facilityRepo->getFacilities($search, $type, $sort);
    }

    public function getFacility(int|string $id): ?array {
        return $this->facilityRepo->findById($id);
    }

    public function updateFacility(int|string $facilityId, array $fields): bool {
        return $this->facilityRepo->updateFacility($facilityId, $fields);
    }

    public function getCourtsByFacility(int|string $facilityId): array {
        return $this->facilityRepo->getCourtsByFacility($facilityId);
    }

    public function getCourtsWithAttributes(int|string $facilityId): array {
        return $this->facilityRepo->getCourtsWithAttributes($facilityId);
    }

    public function getAllCourts(): array {
        return $this->facilityRepo->getAllCourts();
    }

    public function updateCourtStatus(int|string $courtId, string $status): bool {
        return $this->facilityRepo->updateCourtStatus($courtId, $status);
    }

    public function getCourtImagesByFacility(int|string $facilityId): array {
        return $this->facilityRepo->getCourtImagesByFacility($facilityId);
    }

    public function getFacilityAmenities(int|string $facilityId): array {
        return $this->facilityRepo->getFacilityAmenities($facilityId);
    }
}
