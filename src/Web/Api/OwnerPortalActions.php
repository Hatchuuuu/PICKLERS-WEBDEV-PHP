<?php
declare(strict_types=1);

namespace Picklers\Web\Api;

use Picklers\Core\Database;
use Picklers\Domain\Payments;
use Picklers\Services\TournamentService;
use Picklers\Web\Http\LegacyInput;
use Picklers\Web\Http\LegacyResponses;
use Picklers\Web\Service\OwnerScope;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Owner portal writes (POST owner.php?action=…): courts, Open Play hosting,
 * facility settings, staff and payouts. Every write resolves its facility with
 * the portal rule (owned or staffed; see OwnerScope::portalFacility()).
 */
final class OwnerPortalActions
{
    use LegacyResponses;

    /** Upper bound for an Open Play session; also what "Unlimited Players" means. */
    public const OPEN_PLAY_MAX_CAPACITY = 200;

    /** Disbursement channels the payout form offers. */
    public const PAYOUT_METHODS = [
        'GCash',
        'GCash (Instant Disbursement)',
        'Bank Deposit — BDO Unibank',
        'Bank Deposit — BPI',
        'Bank Deposit — UnionBank',
    ];
    public const PAYOUT_MIN_AMOUNT = 100.0;

    /** Facility logos are public images, relative to public/ like other facility images. */
    public const FACILITY_LOGO_DIR = 'uploads/facilities';
    public const FACILITY_LOGO_MAX_BYTES = 2 * 1024 * 1024;

    public function __construct(
        private readonly Database $db,
        private readonly OwnerScope $scope,
        private readonly TournamentService $tournaments,
        #[Autowire('%kernel.project_dir%/public')] private readonly string $publicDir,
    ) {
    }

    public function addCourt(LegacyInput $in, array $user): JsonResponse
    {
        $rawName = trim((string)$in->input('name', ''));
        $surface = trim((string)$in->input('surface', 'Indoor Hard'));
        $rate = max(50.0, (float)$in->input('rate', 450.0));
        $attributes = array_map('strval', (array)$in->input('attributes', []));
        if ($surface === '' || mb_strlen($surface) > 50) {
            return $this->jsonError('Please choose a valid court surface.', 400);
        }
        if ($rate > Payments::MAX_PAYABLE) {
            return $this->jsonError('That hourly rate is above the maximum allowed.', 400);
        }
        $facility = $this->facility($in, $user);
        if ($facility === null) {
            return $this->jsonError('No facility to list this court under. Please complete your owner application first.', 403);
        }

        $existingNums = [];
        foreach ($this->db->getCourtsByFacility($facility['id']) as $c) {
            if (preg_match('/\d+/', (string)($c['name'] ?? ''), $m)) {
                $existingNums[] = (int)$m[0];
            }
        }
        $nextNum = 1;
        while (in_array($nextNum, $existingNums, true)) {
            $nextNum++;
        }
        $courtNum = preg_match('/\d+/', $rawName, $m) ? (int)$m[0] : $nextNum;
        $courtName = 'Court ' . $courtNum;
        // Courts are identified by name in slot locks, rosters and Open Play; two
        // "Court 2" rows at one venue blocked or double-booked each other.
        if ($courtNum < 1 || $courtNum > 999) {
            return $this->jsonError('Court numbers must be between 1 and 999.', 400);
        }
        if (in_array($courtNum, $existingNums, true)) {
            return $this->jsonError("{$courtName} already exists at this facility. Choose a different court number.", 409);
        }
        try {
            $court = $this->db->insertCourt([
                'facility_id' => $facility['id'],
                'name' => $courtName,
                'surface' => $surface,
                'type' => 'Indoor',
                'price' => $rate,
                'status' => 'available',
            ]);
            $this->db->setCourtAttributes((string)$court['id'], $attributes);
            $court['attribute_slugs'] = $attributes;
        } catch (\Throwable $e) {
            return $this->jsonError('Failed to add court. Please try again.', 500);
        }
        if (empty($court)) {
            return $this->jsonError('Failed to add court. Please try again.', 500);
        }

        return $this->jsonSuccess(['court' => $court], "Court '{$courtName}' has been added to your facility inventory.");
    }

