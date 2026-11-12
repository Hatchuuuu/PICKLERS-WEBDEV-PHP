<?php
declare(strict_types=1);

namespace Picklers\Repositories;

/**
 * Pickle Credits: balance history, top-ups and ledger entries.
 */
final class WalletRepository
{
    private readonly \Doctrine\DBAL\Connection $db;
    private readonly \Picklers\Domain\WalletLedger $ledger;
    private readonly \Picklers\Repositories\UserRepository $users;
    private readonly \Picklers\Repositories\NotificationRepository $notifications;

    public function __construct(
        \Doctrine\DBAL\Connection $db,
        \Picklers\Domain\WalletLedger $ledger,
        \Picklers\Repositories\UserRepository $users,
        \Picklers\Repositories\NotificationRepository $notifications,
    ) {
        $this->db = $db;
        $this->ledger = $ledger;
        $this->users = $users;
        $this->notifications = $notifications;
    }

    /** Credit a wallet at most once per idempotency key; see WalletLedger::creditOnce(). MySQL only. */
    public function creditWalletOnce(string $userId, string $amountDecimal, string $label, string $idempotencyKey, array $meta = []): bool {
        return $this->ledger->creditOnce($userId, $amountDecimal, $label, $idempotencyKey, $meta);
    }

    public function getWalletTransactions($userId) {
        $rows = [];
        $stmt = $this->db->executeQuery("SELECT * FROM wallet_transactions WHERE user_id = ? ORDER BY created_at DESC", [$userId]);
        $rows = $stmt->fetchAllAssociative();

        $user = $this->users->getUserById($userId);
        if ($user) {
            $bal = (float)($user['wallet_balance'] ?? 0);

            // If user has a positive balance but no credit/top-up transactions recorded yet, synthesize an initial deposit transaction
            $hasCreditTx = false;
            foreach ($rows as $r) {
                if (($r['type'] ?? '') === 'credit') {
                    $hasCreditTx = true;
                    break;
                }
            }

            if (!$hasCreditTx && $bal > 0) {
                // Display-only. A balance with no recorded credit behind it
                // (seeded/imported accounts) is shown as an opening balance and
                // never written into the ledger.
                $createdAt = (string)($user['created_at'] ?? date('Y-m-d H:i:s'));
                $rows[] = [
                    'id' => 'tx_opening_' . substr(hash('sha256', (string)$userId), 0, 10),
                    'user_id' => $userId,
                    'type' => 'credit',
                    'amount' => $bal,
                    'label' => 'Opening Balance',
                    'date' => date('M j, Y', strtotime($createdAt) ?: time()),
                    'created_at' => $createdAt,
                ];
            }
        }

        // Also compile any court bookings / open play joins for this user
        if ($user) {
            $existingLabels = array_column($rows, 'label');
            $bookings = [];
            $stmt = $this->db->executeQuery("SELECT * FROM bookings WHERE user_id = ? ORDER BY created_at DESC", [$userId]);
            $bookings = $stmt->fetchAllAssociative();
            $bookingsById = array_column($bookings, null, 'id');
            foreach ($rows as &$row) {
                $linkedBooking = $bookingsById[$row['booking_id'] ?? ''] ?? null;
                if ($linkedBooking && !empty($linkedBooking['payment_method'])) {
                    $row['payment_method'] = $linkedBooking['payment_method'];
                }
            }
            unset($row);

            foreach ($bookings as $b) {
                if (($b['status'] ?? '') === 'cancelled') {
                    continue;
                }
                $bId = (string)($b['id'] ?? '');
                $alreadyInTx = false;
                foreach ($existingLabels as $lbl) {
                    if (str_contains($lbl, $bId)) {
                        $alreadyInTx = true;
                        break;
                    }
                }
                if (!$alreadyInTx && (float)($b['price'] ?? 0) > 0) {
                    $payMethod = !empty($b['payment_method']) ? $b['payment_method'] : 'GCash';
                    $facName = !empty($b['facility_name']) ? $b['facility_name'] : 'Pickleball Facility';
                    $courtName = !empty($b['court_name']) ? $b['court_name'] : 'Court Reservation';
                    $isOP = str_starts_with($bId, 'PKL-OP-') || stripos($courtName, 'open play') !== false;
                    $labelPrefix = $isOP ? "Open Play #$bId at $facName" : "Booking #$bId at $facName";
                    $bDate = !empty($b['created_at']) ? date('M j, Y', strtotime($b['created_at'])) : date('M j, Y');

                    $bTx = [
                        'id' => 'tx_b_' . strtolower(str_replace(['PKL-', '-'], '', $bId)),
                        'user_id' => $userId,
                        'type' => 'debit',
                        'amount' => (float)$b['price'],
                        'label' => $labelPrefix . " via $payMethod",
                        'date' => $bDate,
                        'created_at' => $b['created_at'] ?? date('Y-m-d H:i:s')
                    ];
                    $rows[] = $bTx;
                }
            }
        }

        // Sort all transactions by created_at DESC
        usort($rows, function($a, $b) {
            $tA = strtotime($a['created_at'] ?? $a['date'] ?? 'now');
            $tB = strtotime($b['created_at'] ?? $b['date'] ?? 'now');
            return $tB <=> $tA;
        });

        return array_values($rows);
    }

