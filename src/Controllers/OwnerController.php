<?php
declare(strict_types=1);

namespace Picklers\Controllers;

use Picklers\Core\Database;
use Picklers\Core\Request;
use Picklers\Core\Response;
use Picklers\Middleware\AuthMiddleware;
use Picklers\Models\Booking;
use Picklers\Models\Facility;

use Picklers\Services\AuthService;
use Picklers\Services\BookingService;
use Picklers\Services\FacilityService;
use Picklers\Services\TournamentService;

class OwnerController extends BaseController {
    private AuthService $authService;
    private FacilityService $facilityService;
    private BookingService $bookingService;
    private TournamentService $tournaments;
    private Facility $facilityModel;
    private Booking $bookingModel;

    public function __construct(
        ?AuthService $authService = null,
        ?FacilityService $facilityService = null,
        ?BookingService $bookingService = null,
        ?Facility $facilityModel = null,
        ?Booking $bookingModel = null,
        ?TournamentService $tournaments = null
    ) {
        $this->authService = $authService ?? new AuthService();
        $this->facilityService = $facilityService ?? new FacilityService();
        $this->bookingService = $bookingService ?? new BookingService();
        $this->facilityModel = $facilityModel ?? new Facility();
        $this->bookingModel = $bookingModel ?? new Booking();
        $this->tournaments = $tournaments ?? new TournamentService();
    }

    /**
     * Full-page bracket console for one tournament.
     *
     * Reachable at /app/owner/tournaments/{id}. The tournament must belong to a
     * facility this account actually holds — an owner guessing another venue's
     * id gets the same 404 as an id that does not exist, so the URL never
     * confirms whether someone else's tournament is real.
     *
     * @param array<string,string> $params
     */
    public function tournamentDetail(Request $request, array $params = []) {
        $currentUser = AuthMiddleware::requireOwner();
        $tournamentId = (string)($params['id'] ?? $request->query('id', ''));

        $facilities = $this->resolveOwnerFacilities($currentUser);
        $tournament = $tournamentId !== '' ? $this->tournaments->find($tournamentId) : null;

        $owned = false;
        $currentFacility = $facilities[0] ?? null;
        if ($tournament !== null) {
            foreach ($facilities as $facility) {
                if ((string)$facility['id'] === (string)$tournament['facility_id']) {
                    $owned = true;
                    $currentFacility = $facility;
                    break;
                }
            }
            if (!empty($currentUser['is_admin'])) {
                $owned = true;
            }
        }

        if ($tournament === null || !$owned) {
            http_response_code(404);
            return Response::view('pages/owner-tournament', [
                'tournament' => null,
                'currentFacility' => $currentFacility,
                'currentUser' => $this->sanitizeUser($currentUser),
                'categories' => TournamentService::CATEGORIES,
            ]);
        }

        return Response::view('pages/owner-tournament', [
            'tournament' => $tournament,
            'currentFacility' => $currentFacility,
            'currentUser' => $this->sanitizeUser($currentUser),
            'categories' => TournamentService::CATEGORIES,
        ]);
    }

    /**
     * Display the 3-Step Owner Application Wizard
     */
    public function application(Request $request) {
        $currentUser = AuthMiddleware::requireAuth();
        $notice = (string)$request->query('notice', '');

        return Response::view('pages/owner-application', [
            'currentUser' => $this->sanitizeUser($currentUser),
            'notice' => $notice,
            'csrfToken' => \Picklers\Middleware\CsrfMiddleware::getToken()
        ]);
    }

    /**
     * Process Application Submission and Elevate to Owner Role
     */
    public function submitApplication(Request $request) {
        // Enforce CSRF token security
        if (!\Picklers\Middleware\CsrfMiddleware::validate($request)) {
            if ($request->header('X-Requested-With') === 'XMLHttpRequest' || $request->input('ajax') === '1') {
                return Response::json(['success' => false, 'message' => 'Security token invalid or expired. Please refresh the page.'], 403);
            }
            return Response::redirect('owner-application.php?notice=csrf_error');
        }

        $currentUser = AuthMiddleware::requireAuth();
        $db = Database::get();

        // Guard against duplicate submissions: a user with an application already in
        // the review queue must not be able to flood it by resubmitting the wizard.
        foreach ($db->getOwnerApplications('pending_review') as $existingApp) {
            if ((string)($existingApp['user_id'] ?? '') === (string)$currentUser['id']) {
                $msg = 'You already have an application under review. We will notify you once it has been assessed.';
                if ($request->header('X-Requested-With') === 'XMLHttpRequest' || $request->input('ajax') === '1') {
                    return Response::json([
                        'success'      => false,
                        'message'      => $msg,
                        'redirect_url' => 'app.php?tab=settings&notice=application_pending',
                    ], 409);
                }
                return Response::redirect('app.php?tab=settings&notice=application_pending');
            }
        }

        $facilityName = trim((string)$request->input('facility_name', ''));
        $address = trim((string)$request->input('address', ''));
        $latitude = (float)$request->input('latitude', 14.5547);
        $longitude = (float)$request->input('longitude', 121.0244);
        $courtsCount = max(1, (int)$request->input('courts_count', 4));
        $courtSurface = (string)$request->input('court_surface', 'Indoor Hard');
        $operatingHours = (string)$request->input('operating_hours', '06:00 AM – 11:00 PM');

        $ownerName = trim((string)$request->input('owner_name', ''));
        $businessEmail = trim((string)$request->input('business_email', ''));
        $phone = trim((string)$request->input('phone', ''));
        $entityName = trim((string)$request->input('entity_name', ''));
        $regNumber = trim((string)$request->input('reg_number', ''));

        // Every one of these used to fall back to a specific fake business
        // ("BGC Pickleball Arena", "Marcus Vance", "SEC-CS2026-98124", ...)
        // whenever a field was left blank — and the form's own inputs shipped
        // those exact strings as their pre-filled `value`, so an applicant
        // could tab straight through the whole wizard without typing
        // anything real and still pass HTML5 `required` (which only checks
        // "not empty", and a pre-filled fake value isn't empty). This is a
        // facility-owner identity/licensing check for a marketplace handling
        // real money — reject incomplete applications instead of inventing a
        // complete-looking fake one for an admin to unknowingly approve.
        $missing = [];
        if ($facilityName === '') $missing[] = 'Facility Brand Name';
        if ($address === '') $missing[] = 'Street Address';
        if ($ownerName === '') $missing[] = 'Owner Full Name';
        if ($businessEmail === '' || !filter_var($businessEmail, FILTER_VALIDATE_EMAIL)) $missing[] = 'a valid Business Email';
        if ($phone === '') $missing[] = 'Mobile Number';
        if ($entityName === '') $missing[] = 'Registered Legal Trade Name';
        if ($regNumber === '') $missing[] = 'DTI / SEC Registration Number';
        if ($missing !== []) {
            $msg = 'Please complete: ' . implode(', ', $missing) . '.';
            if ($request->header('X-Requested-With') === 'XMLHttpRequest' || $request->input('ajax') === '1') {
                return Response::json(['success' => false, 'message' => $msg], 400);
            }
            return Response::redirect('owner-application.php?notice=incomplete');
        }

        // Handle uploaded permits/IDs safely with whitelist validation and actual file storage
        $uploadDir = dirname(__DIR__, 2) . '/public/uploads/permits';
        if (!is_dir($uploadDir)) {
            @mkdir($uploadDir, 0750, true);
        }

        $allowedExts = ['pdf', 'jpg', 'jpeg', 'png', 'webp'];
        // Returns null (not a fake filename) when no real file was uploaded —
        // the caller must treat that as a rejected application, not silently
        // record a permit/ID that was never actually provided.
        $sanitizeUpload = function(?array $file, string $uploadDir) use ($allowedExts): ?string {
            if (!$file || empty($file['name']) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                return null;
            }
            $cleanName = basename((string)$file['name']);
            $ext = strtolower(pathinfo($cleanName, PATHINFO_EXTENSION));
            if (!in_array($ext, $allowedExts, true)) {
                return null;
            }
            $safeBase = preg_replace('/[^a-zA-Z0-9_\-]/', '_', pathinfo($cleanName, PATHINFO_FILENAME));
            $finalName = substr((string)$safeBase, 0, 40) . '_' . date('Ymd_His') . '.' . $ext;
            if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
                return null;
            }
            if (!move_uploaded_file($file['tmp_name'], $uploadDir . '/' . $finalName)) {
                return null;
            }
            return $finalName;
        };