    public function editCourt(LegacyInput $in, array $user): JsonResponse
    {
        $courtId = trim((string)$in->input('court_id', ''));
        $rawName = trim((string)$in->input('name', ''));
        $surface = trim((string)$in->input('surface', 'Premium Hard'));
        $rate = max(50.0, (float)$in->input('rate', 450.0));
        $attributes = array_map('strval', (array)$in->input('attributes', []));
        if ($courtId === '') {
            return $this->jsonError('Missing court ID', 400);
        }
        if ($surface === '' || mb_strlen($surface) > 50) {
            return $this->jsonError('Please choose a valid court surface.', 400);
        }
        if ($rate > Payments::MAX_PAYABLE) {
            return $this->jsonError('That hourly rate is above the maximum allowed.', 400);
        }
        $existing = $this->db->getCourtById($courtId);
        if ($existing === null) {
            return $this->jsonError('Court not found', 404);
        }
        if (!$this->ownsCourt($user, $courtId)) {
            return $this->jsonError('Unauthorized: You do not own this court', 403);
        }
        if (!preg_match('/\d+/', $rawName, $m)) {
            // A number-less name would normalise to "Court 1", colliding with the real one.
            return $this->jsonError('Court names must include a court number (for example "Court 3").', 400);
        }
        $courtName = 'Court ' . (int)$m[0];

        $previousName = (string)($existing['name'] ?? '');
        if (strcasecmp($previousName, $courtName) !== 0) {
            foreach ($this->db->getCourtsByFacility($existing['facility_id']) as $other) {
                if ((string)($other['id'] ?? '') !== $courtId && strcasecmp((string)($other['name'] ?? ''), $courtName) === 0) {
                    return $this->jsonError("{$courtName} already exists at this facility. Choose a different court number.", 409);
                }
            }
            // Reservations and Open Play sessions reference the court by its current name.
            if ($this->db->countUpcomingCourtBookings($existing['facility_id'], $courtId, $previousName) > 0) {
                return $this->jsonError("{$previousName} has upcoming bookings, so it can't be renamed right now.", 409);
            }
            if ($this->hostsOpenPlay($existing['facility_id'], $previousName)) {
                return $this->jsonError("{$previousName} is hosting Open Play, so it can't be renamed right now.", 409);
            }
        }
        try {
            $court = $this->db->updateCourt($courtId, ['name' => $courtName, 'surface' => $surface, 'price' => $rate]);
            if ($court !== null) {
                // Replaces the set, so unchecking every tag really clears them.
                $this->db->setCourtAttributes($courtId, $attributes);
                $court['attribute_slugs'] = $attributes;
            }
        } catch (\Throwable $e) {
            return $this->jsonError('Failed to update court. Please try again.', 500);
        }
        if ($court === null) {
            return $this->jsonError('Court not found', 404);
        }

        return $this->jsonSuccess(['court' => $court], "Court '{$courtName}' updated successfully.");
    }

    public function toggleCourtStatus(LegacyInput $in, array $user): JsonResponse
    {
        $courtId = trim((string)$in->input('court_id', ''));
        $courtName = trim((string)$in->input('name', ''));
        $active = (bool)$in->input('active', false);
        if ($courtId === '') {
            return $this->jsonError('Missing court ID', 400);
        }
        if (!$this->ownsCourt($user, $courtId)) {
            return $this->jsonError('Unauthorized: You do not own this court', 403);
        }
        $court = $this->db->getCourtById($courtId);
        if ($court === null) {
            return $this->jsonError('Court not found', 404);
        }
        // A court hosting an active Open Play session cannot be disabled.
        $label = (string)($court['name'] ?? $courtName);
        if (!$active && $this->hostsOpenPlay($court['facility_id'], $label)) {
            return $this->jsonError("Court '{$label}' is currently hosting Open Play and cannot be disabled.", 400);
        }
        try {
            $ok = $this->db->updateCourtStatus($courtId, $active ? 'AVAILABLE' : 'UNAVAILABLE');
        } catch (\Throwable $e) {
            return $this->jsonError('Failed to update court status. Please try again.', 500);
        }
        if (!$ok) {
            return $this->jsonError('Court not found', 404);
        }

        return $this->jsonSuccess(['active' => $active], $active
            ? "Court '{$courtName}' is now active and open for reservations."
            : "Court '{$courtName}' has been disabled.");
    }

