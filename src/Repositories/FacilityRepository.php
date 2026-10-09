<?php
declare(strict_types=1);

namespace Picklers\Repositories;

/**
 * Facilities, courts (with their live occupancy), court attributes and favourites.
 * Facility and court reads are cached for the request; every write clears the cache.
 */
final class FacilityRepository implements \Symfony\Contracts\Service\ResetInterface
{
    private readonly \Doctrine\DBAL\Connection $db;
    private readonly \Picklers\Domain\Notifier $notifier;
    private readonly \Picklers\Persistence\SchemaInstaller $schema;
    private readonly \Picklers\Repositories\MatchRepository $matches;

    public function __construct(
        ?\Doctrine\DBAL\Connection $db = null,
        ?\Picklers\Domain\Notifier $notifier = null,
        ?\Picklers\Persistence\SchemaInstaller $schema = null,
        ?\Picklers\Repositories\MatchRepository $matches = null,
    ) {
        $this->db = $db ?? \Picklers\Core\Database::get()->dbal();
        $this->notifier = $notifier ?? \Picklers\Core\Database::get()->notifier();
        $this->schema = $schema ?? new \Picklers\Persistence\SchemaInstaller($this->db);
        $this->matches = $matches ?? new \Picklers\Repositories\MatchRepository($this->db);
    }

    /**
     * Request-scoped read cache for facility/court lookups.
     *
     * These are read many times per request (listing, detail, and once per
     * booking via PricingService) but change rarely. Scoping the cache to a
     * single request keeps it from serving stale data across requests, and any
     * write invalidates it immediately (reset() clears it between requests in
     * long-running processes).
     *
     * @var array<string,mixed>
     */
    private array $readCache = [];

    /** Drop cached facility/court reads after any write that could change them. */
    public function invalidateReadCache(): void {
        $this->readCache = [];
    }

    public function reset(): void {
        $this->invalidateReadCache();
    }

    public function getCourtImagesByFacility($facilityId): array {
        $cacheKey = 'images:' . (string)$facilityId;
        if (array_key_exists($cacheKey, $this->readCache)) {
            return $this->readCache[$cacheKey];
        }
        $stmt = $this->db->executeQuery("SELECT * FROM court_images WHERE facility_id = ? ORDER BY is_primary DESC, sort_order ASC, id ASC", [$facilityId]);
        $result = $stmt->fetchAllAssociative();
        $this->readCache[$cacheKey] = $result;
        return $result;
    }

    public function getFacilityAmenities($facilityId): array {
        $cacheKey = 'amenities:' . (string)$facilityId;
        if (array_key_exists($cacheKey, $this->readCache)) {
            return $this->readCache[$cacheKey];
        }
        $stmt = $this->db->executeQuery(
            "SELECT a.* FROM amenities a
             JOIN facility_amenities fa ON a.id = fa.amenity_id
             WHERE fa.facility_id = ?
             ORDER BY a.id ASC",
            [$facilityId]
        );
        $result = $stmt->fetchAllAssociative();
        $this->readCache[$cacheKey] = $result;
        return $result;
    }

    /** The fixed catalog every court attribute is drawn from, in display order. */
    public function getCourtAttributeCatalog(): array {
        $cacheKey = 'court_attribute_catalog';
        if (array_key_exists($cacheKey, $this->readCache)) {
            return $this->readCache[$cacheKey];
        }
        $result = $this->db->executeQuery("SELECT * FROM court_attributes ORDER BY sort_order ASC")->fetchAllAssociative();
        $this->readCache[$cacheKey] = $result;
        return $result;
    }

    /** A single court's own attribute rows (slug/label/icon/kind), in display order. */
    public function getCourtAttributes(string $courtId): array {
        $cacheKey = 'court_attrs:' . $courtId;
        if (array_key_exists($cacheKey, $this->readCache)) {
            return $this->readCache[$cacheKey];
        }
        $stmt = $this->db->executeQuery(
            "SELECT a.slug, a.label, a.icon, a.kind FROM court_attributes a
             JOIN court_attribute_map m ON m.attribute_id = a.id
             WHERE m.court_id = ?
             ORDER BY a.sort_order ASC",
            [$courtId]
        );
        $result = $stmt->fetchAllAssociative();
        $this->readCache[$cacheKey] = $result;
        return $result;
    }