        $permitName = $sanitizeUpload($_FILES['permit_file'] ?? null, $uploadDir);
        $govIdName  = $sanitizeUpload($_FILES['gov_id_file'] ?? null, $uploadDir);

        if ($permitName === null || $govIdName === null) {
            $docsMissing = [];
            if ($permitName === null) $docsMissing[] = "Mayor's Permit / Business License";
            if ($govIdName === null) $docsMissing[] = 'a valid Government ID';
            $msg = 'Please upload ' . implode(' and ', $docsMissing) . ' (PDF, PNG, or JPG, max 5MB).';
            if ($request->header('X-Requested-With') === 'XMLHttpRequest' || $request->input('ajax') === '1') {
                return Response::json(['success' => false, 'message' => $msg], 400);
            }
            return Response::redirect('owner-application.php?notice=incomplete');
        }

        $appRecord = [
            'id' => 'app_' . bin2hex(random_bytes(6)),
            'user_id' => $currentUser['id'],
            'facility_name' => $facilityName,
            'address' => $address,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'courts_count' => $courtsCount,
            'court_surface' => $courtSurface,
            'operating_hours' => $operatingHours,
            'owner_name' => $ownerName,
            'business_email' => $businessEmail,
            'phone' => $phone,
            'entity_name' => $entityName,
            'reg_number' => $regNumber,
            'permit_file' => $permitName,
            'gov_id_file' => $govIdName,
            'status' => 'pending_review',
            'created_at' => date('Y-m-d H:i:s')
        ];

        // Persist application via Database engine
        $db->createOwnerApplication($appRecord);

        // Update user status to pending verification (DO NOT auto-elevate to is_owner = 1)
        $userId = $currentUser['id'];
        $db->updateUser($userId, [
            'verification_status' => 'pending_owner_review'
        ]);

        if ($request->header('X-Requested-With') === 'XMLHttpRequest' || $request->input('ajax') === '1') {
            return Response::json([
                'success' => true,
                'message' => 'Your facility owner application has been submitted and is pending verification by Picklers Admin.',
                'redirect_url' => 'app.php?tab=settings&notice=application_pending'
            ]);
        }