    /** Flags the active booking itself: dashboard occupancy is computed from bookings. */
    public function endCourtSession(LegacyInput $in, array $user): JsonResponse
    {
        $courtId = trim((string)$in->input('court_id', ''));
        $courtName = trim((string)$in->input('court_name', ''));
        if ($courtId === '' && $courtName === '') {
            return $this->jsonError('Missing court identifier', 400);
        }
        if ($courtId !== '' && !$this->ownsCourt($user, $courtId)) {
            return $this->jsonError('Unauthorized: You do not own this court', 403);
        }
        $facility = $this->facility($in, $user);
        if ($facility === null) {
            return $this->jsonError('No facility found for this account.', 400);
        }
        $result = $this->db->endCourtSessionEarly((string)$facility['id'], $courtId, $courtName);
        if (!$result['success']) {
            return $this->jsonError($result['message'], 404);
        }

        return $this->jsonSuccess([], $result['message']);
    }

    public function deleteCourt(LegacyInput $in, array $user): JsonResponse
    {
        $courtId = trim((string)$in->input('court_id', ''));
        $courtName = trim((string)$in->input('name', ''));
        if ($courtId === '') {
            return $this->jsonError('Missing court ID', 400);
        }
        if (!$this->ownsCourt($user, $courtId)) {
            return $this->jsonError('Unauthorized: You do not own this court', 403);
        }
        $court = $this->db->getCourtById($courtId);
        if ($court === null) {
            return $this->jsonError('Court not found or could not be deleted.', 404);
        }
        $label = (string)($court['name'] ?? $courtName);
        if ($this->hostsOpenPlay($court['facility_id'], $label)) {
            return $this->jsonError("Court '{$label}' is currently hosting Open Play and cannot be deleted until the session is cancelled.", 400);
        }
        // Players hold paid slots on a court with upcoming reservations.
        $upcoming = $this->db->countUpcomingCourtBookings($court['facility_id'], $courtId, $label);
        if ($upcoming > 0) {
            return $this->jsonError("Court '{$label}' has {$upcoming} upcoming booking(s). Resolve them before deleting this court.", 409);
        }
        try {
            $deleted = $this->db->deleteCourt($courtId);
        } catch (\Throwable $e) {
            return $this->jsonError('Failed to delete court. Please try again.', 500);
        }
        if (!$deleted) {
            return $this->jsonError('Court not found or could not be deleted.', 404);
        }

        return $this->jsonSuccess(['court_id' => $courtId], "Court '{$courtName}' has been permanently deleted.");
    }

