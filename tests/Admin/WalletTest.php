<?php
declare(strict_types=1);

namespace Picklers\Tests\Admin;

use Picklers\Admin\Service\Money;

/** R08 — wallet operations: permissions, exact amounts, limits, idempotency, concurrency. */
final class WalletTest extends AdminWebTestCase
{
    private function balance(string $id): string
    {
        return (string)$this->scalar('SELECT wallet_balance FROM users WHERE id = ?', [$id]);
    }

    private function adjust(array $p): array
    {
        return $this->action('admin_adjust_wallet', $p + ['user_id' => 'usr_fx_p2', 'type' => 'credit', 'reason' => 'Goodwill credit for outage', 'idempotency_key' => bin2hex(random_bytes(12))]);
    }

    public function testOnlyPrivilegedAdminsAdjustAndNeverTheirOwnWallet(): void
    {
        $this->signIn('usr_fx_admin');
        self::assertSame(403, $this->adjust(['amount' => '10.00'])['status']);
        $this->signIn('usr_fx_root');
        self::assertSame(403, $this->adjust(['user_id' => 'usr_fx_root', 'amount' => '10.00'])['status']);
        self::assertSame(409, $this->adjust(['user_id' => 'usr_fx_gone', 'amount' => '10.00'])['status'], 'deactivated account');
    }

    public function testInputValidation(): void
    {
        $this->signIn('usr_fx_root');
        foreach (['0', '-5', '1.234', 'abc', '', '50000.01', '1e3'] as $bad) {
            $r = $this->adjust(['amount' => $bad]);
            self::assertSame(422, $r['status'], "amount {$bad}");
        }
        self::assertSame(422, $this->adjust(['amount' => '5', 'reason' => 'x'])['status'], 'reason required');
        self::assertSame(422, $this->adjust(['amount' => '5', 'idempotency_key' => 'short'])['status'], 'form key required');
        self::assertSame(422, $this->adjust(['amount' => '5', 'type' => 'steal'])['status']);
        self::assertSame('80.50', $this->balance('usr_fx_p2'), 'nothing changed');
    }

    public function testExactCentavoArithmeticAndAtomicRecords(): void
    {
        $this->signIn('usr_fx_root');
        self::assertSame(200, $this->adjust(['amount' => '0.10'])['status']);
        $r = $this->adjust(['amount' => '0.20']);
        self::assertSame('80.80', $r['json']['new_balance']);
        self::assertSame('80.80', $this->balance('usr_fx_p2'), '80.50 + 0.10 + 0.20 is exact');
        $tx = self::pdo()->query("SELECT * FROM wallet_transactions WHERE id = " . self::pdo()->quote($r['json']['transaction_id']))->fetch();
        self::assertSame('admin_adjustment', $tx['entry_kind']);
        self::assertSame('usr_fx_root', $tx['actor_user_id']);
        self::assertSame('80.80', $tx['balance_after']);
        $audit = $this->lastAudit('wallet.adjust');
        self::assertStringContainsString('"to":"80.80"', $audit['changes']);
        self::assertSame(Money::parse('1,234.56'), 123456);
        self::assertSame('1234.56', Money::toDecimal(123456));
    }

    public function testDebitCannotGoNegative(): void
    {
        $this->signIn('usr_fx_root');
        $r = $this->adjust(['type' => 'debit', 'amount' => '80.51']);
        self::assertSame(409, $r['status']);
        self::assertSame('80.50', $this->balance('usr_fx_p2'));
        self::assertSame(200, $this->adjust(['type' => 'debit', 'amount' => '80.50'])['status']);
        self::assertSame('0.00', $this->balance('usr_fx_p2'));
    }

    public function testRetriesWithTheSameKeyApplyOnce(): void
    {
        $this->signIn('usr_fx_root');
        $key = bin2hex(random_bytes(12));
        $first = $this->adjust(['amount' => '25.00', 'idempotency_key' => $key]);
        $second = $this->adjust(['amount' => '25.00', 'idempotency_key' => $key]);
        self::assertSame(200, $second['status']);
        self::assertTrue($second['json']['replayed']);
        self::assertFalse($second['json']['changed']);
        self::assertSame($first['json']['transaction_id'], $second['json']['transaction_id']);
        self::assertSame('105.50', $this->balance('usr_fx_p2'));
        self::assertSame(409, $this->adjust(['amount' => '99.00', 'idempotency_key' => $key])['status'], 'key reused for a different amount');
    }

    public function testConcurrentDuplicateSubmissionsApplyOnce(): void
    {
        $key = bin2hex(random_bytes(12));
        $results = $this->concurrently(5, 'wallet', ['user_id' => 'usr_fx_p2', 'type' => 'credit', 'amount' => '10.00', 'key' => $key]);
        foreach ($results as $r) {
            self::assertTrue($r['ok'] ?? false, json_encode($r));
        }
        self::assertSame(1, (int)$this->scalar('SELECT COUNT(*) FROM wallet_transactions WHERE idempotency_key = ?', ['admin-adjust:' . $key]));
        self::assertSame('90.50', $this->balance('usr_fx_p2'));
    }

    public function testConcurrentDebitsNeverOverdraw(): void
    {
        // Five different ₱30 debits against ₱80.50: at most two can succeed.
        $results = $this->concurrently(5, 'wallet', ['user_id' => 'usr_fx_p2', 'type' => 'debit', 'amount' => '30.00']);
        $ok = count(array_filter($results, fn($r) => ($r['ok'] ?? false) === true));
        self::assertSame(2, $ok, json_encode($results));
        self::assertSame('20.50', $this->balance('usr_fx_p2'));
    }
}
