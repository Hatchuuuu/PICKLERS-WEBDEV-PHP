<?php
declare(strict_types=1);

namespace Picklers\Domain;

use Doctrine\DBAL\Connection;

/** Pickle Credits ledger rows (`wallet_transactions`) and the credit-once rule. */
final class WalletLedger
{
    use SharedTransaction;

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * Append one ledger row. $meta (idempotency_key, actor_user_id, entry_kind,
     * booking_id, reason, balance_after) is stored when the ledger columns exist.
     *
     * @return array<string,mixed>
     */
    public function record(string $userId, string $type, string|float $amount, string $label, array $meta = []): array
    {
        $row = [
            'id' => 'tx_' . bin2hex(random_bytes(5)),
            'user_id' => $userId,
            'type' => $type,
            'amount' => $amount,
            'label' => $label,
            'date' => date('M j, Y'),
            'created_at' => date('Y-m-d H:i:s'),
        ];
        $extra = [];
        if ($meta !== [] && $this->hasColumn('wallet_transactions', 'entry_kind')) {
            foreach (['idempotency_key', 'actor_user_id', 'entry_kind', 'booking_id', 'reason', 'balance_after'] as $key) {
                $extra[$key] = $meta[$key] ?? null;
            }
        }
        $this->db->insert('wallet_transactions', $row + $extra);

        return ['amount' => (float)$amount] + $row;
    }

    /**
     * Credit a wallet at most once per idempotency key (refunds, compensation).
     *
     * Also treats a pre-existing legacy refund for the same booking (written
     * before idempotency keys existed) as "already refunded", so no booking can
     * be refunded twice across the player, owner and admin cancellation paths.
     * Must run inside the caller's transaction.
     *
     * @return bool true when this call credited the wallet
     */
    public function creditOnce(string $userId, string $amountDecimal, string $label, string $idempotencyKey, array $meta = []): bool
    {
        if ($this->hasColumn('wallet_transactions', 'idempotency_key')
            && $this->db->fetchOne('SELECT 1 FROM wallet_transactions WHERE idempotency_key = ? LIMIT 1', [$idempotencyKey]) !== false) {
            return false;
        }
        $bookingId = (string)($meta['booking_id'] ?? '');
        if ($bookingId !== '' && $this->db->fetchOne(
            "SELECT 1 FROM wallet_transactions
              WHERE user_id = ? AND type = 'credit' AND label LIKE ? AND LOWER(label) LIKE '%refund%' LIMIT 1",
            [$userId, '%#' . addcslashes($bookingId, '%_\\') . '%']
        ) !== false) {
            return false;
        }
        if ($this->db->fetchOne('SELECT wallet_balance FROM users WHERE id = ? FOR UPDATE', [$userId]) === false) {
            throw new \RuntimeException('Cannot credit a wallet that does not exist.');
        }
        $this->db->executeStatement('UPDATE users SET wallet_balance = wallet_balance + ? WHERE id = ?', [$amountDecimal, $userId]);
        $after = (string)$this->db->fetchOne('SELECT wallet_balance FROM users WHERE id = ?', [$userId]);
        $this->record($userId, 'credit', $amountDecimal, $label, $meta + [
            'idempotency_key' => $idempotencyKey,
            'balance_after' => $after,
        ]);

        return true;
    }
}