    public function hostOpenPlay(LegacyInput $in, array $user): JsonResponse
    {
        $title = trim((string)$in->input('title', '')) ?: 'Community Dink Session';
        if (mb_strlen($title) > 150) {
            return $this->jsonError('Session titles are limited to 150 characters.', 400);
        }
        // `matches.type` carries the court name (see joinMatch()).
        $courtName = Database::normalizeCourtName(trim((string)$in->input('court_name', '')));
        $bracket = trim((string)$in->input('bracket', 'All Levels'));
        if ($bracket === '' || mb_strlen($bracket) > 30) {
            $bracket = 'All Levels';
        }
        $fee = (float)$in->input('fee', 250.0);
        $rawCapacity = strtolower(trim((string)$in->input('capacity', '12')));
        $capacity = $rawCapacity === 'unlimited' ? self::OPEN_PLAY_MAX_CAPACITY : (int)$rawCapacity;
        $date = trim((string)$in->input('date', ''));
        $startTime = trim((string)$in->input('start_time', ''));
        $endTime = trim((string)$in->input('end_time', ''));
        if ($fee < 0 || $fee > Payments::MAX_PAYABLE) {
            return $this->jsonError('Please enter a valid entry fee.', 400);
        }
        if ($capacity < 2 || $capacity > self::OPEN_PLAY_MAX_CAPACITY) {
            return $this->jsonError('Player capacity must be between 2 and ' . self::OPEN_PLAY_MAX_CAPACITY . '.', 400);
        }
        if ($startTime === '' || $endTime === '') {
            return $this->jsonError('Please choose a start and end time.', 400);
        }
        $timeRange = "{$startTime} \u{2013} {$endTime}";
        if ($this->db->parseTimeRange($timeRange) === null) {
            return $this->jsonError('Please choose a valid time range.', 400);
        }
        // A "TBD" or unparseable date published a session that could never expire.
        if (in_array(strtolower($date), ['everyday', 'daily'], true)) {
            $date = 'Everyday';
        } else {
            $dateTs = $date !== '' ? strtotime($date) : false;
            if ($dateTs === false) {
                return $this->jsonError('Please choose a valid session date.', 400);
            }
            if (date('Y-m-d', $dateTs) < date('Y-m-d')) {
                return $this->jsonError('Open Play sessions cannot be scheduled in the past.', 400);
            }
        }
        $facility = $this->facility($in, $user);
        if ($facility === null) {
            return $this->jsonError('No facility to host this session at. Please complete your owner application first.', 403);
        }
        $facilityId = $facility['id'];

        $court = null;
        foreach ($this->db->getCourtsByFacility($facilityId) as $c) {
            if (strcasecmp((string)($c['name'] ?? ''), $courtName) === 0) {
                $court = $c;
                break;
            }
        }
        if ($court === null) {
            return $this->jsonError("{$courtName} is not a court at this facility.", 404);
        }
        if (in_array(strtolower((string)($court['status'] ?? '')), ['maintenance', 'unavailable'], true)) {
            return $this->jsonError("{$courtName} is disabled. Enable it before hosting Open Play.", 409);
        }
        if (($blocked = $this->db->bookingBlockReason($facilityId, (string)($court['id'] ?? ''), $courtName)) !== null) {
            return $this->jsonError($blocked, 409);
        }
        // Publishing never replaces a session players have joined: that must be
        // cancelled explicitly first, which refunds and notifies them.
        foreach ($this->db->getMatchesByFacility($facilityId) as $m) {
            if (strcasecmp(trim((string)($m['type'] ?? '')), $courtName) !== 0 || Database::isMatchExpired($m)) {
                continue;
            }
            if ($this->db->countActiveMatchBookings((string)$m['id'], Database::getMatchTargetDate($m)) > 0) {
                return $this->jsonError("{$courtName} already has an Open Play session with players. Cancel it before hosting a new one.", 409);
            }
        }
        try {
            // Clears this court's earlier sessions (ended, or nobody joined).
            $this->db->cancelOpenPlaySessions('', $facilityId, $courtName);
            $match = $this->db->insertMatch([
                'facility_id' => $facilityId,
                'facility_name' => $facility['name'] ?? '',
                'location' => $facility['location'] ?? '',
                'date' => $date,
                'time' => $timeRange,
                'level' => $bracket,
                'current_players' => 0,
                'max_players' => $capacity,
                'price' => round($fee, 2),
                'type' => $courtName,
                // Rendered to players, so it carries the host's real name, not the title.
                'host' => $user['name'] ?? 'Facility Staff',
                'title' => $title,
            ]);
            $this->db->occupyCourt((int)$facilityId, $courtName, 'Hosted Open Play', $timeRange);
        } catch (\Throwable $e) {
            error_log('[PICKLERS Owner] host_open_play failed: ' . $e->getMessage());

            return $this->jsonError('Failed to publish Open Play session. Please try again.', 500);
        }
        if (empty($match)) {
            return $this->jsonError('Failed to publish Open Play session. Please try again.', 500);
        }

        return $this->jsonSuccess(['session' => $match], "Open Play event '{$title}' published successfully!");
    }