    /**
     * @param array{entry_kind?:string,booking_id?:?string,actor_user_id?:?string,reason?:?string,idempotency_key?:?string,balance_after?:?string} $meta
     *        Ledger evidence recorded for NEW entries once the wallet ledger
     *        migration has run (kind, linked booking, acting admin, reason,
     *        resulting balance, idempotency key). Ignored before that.
     */
    public function addTransaction($userId, $type, $amount, $label, array $meta = []) {
        $id = 'tx_' . bin2hex(random_bytes(5));
        $date = date('M j, Y');
        $createdAt = date('Y-m-d H:i:s');

        $tx = [
            'id' => $id, 'user_id' => $userId, 'type' => $type,
            'amount' => (float)$amount, 'label' => $label, 'date' => $date,
            'created_at' => $createdAt
        ];

        return $this->ledger->record((string)$userId, (string)$type, $amount, (string)$label, $meta);
    }

    public function topUpWallet($userId, $amount, $method) {
        $amount = (float)$amount;
        if ($amount <= 0 || $amount > 50000) {
            return ['success' => false, 'message' => 'Top-up amount must be between ₱1.00 and ₱50,000.00'];
        }

        try {
            $this->db->beginTransaction();

            $stmt = $this->db->executeQuery("SELECT wallet_balance FROM users WHERE id = ? FOR UPDATE", [$userId]);
            $row = $stmt->fetchAssociative();
            if (!$row) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'User not found'];
            }

            $oldBalance = (float)($row['wallet_balance'] ?? 0);
            $newBalance = round($oldBalance + $amount, 2);

            $this->db->executeStatement(
                "UPDATE users SET wallet_balance = ROUND(wallet_balance + ?, 2) WHERE id = ?",
                [$amount, $userId]
            );

            $this->addTransaction($userId, 'credit', $amount, $method, ['entry_kind' => 'top_up', 'balance_after' => (string)$this->db->fetchOne('SELECT wallet_balance FROM users WHERE id = ?', [$userId])]);

            $this->notifications->addNotification(
                $userId,
                'Top-Up Successful! 💳',
                "₱" . number_format($amount, 2) . " Pickle Credits added via $method. Previous balance: ₱" . number_format($oldBalance, 2) . ", Current balance: ₱" . number_format($newBalance, 2),
                'system'
            );

            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            error_log('[DB Error] topUpWallet failed: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Top-up failed. Please try again.'];
        }

        return [
            'success' => true,
            'message' => "Successfully topped up ₱" . number_format($amount, 2) . "! New balance: ₱" . number_format($newBalance, 2),
            'old_balance' => $oldBalance,
            'amount_added' => $amount,
            'new_balance' => $newBalance
        ];
    }
}
