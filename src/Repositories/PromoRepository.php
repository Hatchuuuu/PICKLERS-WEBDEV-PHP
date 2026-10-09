<?php
declare(strict_types=1);

namespace Picklers\Repositories;

/**
 * Promo codes and their redemptions.
 */
final class PromoRepository
{
    private readonly \Doctrine\DBAL\Connection $db;

    public function __construct(
        ?\Doctrine\DBAL\Connection $db = null,
    ) {
        $this->db = $db ?? \Picklers\Core\Database::get()->dbal();
    }

    /**
     * All promo codes as actually stored. This is a pure read — it must never
     * fabricate rows. (It once lazily inserted three synthetic promo codes,
     * one of them pre-marked 'disabled' with invented usage counts, the very
     * first time this was called on an empty table. That meant every fresh
     * install — including the test database — silently acquired fictional
     * data and a promo that could never be redeemed. Real seed data belongs
     * in seedInitialData(), guarded by the same .seeded flag as everything
     * else, seeded honestly with times_used = 0.)
     */
    public function getPromoCodes(): array {
        $stmt = $this->db->executeQuery("SELECT * FROM promo_codes ORDER BY created_at DESC");
        $rows = $stmt->fetchAllAssociative() ?: [];

        return array_map(function($r) {
            $r['discount_value'] = (float)($r['discount_value'] ?? 0);
            $r['min_spend'] = (float)($r['min_spend'] ?? 0);
            $r['usage_limit'] = (int)($r['usage_limit'] ?? 0);
            $r['times_used'] = (int)($r['times_used'] ?? 0);
            $r['user_limit'] = (int)($r['user_limit'] ?? 1);
            return $r;
        }, $rows);
    }

    public function getPromoCode(string $code): ?array {
        $normalized = strtoupper(trim($code));
        if ($normalized === '') return null;

        $promos = $this->getPromoCodes();
        foreach ($promos as $p) {
            if (strtoupper((string)($p['code'] ?? '')) === $normalized) {
                return $p;
            }
        }
        return null;
    }

    public function createPromoCode(array $data): array {
        $id = $data['id'] ?? ('prm_' . bin2hex(random_bytes(6)));
        $code = strtoupper(trim((string)($data['code'] ?? '')));
        if ($code === '') {
            $code = 'PICKLE' . rand(100, 999);
        }
        $type = in_array(($data['discount_type'] ?? 'fixed'), ['fixed', 'percentage', 'percent'], true) ? (string)($data['discount_type'] ?? 'fixed') : 'fixed';
        if ($type === 'percent') $type = 'percentage';

        $value = (float)($data['discount_value'] ?? 0.0);
        $minSpend = (float)($data['min_spend'] ?? 0.0);
        $usageLimit = (int)($data['usage_limit'] ?? 0);
        $userLimit = (int)($data['user_limit'] ?? 1);
        $expiresAt = !empty($data['expires_at']) ? (string)$data['expires_at'] : null;
        // Omitting status used to store '' (an undefined-key read), which
        // evaluatePromo() treats as inactive, so the code never applied.
        $status = in_array(($data['status'] ?? 'active'), ['active', 'disabled', 'expired'], true) ? (string)($data['status'] ?? 'active') : 'active';
        $createdAt = $data['created_at'] ?? date('Y-m-d H:i:s');

        $row = [
            'id' => $id,
            'code' => $code,
            'discount_type' => $type,
            'discount_value' => $value,
            'min_spend' => $minSpend,
            'max_discount' => null,
            'usage_limit' => $usageLimit,
            'times_used' => (int)($data['times_used'] ?? 0),
            'user_limit' => $userLimit,
            'expires_at' => $expiresAt,
            'status' => $status,
            'created_at' => $createdAt
        ];

        $this->db->executeStatement("
            INSERT INTO promo_codes (id, code, discount_type, discount_value, min_spend, usage_limit, times_used, user_limit, expires_at, status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                discount_type = VALUES(discount_type),
                discount_value = VALUES(discount_value),
                min_spend = VALUES(min_spend),
                usage_limit = VALUES(usage_limit),
                user_limit = VALUES(user_limit),
                expires_at = VALUES(expires_at),
                status = VALUES(status)
        ", [$id, $code, $type, $value, $minSpend, $usageLimit, $row['times_used'], $userLimit, $expiresAt, $status, $createdAt]);

        return $row;
    }

    public function recordPromoRedemption(string $code, string $userId, ?string $bookingId = null, float $discountAmount = 0.0): bool {
        $promo = $this->getPromoCode($code);
        if (!$promo) return false;

        $id = 'rdm_' . bin2hex(random_bytes(6));
        $redemption = [
            'id' => $id,
            'promo_id' => $promo['id'],
            'promo_code' => $promo['code'],
            'user_id' => $userId,
            'booking_id' => $bookingId,
            'discount_amount' => $discountAmount,
            'created_at' => date('Y-m-d H:i:s')
        ];

        // The quote was validated before the booking transaction started, so
        // two players could both pass "times_used < usage_limit" and both
        // redeem the last use. Re-check under a row lock, then increment
        // conditionally; failure aborts the caller's booking transaction.
        $lock = $this->db->executeQuery("SELECT * FROM promo_codes WHERE id = ? FOR UPDATE", [$promo['id']]);
        $locked = $lock->fetchAssociative();
        if (!$locked || (string)($locked['status'] ?? '') !== 'active') {
            throw new \Picklers\Exceptions\PromoUnavailableException('This promo code is no longer active.');
        }
        if (!empty($locked['expires_at']) && strtotime((string)$locked['expires_at']) < time()) {
            throw new \Picklers\Exceptions\PromoUnavailableException('This promo code has expired.');
        }
        $userLimit = (int)($locked['user_limit'] ?? 1);
        if ($userLimit > 0) {
            $uses = $this->db->executeQuery("SELECT COUNT(*) FROM promo_redemptions WHERE promo_id = ? AND user_id = ?", [$promo['id'], $userId]);
            if ((int)$uses->fetchOne() >= $userLimit) {
                throw new \Picklers\Exceptions\PromoUnavailableException('You have already used this promo code.');
            }
        }
        $stmtInc = $this->db->executeQuery(
            "UPDATE promo_codes SET times_used = times_used + 1 WHERE id = ? AND (usage_limit <= 0 OR times_used < usage_limit)",
            [$promo['id']]
        );
        if ($stmtInc->rowCount() === 0) {
            throw new \Picklers\Exceptions\PromoUnavailableException('This promo code has reached its usage limit.');
        }

        $this->db->executeStatement("INSERT INTO promo_redemptions (id, promo_id, promo_code, user_id, booking_id, discount_amount, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)", [$id, $promo['id'], $promo['code'], $userId, $bookingId, $discountAmount, $redemption['created_at']]);

        return true;
    }

    public function getPromoRedemptionsCount(string $promoId, ?string $userId = null): int {
        if ($userId) {
            $stmt = $this->db->executeQuery("SELECT COUNT(*) c FROM promo_redemptions WHERE (promo_id = ? OR promo_code = ?) AND user_id = ?", [$promoId, strtoupper($promoId), $userId]);
        } else {
            $stmt = $this->db->executeQuery("SELECT COUNT(*) c FROM promo_redemptions WHERE promo_id = ? OR promo_code = ?", [$promoId, strtoupper($promoId)]);
        }
        return (int)($stmt->fetchAssociative()['c'] ?? 0);
    }
}
