<?php
declare(strict_types=1);

namespace Picklers\Admin\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Picklers\Admin\Http\AdminActionException;
use Picklers\Admin\Repository\AuditRepository;
use Picklers\Admin\Security\AdminUser;
use Picklers\Domain\Payments;

/**
 * Promo codes. Effective state is computed from stored facts, in this order:
 * archived → disabled → expired (expires_at passed, Asia/Manila) → exhausted
 * (usage limit reached) → active.
 *
 * Expiry dates are entered as a calendar day and stored as 23:59:59 Manila time
 * on that day (the old console stored midnight at the START of the day, so a
 * promo "expiring Dec 4" stopped working on Dec 3 at midnight).
 *
 * "Delete" hard-deletes only a promo that was never redeemed; once redeemed it is
 * archived so bookings and redemption history keep their reference.
 */
final class PromoAdminService
{
    public const STATES = ['active', 'disabled', 'expired', 'exhausted', 'archived'];
    public const SORTS = ['created_at', 'code', 'times_used', 'expires_at'];

    private const STATE_SQL = "CASE
            WHEN p.archived_at IS NOT NULL OR p.status = 'archived' THEN 'archived'
            WHEN p.status <> 'active' THEN 'disabled'
            WHEN p.expires_at IS NOT NULL AND p.expires_at < NOW() THEN 'expired'
            WHEN p.usage_limit > 0 AND p.times_used >= p.usage_limit THEN 'exhausted'
            ELSE 'active' END";

    public function __construct(
        private readonly Connection $db,
        private readonly AuditLog $audit,
        private readonly AuditRepository $auditRepository,
    ) {
    }

    /** @return Page<array<string,mixed>> */
    public function search(ListQuery $query): Page
    {
        $where = ['1=1'];
        $params = [];
        if ($query->q !== '') {
            $where[] = 'p.code LIKE ?';
            $params[] = '%' . addcslashes(strtoupper($query->q), '%_\\') . '%';
        }
        if (($v = $query->filter('state')) !== null) {
            $where[] = self::STATE_SQL . ' = ?';
            $params[] = $v;
        }
        $sql = implode(' AND ', $where);
        $total = (int)$this->db->fetchOne("SELECT COUNT(*) FROM promo_codes p WHERE {$sql}", $params);
        if ($total > 0 && $query->offset() >= $total) {
            $query = $query->withPage((int)ceil($total / $query->perPage));
        }
        $order = match ($query->sort) {
            'code' => 'p.code',
            'times_used' => 'p.times_used',
            'expires_at' => 'p.expires_at',
            default => 'p.created_at',
        };
        $dir = $query->dir === 'asc' ? 'ASC' : 'DESC';
        $rows = $this->db->fetchAllAssociative(
            'SELECT p.*, ' . self::STATE_SQL . " AS state,
                    (SELECT COUNT(*) FROM promo_redemptions r WHERE r.promo_id = p.id) AS redemptions,
                    (SELECT COALESCE(SUM(r.discount_amount), 0) FROM promo_redemptions r WHERE r.promo_id = p.id) AS discount_total
               FROM promo_codes p WHERE {$sql} ORDER BY {$order} {$dir}, p.id ASC LIMIT {$query->perPage} OFFSET {$query->offset()}",
            $params
        );

        return new Page($rows, $total, $query);
    }

    /** @return array{redeemable:int,redemptions:int,discount_total:string} */
    public function stats(): array
    {
        $row = $this->db->fetchAssociative(
            'SELECT (SELECT COUNT(*) FROM promo_codes p WHERE ' . self::STATE_SQL . " = 'active') AS redeemable,
                    (SELECT COUNT(*) FROM promo_redemptions) AS redemptions,
                    (SELECT COALESCE(SUM(discount_amount), 0) FROM promo_redemptions) AS discount_total"
        ) ?: [];

        return [
            'redeemable' => (int)($row['redeemable'] ?? 0),
            'redemptions' => (int)($row['redemptions'] ?? 0),
            'discount_total' => Money::toDecimal(Money::fromColumn($row['discount_total'] ?? 0)),
        ];
    }

