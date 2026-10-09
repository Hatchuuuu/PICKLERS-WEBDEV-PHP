<?php
declare(strict_types=1);

namespace Picklers\Admin\Service;

use Doctrine\DBAL\Connection;
use Picklers\Admin\Http\AdminActionException;
use Picklers\Admin\Repository\AuditRepository;
use Picklers\Admin\Security\AdminUser;
use Picklers\Admin\Security\RoleMapper;
use Picklers\Domain\FacilityProvisioner;
use Picklers\Domain\Notifier;
use Psr\Log\LoggerInterface;

/**
 * Partner (court owner) application review.
 *
 * Transition rules: pending_review → approved | rejected. Approved and rejected
 * are final; a rejected applicant submits a new application.
 *
 * Approval is one transaction on the shared connection: the application row is
 * locked, the facility is provisioned by the SAME shared rule the platform has
 * always used, the applicant gains the owner capacity, the application records
 * reviewer/time/note/facility, the applicant is notified and the audit row is
 * written — all or nothing. A second (concurrent or repeated) approval waits on
 * the row lock, then sees "approved" and is refused, so at most one facility is
 * ever created for an application.
 */
final class ApplicationReviewService
{
    public const STATUSES = ['pending_review', 'approved', 'rejected'];
    public const SORTS = ['created_at', 'facility_name'];