    /**
     * Every court at a facility, each with its own attribute rows attached
     * (as 'attributes' and a flat 'attribute_slugs' list) — one query for
     * the courts, one for every attribute across all of them, never one
     * query per court.
     */
    public function getCourtsWithAttributes(int|string $facilityId): array {
        $courts = $this->getCourtsByFacility($facilityId);
        if ($courts === []) {
            return [];
        }

        $ids = array_column($courts, 'id');
        $byCourt = [];

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->executeQuery(
            "SELECT m.court_id, a.slug, a.label, a.icon, a.kind FROM court_attribute_map m
             JOIN court_attributes a ON a.id = m.attribute_id
             WHERE m.court_id IN ($placeholders)
             ORDER BY a.sort_order ASC",
            $ids
        );
        foreach ($stmt->fetchAllAssociative() as $row) {
            $byCourt[$row['court_id']][] = ['slug' => $row['slug'], 'label' => $row['label'], 'icon' => $row['icon'], 'kind' => $row['kind']];
        }

        foreach ($courts as &$c) {
            $c['attributes'] = $byCourt[$c['id']] ?? [];
            $c['attribute_slugs'] = array_column($c['attributes'], 'slug');
        }
        unset($c);

        usort($courts, function($a, $b) {
            return strnatcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
        });

        return $courts;
    }

