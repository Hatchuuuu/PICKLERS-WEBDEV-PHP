<?php
declare(strict_types=1);

namespace Picklers\Services;

use Picklers\Core\Database;

/**
 * PricingService — the single server-side source of truth for money.
 *
 * Every payable amount in the platform MUST be produced here. Clients send
 * intent (which court, how long, which promo code); they never send prices.
 * Court rates are read from the courts table (falling back to the facility's
 * base rate), the service fee is applied server-side, and promo codes are
 * validated against a server-held table rather than trusted from the browser.
 */
final class PricingService {

    /** Platform service fee applied on top of the court fee. */
    public const SERVICE_FEE_RATE = 0.10;

    /** Floor for any charge, so a promo can never zero out or invert a booking. */
    public const MIN_PAYABLE = 10.0;

    /** Guard rails on client-supplied duration. */
    public const MIN_DURATION_HOURS = 1;
    public const MAX_DURATION_HOURS = 8;

    /** Sanity ceiling for a single transaction. */
    public const MAX_PAYABLE = 100000.0;

    /**
     * Server-held promo codes. The browser previously owned this table, which
     * is why the client was able to dictate the final price.
     */
    private const PROMO_CODES = [
        'WELCOME10' => ['type' => 'percent', 'value' => 0.10,  'label' => 'WELCOME10 (10% Off)'],
        'PICKLE50'  => ['type' => 'fixed',   'value' => 50.0,  'label' => 'PICKLE50 (₱50 Off)'],
        'DINKFREE'  => ['type' => 'fixed',   'value' => 100.0, 'label' => 'DINKFREE (₱100 Off)'],
    ];

    private Database $db;

    public function __construct(?Database $db = null) {
        $this->db = $db ?? Database::get();
    }

    /**
     * Clamp a client-supplied duration into a bookable range.
     */
    public function normalizeDuration(mixed $duration): int {
        $hours = (int)$duration;
        if ($hours < self::MIN_DURATION_HOURS) {
            return self::MIN_DURATION_HOURS;
        }
        if ($hours > self::MAX_DURATION_HOURS) {
            return self::MAX_DURATION_HOURS;
        }
        return $hours;
    }

    /**
     * Resolve a court to its authoritative row.
     *
     * When $courtId is given, identity is the court id and nothing else —
     * an id that doesn't resolve at this facility returns null outright
     * rather than guessing from the name, because a caller that supplied an
     * id asked for a SPECIFIC court, not "whichever one has a similar name".
     * When no id is given (legacy callers, and the test suite), falls back
     * to matching by name within the facility.
     *
     * @return array{id?:string,name?:string,price?:mixed,...}|null
     */
    public function resolveCourt(int|string $facilityId, string $courtName, string $courtId = ''): ?array {
        $courts = $this->db->getCourtsByFacility($facilityId);

        if ($courtId !== '') {
            foreach ($courts as $court) {
                if ((string)($court['id'] ?? '') === $courtId) {
                    return $court;
                }
            }
            return null;
        }

        $needle = $this->normalizeCourtName($courtName);
        if ($needle !== '') {
            foreach ($courts as $court) {
                if ($this->normalizeCourtName((string)($court['name'] ?? '')) === $needle) {
                    return $court;
                }
            }
        }

        return null;
    }

    /**
     * Resolve the authoritative hourly rate for a court.
     *
     * Looks the court up by name within its facility; if the name does not
     * resolve (renamed court, legacy label), falls back to the facility's own
     * base rate. Returns null only when the facility itself does not exist.
     *
     * This name-only, fallback-to-facility-rate behaviour is intentionally
     * preserved for legacy/name-only callers. It is NOT used for the id-based
     * booking path in quoteCourtBooking() below: once a caller supplies a
     * court_id, an id that fails to resolve refuses the quote outright rather
     * than silently pricing off the facility's base rate — that silent
     * fallback is what let a court name mangled in transit (see
     * resolveCourt()'s doc comment) get billed at the wrong rate instead of
     * being caught.
     */
    public function resolveCourtRate(int|string $facilityId, string $courtName): ?float {
        $court = $this->resolveCourt($facilityId, $courtName);
        if ($court !== null) {
            $rate = (float)($court['price'] ?? 0);
            if ($rate > 0) {
                return $rate;
            }
        }

        $facility = $this->db->getFacility($facilityId);
        if (!$facility) {
            return null;
        }

        foreach (['price_numeric', 'min_price'] as $key) {
            if (isset($facility[$key]) && (float)$facility[$key] > 0) {
                return (float)$facility[$key];
            }
        }

        return null;
    }