    public function __construct(
        private readonly Connection $db,
        private readonly Notifier $notifier,
        private readonly FacilityProvisioner $facilities,
        private readonly AuditLog $audit,
        private readonly AuditRepository $auditRepository,
        private readonly DocumentStorage $documents,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @return Page<array<string,mixed>> */
    public function search(ListQuery $query): Page
    {
        $where = ['1=1'];
        $params = [];
        if ($query->q !== '') {
            $where[] = '(a.id LIKE ? OR a.facility_name LIKE ? OR a.owner_name LIKE ? OR a.business_email LIKE ? OR a.entity_name LIKE ? OR a.reg_number LIKE ? OR a.address LIKE ?)';
            array_push($params, ...array_fill(0, 7, $query->likePattern()));
        }
        if (($status = $query->filter('status')) !== null) {
            $where[] = 'a.status = ?';
            $params[] = $status;
        }
        $sql = implode(' AND ', $where);
        $total = (int)$this->db->fetchOne("SELECT COUNT(*) FROM owner_applications a WHERE {$sql}", $params);
        if ($total > 0 && $query->offset() >= $total) {
            $query = $query->withPage((int)ceil($total / $query->perPage));
        }
        $order = $query->sort === 'facility_name' ? 'a.facility_name' : 'a.created_at';
        $dir = $query->dir === 'asc' ? 'ASC' : 'DESC';
        $rows = $this->db->fetchAllAssociative(
            "SELECT a.*, u.name AS applicant_account_name, r.name AS reviewer_name
               FROM owner_applications a
               LEFT JOIN users u ON u.id = a.user_id
               LEFT JOIN users r ON r.id = a.reviewed_by
              WHERE {$sql} ORDER BY {$order} {$dir}, a.id ASC LIMIT {$query->perPage} OFFSET {$query->offset()}",
            $params
        );
        foreach ($rows as &$row) {
            $row['permit_state'] = $this->documents->state($row['permit_file'] ?? null);
            $row['gov_id_state'] = $this->documents->state($row['gov_id_file'] ?? null);
        }
        unset($row);

        return new Page($rows, $total, $query);
    }

    public function pendingCount(): int
    {
        return (int)$this->db->fetchOne("SELECT COUNT(*) FROM owner_applications WHERE status = 'pending_review'");
    }

    /** @return array<string,mixed> */
    public function detail(string $id): array
    {
        $app = $this->db->fetchAssociative(
            'SELECT a.*, u.name AS applicant_account_name, u.email AS applicant_account_email, u.role AS applicant_role,
                    u.is_admin AS applicant_is_admin, u.is_owner AS applicant_is_owner,
                    r.name AS reviewer_name, f.name AS provisioned_facility_name
               FROM owner_applications a
               LEFT JOIN users u ON u.id = a.user_id
               LEFT JOIN users r ON r.id = a.reviewed_by
               LEFT JOIN facilities f ON f.id = a.facility_id
              WHERE a.id = ?',
            [$id]
        );
        if ($app === false) {
            throw AdminActionException::notFound('Application');
        }
        $app['permit_state'] = $this->documents->state($app['permit_file'] ?? null);
        $app['gov_id_state'] = $this->documents->state($app['gov_id_file'] ?? null);
        $app['history'] = $this->auditRepository->historyFor('application', $id);
        $app['other_applications'] = (int)$this->db->fetchOne('SELECT COUNT(*) FROM owner_applications WHERE user_id = ? AND id <> ?', [$app['user_id'], $id]);

        return $app;
    }

    /**
     * Legacy contract: callers send user_id (+ application_id). The application id
     * is authoritative; a mismatched pair is refused.
     */
    public function resolve(string $userId, string $applicationId): array
    {
        if ($applicationId === '' && $userId === '') {
            throw AdminActionException::invalid('Choose an application to review.');
        }
        $app = $applicationId !== ''
            ? $this->db->fetchAssociative('SELECT * FROM owner_applications WHERE id = ?', [$applicationId])
            : $this->db->fetchAssociative('SELECT * FROM owner_applications WHERE user_id = ? ORDER BY created_at DESC LIMIT 1', [$userId]);
        if ($app === false) {
            throw AdminActionException::notFound('Application');
        }
        if ($userId !== '' && (string)$app['user_id'] !== $userId) {
            throw AdminActionException::invalid('That application does not belong to this user.');
        }

        return $app;
    }

    /** @return array{facility_id:int,facility_name:string,applicant:string} */
    public function approve(AdminUser $actor, string $applicationId, string $note): array
    {
        $note = trim($note);
        if (mb_strlen($note) > 1000) {
            throw AdminActionException::invalid('Keep the approval note under 1,000 characters.', 'reason');
        }

        return $this->db->transactional(function () use ($actor, $applicationId, $note): array {
            $app = $this->db->fetchAssociative('SELECT * FROM owner_applications WHERE id = ? FOR UPDATE', [$applicationId]);
            if ($app === false) {
                throw AdminActionException::notFound('Application');
            }
            $status = (string)($app['status'] ?? 'pending_review');
            if ($status !== 'pending_review') {
                throw AdminActionException::conflict("This application was already {$status}" . ($app['reviewed_at'] ? ' on ' . $app['reviewed_at'] : '') . '. Refresh to see its current state.', ['status' => $status]);
            }
            $applicant = $this->db->fetchAssociative('SELECT * FROM users WHERE id = ? FOR UPDATE', [$app['user_id']]);
            if ($applicant === false || RoleMapper::isDeactivated($applicant)) {
                throw AdminActionException::conflict('The applicant account no longer exists or is deactivated. Reject the application instead.');
            }

            try {
                // The shared provisioning rule (also used by the owner flow); joins this transaction.
                $facilityId = $this->facilities->createFromApplication($app);
            } catch (\Throwable $e) {
                $this->logger->error('[PICKLERS Admin] provisioning failed for {application}: {message}', ['application' => $applicationId, 'message' => $e->getMessage(), 'exception' => $e]);
                throw new AdminActionException('Could not provision the facility, so nothing was changed. Try again; if it keeps failing, check the System panel.', 500);
            }
            $linked = $this->db->fetchOne('SELECT id FROM owner_applications WHERE facility_id = ? AND id <> ?', [$facilityId, $applicationId]);
            if ($linked !== false) {
                throw AdminActionException::conflict("This applicant already has the facility \"{$app['facility_name']}\" from application {$linked}; nothing was changed.");
            }

            $userChanges = ['is_owner' => 1, 'verification_status' => 'verified'];
            if (!RoleMapper::isAdmin($applicant)) {
                $userChanges['role'] = 'owner';
            }
            $this->db->update('users', $userChanges, ['id' => $app['user_id']]);
            $this->db->update('owner_applications', [
                'status' => 'approved',
                'reviewed_by' => $actor->id(),
                'reviewed_at' => date('Y-m-d H:i:s'),
                'review_reason' => $note !== '' ? $note : null,
                'facility_id' => $facilityId,
            ], ['id' => $applicationId]);

            $this->notifier->notify(
                (string)$app['user_id'],
                '🎉 You’re live on Picklers!',
                sprintf('%s is now listed in Discover Courts. Open the Owner Portal to set your rates, court tags, and photos.', $app['facility_name'] ?: 'Your facility'),
                'system'
            );
            $this->audit->record('application.approve', 'application', $applicationId, [
                'status' => ['from' => 'pending_review', 'to' => 'approved'],
                'applicant_user_id' => $app['user_id'],
                'facility_id' => $facilityId,
                'user' => ['is_owner' => 1, 'verification_status' => 'verified'],
            ], $note !== '' ? $note : null);

            return ['facility_id' => $facilityId, 'facility_name' => (string)$app['facility_name'], 'applicant' => (string)($applicant['name'] ?? $app['owner_name'])];
        });
    }

    public function reject(AdminUser $actor, string $applicationId, string $reason): array
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw AdminActionException::invalid('Give the applicant a reason (at least 5 characters).', 'reason');
        }
        if (mb_strlen($reason) > 500) {
            throw AdminActionException::invalid('Keep the reason under 500 characters.', 'reason');
        }

