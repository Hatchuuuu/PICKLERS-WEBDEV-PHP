<?php
declare(strict_types=1);

namespace Picklers\Tests\Admin;

use Picklers\Core\Database;
use Picklers\Services\PricingService;

/** R09 — promo codes: validation, Manila expiry, archive-not-delete, concurrent redemption. */
final class PromosTest extends AdminWebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->signIn('usr_fx_admin');
    }

    private function create(array $p): array
    {
        return $this->action('admin_create_promo', $p + ['discount_type' => 'fixed', 'discount_value' => '50', 'min_spend' => '0', 'usage_limit' => '0', 'user_limit' => '1']);
    }

    public function testCreateValidatesAndStoresEndOfDayManilaExpiry(): void
    {
        self::assertSame(409, $this->create(['code' => 'fxlive50'])['status'], 'duplicate codes are case-insensitive');
        self::assertSame(422, $this->create(['code' => 'BAD CODE!'])['status']);
        self::assertSame(422, $this->create(['code' => 'PCT101', 'discount_type' => 'percentage', 'discount_value' => '101'])['status']);
        self::assertSame(422, $this->create(['code' => 'ZERO', 'discount_value' => '0'])['status']);
        self::assertSame(422, $this->create(['code' => 'PASTDATE', 'expires_at' => '2020-01-01'])['status']);
        self::assertSame(422, $this->create(['code' => 'NEGLIMIT', 'usage_limit' => '-1'])['status']);
        $day = (new \DateTimeImmutable('+5 days', new \DateTimeZone('Asia/Manila')))->format('Y-m-d');
        $r = $this->create(['code' => 'fxnew25', 'expires_at' => $day]);
        self::assertSame(200, $r['status'], json_encode($r['json']));
        self::assertSame('FXNEW25', $r['json']['promo']['code']);
        self::assertSame($day . ' 23:59:59', $this->scalar("SELECT expires_at FROM promo_codes WHERE code = 'FXNEW25'"));
        self::assertSame('usr_fx_admin', $this->scalar("SELECT created_by FROM promo_codes WHERE code = 'FXNEW25'"));
    }

    public function testToggleAndExpiredCannotBeEnabled(): void
    {
        self::assertSame('disabled', $this->action('admin_toggle_promo', ['promo_id' => 'fx_promo_live'])['json']['status']);
        $q = (new PricingService(Database::get()))->evaluatePromo('FXLIVE50', 500.0, 'usr_fx_p2');
        self::assertFalse($q['valid'], 'disabled promo is not redeemable');
        self::assertSame('active', $this->action('admin_toggle_promo', ['promo_id' => 'fx_promo_live'])['json']['status']);
        self::assertSame(200, $this->action('admin_toggle_promo', ['promo_id' => 'fx_promo_old'])['status'], 'disable an expired promo');
        self::assertSame(409, $this->action('admin_toggle_promo', ['promo_id' => 'fx_promo_old'])['status'], 'but it cannot be re-enabled');
    }

    public function testDeleteOnlyUnusedOtherwiseArchiveKeepingHistory(): void
    {
        $r = $this->action('admin_delete_promo', ['promo_id' => 'fx_promo_live']);
        self::assertSame(200, $r['status']);
        self::assertTrue($r['json']['archived']);
        self::assertSame('archived', $this->scalar("SELECT status FROM promo_codes WHERE id = 'fx_promo_live'"));
        self::assertSame(1, (int)$this->scalar("SELECT COUNT(*) FROM promo_redemptions WHERE promo_id = 'fx_promo_live'"), 'history kept');
        self::assertFalse((new PricingService(Database::get()))->evaluatePromo('FXLIVE50', 500.0, 'usr_fx_p2')['valid']);
        self::assertSame(409, $this->action('admin_toggle_promo', ['promo_id' => 'fx_promo_live'])['status'], 'archived stays retired');

        $r = $this->action('admin_delete_promo', ['promo_id' => 'fx_promo_old']);
        self::assertFalse($r['json']['archived']);
        self::assertFalse($this->scalar("SELECT id FROM promo_codes WHERE id = 'fx_promo_old'"));
    }

    public function testExhaustedPromoIsRefusedAtCommitEvenIfQuotedEarlier(): void
    {
        $fac = (int)$this->scalar("SELECT id FROM facilities WHERE name = 'Fixture Arena Dumaguete'");
        $date = (new \DateTimeImmutable('+9 days'))->format('D, M j, Y');
        // FXFULL10 is already at its limit: the booking must fail and nothing be charged.
        $res = Database::get()->createBooking('usr_fx_p1', $fac, 'Court 1', $date, '7:00 AM - 8:00 AM', 1, 300.0, 'Pickle Credits', 'crt_' . $fac . '_1', 'FXFULL10', 30.0);
        self::assertFalse($res['success']);
        self::assertStringContainsString('usage limit', $res['message']);
        self::assertSame('1200.00', (string)$this->scalar("SELECT wallet_balance FROM users WHERE id = 'usr_fx_p1'"), 'nothing was charged');
    }

    public function testConcurrentRedemptionsOfTheLastUse(): void
    {
        $r = $this->create(['code' => 'FXLAST1', 'usage_limit' => '1', 'user_limit' => '0']);
        self::assertSame(200, $r['status']);
        $fac = (int)$this->scalar("SELECT id FROM facilities WHERE name = 'Fixture Arena Dumaguete'");
        $results = $this->concurrently(4, 'book_promo', [
            'user_id' => 'usr_fx_p3', 'facility_id' => $fac, 'date' => (new \DateTimeImmutable('+11 days'))->format('D, M j, Y'),
            'price' => 300.0, 'promo' => 'FXLAST1', 'discount' => 50.0,
        ]);
        $ok = array_filter($results, fn($x) => ($x['result']['success'] ?? false) === true);
        self::assertCount(1, $ok, json_encode($results));
        self::assertSame(1, (int)$this->scalar("SELECT times_used FROM promo_codes WHERE code = 'FXLAST1'"));
        self::assertSame(1, (int)$this->scalar("SELECT COUNT(*) FROM promo_redemptions WHERE promo_code = 'FXLAST1'"));
    }

    public function testDetailShowsRedemptions(): void
    {
        $this->client->request('GET', '/admin/detail/promo/fx_promo_full');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#promo-redemptions', 'Redemptions (1)');
        $this->client->request('GET', '/admin/panel/promos', ['state' => 'exhausted']);
        self::assertSelectorTextContains('table', 'FXFULL10');
    }
}
