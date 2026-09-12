<?php
declare(strict_types=1);

namespace Picklers\Models;

use Picklers\Services\FacilityService;

class Facility {
    private FacilityService $facilityService;

    public function __construct(?FacilityService $facilityService = null) {
        $this->facilityService = $facilityService ?? new FacilityService();
    }

    public function all(string $search = '', string $type = 'All', string $sort = 'recommended'): array {
        return $this->facilityService->getFacilities($search, $type, $sort);
    }

    public function find(int|string $id): ?array {
        return $this->facilityService->getFacility($id);
    }

    public function getCourts(int|string $facilityId): array {
        return $this->facilityService->getCourtsByFacility($facilityId);
    }

    public function getAllCourts(): array {
        return $this->facilityService->getAllCourts();
    }

    public function updateCourtStatus(int|string $courtId, string $status): bool {
        return $this->facilityService->updateCourtStatus($courtId, $status);
    }
}