    /**
     * Build the authoritative quote for a court booking.
     *
     * @param string $courtId When supplied, this is the ONLY thing that
     *        identifies the court — see resolveCourt(). Leave empty for the
     *        legacy name-only lookup (falls back to the facility's base rate
     *        if the name doesn't match any court exactly).
     * @return array{success:bool,message?:string,rate?:float,court_fee?:float,
     *               service_fee?:float,discount?:float,promo_label?:?string,total?:float}
     */
    public function quoteCourtBooking(
        int|string $facilityId,
        string $courtName,
        mixed $duration,
        ?string $promoCode = null,
        ?string $userId = null,
        string $courtId = ''
    ): array {
        $hours = $this->normalizeDuration($duration);

        if ($courtId !== '') {
            $court = $this->resolveCourt($facilityId, $courtName, $courtId);
            if ($court === null) {
                return ['success' => false, 'message' => 'That court is no longer listed at this venue.'];
            }
            $rate = (float)($court['price'] ?? 0);
            if ($rate <= 0) {
                return ['success' => false, 'message' => 'This court has no published rate. Please contact the venue.'];
            }
            return $this->assemble($rate * $hours, $promoCode, $rate, $userId);
        }

        $rate = $this->resolveCourtRate($facilityId, $courtName);
        if ($rate === null) {
            return ['success' => false, 'message' => 'That facility is no longer available for booking.'];
        }
        if ($rate <= 0) {
            return ['success' => false, 'message' => 'This court has no published rate. Please contact the venue.'];
        }

        return $this->assemble($rate * $hours, $promoCode, $rate, $userId);
    }

    /**
     * Build the authoritative quote for joining an Open Play session.
     * Open Play is charged as a flat per-player entry share, not per hour.
     *
     * @param array $match The match row as stored (authoritative price lives here).
     */
    public function quoteMatchJoin(array $match, ?string $promoCode = null, ?string $userId = null): array {
        $entry = (float)($match['price'] ?? 0);
        if ($entry <= 0) {
            // A free/community session is legitimate — no fee, no service charge.
            return [
                'success'     => true,
                'rate'        => 0.0,
                'court_fee'   => 0.0,
                'service_fee' => 0.0,
                'discount'    => 0.0,
                'promo_label' => null,
                'total'       => 0.0,
            ];
        }

        return $this->assemble($entry, $promoCode, $entry, $userId);
    }

