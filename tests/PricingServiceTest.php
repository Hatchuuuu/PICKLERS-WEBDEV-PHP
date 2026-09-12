<?php
declare(strict_types=1);

namespace Picklers\Tests;

use Picklers\Services\PricingService;

/**
 * Guards the server-authoritative pricing engine.
 *
 * Regression origin: book_court and join_match previously took `price` straight
 * from the request body. Posting price=0 booked for free and a NEGATIVE price
 * inverted the wallet deduction, minting credits.
 */
final class PricingServiceTest extends TestCase {

    public function run(): void {
        $p = new PricingService();

        // ── Court rate resolution ──────────────────────────────────────────
        $this->assertSame(300.0, $p->resolveCourtRate(1, 'Court 3'),
            'Court 3 at facility 1 resolves to its published rate');
        $this->assertNotNull($p->resolveCourtRate(1, 'Totally Unknown Court'),
            'An unknown court name falls back to the facility rate rather than free');
        $this->assertNull($p->resolveCourtRate(999999, 'Court 3'),
            'A non-existent facility resolves to no rate at all');

        // ── Duration clamping (client-supplied) ────────────────────────────
        $this->assertSame(1, $p->normalizeDuration(0),      'Zero duration clamps up to 1h');
        $this->assertSame(1, $p->normalizeDuration(-5),     'Negative duration clamps up to 1h');
        $this->assertSame(8, $p->normalizeDuration(999),    'Excessive duration clamps to the 8h ceiling');
        $this->assertSame(3, $p->normalizeDuration('3'),    'Numeric strings are accepted');
        $this->assertSame(1, $p->normalizeDuration('abc'),  'Garbage duration clamps to the 1h floor');

        // ── Quote arithmetic ───────────────────────────────────────────────
        $q = $p->quoteCourtBooking(1, 'Court 3', 2);
        $this->assertTrue((bool)$q['success'],        'A valid court quote succeeds');
        $this->assertSame(600.0, $q['court_fee'],     '2h at P300 is a P600 court fee');
        $this->assertSame(60.0,  $q['service_fee'],   'Service fee is 10% of the court fee');
        $this->assertSame(660.0, $q['total'],         'Total is court fee plus service fee');

        // ── Promo codes are validated server-side ──────────────────────────
        $w = $p->quoteCourtBooking(1, 'Court 3', 2, 'WELCOME10');
        $this->assertSame(66.0,  $w['discount'], 'WELCOME10 discounts 10% of the subtotal');
        $this->assertSame(594.0, $w['total'],    'WELCOME10 total is subtotal minus discount');

        $f = $p->quoteCourtBooking(1, 'Court 3', 2, 'PICKLE50');
        $this->assertSame(50.0,  $f['discount'], 'PICKLE50 is a flat P50 off');
        $this->assertSame(610.0, $f['total'],    'PICKLE50 total reflects the flat discount');

        $bogus = $p->quoteCourtBooking(1, 'Court 3', 2, 'FREE-STUFF-PLEASE');
        $this->assertSame(0.0,   $bogus['discount'], 'An unknown promo code grants nothing');
        $this->assertSame(660.0, $bogus['total'],    'An unknown promo leaves the total untouched');

        $lower = $p->quoteCourtBooking(1, 'Court 3', 2, 'welcome10');
        $this->assertSame(66.0, $lower['discount'], 'Promo codes are case-insensitive');

        // ── A promo can never zero out or invert a charge ───────────────────
        // WELCOME100 (fixed ₱100 off, no minimum spend) is deliberately the
        // fixture here rather than DINKFREE: DINKFREE carries a real ₱400
        // minimum spend in the seeded data, so it is correctly REJECTED
        // outright on a ₱22 subtotal (see the assertion below) and never
        // reaches the floor-clamping arithmetic this block exists to guard.
        $tiny = $p->quoteMatchJoin(['price' => 20.0], 'WELCOME100');
        $this->assertSame(PricingService::MIN_PAYABLE, $tiny['total'],
            'An oversized discount floors at the minimum payable, never negative');
        $this->assertGreaterThan(0, $tiny['total'],
            'The floored total is still strictly positive');

        // ── A promo's own minimum spend is enforced before any discount math ─
        $belowMin = $p->quoteMatchJoin(['price' => 20.0], 'DINKFREE');
        $this->assertSame(22.0, $belowMin['total'],
            'DINKFREE requires a ₱400 minimum spend, so a ₱22 subtotal gets no discount at all');
        $this->assertNull($belowMin['promo_label'],
            'A promo that failed its minimum-spend gate is not reported as applied');

        // ── Open Play entry pricing ────────────────────────────────────────
        $m = $p->quoteMatchJoin(['price' => 150.0]);
        $this->assertSame(165.0, $m['total'], 'Open Play charges entry fee plus service fee');

        $free = $p->quoteMatchJoin(['price' => 0]);
        $this->assertSame(0.0, $free['total'], 'A genuinely free session stays free');

        // ── Displayed price must equal charged price (whole pesos) ──────────
        $odd = $p->quoteCourtBooking(1, 'Court 3', 3, 'WELCOME10');
        $this->assertSame((float)round($odd['service_fee']), $odd['service_fee'],
            'Service fee is a whole peso amount so the UI and the charge agree');
        $this->assertSame((float)round($odd['discount']), $odd['discount'],
            'Percentage discounts are whole pesos so the UI and the charge agree');

        // ── Court identity by id (Regression: a court name truncated in
        // transit — "Court 1 · Central Show Court" mangled down to "Court 1"
        // by a client-side display-cleanup regex — silently matched nothing
        // and fell back to the facility's base rate, over/undercharging the
        // real court. Resolving by id sidesteps name-matching entirely.) ────
        $byId = $p->resolveCourt(1, '', 'crt_1_1');
        $this->assertNotNull($byId, 'A real court id resolves regardless of what name (if any) accompanies it');
        $this->assertSame('crt_1_1', $byId['id'] ?? null, 'The resolved row is the exact court asked for');

        $this->assertNull($p->resolveCourt(1, 'Court A · Championship Pro', 'crt_nonexistent'),
            'An id that does not resolve at the facility refuses outright — it never falls back to a name guess');

        $idQuote = $p->quoteCourtBooking(1, 'anything, ignored when an id is given', 1, null, null, 'crt_1_1');
        $this->assertTrue((bool)$idQuote['success'], 'An id-based quote succeeds when the id resolves');
        $this->assertSame(250.0, $idQuote['rate'], 'crt_1_1 is priced at its own published rate, not the facility fallback');

        $badIdQuote = $p->quoteCourtBooking(1, 'Court A · Championship Pro', 1, null, null, 'crt_does_not_exist');
        $this->assertFalse((bool)$badIdQuote['success'],
            'An unresolvable court id is refused rather than silently priced off the facility base rate');

        // ── A promo's per-user redemption cap is actually enforced (Regression:
        // assemble() called evaluatePromo() without the user id, so the
        // user_limit branch — already written — was unreachable from every
        // real booking path; a one-per-customer code was unlimited) ─────────
        $db = \Picklers\Core\Database::get();
        $freshUserId = 'usr_test_promo_cap_' . bin2hex(random_bytes(4));
        $db->createUser([
            'id' => $freshUserId, 'name' => 'Promo Cap Tester',
            'email' => $freshUserId . '@example.test', 'password_hash' => password_hash('x', PASSWORD_DEFAULT),
        ]);

        $firstUse = $p->quoteCourtBooking(1, '', 2, 'WELCOME100', $freshUserId, 'crt_1_1');
        $this->assertTrue((bool)$firstUse['success'] && ($firstUse['discount'] ?? 0) > 0,
            'WELCOME100 (user_limit 1) applies the first time this user quotes it');

        $db->recordPromoRedemption('WELCOME100', $freshUserId, 'PKL-TEST-CAP', (float)$firstUse['discount']);

        $secondUse = $p->quoteCourtBooking(1, '', 2, 'WELCOME100', $freshUserId, 'crt_1_1');
        $this->assertSame(0.0, $secondUse['discount'] ?? null,
            'REGRESSION: the same user redeeming WELCOME100 a second time gets no discount — the cap is enforced');

        $otherUserId = 'usr_test_promo_cap_' . bin2hex(random_bytes(4));
        $db->createUser([
            'id' => $otherUserId, 'name' => 'Promo Cap Tester 2',
            'email' => $otherUserId . '@example.test', 'password_hash' => password_hash('x', PASSWORD_DEFAULT),
        ]);
        $differentUser = $p->quoteCourtBooking(1, '', 2, 'WELCOME100', $otherUserId, 'crt_1_1');
        $this->assertTrue(($differentUser['discount'] ?? 0) > 0,
            'A DIFFERENT user is unaffected by the first user having exhausted their own cap');
    }
}