        return Response::redirect('app.php?tab=settings&notice=application_pending');
    }

    /**
     * Resolve the set of facilities the current owner manages, applying the
     * same admin/owner filtering and default-facility fallback used across
     * the Owner Portal. Shared by index() and handlePost() so every owner
     * action resolves facility_id the exact same way.
     */
    private function resolveOwnerFacilities(array $currentUser): array {
        $allFacilities = $this->facilityModel->all();
        if (!empty($currentUser['is_admin'])) {
            return $allFacilities;
        }

        // Real rows only. An owner with none gets an empty array here and
        // index() renders an onboarding state for it — never a fabricated
        // facility (a fictional Manila venue was previously invented here,
        // whose string id collapsed to facility_id = 0 the moment a court
        // was added against it, silently vanishing that court from every
        // real query keyed on an actual facility id).
        return array_values(array_filter($allFacilities, function($f) use ($currentUser) {
            return isset($f['owner_id']) && (string)$f['owner_id'] === (string)$currentUser['id'];
        }));
    }

    /**
     * The one facility a write action applies to, verified as one this owner
     * actually holds.
     *
     * Every mutating action below (add_court, host_open_play, add_staff)
     * used to reach for $facilities[0] unconditionally — correct only by
     * coincidence, because every real owner account happens to hold exactly
     * one facility today. It was never a real ownership check: nothing
     * stopped a request from acting on the wrong facility once an owner (or
     * an admin managing several) held more than one, and an owner with NONE
     * (see resolveOwnerFacilities()) would pass facility_id => null through
     * to an insert with no error at all — a court silently orphaned from
     * every facility-scoped read.
     *
     * No client currently sends an explicit facility_id (there is no
     * facility-switcher UI yet — a forward-looking enhancement, not part of
     * this fix), so this preserves today's behaviour
     * exactly for the single-facility case while making both the
     * zero-facility and any future multi-facility case fail safely instead
     * of silently.
     */
    private function resolveRequestedFacility(array $currentUser, Request $request): ?array {
        $facilities = $this->resolveOwnerFacilities($currentUser);
        if ($facilities === []) {
            return null;
        }

        $requested = trim((string)$request->input('facility_id', ''));
        if ($requested === '') {
            return $facilities[0];
        }

        foreach ($facilities as $f) {
            if ((string)($f['id'] ?? '') === $requested) {
                return $f;
            }
        }

        return null; // Requested a facility this owner does not hold.
    }

    /**
     * Main Owner Portal View (Dashboard, Courts, Tournaments, Earnings, Messages, Settings)
     */
    public function index(Request $request) {
        $currentUser = AuthMiddleware::requireOwner();

        $facilities = $this->resolveOwnerFacilities($currentUser);

        if ($facilities === []) {
            return Response::view('pages/owner-onboarding', [
                'currentUser' => $this->sanitizeUser($currentUser),
                'application' => Database::get()->getLatestApplicationForUser((string)$currentUser['id']),
            ]);
        }

        $facilityId = (string)$request->query('facility_id', '');
        $currentFacility = $facilities[0];
        if (!empty($facilityId)) {
            foreach ($facilities as $fac) {
                if ((string)$fac['id'] === $facilityId) {
                    $currentFacility = $fac;
                    break;
                }
            }
        }

        // getCourtsWithAttributes() rather than the plain getCourts() so each
        // court already carries its own attribute_slugs (Indoor/Outdoor/
        // Covered/...) for the tab's chips and the edit-court modal's picker.
        $courts = Database::get()->getCourtsWithAttributes($currentFacility['id'] ?? 1);
        $bookings = $this->bookingModel->all();
        $courtAttributeCatalog = Database::get()->getCourtAttributeCatalog();

        // Tab selection (Strictly 5 Primary Modules: Dashboard, My Courts, Tournaments, Messages, Settings)
        $activeTab = (string)$request->query('tab', 'dashboard');
        $validTabs = ['dashboard', 'courts', 'tournaments', 'messages', 'staff', 'earnings', 'settings'];
        if (!in_array($activeTab, $validTabs, true)) {
            $activeTab = 'dashboard';
        }

        // Section 1: KPI Metrics & Sparklines — sourced from real booking data
        $fin = Database::get()->getOwnerFinancials($currentFacility['id'] ?? 0);
        $spark = $fin['spark'];
        $metrics = [
            'monthly_revenue' => [
                'label' => 'MONTHLY REVENUE',
                'value' => '₱' . number_format($fin['monthly_gross'], 0),
                'delta' => $fin['monthly_gross'] > 0 ? '↗ This Month' : '— No data yet',
                'color' => 'emerald',
                'icon'  => 'peso',
                'spark' => $spark,
            ],
            'repeaters_rate' => [
                'label' => 'REPEATERS',
                'value' => $fin['repeater_rate'] . '%',
                'delta' => $fin['repeater_rate'] >= 40 ? '↗ Strong' : ($fin['repeater_rate'] > 0 ? '↘ Growing' : '— No data yet'),
                'color' => 'crimson',
                'icon'  => 'user',
                'spark' => array_reverse($spark),
            ],
            'today_revenue' => [
                'label' => "TODAY'S REVENUE",
                'value' => '₱' . number_format($fin['today_gross'], 0),
                'delta' => $fin['today_gross'] > 0 ? '↗ Today' : '— No data yet',
                'color' => 'cyan',
                'icon'  => 'trend',
                'spark' => $spark,
            ],
            'active_bookings' => [
                'label' => 'ACTIVE BOOKINGS',
                'value' => (string)$fin['active_bookings'],
                'delta' => $fin['active_bookings'] > 0 ? '↗ Live' : '— None yet',
                'color' => 'amber',
                'icon'  => 'clock',
                'spark' => $spark,
            ],
            'new_players' => [
                'label' => 'NEW PLAYERS',
                'value' => (string)$fin['new_players_today'],
                'delta' => $fin['new_players_today'] > 0 ? '↗ Today' : '— None yet',
                'color' => 'purple',
                'icon'  => 'users',
                'spark' => $spark,
            ],
        ];

        // Build real courts data mapping
        $facilityMatches = \Picklers\Core\Database::get()->getMatchesByFacility((int)($currentFacility['id'] ?? 1));
        $totalCourtsForFacility = count($courts);

        $realCourts = array_map(function ($c) use ($facilityMatches, $totalCourtsForFacility) {
            $statusNorm = strtoupper(trim((string)($c['status'] ?? 'AVAILABLE')));
            if (!in_array($statusNorm, ['AVAILABLE', 'UNAVAILABLE', 'OCCUPIED'], true)) {
                $statusNorm = 'UNAVAILABLE';
            }
            $cName = trim((string)($c['name'] ?? ''));
            $cId = trim((string)($c['id'] ?? ''));

            $hasOpenPlay = false;
            $openPlayMatch = null;
            foreach ($facilityMatches as $m) {
                if (\Picklers\Core\Database::isMatchExpired($m)) {
                    continue;
                }
                $mType = trim((string)($m['type'] ?? ''));
                $mCourtId = trim((string)($m['court_id'] ?? ''));
                $mCourtName = trim((string)($m['court_name'] ?? ''));

                $isMatchForCourt = false;
                if ($cId !== '' && $mCourtId !== '' && $mCourtId === $cId) {
                    $isMatchForCourt = true;
                } elseif ($cName !== '' && $mCourtName !== '' && strcasecmp($mCourtName, $cName) === 0) {
                    $isMatchForCourt = true;
                } elseif ($cName !== '' && (strcasecmp($mType, $cName) === 0 || str_contains(strtolower($mType), strtolower($cName)))) {
                    $isMatchForCourt = true;
                } elseif ($totalCourtsForFacility === 1) {
                    $isMatchForCourt = true;
                }

                if ($isMatchForCourt) {
                    $hasOpenPlay = true;
                    $openPlayMatch = $m;
                    break;
                }
            }

            $normCourtName = \Picklers\Core\Database::normalizeCourtName((string)($c['name'] ?? 'Court'));
            return [
                'id' => $c['id'] ?? '',
                'name' => $normCourtName,
                'type' => $c['type'] ?? 'Indoor',
                'surface' => $c['surface'] ?? 'Hard Court',
                'rate' => $c['price'] ?? 0,
                'active' => $statusNorm !== 'UNAVAILABLE',
                'status' => $statusNorm,
                'player' => $c['occupied_by'] ?? null,
                'player_time' => $c['occupied_until'] ?? null,
                'attributes' => $c['attributes'] ?? [],
                'attribute_slugs' => $c['attribute_slugs'] ?? [],
                'has_open_play' => $hasOpenPlay,
                'open_play_match' => $openPlayMatch,
                'next_booking' => $c['next_booking'] ?? null,
                'upcoming_bookings' => $c['upcoming_bookings'] ?? [],
                'completed_bookings' => $c['completed_bookings'] ?? [],
                'start_min' => $c['start_min'] ?? null,
                'end_min' => $c['end_min'] ?? null
            ];
        }, $courts);

        // Section 1: Live Courts Grid (Built dynamically from real facility courts)
        $liveCourts = [];
        foreach ($realCourts as $rc) {
            $statusNorm = strtoupper(trim((string)($rc['status'] ?? 'AVAILABLE')));
            $hasOP = !empty($rc['has_open_play']) || (isset($rc['player']) && stripos((string)$rc['player'], 'Open Play') !== false);
            $isRealPlayerBooking = ($statusNorm === 'OCCUPIED' && !empty($rc['player']) && strcasecmp((string)$rc['player'], 'Hosted Open Play') !== 0 && stripos((string)$rc['player'], 'Open Play') === false);

            if ($hasOP) {
                $opMatch = $rc['open_play_match'] ?? [];
                $isEveryday = strtolower(trim((string)($opMatch['date'] ?? ''))) === 'everyday';
                $rawTime = $opMatch['time'] ?? '6:00 AM – 11:00 PM';
                $timeFormatted = $isEveryday ? ("Everyday • " . $rawTime) : $rawTime;

                $liveCourts[] = [
                    'id' => $rc['id'],
                    'name' => $rc['name'],
                    'dot' => 'amber',
                    'player_name' => null,
                    'status' => 'open_play',
                    'timer' => null,
                    'has_open_play' => true,
                    'open_play_title' => $opMatch['title'] ?? 'Community Open Play',
                    'joined_players' => (int)($opMatch['current_players'] ?? $opMatch['joined'] ?? 0),
                    'max_players' => (int)($opMatch['max_players'] ?? $opMatch['capacity'] ?? 12),
                    'time_range' => $timeFormatted,
                    'is_everyday' => $isEveryday,
                    'upcoming_bookings' => $rc['upcoming_bookings'] ?? [],
                    'completed_bookings' => $rc['completed_bookings'] ?? []
                ];
            } elseif ($isRealPlayerBooking) {
                $sMin = isset($rc['start_min']) ? (int)$rc['start_min'] : 660;
                $eMin = isset($rc['end_min']) ? (int)$rc['end_min'] : ($sMin + 60);
                if ($eMin <= $sMin) { $eMin = $sMin + 60; }

                $nowSec = (int)date('H') * 3600 + (int)date('i') * 60 + (int)date('s');
                $startSec = $sMin * 60;
                $endSec = $eMin * 60;

                $totalSec = max(60, $endSec - $startSec);
                $secLeft = max(0, $endSec - $nowSec);
                $pct = (int)min(100, max(0, round((($totalSec - $secLeft) / $totalSec) * 100)));

                $mL = floor($secLeft / 60);
                $sL = $secLeft % 60;
                $timerFormatted = sprintf('%02d:%02d', $mL, $sL);

                $pColor = 'green';
                if ($pct >= 85) { $pColor = 'red'; }
                elseif ($pct >= 65) { $pColor = 'amber'; }

                $liveCourts[] = [
                    'id' => $rc['id'],
                    'name' => $rc['name'],
                    'dot' => 'cyan',
                    'player_name' => $rc['player'],
                    'status' => 'occupied',
                    'timer' => $timerFormatted,
                    'seconds_left' => $secLeft,
                    'total_seconds' => $totalSec,
                    'progress_percent' => $pct,
                    'progress_color' => $pColor,
                    'has_open_play' => false,
                    'upcoming_bookings' => $rc['upcoming_bookings'] ?? [],
                    'completed_bookings' => $rc['completed_bookings'] ?? []
                ];
            } elseif ($statusNorm === 'UNAVAILABLE') {
                $liveCourts[] = [
                    'id' => $rc['id'],
                    'name' => $rc['name'],
                    'dot' => 'gray',
                    'player_name' => null,
                    'status' => 'maintenance',
                    'timer' => null,
                    'has_open_play' => false,
                    'upcoming_bookings' => $rc['upcoming_bookings'] ?? [],
                    'completed_bookings' => $rc['completed_bookings'] ?? []
                ];
            } else {
                $liveCourts[] = [
                    'id' => $rc['id'],
                    'name' => $rc['name'],
                    'dot' => !empty($rc['next_booking']) ? 'cyan' : 'green',
                    'player_name' => null,
                    'status' => 'waiting',
                    'timer' => null,
                    'has_open_play' => false,
                    'next_booking' => $rc['next_booking'] ?? null,
                    'upcoming_bookings' => $rc['upcoming_bookings'] ?? [],
                    'completed_bookings' => $rc['completed_bookings'] ?? []
                ];
            }
        }

        // Section 1: Booking Requests Queue (Live Database Records) — shared
        // with the 'get_pending_requests' API action (ApiController) via
        // Database::getPendingBookingRequests() so the first page render and
        // every later live refresh build the exact same shape from one place.
        $realRequests = \Picklers\Core\Database::get()->getPendingBookingRequests($currentFacility['id'] ?? '');

        // Real requests only. This used to always append three fabricated
        // rows (Bob Joshua, Daniel Alfeche, Alex Reyes) after the real ones —
        // an owner had no way to tell which requests were real, and
        // Accept/Decline on a demo row hit the API with a booking id that
        // never existed. An empty queue is a real, honest state; the view
        // renders its own "no pending requests" empty state for it.
        $pendingRequests = $realRequests;

        // Section 2 (Real Data): Open Play sessions actually hosted for this facility,
        // sourced via Database::getMatchesByFacility() -- see host_open_play in
        // handlePost() for how these get persisted (Database::insertMatch()). Transformed
        // to the exact field shape views/partials/owner/_tab-courts.php's 'active' grid
        // reads (tag/title/date/time/level/price/joined/capacity), the same way
        // $realCourts/$realStaff below are built to match their consuming views.
        // The `matches` schema has no completed/status column, so 'completed' is left
        // empty -- the view's completed grid already just renders a static placeholder
        // regardless (see _tab-courts.php's #openPlayCompletedGrid), so this is accurate
        // rather than a loss of behavior.

        // Section 3: Tournaments Hub.
        //
        // TournamentService owns the record shape (roster, bracket, lifecycle)
        // and hydrates every read, so the view receives real bracket state
        // rather than the fabricated 8-team quarterfinal placeholder this used
        // to inject whenever a live tournament had no bracket drawn yet.
        $tournaments = $this->tournaments->grouped((string)($currentFacility['id'] ?? ''));


        // Section 4: Earnings & Payouts — sourced from real booking data (same
        // getOwnerFinancials() call used for Dashboard KPIs above).
        $earnings = [
            'gross'      => $fin['gross'],
            'fee_pct'    => $fin['fee_pct'],
            'fee_amount' => $fin['fee_amount'],
            'net'        => $fin['net'],
            'available'  => $fin['available'],
            'ledger'     => $fin['ledger'],
        ];

        // Section 5: Direct Messages — real conversations from the DM store
        $conversations = Database::get()->getConversationPartners((string)($currentUser['id'] ?? ''));

        // Section 6: Staff & Settings
        $rawStaff = Database::get()->getStaffByFacility($currentFacility['id']);
        $staffMembers = array_map(function($st) {
            return [
                'id' => (string)($st['id'] ?? ''),
                'name' => (string)($st['name'] ?? ''),
                'email' => (string)($st['email'] ?? ''),
                'role' => (string)($st['role'] ?? 'Front Desk'),
                'date' => (string)($st['date_joined'] ?? $st['date'] ?? date('M Y')),
                'status' => (string)($st['status'] ?? 'Active')
            ];
        }, $rawStaff);

        $amenities = [
            'showers' => ['label' => 'Showers & Changing Rooms', 'enabled' => true, 'icon' => 'droplets'],
            'ac' => ['label' => 'Air Conditioning / High-Velocity Fans', 'enabled' => true, 'icon' => 'wind'],
            'pro_shop' => ['label' => 'Pro Shop & Paddle Rentals', 'enabled' => true, 'icon' => 'shopping-bag'],
            'cafe' => ['label' => 'Cafe & Lounge Area', 'enabled' => true, 'icon' => 'coffee'],
            'parking' => ['label' => 'Free Parking / Valet', 'enabled' => true, 'icon' => 'car']
        ];

        $db = Database::get();
        $userNotifications = $db->getNotifications($currentUser['id'] ?? 'usr_owner');
        if (empty($userNotifications)) {
            $userNotifications = $db->getNotifications('usr_owner');
        }
        $unreadNotifsCount = count(array_filter($userNotifications, fn($n) => empty($n['is_read'])));


        // Map real staff records (from Database::get()->getStaffByFacility(), sourced from
        // the same facility as $realCourts above) to the field shape
        // views/partials/owner/_tab-staff.php expects. date_joined is stored by insertStaff()
        // already in the 'M Y' display format (see the add_staff handler in handlePost()), so
        // it's passed straight through as 'date' with no reformatting needed.
        $realStaff = array_map(function ($s) {
            return [
                'id' => $s['id'] ?? '',
                'name' => $s['name'] ?? 'Staff Member',
                'email' => $s['email'] ?? 'staff@facility.com',
                'date' => $s['date_joined'] ?? date('M Y'),
                'role' => $s['role'] ?? 'Front Desk',
                'status' => $s['status'] ?? 'Active',
            ];
        }, $db->getStaffByFacility((int)($currentFacility['id'] ?? 1)));

        // Build real Open Play sessions for this facility, categorizing active vs completed (expired past end time / date)
        $realOpenPlayMatches = [];
        $completedOpenPlayMatches = [];
        $seenMatchIds = [];

        foreach ($facilityMatches as $m) {
            $mId = (string)($m['id'] ?? '');
            if ($mId !== '' && in_array($mId, $seenMatchIds, true)) {
                continue;
            }
            if ($mId !== '') {
                $seenMatchIds[] = $mId;
            }

            $level = (string)($m['level'] ?? 'All Levels');
            $isExp = \Picklers\Core\Database::isMatchExpired($m);

            $opItem = [
                'id' => $m['id'] ?? '',
                'court_name' => $m['type'] ?? 'Court',
                'tag' => strtoupper($level) . ' OPEN PLAY',
                'title' => $m['title'] ?? ($m['type'] ?? 'Open Play Session'),
                'date' => $m['date'] ?? 'TBD',
                'time' => $m['time'] ?? 'TBD',
                'level' => $level,
                'price' => $m['price'] ?? 0,
                'joined' => (int)($m['current_players'] ?? 0),
                'capacity' => (int)($m['max_players'] ?? 4),
                'is_completed' => $isExp
            ];

            if ($isExp) {
                $completedOpenPlayMatches[] = $opItem;
            } else {
                $realOpenPlayMatches[] = $opItem;
            }
        }

        $openPlayMatches = [
            'active' => $realOpenPlayMatches,
            'completed' => $completedOpenPlayMatches
        ];

        return Response::view('pages/owner', [
            'activeTab' => $activeTab,
            'currentFacility' => $currentFacility,
            'facilities' => $facilities,
            'metrics' => $metrics,
            'financials' => $fin,
            'liveCourts' => $liveCourts,
            'courts' => $realCourts,
            'bookings' => $bookings,
            'pendingRequests' => $pendingRequests,
            'openPlayMatches' => $openPlayMatches,
            'tournaments' => $tournaments,
            'earnings' => $earnings,
            'conversations' => $conversations,
            'staffMembers' => $realStaff,
            'amenities' => $amenities,
            'userNotifications' => $userNotifications,
            'unreadNotifsCount' => $unreadNotifsCount,
            'courtAttributeCatalog' => $courtAttributeCatalog,
            'currentUser' => $this->sanitizeUser($currentUser)
        ]);
    }

    /**
     * Central POST handler for Owner Portal Form Submissions & AJAX actions
     */
    public function handlePost(Request $request): Response {
        $currentUser = AuthMiddleware::requireOwner();

        if (!\Picklers\Middleware\CsrfMiddleware::validate($request)) {
            if ($request->header('X-Requested-With') === 'XMLHttpRequest' || $request->input('ajax') === '1') {
                return Response::json(['success' => false, 'message' => 'Security token invalid or expired. Please refresh the page.'], 403);
            }
            return Response::redirect('owner.php?notice=csrf_error');
        }

        $action = (string)$request->input('action', '');

        switch ($action) {
            case 'add_court':
                $rawCourtName = trim((string)$request->input('name', ''));
                $surface = (string)$request->input('surface', 'Indoor Hard');
                $rate = max(50.0, (float)$request->input('rate', 450.0));
                $attributes = array_map('strval', (array)$request->input('attributes', []));

                $facility = $this->resolveRequestedFacility($currentUser, $request);
                if ($facility === null) {
                    return $this->jsonError('No facility to list this court under. Please complete your owner application first.', 403);
                }

                $db = Database::get();
                $existingCourts = $db->getCourtsByFacility($facility['id']);
                $existingNums = [];
                foreach ($existingCourts as $c) {
                    if (preg_match('/\d+/', (string)($c['name'] ?? ''), $m)) {
                        $existingNums[] = (int)$m[0];
                    }
                }
                $nextNum = 1;
                while (in_array($nextNum, $existingNums, true)) {
                    $nextNum++;
                }

                if (preg_match('/\d+/', $rawCourtName, $matches)) {
                    $courtName = "Court " . (int)$matches[0];
                } else {
                    $courtName = "Court " . $nextNum;
                }

                try {
                    $newCourt = $db->insertCourt([
                        'facility_id' => $facility['id'],
                        'name' => $courtName,
                        'surface' => $surface,
                        'type' => 'Indoor',
                        'price' => $rate,
                        'status' => 'available'
                    ]);
                    $db->setCourtAttributes((string)$newCourt['id'], $attributes);
                    $newCourt['attribute_slugs'] = $attributes;
                } catch (\Throwable $e) {
                    return $this->jsonError('Failed to add court. Please try again.', 500);
                }

                if (empty($newCourt)) {
                    return $this->jsonError('Failed to add court. Please try again.', 500);
                }

                return $this->jsonSuccess([
                    'court' => $newCourt
                ], "Court '{$courtName}' has been added to your facility inventory.");

            case 'edit_court':
                $courtId = trim((string)$request->input('court_id', ''));
                $rawCourtName = trim((string)$request->input('name', ''));
                $surface = (string)$request->input('surface', 'Premium Hard');
                $rate = max(50.0, (float)$request->input('rate', 450.0));
                $attributes = array_map('strval', (array)$request->input('attributes', []));

                if ($courtId === '') {
                    return $this->jsonError('Missing court ID', 400);
                }

                if (preg_match('/\d+/', $rawCourtName, $matches)) {
                    $courtName = "Court " . (int)$matches[0];
                } else {
                    $courtName = $rawCourtName !== '' ? $rawCourtName : 'Court 1';
                }

                $db = Database::get();
                if (empty($currentUser['is_admin'])) {
                    if (!$db->verifyCourtOwner($courtId, (string)$currentUser['id'])) {
                        return $this->jsonError('Unauthorized: You do not own this court', 403);
                    }
                }

                try {
                    $updatedCourt = $db->updateCourt($courtId, [
                        'name' => $courtName,
                        'surface' => $surface,
                        'price' => $rate
                    ]);
                    if ($updatedCourt !== null) {
                        // Unconditional, not gated on a non-empty selection —
                        // an owner unchecking every tag is a real "clear
                        // these" action, and setCourtAttributes() replaces
                        // (not appends), so this is exactly the right call
                        // whether the new set is 3 tags or 0.
                        $db->setCourtAttributes($courtId, $attributes);
                        $updatedCourt['attribute_slugs'] = $attributes;
                    }
                } catch (\Throwable $e) {
                    return $this->jsonError('Failed to update court. Please try again.', 500);
                }

                if ($updatedCourt === null) {
                    return $this->jsonError('Court not found', 404);
                }

                return $this->jsonSuccess([
                    'court' => $updatedCourt
                ], "Court '{$courtName}' updated successfully.");

            case 'toggle_court_status':
                $courtId = trim((string)$request->input('court_id', ''));
                $courtName = trim((string)$request->input('name', ''));
                $active = (bool)$request->input('active', false);

                if ($courtId === '') {
                    return $this->jsonError('Missing court ID', 400);
                }

                $db = Database::get();
                if (empty($currentUser['is_admin'])) {
                    if (!$db->verifyCourtOwner($courtId, (string)$currentUser['id'])) {
                        return $this->jsonError('Unauthorized: You do not own this court', 403);
                    }
                }

                // Guard: Cannot disable a court that is currently occupied or hosting Open Play
                if (!$active) {
                    $facility = $this->resolveRequestedFacility($currentUser, $request);
                    if ($facility !== null) {
                        $facilityMatches = $db->getMatchesByFacility($facility['id']);
                        foreach ($facilityMatches as $m) {
                            $mType = trim((string)($m['type'] ?? ''));
                            $mCourtId = trim((string)($m['court_id'] ?? ''));
                            if (($courtId !== '' && $mCourtId === $courtId) ||
                                ($courtName !== '' && (strcasecmp($mType, $courtName) === 0 || str_contains(strtolower($mType), strtolower($courtName))))) {
                                return $this->jsonError("Court '{$courtName}' is currently hosting Open Play and cannot be disabled.", 400);
                            }
                        }
                    }
                }

                // Matches the uppercase AVAILABLE/UNAVAILABLE/OCCUPIED convention
                // read by views/partials/owner/_tab-courts.php's status display logic.
                $statusValue = $active ? 'AVAILABLE' : 'UNAVAILABLE';
                $statusMsg = $active ? "Court '{$courtName}' is now active and open for reservations." : "Court '{$courtName}' has been disabled.";

                try {
                    $ok = $db->updateCourtStatus($courtId, $statusValue);
                } catch (\Throwable $e) {
                    return $this->jsonError('Failed to update court status. Please try again.', 500);
                }

                if (!$ok) {
                    return $this->jsonError('Court not found', 404);
                }

                return $this->jsonSuccess(['active' => $active], $statusMsg);

            case 'end_court_session':
                // Previously wired to 'toggle_court_status', which only ever
                // touches courts.status — a field the dashboard's dynamic
                // occupancy calculation doesn't read for a real, timed
                // booking. That made "End Session Early" a no-op: the court
                // showed occupied by the same booking again on the very next
                // page load. This calls Database::endCourtSessionEarly(),
                // which flags the actual active booking so it's genuinely
                // excluded from that calculation.
                $courtId = trim((string)$request->input('court_id', ''));
                $courtName = trim((string)$request->input('court_name', ''));
                if ($courtId === '' && $courtName === '') {
                    return $this->jsonError('Missing court identifier', 400);
                }

                $db = Database::get();
                if ($courtId !== '' && empty($currentUser['is_admin']) && !$db->verifyCourtOwner($courtId, (string)$currentUser['id'])) {
                    return $this->jsonError('Unauthorized: You do not own this court', 403);
                }

                $sessionFacility = $this->resolveRequestedFacility($currentUser, $request);
                if ($sessionFacility === null) {
                    return $this->jsonError('No facility found for this account.', 400);
                }

                $endResult = $db->endCourtSessionEarly((string)$sessionFacility['id'], $courtId, $courtName);
                if (!$endResult['success']) {
                    return $this->jsonError($endResult['message'], 404);
                }
                return $this->jsonSuccess([], $endResult['message']);

            case 'delete_court':
                $courtId = trim((string)$request->input('court_id', ''));
                $courtName = trim((string)$request->input('name', ''));

                if ($courtId === '') {
                    return $this->jsonError('Missing court ID', 400);
                }

                $db = Database::get();
                if (empty($currentUser['is_admin'])) {
                    if (!$db->verifyCourtOwner($courtId, (string)$currentUser['id'])) {
                        return $this->jsonError('Unauthorized: You do not own this court', 403);
                    }
                }

                $facility = $this->resolveRequestedFacility($currentUser, $request);
                if ($facility !== null) {
                    $facilityMatches = $db->getMatchesByFacility($facility['id']);
                    foreach ($facilityMatches as $m) {
                        $mType = trim((string)($m['type'] ?? ''));
                        $mCourtId = trim((string)($m['court_id'] ?? ''));
                        if (($courtId !== '' && $mCourtId === $courtId) ||
                            ($courtName !== '' && (strcasecmp($mType, $courtName) === 0 || str_contains(strtolower($mType), strtolower($courtName))))) {
                            return $this->jsonError("Court '{$courtName}' is currently hosting Open Play and cannot be deleted until the session is cancelled.", 400);
                        }
                    }
                }

                try {
                    $deleted = $db->deleteCourt($courtId);
                } catch (\Throwable $e) {
                    return $this->jsonError('Failed to delete court. Please try again.', 500);
                }

                if (!$deleted) {
                    return $this->jsonError('Court not found or could not be deleted.', 404);
                }

                return $this->jsonSuccess(['court_id' => $courtId], "Court '{$courtName}' has been permanently deleted.");

            case 'host_open_play':
                // No 400 on an empty title: the pre-existing (fake) implementation of this
                // case never required a title either -- it silently fell back to 'Community
                // Dink Session'. Preserved here rather than introducing a stricter requirement.
                $title = trim((string)$request->input('title', ''));
                if ($title === '') {
                    $title = 'Community Dink Session';
                }
                // The Host Open Play modal (views/partials/owner/_modals.php) targets a
                // specific court (openHostOpenPlayForCourt() in owner.js), but the `matches`
                // table has no court-reference column. Database::joinMatch() already treats
                // `matches.type` as the court name downstream (it copies $match['type']
                // straight into booking['court_name']), so that's the existing, load-bearing
                // convention this reuses rather than inventing a new one.
                $courtName = trim((string)$request->input('court_name', ''));
                $bracket = (string)$request->input('bracket', 'All Levels');
                $fee = max(0.0, (float)$request->input('fee', 250.0));
                $capacity = max(2, (int)$request->input('capacity', 12));
                $date = trim((string)$request->input('date', ''));
                $startTime = trim((string)$request->input('start_time', ''));
                $endTime = trim((string)$request->input('end_time', ''));

                if ($startTime !== '' && $endTime !== '') {
                    $timeRange = "{$startTime} \u{2013} {$endTime}";
                } else {
                    $timeRange = $startTime ?: ($endTime ?: 'TBD');
                }

                $facility = $this->resolveRequestedFacility($currentUser, $request);
                if ($facility === null) {
                    return $this->jsonError('No facility to host this session at. Please complete your owner application first.', 403);
                }
                $facilityId = $facility['id'];
                $facilityName = $facility['name'] ?? '';
                $location = $facility['location'] ?? '';

                try {
                    $db = Database::get();
                    if (!empty($courtName) && !empty($facilityId)) {
                        $db->deleteMatch($courtName, $facilityId);
                    }
                    $newMatch = $db->insertMatch([
                        'facility_id' => $facilityId,
                        'facility_name' => $facilityName,
                        'location' => $location,
                        'date' => $date !== '' ? $date : date('F j, Y'),
                        'time' => $timeRange,
                        'level' => $bracket,
                        'current_players' => 0,
                        'max_players' => $capacity,
                        'price' => $fee,
                        // Court name folded into `type` (see comment above) -- falls back to
                        // the same 'Open Play Session' default used by joinMatch()/getMatches().
                        'type' => $courtName !== '' ? $courtName : 'Open Play Session',
                        // `host` is a real, rendered field (views/partials/app/_tab-explore.php
                        // shows "Host: {host}" to players across all facilities), so it must
                        // carry an actual host-person name, not the session title.
                        'host' => $currentUser['name'] ?? 'Facility Staff',
                        // Session title has its own dedicated column.
                        'title' => $title
                    ]);
                    if (!empty($courtName) && !empty($facilityId)) {
                        $db->occupyCourt((int)$facilityId, $courtName, 'Hosted Open Play', $timeRange);
                    }
                } catch (\Throwable $e) {
                    return $this->jsonError('Failed to publish Open Play session. Please try again.', 500);
                }

                if (empty($newMatch)) {
                    return $this->jsonError('Failed to publish Open Play session. Please try again.', 500);
                }

                return $this->jsonSuccess([
                    'session' => $newMatch
                ], "Open Play event '{$title}' published successfully!");

            case 'cancel_open_play':
                $matchId = trim((string)$request->input('match_id', ''));
                $title = trim((string)$request->input('title', ''));
                $courtName = trim((string)$request->input('court_name', ''));
                $target = $matchId !== '' ? $matchId : $title;

                if ($target === '' && $courtName === '') {
                    return $this->jsonError('Missing session identifier', 400);
                }

                $facility = $this->resolveRequestedFacility($currentUser, $request);
                if ($facility === null) {
                    return $this->jsonError('Facility not found', 403);
                }

                $db = Database::get();
                $deleted = $db->deleteMatch($target, $facility['id'], $courtName);
                if ($courtName !== '') {
                    $db->clearCourtSession($courtName, (int)$facility['id']);
                }
                return $this->jsonSuccess([], "Open Play session cancelled.");

            case 'update_facility_settings':
                $facility = $this->resolveRequestedFacility($currentUser, $request);
                if ($facility === null) {
                    return $this->jsonError('No facility context found. Please complete owner onboarding first.', 403);
                }

                $facName = trim((string)$request->input('facility_name', $request->input('name', '')));
                $facLoc = trim((string)$request->input('facility_location', $request->input('location', '')));
                $hoursStr = trim((string)$request->input('hours', ''));
                $facImage = trim((string)$request->input('image', $request->input('facility_image', $request->input('logo', ''))));

                if ($facName === '') {
                    return $this->jsonError('Facility name cannot be empty.', 400);
                }

                $updateData = ['name' => $facName];
                if ($facLoc !== '') {
                    $updateData['location'] = $facLoc;
                }
                if ($hoursStr !== '') {
                    $updateData['hours'] = $hoursStr;
                }
                if ($facImage !== '') {
                    $updateData['image'] = $facImage;
                }

                // Payout destination + accepted-methods — previously saved to
                // localStorage only (see saveFacilitySettings() in owner.js),
                // so it never reached here at all. Digits-only, capped at the
                // same 11-char length the settings form's own input enforces.
                if ($request->input('gcash_number', null) !== null) {
                    $updateData['gcash_number'] = substr(preg_replace('/\D/', '', (string)$request->input('gcash_number', '')), 0, 11);
                }
                if ($request->input('maya_number', null) !== null) {
                    $updateData['maya_number'] = substr(preg_replace('/\D/', '', (string)$request->input('maya_number', '')), 0, 11);
                }
                if ($request->input('gcash_enabled', null) !== null) {
                    $updateData['gcash_enabled'] = filter_var($request->input('gcash_enabled'), FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
                }
                if ($request->input('maya_enabled', null) !== null) {
                    $updateData['maya_enabled'] = filter_var($request->input('maya_enabled'), FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
                }
                if ($request->input('cash_on_site', null) !== null) {
                    $updateData['cash_on_site'] = filter_var($request->input('cash_on_site'), FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
                }

                $db = Database::get();
                $ok = $db->updateFacility($facility['id'], $updateData);
                if (!$ok) {
                    return $this->jsonError('Failed to update facility settings. Please try again.', 500);
                }

                return $this->jsonSuccess([
                    'facility' => $db->getFacility($facility['id'])
                ], 'Facility profile & settings updated successfully!');

            case 'get_open_play_roster':
                $courtName = trim((string)$request->input('court_name', $request->query('court_name', '')));
                $courtId = trim((string)$request->input('court_id', $request->query('court_id', '')));
                $facility = $this->resolveRequestedFacility($currentUser, $request);
                if ($facility === null) {
                    return $this->jsonError('No facility context found.', 403);
                }
                $roster = Database::get()->getOpenPlayRoster($facility['id'], $courtName, $courtId);
                return $this->jsonSuccess(['roster' => $roster, 'court_name' => $courtName]);

            case 'create_tournament':
                // Legacy endpoint kept alive for any old client still posting to
                // owner.php. api.php?action=create_tournament is the current one;
                // both land in the same service so validation cannot diverge.
                $facility = $this->resolveRequestedFacility($currentUser, $request);
                if ($facility === null) {
                    return $this->jsonError('Complete your owner application before hosting a tournament.', 403);
                }

                $created = $this->tournaments->create([
                    'facility_id' => (string)$facility['id'],
                    'owner_id'    => (string)$currentUser['id'],
                    'title'       => trim((string)$request->input('title', '')),
                    'category'    => (string)$request->input('category', $request->input('type', '')),
                    'format'      => (string)$request->input('format', 'single_elimination'),
                    'pairing_mode' => (string)$request->input('pairing_mode', 'fixed'),
                    'max_teams'   => $request->input('max_teams', 16),
                    'prize_pool'  => (string)$request->input('prize_pool', ''),
                    'entry_fee'   => (string)$request->input('entry_fee', ''),
                    'date'        => (string)$request->input('date', ''),
                    'end_date'    => (string)$request->input('end_date', ''),
                ]);

                if ($created['error'] !== null) {
                    return $this->jsonError($created['error'], 422);
                }

                return $this->jsonSuccess(
                    ['tournament' => $created['tournament']],
                    'Tournament "' . $created['tournament']['title'] . '" published successfully!'
                );

            case 'add_staff':
                $name = trim((string)$request->input('name', ''));
                $email = trim((string)$request->input('email', ''));
                $role = (string)$request->input('role', 'Front Desk');

                if ($name === '') {
                    return $this->jsonError('Staff name is required', 400);
                }
                if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    return $this->jsonError('Please enter a valid email address', 400);
                }

                $facility = $this->resolveRequestedFacility($currentUser, $request);
                if ($facility === null) {
                    return $this->jsonError('No facility to add this staff member to. Please complete your owner application first.', 403);
                }

                try {
                    $newStaff = Database::get()->insertStaff([
                        'facility_id' => $facility['id'],
                        'name' => $name,
                        'email' => $email ?: 'staff@facility.com',
                        'role' => $role,
                        'status' => 'Active',
                        'date_joined' => date('M Y')
                    ]);
                } catch (\Throwable $e) {
                    return $this->jsonError('Failed to add staff member. Please try again.', 500);
                }

                if (empty($newStaff)) {
                    return $this->jsonError('Failed to add staff member. Please try again.', 500);
                }

                return $this->jsonSuccess([
                    'staff' => $newStaff
                ], "Staff member {$name} ({$role}) added to facility roster.");

            case 'revoke_staff':
                $staffId = trim((string)$request->input('staff_id', ''));

                if ($staffId === '') {
                    return $this->jsonError('Missing staff ID', 400);
                }

                $db = Database::get();
                if (empty($currentUser['is_admin'])) {
                    if (!$db->verifyStaffOwner($staffId, (string)$currentUser['id'])) {
                        return $this->jsonError('Unauthorized: You do not manage this staff member', 403);
                    }
                }

                try {
                    $ok = $db->deleteStaff($staffId);
                } catch (\Throwable $e) {
                    return $this->jsonError('Failed to revoke staff access. Please try again.', 500);
                }

                if (!$ok) {
                    return $this->jsonError('Staff member not found', 404);
                }

                return $this->jsonSuccess([], 'Staff access revoked successfully.');

            case 'request_payout':
                $amount = (float)$request->input('amount', 0.0);
                $method = trim((string)$request->input('method', 'GCash'));
                $accountName = trim((string)$request->input('account_name', ''));
                $accountNumber = trim((string)$request->input('account_number', ''));

                if ($amount <= 0) {
                    return $this->jsonError('Payout amount must be greater than zero.', 400);
                }
                if ($accountName === '' || $accountNumber === '') {
                    return $this->jsonError('Account name and account/mobile number are required.', 400);
                }

                $payoutFacility = $this->resolveRequestedFacility($currentUser, $request);
                if ($payoutFacility === null) {
                    return $this->jsonError('No facility found for this account.', 400);
                }

                $financials = Database::get()->getOwnerFinancials((string)$payoutFacility['id']);
                $available  = (float)($financials['available'] ?? 0.0);

                if ($amount > $available) {
                    return $this->jsonError(
                        "Requested amount exceeds your available balance of ₱" . number_format($available, 2) . ".",
                        400
                    );
                }

                $payoutRecord = Database::get()->createPayoutRequest(
                    (string)$currentUser['id'],
                    (string)$payoutFacility['id'],
                    $amount,
                    $method,
                    "Disbursement to: {$accountName}, {$accountNumber}"
                );

                return $this->jsonSuccess(
                    ['payout' => $payoutRecord],
                    "Payout request for ₱" . number_format($amount, 2) . " via {$method} submitted for processing."
                );

            default:
                if ($request->header('X-Requested-With') === 'XMLHttpRequest' || $request->input('ajax') === '1') {
                    return Response::json(['success' => true, 'message' => 'Action processed successfully.']);
                }
                return Response::redirect('owner.php?notice=success');
        }
    }
}