    /** @return array<string,mixed> */
    public function detail(string $id): array
    {
        $promo = $this->db->fetchAssociative('SELECT p.*, ' . self::STATE_SQL . ' AS state, c.name AS created_by_name, a.name AS archived_by_name
            FROM promo_codes p LEFT JOIN users c ON c.id = p.created_by LEFT JOIN users a ON a.id = p.archived_by WHERE p.id = ?', [$id]);
        if ($promo === false) {
            throw AdminActionException::notFound('Promo code');
        }
        $promo['redemption_rows'] = $this->db->fetchAllAssociative(
            'SELECT r.*, u.name AS user_name, b.status AS booking_status, b.facility_name FROM promo_redemptions r
               LEFT JOIN users u ON u.id = r.user_id LEFT JOIN bookings b ON b.id = r.booking_id
              WHERE r.promo_id = ? ORDER BY r.created_at DESC LIMIT 200',
            [$id]
        );
        $promo['history'] = $this->auditRepository->historyFor('promo', $id);

        return $promo;
    }

    /** @param array<string,mixed> $in */
    public function create(AdminUser $actor, array $in): array
    {
        $code = strtoupper(trim((string)($in['code'] ?? '')));
        if ($code === '') {
            $code = 'PKL' . strtoupper(bin2hex(random_bytes(3)));
        }
        if (!preg_match('/^[A-Z0-9_-]{3,50}$/', $code)) {
            throw AdminActionException::invalid('Promo codes must be 3–50 letters, numbers, dashes or underscores.', 'code');
        }
        $type = (string)($in['discount_type'] ?? 'fixed');
        if ($type === 'percent') {
            $type = 'percentage';
        }
        if (!in_array($type, ['fixed', 'percentage'], true)) {
            throw AdminActionException::invalid('Invalid discount type.', 'discount_type');
        }
        try {
            $value = Money::parse((string)($in['discount_value'] ?? ''));
            $minSpend = Money::parse((string)(($in['min_spend'] ?? '') === '' ? '0' : $in['min_spend']));
        } catch (\InvalidArgumentException $e) {
            throw AdminActionException::invalid($e->getMessage(), 'discount_value');
        }
        if ($value <= 0) {
            throw AdminActionException::invalid('Enter a valid discount value greater than 0.', 'discount_value');
        }
        if ($type === 'percentage' && $value > 100 * 100) {
            throw AdminActionException::invalid('Percentage discount cannot exceed 100%.', 'discount_value');
        }
        if ($type === 'fixed' && $value > (int)round(Payments::MAX_PAYABLE * 100)) {
            throw AdminActionException::invalid('Fixed discount is above the maximum transaction amount.', 'discount_value');
        }
        if ($minSpend < 0) {
            throw AdminActionException::invalid('Minimum spend cannot be negative.', 'min_spend');
        }
        $usageLimit = filter_var($in['usage_limit'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 1_000_000]]);
        $userLimit = filter_var($in['user_limit'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 1000]]);
        if ($usageLimit === false) {
            throw AdminActionException::invalid('Total usage limit must be a whole number from 0 (unlimited) upward.', 'usage_limit');
        }
        if ($userLimit === false) {
            throw AdminActionException::invalid('Per-user limit must be a whole number from 0 (unlimited) upward.', 'user_limit');
        }
        $expiresAt = null;
        $expiryInput = trim((string)($in['expires_at'] ?? ''));
        if ($expiryInput !== '') {
            $day = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($expiryInput, 0, 10), new \DateTimeZone('Asia/Manila'));
            if ($day === false || $day->format('Y-m-d') !== substr($expiryInput, 0, 10)) {
                throw AdminActionException::invalid('Expiry must be a valid date.', 'expires_at');
            }
            $end = $day->setTime(23, 59, 59);
            if ($end < new \DateTimeImmutable('now', new \DateTimeZone('Asia/Manila'))) {
                throw AdminActionException::invalid('Expiry must be today or a future date.', 'expires_at');
            }
            $expiresAt = $end->format('Y-m-d H:i:s');
        }

        $id = 'promo_' . bin2hex(random_bytes(6));
        try {
            $this->db->transactional(function () use ($actor, $id, $code, $type, $value, $minSpend, $usageLimit, $userLimit, $expiresAt): void {
                $this->db->insert('promo_codes', [
                    'id' => $id, 'code' => $code, 'discount_type' => $type, 'discount_value' => Money::toDecimal($value),
                    'min_spend' => Money::toDecimal($minSpend), 'max_discount' => null, 'usage_limit' => $usageLimit,
                    'times_used' => 0, 'user_limit' => $userLimit, 'expires_at' => $expiresAt, 'status' => 'active',
                    'created_at' => date('Y-m-d H:i:s'), 'created_by' => $actor->id(),
                ]);
                $this->audit->record('promo.create', 'promo', $id, [
                    'code' => $code, 'discount_type' => $type, 'discount_value' => Money::toDecimal($value),
                    'min_spend' => Money::toDecimal($minSpend), 'usage_limit' => $usageLimit, 'user_limit' => $userLimit, 'expires_at' => $expiresAt,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            throw AdminActionException::conflict("Promo code {$code} already exists.");
        }

        return ['promo' => ['id' => $id, 'code' => $code], 'message' => "Promo code {$code} created and activated."];
    }

    public function toggle(AdminUser $actor, string $id): array
    {
        return $this->db->transactional(function () use ($id): array {
            $p = $this->db->fetchAssociative('SELECT p.*, ' . self::STATE_SQL . ' AS state FROM promo_codes p WHERE p.id = ? FOR UPDATE', [$id]);
            if ($p === false) {
                throw AdminActionException::notFound('Promo code');
            }
            if ($p['state'] === 'archived') {
                throw AdminActionException::conflict('Archived promo codes cannot be re-enabled.');
            }
            $to = $p['status'] === 'active' ? 'disabled' : 'active';
            if ($to === 'active' && $p['expires_at'] !== null && strtotime((string)$p['expires_at']) < time()) {
                throw AdminActionException::conflict('This promo has expired; enabling it would not make it redeemable. Create a new code instead.');
            }
            $this->db->update('promo_codes', ['status' => $to], ['id' => $id]);
            $this->audit->record('promo.' . ($to === 'active' ? 'enable' : 'disable'), 'promo', $id, ['code' => $p['code'], 'status' => ['from' => $p['status'], 'to' => $to]]);

            return ['status' => $to, 'message' => "Promo code {$p['code']} " . ($to === 'active' ? 'enabled' : 'disabled') . '.'];
        });
    }

    /** Hard delete only when never redeemed; otherwise archive. */
    public function deleteOrArchive(AdminUser $actor, string $id): array
    {
        return $this->db->transactional(function () use ($actor, $id): array {
            $p = $this->db->fetchAssociative('SELECT * FROM promo_codes WHERE id = ? FOR UPDATE', [$id]);
            if ($p === false) {
                throw AdminActionException::notFound('Promo code');
            }
            $redemptions = (int)$this->db->fetchOne('SELECT COUNT(*) FROM promo_redemptions WHERE promo_id = ?', [$id]);
            if ($redemptions > 0 || (int)$p['times_used'] > 0) {
                if ($p['archived_at'] !== null) {
                    throw AdminActionException::conflict('This promo code is already archived.');
                }
                $this->db->update('promo_codes', ['status' => 'archived', 'archived_at' => date('Y-m-d H:i:s'), 'archived_by' => $actor->id()], ['id' => $id]);
                $this->audit->record('promo.archive', 'promo', $id, ['code' => $p['code'], 'redemptions' => $redemptions]);

                return ['archived' => true, 'message' => "{$p['code']} has {$redemptions} redemption(s), so it was archived (kept for history) instead of deleted."];
            }
            $this->db->delete('promo_codes', ['id' => $id]);
            $this->audit->record('promo.delete', 'promo', $id, ['code' => $p['code']]);

            return ['archived' => false, 'message' => "Promo code {$p['code']} removed."];
        });
    }
}
