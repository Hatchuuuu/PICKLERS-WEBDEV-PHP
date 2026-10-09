<?php
declare(strict_types=1);

namespace Picklers\Web\Controller;

use Picklers\Core\Database;
use Picklers\Services\TournamentService;
use Picklers\Web\Http\LegacyInput;
use Picklers\Web\Service\OwnerPortalView;
use Picklers\Web\Service\OwnerScope;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Owner portal pages, the tournament bracket console and the owner application. */
final class OwnerPagesController extends AbstractWebController
{
    public const DOCUMENT_MIME_TYPES = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];
    public const DOCUMENT_MAX_BYTES = 5 * 1024 * 1024;
    private const DOCUMENT_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png', 'webp'];

    public function __construct(
        private readonly Database $db,
        private readonly OwnerScope $scope,
        private readonly OwnerPortalView $portal,
        private readonly TournamentService $tournaments,
        #[Autowire('%picklers.document_dir%')] private readonly string $documentDir,
    ) {
    }

    #[Route('/owner', name: 'owner', methods: ['GET'])]
    #[Route('/owner.php', name: 'owner_php', methods: ['GET'])]
    #[Route('/app/owner', name: 'owner_app', methods: ['GET'])]
    public function portal(Request $request): Response
    {
        if (($deny = $this->denyUnlessPortalUser($request)) !== null) {
            return $deny;
        }
        $user = $this->sessionUser()->row();
        $facilities = $this->scope->portalFacilities($user);
        if ($facilities === []) {
            return $this->render('web/owner-onboarding.html.twig', [
                'currentUser' => $user,
                'application' => $this->db->getLatestApplicationForUser((string)$user['id']),
                'csrfToken' => $this->csrfToken(),
            ]);
        }

        return $this->render('web/owner.html.twig', $this->portal->build(
            $user,
            $facilities,
            (string)$request->query->get('facility_id', ''),
            (string)$request->query->get('tab', 'dashboard'),
        ) + ['csrfToken' => $this->csrfToken()]);
    }

    /**
     * The bracket console for one tournament. Someone else's tournament gets the
     * same 404 as one that does not exist, so the URL never confirms other ids.
     */
    #[Route('/owner/tournaments/{id}', name: 'owner_tournament', methods: ['GET'])]
    #[Route('/app/owner/tournaments/{id}', name: 'owner_tournament_app', methods: ['GET'])]
    public function tournament(Request $request, string $id): Response
    {
        if (($deny = $this->denyUnlessPortalUser($request)) !== null) {
            return $deny;
        }
        $user = $this->sessionUser()->row();
        $facilities = $this->scope->portalFacilities($user);
        $tournament = $this->tournaments->find($id);
        $facility = $facilities[0] ?? null;
        $owned = false;
        if ($tournament !== null) {
            foreach ($facilities as $f) {
                if ((string)$f['id'] === (string)$tournament['facility_id']) {
                    $owned = true;
                    $facility = $f;
                    break;
                }
            }
            $owned = $owned || !empty($user['is_admin']);
        }
        $visible = $tournament !== null && $owned;

        return $this->render('web/owner-tournament.html.twig', [
            'tournament' => $visible ? $tournament : null,
            'currentFacility' => $facility,
            'currentUser' => $user,
            'categories' => TournamentService::CATEGORIES,
            'csrfToken' => $this->csrfToken(),
        ], new Response('', $visible ? 200 : 404));
    }

    #[Route('/owner-application', name: 'owner_application', methods: ['GET'])]
    #[Route('/owner-application.php', name: 'owner_application_php', methods: ['GET'])]
    #[Route('/app/owner-application', name: 'owner_application_app', methods: ['GET'])]
    public function application(Request $request): Response
    {
        $user = $this->sessionUser()?->row();
        if ($user === null) {
            return $this->redirectToPath($request, 'auth.php');
        }
        $latest = $this->db->getLatestApplicationForUser((string)$user['id']);

        return $this->render('web/owner-application.html.twig', [
            'currentUser' => $user,
            'notice' => (string)$request->query->get('notice', ''),
            'latestApp' => $latest,
            'hasPendingApp' => $latest && in_array($latest['status'] ?? '', ['pending_review', 'pending'], true)
                && empty($user['is_owner']) && ($user['role'] ?? '') !== 'owner',
            'csrfToken' => $this->csrfToken(),
        ]);
    }

    /**
     * File an owner application. An application is an identity and licensing check
     * for a marketplace that moves real money: incomplete submissions are refused,
     * never completed with placeholders, and the account is NOT made an owner here.
     */
    #[Route('/owner-application', name: 'owner_application_post', methods: ['POST'])]
    #[Route('/owner-application.php', name: 'owner_application_post_php', methods: ['POST'])]
    #[Route('/app/owner-application', name: 'owner_application_post_app', methods: ['POST'])]
    public function submitApplication(Request $request): Response
    {
        $in = new LegacyInput($request);
        $ajax = $in->wantsJson();
        $fail = fn(string $message, int $status, string $redirect) => $ajax
            ? $this->legacyError($message, $status)
            : $this->redirectToPath($request, $redirect);

        if (!$this->validCsrf($request)) {
            return $fail('Security token invalid or expired. Please refresh the page.', 403, 'owner-application.php?notice=csrf_error');
        }
        $user = $this->sessionUser()?->row();
        if ($user === null) {
            return $this->redirectToPath($request, 'auth.php');
        }
        // One application in the review queue at a time.
        foreach ($this->db->getOwnerApplications('pending_review') as $existing) {
            if ((string)($existing['user_id'] ?? '') === (string)$user['id']) {
                return $ajax
                    ? $this->legacyJson(['success' => false, 'message' => 'You already have an application under review. We will notify you once it has been assessed.', 'redirect_url' => 'app.php?tab=settings&notice=application_pending'], 409)
                    : $this->redirectToPath($request, 'app.php?tab=settings&notice=application_pending');
            }
        }

        $field = static fn(string $key, string $default = '') => trim((string)$in->input($key, $default));
        $data = [
            'facility_name' => $field('facility_name'),
            'address' => $field('address'),
            'owner_name' => $field('owner_name'),
            'business_email' => $field('business_email'),
            'phone' => $field('phone'),
            'entity_name' => $field('entity_name'),
            'reg_number' => $field('reg_number'),
            'court_surface' => $field('court_surface', 'Indoor Hard'),
            'operating_hours' => $field('operating_hours', '06:00 AM – 11:00 PM'),
        ];
        $missing = array_keys(array_filter([
            'Facility Brand Name' => $data['facility_name'] === '',
            'Street Address' => $data['address'] === '',
            'Owner Full Name' => $data['owner_name'] === '',
            'a valid Business Email' => $data['business_email'] === '' || !filter_var($data['business_email'], FILTER_VALIDATE_EMAIL),
            'Mobile Number' => $data['phone'] === '',
            'Registered Legal Trade Name' => $data['entity_name'] === '',
            'DTI / SEC Registration Number' => $data['reg_number'] === '',
        ]));
        if ($missing !== []) {
            return $fail('Please complete: ' . implode(', ', $missing) . '.', 400, 'owner-application.php?notice=incomplete');
        }
        // Column limits are hard SQL errors under strict mode.
        $limits = [
            'facility_name' => ['Facility Brand Name', 150], 'address' => ['Street Address', 500], 'owner_name' => ['Owner Full Name', 120],
            'business_email' => ['Business Email', 120], 'phone' => ['Mobile Number', 50], 'entity_name' => ['Registered Legal Trade Name', 150],
            'reg_number' => ['DTI / SEC Registration Number', 100], 'court_surface' => ['Court Surface', 60], 'operating_hours' => ['Operating Hours', 100],
        ];
        $tooLong = [];
        foreach ($limits as $key => [$label, $max]) {
            if (mb_strlen($data[$key]) > $max) {
                $tooLong[] = "{$label} (max {$max} characters)";
            }
        }
        if ($tooLong !== []) {
            return $fail('Please shorten: ' . implode(', ', $tooLong) . '.', 400, 'owner-application.php?notice=incomplete');
        }

        // Permits and government IDs are private: stored outside the web root and
        // served only to administrators (Admin\Controller\DocumentController).
        $permit = $this->storeDocument($request->files->get('permit_file'));
        $govId = $this->storeDocument($request->files->get('gov_id_file'));
        if ($permit === null || $govId === null) {
            foreach ([$permit, $govId] as $stored) {
                if ($stored !== null) {
                    @unlink($this->documentDir . '/' . $stored);
                }
            }
            $docs = array_keys(array_filter(["Mayor's Permit / Business License" => $permit === null, 'a valid Government ID' => $govId === null]));

            return $fail('Please upload ' . implode(' and ', $docs) . ' (PDF, PNG, or JPG, max 5MB).', 400, 'owner-application.php?notice=incomplete');
        }

        // No invented coordinates: a missing GPS fix is stored as unknown.
        $lat = $field('latitude');
        $lng = $field('longitude');
        $this->db->createOwnerApplication($data + [
            'id' => 'app_' . bin2hex(random_bytes(6)),
            'user_id' => $user['id'],
            'latitude' => is_numeric($lat) && abs((float)$lat) <= 90 ? (float)$lat : null,
            'longitude' => is_numeric($lng) && abs((float)$lng) <= 180 ? (float)$lng : null,
            // Scaffolded into real court rows on approval, so it is bounded.
            'courts_count' => min(50, max(1, (int)$in->input('courts_count', 1))),
            'permit_file' => $permit,
            'gov_id_file' => $govId,
            'status' => 'pending_review',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $this->db->updateUser((string)$user['id'], ['verification_status' => 'pending_owner_review']);

        return $ajax
            ? $this->legacyJson(['success' => true, 'message' => 'Your facility owner application has been submitted and is pending verification by Picklers Admin.', 'redirect_url' => 'app.php?tab=settings&notice=application_pending'])
            : $this->redirectToPath($request, 'app.php?tab=settings&notice=application_pending');
    }

    /** Owners (and staff/admins) only, as the portal always required. */
    private function denyUnlessPortalUser(Request $request): ?Response
    {
        $user = $this->sessionUser()?->row();
        if ($user === null) {
            return $this->redirectToPath($request, 'auth.php');
        }

        return $this->scope->canUsePortal($user) ? null : $this->redirectToPath($request, 'owner-application.php?notice=verification_required');
    }

    /**
     * Store one private document; its stored name, or null when nothing acceptable
     * was uploaded (size, extension, and the bytes' real type must all agree).
     */
    private function storeDocument(mixed $file): ?string
    {
        if (!$file instanceof UploadedFile || !$file->isValid() || $file->getSize() <= 0 || $file->getSize() > self::DOCUMENT_MAX_BYTES) {
            return null;
        }
        $original = basename($file->getClientOriginalName());
        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file->getPathname()) ?: '';
        if (!in_array($ext, self::DOCUMENT_EXTENSIONS, true) || !in_array($mime, self::DOCUMENT_MIME_TYPES, true)) {
            return null;
        }
        // Random suffix: names are not guessable and two uploads never overwrite each other.
        $base = substr((string)preg_replace('/[^a-zA-Z0-9_\-]/', '_', pathinfo($original, PATHINFO_FILENAME)), 0, 40);
        $name = $base . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
        try {
            if (!is_dir($this->documentDir)) {
                @mkdir($this->documentDir, 0750, true);
            }
            $file->move($this->documentDir, $name);
        } catch (\Throwable $e) {
            return null;
        }

        return $name;
    }
}