    public function cancelOpenPlay(LegacyInput $in, array $user): JsonResponse
    {
        $matchId = trim((string)$in->input('match_id', ''));
        $courtName = trim((string)$in->input('court_name', ''));
        $target = $matchId !== '' ? $matchId : trim((string)$in->input('title', ''));
        if ($target === '' && $courtName === '') {
            return $this->jsonError('Missing session identifier', 400);
        }
        $facility = $this->facility($in, $user);
        if ($facility === null) {
            return $this->jsonError('Facility not found', 403);
        }
        try {
            $outcome = $this->db->cancelOpenPlaySessions($target, $facility['id'], $courtName);
        } catch (\Throwable $e) {
            return $this->jsonError('Could not cancel this session. Nothing was changed — please try again.', 500);
        }
        if ($courtName !== '') {
            $this->db->clearCourtSession($courtName, (int)$facility['id']);
        }
        if (!$outcome['deleted']) {
            return $this->jsonError('No active Open Play session was found to cancel.', 404);
        }
        $message = 'Open Play session cancelled.';
        if ($outcome['cancelled_bookings'] > 0) {
            $message .= ' ' . $outcome['cancelled_bookings'] . ' player request(s) cancelled and notified'
                . ($outcome['refunded_amount'] > 0 ? '; ₱' . number_format($outcome['refunded_amount'], 2) . ' refunded to Pickle Credits.' : '.');
        }

        return $this->jsonSuccess($outcome, $message);
    }

