<?php
declare(strict_types=1);

namespace Picklers\Admin\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Picklers\Admin\Http\AdminActionException;
use Picklers\Admin\Security\AdminUser;
use Picklers\Admin\Security\RoleMapper;
use Picklers\Domain\Notifier;

/**
 * Manual Pickle Credits credits/debits by a privileged administrator.
 *
 * - Amounts are parsed to integer centavos and written as exact DECIMAL strings.
 * - Per-adjustment ceiling: MAX_ADJUSTMENT. Debits can never take a balance below zero.
 * - One transaction: lock the user row, update the balance, insert the ledger row
 *   (kind, actor, reason, resulting balance, idempotency key), notify, audit.
 * - Idempotent: the dialog sends a key generated when it was opened. A retry or
 *   double-submit with the same key returns the original result without writing
 *   again; the UNIQUE index on idempotency_key makes this hold under concurrency.
 *   Reusing a key for a DIFFERENT adjustment is refused.
 * - This moves in-app credits only; it is not a refund to GCash/Maya/cards.
 */
final class WalletAdjustmentService
{
    public const MAX_ADJUSTMENT_CENTAVOS = 5_000_000; // ₱50,000.00

    public function __construct(
        private readonly Connection $db,
        private readonly Notifier $notifier,
        private readonly AuditLog $audit,
    ) {
    }

    public function adjust(AdminUser $actor, string $targetId, string $type, string $amountInput, string $reason, string $idempotencyKey): array
    {
        if (!in_array($type, ['credit', 'debit'], true)) {
            throw AdminActionException::invalid("Invalid type. Must be 'credit' or 'debit'.", 'type');
        }
        try {
            $centavos = Money::parse($amountInput);
        } catch (\InvalidArgumentException $e) {
            throw AdminActionException::invalid($e->getMessage(), 'amount');
        }
        if ($centavos <= 0) {
            throw AdminActionException::invalid('Amount must be greater than zero.', 'amount');
        }
        if ($centavos > self::MAX_ADJUSTMENT_CENTAVOS) {
            throw AdminActionException::invalid('A single adjustment is limited to ' . Money::format(self::MAX_ADJUSTMENT_CENTAVOS) . '.', 'amount');
        }
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw AdminActionException::invalid('Give a reason (at least 5 characters) — the player sees it and it is audited.', 'label');
        }
        $reason = mb_substr($reason, 0, 140);
        if (!preg_match('/^[A-Za-z0-9\-]{16,64}$/', $idempotencyKey)) {
            throw AdminActionException::invalid('This form expired. Close it and open the wallet adjustment again.');
        }
        if ($targetId === $actor->id()) {
            throw AdminActionException::forbidden('You cannot adjust your own wallet. Ask another privileged administrator.');
        }
        $key = 'admin-adjust:' . $idempotencyKey;
        $amount = Money::toDecimal($centavos);

        if (($replay = $this->replay($key, $targetId, $type, $amount)) !== null) {
            return $replay;
        }

        try {
            return $this->db->transactional(function () use ($actor, $targetId, $type, $amount, $centavos, $reason, $key): array {
                $user = $this->db->fetchAssociative('SELECT id, name, role, wallet_balance FROM users WHERE id = ? FOR UPDATE', [$targetId]);
                if ($user === false) {
                    throw AdminActionException::notFound('User');
                }
                if (RoleMapper::isDeactivated($user)) {
                    throw AdminActionException::conflict('This account is deactivated; reactivate it before adjusting its wallet.');
                }
                $before = Money::fromColumn($user['wallet_balance']);
                $after = $type === 'credit' ? $before + $centavos : $before - $centavos;
                if ($after < 0) {
                    throw AdminActionException::conflict('Insufficient balance: the wallet holds only ' . Money::format($before) . '. Debits cannot make a balance negative.');
                }
                if ($after > Money::MAX_CENTAVOS) {
                    throw AdminActionException::conflict('That credit would exceed the maximum wallet balance.');
                }
                $this->db->update('users', ['wallet_balance' => Money::toDecimal($after)], ['id' => $targetId]);
                $txId = 'tx_' . bin2hex(random_bytes(5));
                $this->db->insert('wallet_transactions', [
                    'id' => $txId,
                    'user_id' => $targetId,
                    'type' => $type,
                    'amount' => $amount,
                    'label' => '[Admin] ' . $reason,
                    'date' => date('M j, Y'),
                    'created_at' => date('Y-m-d H:i:s'),
                    'idempotency_key' => $key,
                    'actor_user_id' => $actor->id(),
                    'entry_kind' => 'admin_adjustment',
                    'reason' => $reason,
                    'balance_after' => Money::toDecimal($after),
                ]);
                $this->notifier->notify(
                    $targetId,
                    $type === 'credit' ? 'Wallet Credit Added' : 'Wallet Deduction Applied',
                    Money::format($centavos) . ($type === 'credit' ? ' was credited to' : ' was deducted from') . " your Pickle Credits wallet by Picklers support. Reason: {$reason}.",
                    'wallet'
                );
                $this->audit->record('wallet.adjust', 'user', $targetId, [
                    'type' => $type,
                    'amount' => $amount,
                    'balance' => ['from' => Money::toDecimal($before), 'to' => Money::toDecimal($after)],
                    'transaction_id' => $txId,
                ], $reason);

                return [
                    'user_id' => $targetId, 'type' => $type, 'amount' => $amount,
                    'new_balance' => Money::toDecimal($after), 'transaction_id' => $txId, 'replayed' => false,
                    'message' => 'Wallet ' . $type . ' of ' . Money::format($centavos) . ' applied. New balance ' . Money::format($after) . '.',
                ];
            });
        } catch (UniqueConstraintViolationException) {
            // A concurrent request with the same key committed first.
            return $this->replay($key, $targetId, $type, $amount)
                ?? throw AdminActionException::conflict('This adjustment was already submitted. Refresh to see the current balance.');
        }
    }

    private function replay(string $key, string $targetId, string $type, string $amount): ?array
    {
        $prior = $this->db->fetchAssociative('SELECT id, user_id, type, amount, balance_after FROM wallet_transactions WHERE idempotency_key = ?', [$key]);
        if ($prior === false) {
            return null;
        }
        if ($prior['user_id'] !== $targetId || $prior['type'] !== $type || Money::fromColumn($prior['amount']) !== Money::fromColumn($amount)) {
            throw AdminActionException::conflict('This form was already used for a different adjustment. Close it and start again.');
        }

        return [
            'user_id' => $targetId, 'type' => $type, 'amount' => Money::toDecimal(Money::fromColumn($prior['amount'])),
            'new_balance' => (string)$prior['balance_after'], 'transaction_id' => $prior['id'], 'replayed' => true,
            'message' => 'This adjustment was already applied (duplicate submission ignored).',
        ];
    }
}