    /**
     * Validate a promo code against the server-held table.
     *
     * @return array{valid:bool,discount:float,label:?string,message:string}
     */
    public function evaluatePromo(?string $code, float $subtotal, ?string $userId = null): array {
        $normalized = strtoupper(trim((string)$code));
        if ($normalized === '') {
            return ['valid' => false, 'discount' => 0.0, 'label' => null, 'message' => ''];
        }

        // Fetch dynamic promo code from Database
        $dbPromo = $this->db->getPromoCode($normalized);
        if ($dbPromo) {
            $status = (string)($dbPromo['status'] ?? 'active');
            if ($status !== 'active') {
                return ['valid' => false, 'discount' => 0.0, 'label' => null, 'message' => 'This promo code is currently inactive.'];
            }

            if (!empty($dbPromo['expires_at'])) {
                $exp = strtotime((string)$dbPromo['expires_at']);
                if ($exp && $exp < time()) {
                    return ['valid' => false, 'discount' => 0.0, 'label' => null, 'message' => 'This promo code has expired.'];
                }
            }

            $minSpend = (float)($dbPromo['min_spend'] ?? 0);
            if ($minSpend > 0 && $subtotal < $minSpend) {
                return ['valid' => false, 'discount' => 0.0, 'label' => null, 'message' => "Minimum spend of ₱" . number_format($minSpend, 2) . " required."];
            }

            $usageLimit = (int)($dbPromo['usage_limit'] ?? 0);
            $timesUsed = (int)($dbPromo['times_used'] ?? 0);
            if ($usageLimit > 0 && $timesUsed >= $usageLimit) {
                return ['valid' => false, 'discount' => 0.0, 'label' => null, 'message' => 'This promo code limit has been reached.'];
            }

            if ($userId) {
                $userLimit = (int)($dbPromo['user_limit'] ?? 1);
                $userUses = $this->db->getPromoRedemptionsCount($dbPromo['id'], $userId);
                if ($userLimit > 0 && $userUses >= $userLimit) {
                    return ['valid' => false, 'discount' => 0.0, 'label' => null, 'message' => 'You have already used this promo code.'];
                }
            }

            $type = (string)($dbPromo['discount_type'] ?? 'fixed');
            $val = (float)($dbPromo['discount_value'] ?? 0);

            if ($type === 'percentage' || $type === 'percent') {
                $rawVal = $val > 1 ? ($val / 100) : $val;
                $discount = (float)round($subtotal * $rawVal);
                $label = "{$normalized} (" . round($rawVal * 100) . "% Off)";
            } else {
                $discount = $val;
                $label = "{$normalized} (₱" . number_format($val, 2) . " Off)";
            }

            // Apply the owner-defined per-redemption cap before the affordability floor.
            $ownerCap = (float)($dbPromo['max_discount'] ?? 0);
            if ($ownerCap > 0) {
                $discount = min($discount, $ownerCap);
            }
            $maxDiscount = max(0.0, $subtotal - self::MIN_PAYABLE);
            $discount = min($discount, $maxDiscount);
            $discount = max(0.0, round($discount, 2));

            return [
                'valid' => true,
                'discount' => $discount,
                'label' => $label,
                'message' => 'Promo code applied!',
            ];
        }

        // Hardcoded Fallback Promo Codes for backwards compatibility
        if (!isset(self::PROMO_CODES[$normalized])) {
            return [
                'valid'    => false,
                'discount' => 0.0,
                'label'    => null,
                'message'  => 'Invalid or expired promo code.',
            ];
        }

        $promo = self::PROMO_CODES[$normalized];
        $discount = $promo['type'] === 'percent'
            ? (float)round($subtotal * (float)$promo['value'])
            : (float)$promo['value'];

        $maxDiscount = max(0.0, $subtotal - self::MIN_PAYABLE);
        $discount    = min($discount, $maxDiscount);
        $discount    = max(0.0, round($discount, 2));

        return [
            'valid'    => true,
            'discount' => $discount,
            'label'    => (string)$promo['label'],
            'message'  => 'Voucher applied.',
        ];
    }

    /**
     * Compose court fee + service fee - discount into a final, clamped total.
     */
    private function assemble(float $baseFee, ?string $promoCode, float $rate, ?string $userId = null): array {
        $courtFee = round($baseFee, 2);
        // Whole-peso service fee, matching the checkout screen's presentation.
        $serviceFee = (float)round($courtFee * self::SERVICE_FEE_RATE);
        $subtotal   = round($courtFee + $serviceFee, 2);

        // $userId flows through here so a promo's own per-user redemption cap
        // is actually enforced at the point of charge — evaluatePromo() has
        // always had that check, but this call was the only path to it and
        // never passed the id, so a one-per-customer code was unlimited.
        $promo    = $this->evaluatePromo($promoCode, $subtotal, $userId);
        $discount = $promo['discount'];

        $total = round($subtotal - $discount, 2);
        if ($total < self::MIN_PAYABLE) {
            $total = self::MIN_PAYABLE;
        }
        if ($total > self::MAX_PAYABLE) {
            return ['success' => false, 'message' => 'This booking exceeds the maximum single-transaction limit.'];
        }

        return [
            'success'     => true,
            'rate'        => round($rate, 2),
            'court_fee'   => $courtFee,
            'service_fee' => $serviceFee,
            'discount'    => $discount,
            'promo_label' => $promo['valid'] ? $promo['label'] : null,
            'total'       => $total,
        ];
    }

    private function normalizeCourtName(string $name): string {
        return strtolower(trim((string)preg_replace('/\s+/', ' ', $name)));
    }
}