    public function updateFacilitySettings(LegacyInput $in, array $user): JsonResponse
    {
        $facility = $this->facility($in, $user);
        if ($facility === null) {
            return $this->jsonError('No facility context found. Please complete owner onboarding first.', 403);
        }
        $name = trim((string)$in->input('facility_name', $in->input('name', '')));
        $location = trim((string)$in->input('facility_location', $in->input('location', '')));
        $hours = trim((string)$in->input('hours', ''));
        $image = trim((string)$in->input('image', $in->input('facility_image', $in->input('logo', ''))));
        if ($name === '') {
            return $this->jsonError('Facility name cannot be empty.', 400);
        }
        // facilities.name/location/hours are VARCHAR(150/200/50).
        if (mb_strlen($name) > 150 || mb_strlen($location) > 200 || mb_strlen($hours) > 50) {
            return $this->jsonError('Facility name (150), location (200) or hours (50 characters) is too long.', 400);
        }
        if ($image !== '' && (strlen($image) > 65000
            || !preg_match('#^(https?://|data:image/(png|jpe?g|webp);base64,|assets/|uploads/facilities/)#i', $image))) {
            return $this->jsonError('Unsupported facility image.', 400);
        }
        $update = ['name' => $name] + array_filter(['location' => $location, 'hours' => $hours, 'image' => $image], static fn($v) => $v !== '');

        // Payout numbers must be complete PH mobile numbers, checked before the
        // logo is stored so a rejected number leaves no orphaned upload. Blank clears.
        foreach (['gcash_number' => 'GCash', 'maya_number' => 'Maya'] as $field => $label) {
            $raw = $in->input($field, null);
            if ($raw === null) {
                continue;
            }
            $digits = (string)preg_replace('/\D/', '', (string)$raw);
            if ($digits !== '' && !preg_match('/^09\d{9}$/', $digits)) {
                return $this->jsonError("Please enter a valid 11-digit {$label} number starting with 09.", 400);
            }
            $update[$field] = substr($digits, 0, 11);
        }

        $storedLogo = null;
        $logo = $in->request->files->get('logo');
        if ($logo instanceof UploadedFile && $logo->getError() !== UPLOAD_ERR_NO_FILE) {
            $storedLogo = $this->storeLogo($logo, (string)$facility['id']);
            if ($storedLogo === null) {
                return $this->jsonError('Please upload a PNG, JPG or WEBP logo under 2MB.', 400);
            }
            $update['image'] = $storedLogo;
        }
        foreach (['gcash_enabled', 'maya_enabled', 'cash_on_site'] as $flag) {
            if ($in->input($flag, null) !== null) {
                $update[$flag] = filter_var($in->input($flag), FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
            }
        }

        $logoDir = $this->publicDir . '/' . self::FACILITY_LOGO_DIR . '/';
        if (!$this->db->updateFacility($facility['id'], $update)) {
            if ($storedLogo !== null) {
                @unlink($logoDir . basename($storedLogo));
            }

            return $this->jsonError('Failed to update facility settings. Please try again.', 500);
        }
        $previous = (string)($facility['image'] ?? '');
        if ($storedLogo !== null && $previous !== $storedLogo && str_starts_with($previous, self::FACILITY_LOGO_DIR . '/')) {
            @unlink($logoDir . basename($previous));
        }

        return $this->jsonSuccess(['facility' => $this->db->getFacility($facility['id'])], 'Facility profile & settings updated successfully!');
    }

    public function openPlayRoster(LegacyInput $in, array $user): JsonResponse
    {
        $courtName = trim((string)$in->input('court_name', $in->query('court_name', '')));
        $courtId = trim((string)$in->input('court_id', $in->query('court_id', '')));
        $facility = $this->facility($in, $user);
        if ($facility === null) {
            return $this->jsonError('No facility context found.', 403);
        }

        return $this->jsonSuccess(['roster' => $this->db->getOpenPlayRoster($facility['id'], $courtName, $courtId), 'court_name' => $courtName]);
    }

    /** Older clients post here; api?action=create_tournament is current. Same service, same validation. */
    public function createTournament(LegacyInput $in, array $user): JsonResponse
    {
        $facility = $this->facility($in, $user);
        if ($facility === null) {
            return $this->jsonError('Complete your owner application before hosting a tournament.', 403);
        }
        $created = $this->tournaments->create([
            'facility_id' => (string)$facility['id'],
            'owner_id' => (string)$user['id'],
            'title' => trim((string)$in->input('title', '')),
            'category' => (string)$in->input('category', $in->input('type', '')),
            'format' => (string)$in->input('format', 'single_elimination'),
            'pairing_mode' => (string)$in->input('pairing_mode', 'fixed'),
            'max_teams' => $in->input('max_teams', 16),
            'prize_pool' => (string)$in->input('prize_pool', ''),
            'entry_fee' => (string)$in->input('entry_fee', ''),
            'date' => (string)$in->input('date', ''),
            'end_date' => (string)$in->input('end_date', ''),
        ]);
        if ($created['error'] !== null) {
            return $this->jsonError($created['error'], 422);
        }

        return $this->jsonSuccess(['tournament' => $created['tournament']], 'Tournament "' . $created['tournament']['title'] . '" published successfully!');
    }

    public function addStaff(LegacyInput $in, array $user): JsonResponse
    {
        $name = trim((string)$in->input('name', ''));
        $email = trim((string)$in->input('email', ''));
        $role = (string)$in->input('role', 'Front Desk');
        if ($name === '') {
            return $this->jsonError('Staff name is required', 400);
        }
        if (mb_strlen($name) > 100 || mb_strlen($role) > 50 || strlen($email) > 150) {
            return $this->jsonError('Staff name (100), role (50) or email (150 characters) is too long.', 400);
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->jsonError('Please enter a valid email address', 400);
        }
        $facility = $this->facility($in, $user);
        if ($facility === null) {
            return $this->jsonError('No facility to add this staff member to. Please complete your owner application first.', 403);
        }
        try {
            $staff = $this->db->insertStaff([
                'facility_id' => $facility['id'],
                'name' => $name,
                'email' => $email,
                'role' => $role,
                'status' => 'Active',
                'date_joined' => date('M Y'),
            ]);
        } catch (\Throwable $e) {
            return $this->jsonError('Failed to add staff member. Please try again.', 500);
        }
        if (empty($staff)) {
            return $this->jsonError('Failed to add staff member. Please try again.', 500);
        }

        return $this->jsonSuccess(['staff' => $staff], "Staff member {$name} ({$role}) added to facility roster.");
    }

    public function revokeStaff(LegacyInput $in, array $user): JsonResponse
    {
        $staffId = trim((string)$in->input('staff_id', ''));
        if ($staffId === '') {
            return $this->jsonError('Missing staff ID', 400);
        }
        if (empty($user['is_admin']) && !$this->db->verifyStaffOwner($staffId, (string)$user['id'])) {
            return $this->jsonError('Unauthorized: You do not manage this staff member', 403);
        }
        try {
            $ok = $this->db->deleteStaff($staffId);
        } catch (\Throwable $e) {
            return $this->jsonError('Failed to revoke staff access. Please try again.', 500);
        }
        if (!$ok) {
            return $this->jsonError('Staff member not found', 404);
        }

        return $this->jsonSuccess([], 'Staff access revoked successfully.');
    }

    /**
     * The request text becomes the instruction someone follows to send real money,
     * so only offered channels are accepted. Balance check and insert run together
     * under a per-facility lock, net of payouts already requested.
     */
    public function requestPayout(LegacyInput $in, array $user): JsonResponse
    {
        $amount = round((float)$in->input('amount', 0.0), 2);
        $method = trim((string)$in->input('method', ''));
        $accountName = trim((string)$in->input('account_name', ''));
        $accountNumber = trim((string)$in->input('account_number', ''));
        if ($amount < self::PAYOUT_MIN_AMOUNT) {
            return $this->jsonError('The minimum payout is ₱' . number_format(self::PAYOUT_MIN_AMOUNT, 2) . '.', 400);
        }
        if (!in_array($method, self::PAYOUT_METHODS, true)) {
            return $this->jsonError('Please choose a supported disbursement method.', 400);
        }
        if ($accountName === '' || $accountNumber === '') {
            return $this->jsonError('Account name and account/mobile number are required.', 400);
        }
        if (mb_strlen($accountName) > 120 || !preg_match('/^[0-9\s\-]{6,34}$/', $accountNumber)) {
            return $this->jsonError('Please enter a valid account name and account/mobile number.', 400);
        }
        $facility = $this->facility($in, $user);
        if ($facility === null) {
            return $this->jsonError('No facility found for this account.', 400);
        }
        $payout = $this->db->createPayoutRequest((string)$user['id'], (string)$facility['id'], $amount, $method, "Disbursement to: {$accountName}, {$accountNumber}");
        if (empty($payout['success'])) {
            return $this->jsonError((string)($payout['message'] ?? 'Payout request could not be submitted.'), 400);
        }

        return $this->jsonSuccess(['payout' => $payout['payout']], 'Payout request for ₱' . number_format($amount, 2) . " via {$method} submitted for processing.");
    }

    private function facility(LegacyInput $in, array $user): ?array
    {
        return $this->scope->portalFacility($user, (string)$in->input('facility_id', ''));
    }

    private function ownsCourt(array $user, string $courtId): bool
    {
        return !empty($user['is_admin']) || $this->db->verifyCourtOwner($courtId, (string)$user['id']);
    }

    /** An unexpired Open Play session on exactly this court ("Court 1" never matches "Court 10"). */
    private function hostsOpenPlay(int|string $facilityId, string $courtLabel): bool
    {
        foreach ($this->db->getMatchesByFacility($facilityId) as $m) {
            if (!Database::isMatchExpired($m) && strcasecmp(trim((string)($m['type'] ?? '')), $courtLabel) === 0) {
                return true;
            }
        }

        return false;
    }

    /** Validate and store an uploaded facility logo; its public path, or null when rejected. */
    private function storeLogo(UploadedFile $file, string $facilityId): ?string
    {
        if (!$file->isValid() || $file->getSize() <= 0 || $file->getSize() > self::FACILITY_LOGO_MAX_BYTES) {
            return null;
        }
        $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file->getPathname()) ?: ''; // the bytes, not the client's claim
        if (!isset($extensions[$mime]) || @getimagesize($file->getPathname()) === false) {
            return null;
        }
        $name = 'facility_' . preg_replace('/[^0-9A-Za-z]/', '', $facilityId) . '_' . bin2hex(random_bytes(8)) . '.' . $extensions[$mime];
        try {
            $file->move($this->publicDir . '/' . self::FACILITY_LOGO_DIR, $name);
        } catch (\Throwable $e) {
            return null;
        }

        return self::FACILITY_LOGO_DIR . '/' . $name;
    }
}