    /**
     * Replace a court's attribute set atomically. Unknown slugs are
     * silently ignored rather than erroring — a stale client sending a slug
     * this deployment's catalog doesn't recognise should not break the rest
     * of a legitimate edit.
     *
     * @param string[] $slugs
     */
    public function setCourtAttributes(string $courtId, array $slugs): void {
        $this->invalidateReadCache();
        $this->notifier->bumpSync('courts', 'facilities'); // attribute chips render on Discover's facility card

        $this->db->beginTransaction();
        try {
            $this->db->executeStatement("DELETE FROM court_attribute_map WHERE court_id = ?", [$courtId]);

            if ($slugs !== []) {
                $placeholders = implode(',', array_fill(0, count($slugs), '?'));
                $stmt = $this->db->executeQuery("SELECT id FROM court_attributes WHERE slug IN ($placeholders)", array_values($slugs));
                $attrIds = $stmt->fetchFirstColumn();

                $insSql = "INSERT IGNORE INTO court_attribute_map (court_id, attribute_id) VALUES (?, ?)";
                foreach ($attrIds as $attrId) {
                    $this->db->executeStatement($insSql, [$courtId, $attrId]);
                }
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Discover-tab filtering by court attributes. HAVING COUNT(DISTINCT ...)
     * = count($slugs) gives AND semantics — a facility must have a court
     * carrying EVERY requested tag (not necessarily the SAME court for all
     * of them, which matches how the filter UI presents this: "show venues
     * with an Outdoor court and a Covered court", not "one court that is
     * both").
     *
     * @param string[] $slugs
     */
    public function getFacilitiesByAttributes(array $slugs, string $search = ''): array {
        $slugs = array_values(array_unique(array_filter($slugs)));
        if ($slugs === []) {
            return $this->getFacilities($search);
        }

        $placeholders = implode(',', array_fill(0, count($slugs), '?'));
        $params = $slugs;

        $sql = "SELECT f.*,
                       COUNT(DISTINCT c.id) AS real_courts_count,
                       COALESCE(MIN(c.price), f.price_numeric) AS min_price,
                       COALESCE(MAX(c.price), f.price_numeric) AS max_price
                  FROM facilities f
                  JOIN courts c              ON c.facility_id = f.id
                  JOIN court_attribute_map m ON m.court_id = c.id
                  JOIN court_attributes a    ON a.id = m.attribute_id
                 WHERE a.slug IN ($placeholders)";

        if ($search !== '') {
            $sql .= " AND (f.name LIKE ? OR f.location LIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }

        $sql .= " GROUP BY f.id HAVING COUNT(DISTINCT a.slug) = ? ORDER BY f.rating DESC, f.id ASC";
        $params[] = count($slugs);

        $stmt = $this->db->executeQuery($sql, $params);
        $rows = $stmt->fetchAllAssociative();
        foreach ($rows as &$r) {
            if (isset($r['real_courts_count']) && (int)$r['real_courts_count'] > 0) {
                $r['courts_count'] = (int)$r['real_courts_count'];
            }
        }
        unset($r);
        return $rows;
    }

    public function getFacilities($search = '', $type = 'All', $sort = 'recommended') {
        $cacheKey = 'facilities:' . $search . '|' . $type . '|' . $sort;
        if (array_key_exists($cacheKey, $this->readCache)) {
            return $this->readCache[$cacheKey];
        }
        $result = $this->getFacilitiesUncached($search, $type, $sort);
        $this->readCache[$cacheKey] = $result;
        return $result;
    }

    private function getFacilitiesUncached($search = '', $type = 'All', $sort = 'recommended') {
        $sql = "SELECT f.*, 
                       COUNT(DISTINCT c.id) as real_courts_count,
                       COALESCE(MIN(c.price), f.price_numeric) as min_price, 
                       COALESCE(MAX(c.price), f.price_numeric) as max_price,
                       COALESCE(AVG(c.price), f.price_numeric) as avg_price
                FROM facilities f
                LEFT JOIN courts c ON f.id = c.facility_id
                WHERE f.is_verified = 1 AND f.image IS NOT NULL AND f.image != ''"
                // Facilities suspended by Picklers admins are not offered to players.
                . ($this->schema->hasColumn('facilities', 'operating_status') ? " AND f.operating_status <> 'suspended'" : '');
        $params = [];
        if (!empty($search)) {
            $sql .= " AND (f.name LIKE ? OR f.location LIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }
        if ($type !== 'All') {
            $sql .= " AND f.type LIKE ?";
            $params[] = "%$type%";
        }
        $sql .= " GROUP BY f.id";
        if ($sort === 'price_asc') {
            $sql .= " ORDER BY min_price ASC";
        } elseif ($sort === 'rating_desc') {
            $sql .= " ORDER BY f.rating DESC";
        } else {
            $sql .= " ORDER BY f.id ASC";
        }
        $stmt = $this->db->executeQuery($sql, $params);
        $rows = $stmt->fetchAllAssociative();
        foreach ($rows as &$r) {
            if (isset($r['real_courts_count']) && (int)$r['real_courts_count'] > 0) {
                $r['courts_count'] = (int)$r['real_courts_count'];
            }
        }
        unset($r);
        return $rows;
    }

    public function getFacility($id) {
        $cacheKey = 'facility:' . (string)$id;
        if (array_key_exists($cacheKey, $this->readCache)) {
            return $this->readCache[$cacheKey];
        }
        $result = $this->getFacilityUncached($id);
        $this->readCache[$cacheKey] = $result;
        return $result;
    }

    private function getFacilityUncached($id) {
        $stmt = $this->db->executeQuery("SELECT f.*, 
                       COALESCE(MIN(c.price), f.price_numeric) as min_price, 
                       COALESCE(MAX(c.price), f.price_numeric) as max_price
                FROM facilities f
                LEFT JOIN courts c ON f.id = c.facility_id
                WHERE f.id = ?
                GROUP BY f.id", [$id]);
        return $stmt->fetchAssociative() ?: null;
    }

    public function updateFacility(int|string $facilityId, array $fields): bool {
        $allowed = [
            'name', 'location', 'hours', 'type', 'transit', 'image', 'price', 'price_numeric',
            'gcash_number', 'maya_number', 'gcash_enabled', 'maya_enabled', 'cash_on_site',
        ];
        $clean = [];
        foreach ($fields as $k => $v) {
            if (in_array($k, $allowed, true)) {
                $clean[$k] = $v;
            }
        }
        if (empty($clean)) {
            return false;
        }

        $this->invalidateReadCache();
        $this->notifier->bumpSync('facilities');
        $facilityId = (int)$facilityId;

        $set = [];
        $vals = [];
        foreach ($clean as $k => $v) {
            $set[] = "`$k` = ?";
            $vals[] = $v;
        }
        $vals[] = $facilityId;
        $this->db->executeStatement("UPDATE facilities SET " . implode(", ", $set) . " WHERE id = ?", $vals);
        return true;
    }

    public function getCourtsByFacility($facilityId) {
        $cacheKey = 'courts:' . (string)$facilityId;
        if (array_key_exists($cacheKey, $this->readCache)) {
            return $this->readCache[$cacheKey];
        }
        $result = $this->getCourtsByFacilityUncached($facilityId);
        $this->readCache[$cacheKey] = $result;
        return $result;
    }

    private function getCourtsByFacilityUncached($facilityId) {
        $stmt = $this->db->executeQuery("SELECT * FROM courts WHERE facility_id = ?", [$facilityId]);
        $courts = $stmt->fetchAllAssociative();

        $todayStr = date('Y-m-d');
        $nowMin = ((int)date('G') * 60) + (int)date('i');

        // Fetch active confirmed bookings for this facility today (excluding pending requests)
        $activeBookingsToday = [];
        $bStmt = $this->db->executeQuery(
            "SELECT b.*, u.name as user_name, u.avatar_url as user_avatar FROM bookings b
             LEFT JOIN users u ON b.user_id = u.id
             WHERE b.facility_id = ? AND b.booking_date = ?
               AND b.status IN ('confirmed', 'completed', 'upcoming')
               AND (b.ended_early = 0 OR b.ended_early IS NULL)",
            [$facilityId, $todayStr]
        );
        $activeBookingsToday = $bStmt->fetchAllAssociative();

        $matches = $this->matches->getMatchesByFacility($facilityId);

        foreach ($courts as &$c) {
            $cId = (string)($c['id'] ?? '');
            $cName = trim((string)($c['name'] ?? ''));
            $cStatusInDb = strtolower(trim((string)($c['status'] ?? 'available')));

            // Respect manual owner/admin overrides ('maintenance', 'unavailable')
            if (in_array($cStatusInDb, ['maintenance', 'unavailable'], true)) {
                $c['status'] = $cStatusInDb;
                $c['occupied_by'] = null;
                $c['occupied_until'] = null;
                continue;
            }

            $currentBooking = null;
            $nextBooking = null;
            $nextBookingStart = 99999;
            $upcomingList = [];
            $completedList = [];

            foreach ($activeBookingsToday as $b) {
                $sameCourt = false;
                if ($cId !== '' && !empty($b['court_id']) && (string)$b['court_id'] === $cId) {
                    $sameCourt = true;
                } elseif ($cName !== '' && !empty($b['court_name']) && strcasecmp(trim((string)$b['court_name']), $cName) === 0) {
                    $sameCourt = true;
                }

                if ($sameCourt) {
                    $sMin = isset($b['start_min']) && $b['start_min'] !== null ? (int)$b['start_min'] : null;
                    $eMin = isset($b['end_min']) && $b['end_min'] !== null ? (int)$b['end_min'] : null;
                    if ($sMin === null || $eMin === null) {
                        $range = \Picklers\Domain\Schedule::parseTimeRange((string)($b['time'] ?? ''));
                        if ($range) {
                            $sMin = $range[0];
                            $eMin = $range[1];
                        }
                    }

                    if ($sMin !== null && $eMin !== null) {
                        if ($nowMin >= $sMin && $nowMin < $eMin) {
                            $currentBooking = $b;
                            $currentBooking['_start_min'] = $sMin;
                            $currentBooking['_end_min'] = $eMin;
                        } elseif ($sMin > $nowMin) {
                            $upcomingList[] = [
                                'user_name' => !empty($b['user_name']) ? $b['user_name'] : ($b['author_name'] ?? 'Player'),
                                'user_avatar' => !empty($b['user_avatar']) ? $b['user_avatar'] : ($b['avatar_url'] ?? ($b['avatar'] ?? null)),
                                'time' => (string)($b['time'] ?? ''),
                                'status' => (string)($b['status'] ?? 'confirmed')
                            ];
                            if ($sMin < $nextBookingStart) {
                                $nextBooking = $b;
                                $nextBookingStart = $sMin;
                            }
                        } elseif ($eMin <= $nowMin) {
                            $completedList[] = [
                                'user_name' => !empty($b['user_name']) ? $b['user_name'] : ($b['author_name'] ?? 'Player'),
                                'user_avatar' => !empty($b['user_avatar']) ? $b['user_avatar'] : ($b['avatar_url'] ?? ($b['avatar'] ?? null)),
                                'time' => (string)($b['time'] ?? ''),
                                'status' => 'completed'
                            ];
                        }
                    }
                }
            }

            $c['upcoming_bookings'] = $upcomingList;
            $c['completed_bookings'] = $completedList;

            if ($nextBooking) {
                $c['next_booking'] = [
                    'user_name' => !empty($nextBooking['user_name']) ? $nextBooking['user_name'] : ($nextBooking['author_name'] ?? 'Player'),
                    'time' => (string)($nextBooking['time'] ?? ''),
                    'status' => (string)($nextBooking['status'] ?? 'confirmed')
                ];
            } else {
                $c['next_booking'] = null;
            }

            // Check if there is an unexpired Open Play session active on this court
            $currentMatch = null;
            if (!empty($matches)) {
                foreach ($matches as $m) {
                    if (\Picklers\Domain\Schedule::isMatchExpired($m)) continue;
                    $mType = trim((string)($m['type'] ?? ''));
                    $mCourtId = trim((string)($m['court_id'] ?? ''));
                    $mCourtName = trim((string)($m['court_name'] ?? ''));

                    $isMatchForCourt = false;
                    if ($cId !== '' && $mCourtId !== '' && $mCourtId === $cId) {
                        $isMatchForCourt = true;
                    } elseif ($cName !== '' && $mCourtName !== '' && strcasecmp($mCourtName, $cName) === 0) {
                        $isMatchForCourt = true;
                    } elseif ($cName !== '' && strcasecmp($mType, $cName) === 0) {
                        $isMatchForCourt = true;
                    } elseif (count($courts) === 1) {
                        $isMatchForCourt = true;
                    }

                    if ($isMatchForCourt) {
                        $currentMatch = $m;
                        break;
                    }
                }
            }

            if ($currentMatch) {
                $c['status'] = 'occupied';
                $c['occupied_by'] = 'Hosted Open Play';
                $c['occupied_until'] = (string)($currentMatch['time'] ?? null);
                $c['player_avatar'] = !empty($currentMatch['user_avatar']) ? $currentMatch['user_avatar'] : ($currentMatch['host_avatar'] ?? ($currentMatch['avatar_url'] ?? ($currentMatch['avatar'] ?? null)));
                $c['has_open_play'] = true;
                $c['open_play_match'] = $currentMatch;
                $c['open_play_id'] = $currentMatch['id'] ?? null;
                continue;
            }

            if ($currentBooking) {
                $c['status'] = 'occupied';
                $c['occupied_by'] = !empty($currentBooking['user_name']) ? $currentBooking['user_name'] : ($c['occupied_by'] ?? 'Reserved');
                $c['occupied_until'] = (string)($currentBooking['time'] ?? \Picklers\Domain\Schedule::minutesToLabel($currentBooking['_end_min']));
                $c['player_avatar'] = !empty($currentBooking['user_avatar']) ? $currentBooking['user_avatar'] : ($currentBooking['avatar_url'] ?? ($currentBooking['avatar'] ?? null));
                $c['start_min'] = $currentBooking['_start_min'] ?? null;
                $c['end_min'] = $currentBooking['_end_min'] ?? null;
                continue;
            }

            // Default to available if no active booking or match applies at the current time
            $c['status'] = 'available';
            $c['occupied_by'] = null;
            $c['occupied_until'] = null;
        }
        unset($c);

        usort($courts, function($a, $b) {
            return strnatcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
        });

        return $courts;
    }

    public function getAllCourts() {
        $stmt = $this->db->executeQuery("SELECT c.*, f.name as facility_name FROM courts c JOIN facilities f ON c.facility_id = f.id");
        return $stmt->fetchAllAssociative();
    }

    /**
     * Does this owner actually hold the facility a court belongs to?
     *
     * A facility with no owner set matches nobody.
     */
    public function verifyCourtOwner(string $courtId, string $ownerUserId): bool {
        $stmt = $this->db->executeQuery(
            "SELECT COUNT(*) as c FROM courts c
             JOIN facilities f ON c.facility_id = f.id
             WHERE c.id = ? AND f.owner_id = ?",
            [$courtId, $ownerUserId]
        );
        return (int)($stmt->fetchAssociative()['c'] ?? 0) > 0;
    }

    public function updateCourtStatus($courtId, string $status): bool {
        $status = \Picklers\Domain\Schedule::normalizeCourtStatus($status);
        $this->invalidateReadCache();
        $this->notifier->bumpSync('courts');
        $this->db->executeStatement("UPDATE courts SET status = ? WHERE id = ?", [$status, $courtId]);
        return true;
    }

    public function updateCourtStatusByNameOrId($facilityId, string $courtName, string $status = 'available'): bool {
        $status = \Picklers\Domain\Schedule::normalizeCourtStatus($status);
        $this->invalidateReadCache();
        $this->notifier->bumpSync('courts');
        $facilityId = (int)$facilityId;
        $this->db->executeStatement("UPDATE courts SET status = ? WHERE facility_id = ? AND (name = ? OR id = ?)", [$status, $facilityId, $courtName, $courtName]);
        return true;
    }

    public function occupyCourt($facilityId, $courtIdOrName, string $playerName, ?string $timeRange = null): bool {
        $status = 'OCCUPIED';
        $this->invalidateReadCache();
        $this->notifier->bumpSync('courts');
        $facilityId = (int)$facilityId;
        $this->db->executeStatement("UPDATE courts SET status = ?, occupied_by = ?, occupied_until = ? WHERE (id = ? OR (facility_id = ? AND name = ?))", [$status, $playerName, $timeRange, $courtIdOrName, $facilityId, $courtIdOrName]);
        return true;
    }

    public function clearCourtSession($courtId, $facilityId = null): bool {
        $status = 'AVAILABLE';
        $this->invalidateReadCache();
        $this->notifier->bumpSync('courts');
        $this->db->executeStatement("UPDATE courts SET status = ?, occupied_by = NULL, occupied_until = NULL WHERE id = ? OR (facility_id = ? AND name = ?)", [$status, $courtId, (int)$facilityId, $courtId]);
        return true;
    }

    /**
     * Ends whichever real, timed booking is currently occupying a court,
     * right now, without waiting for its original end time.
     *
     * getCourtsByFacilityUncached() derives "occupied" purely from a
     * booking's own start/end time window on every read — toggling the
     * court's own status row (the old "End Session Early" implementation)
     * touched a field nothing here ever reads for this case, so the court
     * silently went right back to "occupied" on the very next dashboard
     * load. This instead flags the actual active booking itself
     * (ended_early = 1, see the column's doc comment on the CREATE TABLE)
     * so it's excluded from that time-window match from now on. Status,
     * price, and the original time range are left untouched — the player
     * paid for and was entitled to the full slot; this is not a
     * cancellation and carries no refund.
     *
     * @return array{success:bool, message:string, booking?:array}
     */
    public function endCourtSessionEarly(int|string $facilityId, string $courtId, string $courtName): array {
        $this->invalidateReadCache();
        $todayStr = date('Y-m-d');
        $nowMin = ((int)date('G') * 60) + (int)date('i');

        $findActive = function (array $rows) use ($nowMin): ?array {
            foreach ($rows as $row) {
                $sMin = isset($row['start_min']) && $row['start_min'] !== null ? (int)$row['start_min'] : null;
                $eMin = isset($row['end_min']) && $row['end_min'] !== null ? (int)$row['end_min'] : null;
                if ($sMin === null || $eMin === null) {
                    $range = \Picklers\Domain\Schedule::parseTimeRange((string)($row['time'] ?? ''));
                    if ($range) {
                        $sMin = $range[0];
                        $eMin = $range[1];
                    }
                }
                if ($sMin !== null && $eMin !== null && $nowMin >= $sMin && $nowMin < $eMin) {
                    return $row;
                }
            }
            return null;
        };

        try {
            $this->db->beginTransaction();
            $stmt = $this->db->executeQuery(
                "SELECT * FROM bookings
                 WHERE facility_id = ? AND booking_date = ? AND status IN ('pending', 'confirmed')
                   AND (ended_early = 0 OR ended_early IS NULL)
                   AND (court_id = ? OR court_name = ?)
                 FOR UPDATE",
                [$facilityId, $todayStr, $courtId, $courtName]
            );
            $target = $findActive($stmt->fetchAllAssociative());

            if ($target === null) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'No active session found for this court right now.'];
            }

            $this->db->executeStatement("UPDATE bookings SET ended_early = 1 WHERE id = ?", [$target['id']]);
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            error_log('[DB Error] endCourtSessionEarly failed: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Could not end this session. Please try again.'];
        }

        $this->notifier->bumpSync('courts', 'bookings');
        return [
            'success' => true,
            'message' => 'Session ended. Court is free for a new booking.',
            'booking' => $target,
        ];
    }

    public function insertCourt(array $courtData): array {
        $this->invalidateReadCache();
        // 'facilities' too: Discover's facility card shows courts_count and
        // a min-max price range derived from the court list, so a new court
        // changes what that card should say without the facility row itself
        // changing.
        $this->notifier->bumpSync('courts', 'facilities');
        $id = $courtData['id'] ?? ('crt_' . bin2hex(random_bytes(4)));

        $court = [
            'id' => $id,
            'facility_id' => $courtData['facility_id'],
            'name' => \Picklers\Domain\Schedule::normalizeCourtName((string)($courtData['name'] ?? '')),
            'surface' => $courtData['surface'] ?? 'Hard Court',
            'type' => $courtData['type'] ?? 'Indoor',
            'price' => $courtData['price'] ?? 450.00,
            'status' => \Picklers\Domain\Schedule::normalizeCourtStatus((string)($courtData['status'] ?? 'available')),
            'occupied_by' => $courtData['occupied_by'] ?? null,
            'occupied_until' => $courtData['occupied_until'] ?? null
        ];

        $this->db->executeStatement(
            "INSERT INTO courts (id, facility_id, name, surface, type, price, status, occupied_by, occupied_until) VALUES (?,?,?,?,?,?,?,?,?)",
            [
                $court['id'], $court['facility_id'], $court['name'], $court['surface'],
                $court['type'], $court['price'], $court['status'], $court['occupied_by'], $court['occupied_until']
            ]
        );
        return $court;
    }

    public function updateCourt(string $courtId, array $fields): ?array {
        $this->invalidateReadCache();
        $this->notifier->bumpSync('courts', 'facilities'); // price/name changes affect the Discover card too
        $allowed = ['name', 'surface', 'type', 'price'];
        $updates = array_intersect_key($fields, array_flip($allowed));
        if (isset($updates['name'])) {
            $updates['name'] = \Picklers\Domain\Schedule::normalizeCourtName((string)$updates['name']);
        }

        if (!empty($updates)) {
            $setParts = [];
            $params = [];
            foreach ($updates as $col => $val) {
                $setParts[] = "{$col} = ?";
                $params[] = $val;
            }
            $params[] = $courtId;
            $stmt = $this->db->executeQuery("UPDATE courts SET " . implode(', ', $setParts) . " WHERE id = ?", $params);
        }
        $stmt = $this->db->executeQuery("SELECT * FROM courts WHERE id = ?", [$courtId]);
        $row = $stmt->fetchAssociative();
        return $row ?: null;
    }

    public function getCourtById(string $courtId): ?array {
        $stmt = $this->db->executeQuery("SELECT * FROM courts WHERE id = ?", [$courtId]);
        return $stmt->fetchAssociative() ?: null;
    }

    /** Pending/confirmed bookings on one court that haven't been played yet. */
    public function countUpcomingCourtBookings(int|string $facilityId, string $courtId, string $courtName): int {
        $today = date('Y-m-d');
        $stmt = $this->db->executeQuery(
            "SELECT * FROM bookings
              WHERE facility_id = ? AND status IN ('pending', 'confirmed')
                AND (booking_date IS NULL OR booking_date >= ?)
                AND (court_id = ? OR court_name = ?)",
            [$facilityId, $today, $courtId, $courtName]
        );
        $rows = $stmt->fetchAllAssociative();
        $count = 0;
        foreach ($rows as $row) {
            if (!\Picklers\Domain\Schedule::isBookingPast($row)) {
                $count++;
            }
        }
        return $count;
    }

    public function deleteCourt(string $courtId): bool {
        // Every other court write invalidates the read cache and bumps the
        // sync channels; a deleted court stayed on Discover until a reload.
        $this->invalidateReadCache();
        $this->notifier->bumpSync('courts', 'facilities');
        $stmt = $this->db->executeQuery("DELETE FROM courts WHERE id = ?", [$courtId]);
        $deleted = $stmt->rowCount() > 0;
        if ($deleted) {
            $this->db->executeStatement("DELETE FROM court_attribute_map WHERE court_id = ?", [$courtId]);
        }
        return $deleted;
    }

    /** The user id that owns a facility, or null if unassigned/not found. */
    public function getFacilityOwnerId(int|string $facilityId): ?string {
        $facility = $this->getFacility($facilityId);
        $ownerId = $facility['owner_id'] ?? null;
        return ($ownerId !== null && $ownerId !== '') ? (string)$ownerId : null;
    }

    /**
     * Toggle a player's favorite on a facility. Mirrors toggleLikePost()'s shape.
     */
    public function toggleFavoriteFacility(string $userId, int|string $facilityId): array {
        $facilityId = (int)$facilityId;
        if ($facilityId <= 0 || !$this->getFacility($facilityId)) {
            return ['success' => false, 'message' => 'Facility not found.'];
        }
        $stmt = $this->db->executeQuery("SELECT COUNT(*) as c FROM facility_favorites WHERE user_id = ? AND facility_id = ?", [$userId, $facilityId]);
        $isFavorited = ((int)($stmt->fetchAssociative()['c'] ?? 0)) > 0;
        if ($isFavorited) {
            $this->db->executeStatement("DELETE FROM facility_favorites WHERE user_id = ? AND facility_id = ?", [$userId, $facilityId]);
            $favorited = false;
        } else {
            $this->db->executeStatement("INSERT INTO facility_favorites (user_id, facility_id) VALUES (?, ?)", [$userId, $facilityId]);
            $favorited = true;
        }
        return ['success' => true, 'favorited' => $favorited];
    }

    /** @return array<int,int> facility ids this player has favorited */
    public function getFavoriteFacilityIds(string $userId): array {
        if ($userId === '') {
            return [];
        }
        $stmt = $this->db->executeQuery("SELECT facility_id FROM facility_favorites WHERE user_id = ?", [$userId]);
        return array_map('intval', array_column($stmt->fetchAllAssociative(), 'facility_id'));
    }
}
