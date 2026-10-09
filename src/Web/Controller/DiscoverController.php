<?php
declare(strict_types=1);

namespace Picklers\Web\Controller;

use Picklers\Web\Service\DiscoverService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Section 1 — Discover. Public reads plus the signed-in favourite toggle.
 * The legacy names (/api?action=facilities, facility_detail, slot_availability,
 * court_availability, toggle_favorite_facility) reach these same methods through
 * the /api compatibility controller.
 */
#[Route('/api/facilities')]
final class DiscoverController extends AbstractWebController
{
    public function __construct(private readonly DiscoverService $discover)
    {
    }

    #[Route('', name: 'web_facilities', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $facilities = $this->discover->list(
            (string)$request->query->get('search', ''),
            (string)$request->query->get('type', 'All'),
            (string)$request->query->get('sort', 'recommended'),
            $this->sessionUser()?->id(),
        );

        return $this->legacyJson(['success' => true, 'facilities' => $facilities]);
    }

    #[Route('/{id}', name: 'web_facility', methods: ['GET'])]
    public function detail(string $id): JsonResponse
    {
        $detail = $this->discover->detail(DiscoverService::facilityId($id), $this->sessionUser()?->id());

        return $detail === null
            ? $this->legacyError('Facility not found', 404)
            : $this->legacyJson(['success' => true] + $detail);
    }

    #[Route('/{id}/courts/{courtId}/slots', name: 'web_facility_slots', methods: ['GET'])]
    public function slots(string $id, string $courtId, Request $request): JsonResponse
    {
        if (trim($id) === '' || trim($courtId) === '') {
            return $this->legacyError('facility_id and court_id are required.', 400);
        }

        return $this->legacyJson(['success' => true] + $this->discover->slots(
            DiscoverService::facilityId($id),
            trim($courtId),
            (string)$request->query->get('date', ''),
        ));
    }

    #[Route('/{id}/favorite', name: 'web_facility_favorite', methods: ['POST'])]
    public function favorite(string $id, Request $request): JsonResponse
    {
        $user = $this->guardMutation($request, 'toggle_favorite_facility', 'Please log in to save favorites');
        if ($id === '') {
            return $this->legacyError('Missing facility ID', 400);
        }

        return $this->legacyJson($this->discover->toggleFavorite($user->id(), $id));
    }
}