        return $this->db->transactional(function () use ($actor, $applicationId, $reason): array {
            $app = $this->db->fetchAssociative('SELECT * FROM owner_applications WHERE id = ? FOR UPDATE', [$applicationId]);
            if ($app === false) {
                throw AdminActionException::notFound('Application');
            }
            $status = (string)($app['status'] ?? 'pending_review');
            if ($status !== 'pending_review') {
                throw AdminActionException::conflict("This application was already {$status}. Refresh to see its current state.", ['status' => $status]);
            }
            $this->db->update('owner_applications', [
                'status' => 'rejected',
                'reviewed_by' => $actor->id(),
                'reviewed_at' => date('Y-m-d H:i:s'),
                'review_reason' => $reason,
            ], ['id' => $applicationId]);

            // An already-approved owner keeps verification earned elsewhere; a
            // pending applicant's review flag is cleared (legacy behaviour).
            $applicant = $this->db->fetchAssociative('SELECT * FROM users WHERE id = ?', [$app['user_id']]);
            if ($applicant !== false && !RoleMapper::isOwner($applicant) && !RoleMapper::isDeactivated($applicant) && ($applicant['verification_status'] ?? '') === 'pending') {
                $this->db->update('users', ['verification_status' => 'unverified'], ['id' => $app['user_id']]);
            }
            $this->notifier->notify(
                (string)$app['user_id'],
                'Owner Application Update',
                "Your Court Owner application for {$app['facility_name']} was not approved. Reason: {$reason}. You can submit a new application at any time.",
                'system'
            );
            $this->audit->record('application.reject', 'application', $applicationId, [
                'status' => ['from' => 'pending_review', 'to' => 'rejected'],
                'applicant_user_id' => $app['user_id'],
            ], $reason);

            return ['applicant' => (string)($applicant['name'] ?? $app['owner_name'])];
        });
    }

    /** True when the filename belongs to some application (documents are served only then). */
    public function documentIsOnFile(string $file): ?string
    {
        $id = $this->db->fetchOne('SELECT id FROM owner_applications WHERE permit_file = ? OR gov_id_file = ? LIMIT 1', [$file, $file]);

        return $id === false ? null : (string)$id;
    }
}
