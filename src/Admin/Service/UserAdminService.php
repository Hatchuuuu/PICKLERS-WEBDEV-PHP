<?php
declare(strict_types=1);

namespace Picklers\Admin\Service;

use Doctrine\DBAL\Connection;
use Picklers\Admin\Http\AdminActionException;
use Picklers\Admin\Repository\AccountRepository;
use Picklers\Admin\Repository\AuditRepository;
use Picklers\Admin\Security\AdminUser;
use Picklers\Admin\Security\Capability;
use Picklers\Admin\Security\ImpersonationManager;
use Picklers\Admin\Security\RoleMapper;
use Picklers\Domain\Notifier;
use Picklers\Domain\PasswordPolicy;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Users & roles administration with explicit privilege boundaries:
 *  - nobody changes their own role, privilege, status or password from here;
 *  - anything touching administrator power (granting/removing admin, privilege,
 *    deactivating or resetting an admin) needs a privileged administrator;
 *  - the last active administrator / privileged administrator cannot be removed;
 *  - deactivation keeps every booking, wallet and audit row; permanent deletion
 *    is only allowed for accounts with no financial or booking history.
 */
final class UserAdminService
{
    public const PASSWORD_RESET_REASON_MIN = 5;

    public function __construct(
        private readonly Connection $db,
        private readonly AccountRepository $accounts,
        private readonly AuditRepository $auditRepository,
        private readonly AuditLog $audit,
        private readonly Notifier $notifier,
        private readonly Security $security,
        private readonly ImpersonationManager $impersonation,
    ) {
    }

    /** @return array<string,mixed> */
    public function detail(string $id): array
    {
        $user = $this->accounts->find($id);
        if ($user === null) {
            throw AdminActionException::notFound('User');
        }
        $user['role_labels'] = RoleMapper::labels($user, $user['is_privileged']);
        $user['primary_role'] = RoleMapper::primary($user, $user['is_privileged']);
        $user['transactions'] = $this->db->fetchAllAssociative(
            'SELECT id, type, amount, label, entry_kind, created_at FROM wallet_transactions WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT 20',
            [$id]
        );
        $user['booking_counts'] = $this->db->fetchAllKeyValue('SELECT status, COUNT(*) FROM bookings WHERE user_id = ? GROUP BY status', [$id]);
        $user['facilities'] = $this->db->fetchAllAssociative('SELECT id, name, operating_status FROM facilities WHERE owner_id = ? ORDER BY name', [$id]);
        $user['applications'] = $this->db->fetchAllAssociative('SELECT id, facility_name, status, created_at FROM owner_applications WHERE user_id = ? ORDER BY created_at DESC', [$id]);
        $user['history'] = $this->auditRepository->historyFor('user', $id);
        $user['history_counts'] = $this->historyCounts($id);

        return $user;
    }

    public function toggleVerification(AdminUser $actor, string $targetId): array
    {
        $target = $this->target($actor, $targetId, allowSelf: true);

        return $this->setVerification($actor, $targetId, ($target['verification_status'] ?? '') === 'verified' ? 'unverified' : 'verified');
    }

    public function setVerification(AdminUser $actor, string $targetId, string $status): array
    {
        if (!in_array($status, ['verified', 'unverified'], true)) {
            throw AdminActionException::invalid('Verification must be verified or unverified.');
        }

        return $this->db->transactional(function () use ($actor, $targetId, $status): array {
            $target = $this->target($actor, $targetId, allowSelf: true, lock: true);
            $this->assertActive($target);
            $from = (string)($target['verification_status'] ?? 'unverified');
            if ($from === $status) {
                return ['new_status' => $status, 'message' => "Already {$status}."];
            }
            $this->accounts->update($targetId, ['verification_status' => $status]);
            if ($status === 'verified') {
                $this->notifier->notify($targetId, 'Identity Verified! 🛡️', 'Your player identity has been officially verified by the Picklers admin team.', 'system');
            }
            $this->audit->record('user.verification', 'user', $targetId, ['verification_status' => ['from' => $from, 'to' => $status]]);

            return ['new_status' => $status, 'message' => "Verification set to {$status}."];
        });
    }

    /** role ∈ player | owner | admin (legacy contract). */
    public function changeRole(AdminUser $actor, string $targetId, string $role): array
    {
        if (!in_array($role, ['player', 'owner', 'admin'], true)) {
            throw AdminActionException::invalid('Invalid role. Must be player, owner, or admin.', 'role');
        }

        return $this->db->transactional(function () use ($actor, $targetId, $role): array {
            $target = $this->target($actor, $targetId, lock: true);
            $this->assertActive($target);
            $wasAdmin = RoleMapper::isAdmin($target);
            $before = RoleMapper::primary($target, $target['is_privileged']);
            if ($wasAdmin || $role === 'admin') {
                $this->requireCapability(Capability::SET_ADMIN_ROLE, 'user.role', $targetId, 'Only a privileged administrator can grant or remove administrator access.');
            }
            if ($wasAdmin && $role !== 'admin') {
                $this->protectLastAdmin($target, 'remove administrator access from');
                if ($target['is_privileged']) {
                    $this->requireCapability(Capability::MANAGE_PRIVILEGE, 'user.role', $targetId, 'Only a privileged administrator can demote a privileged administrator.');
                    $this->protectLastPrivileged($target);
                    $this->accounts->revokePrivilege($targetId);
                }
            }
            if ($role === 'player' && RoleMapper::isOwner($target)) {
                $listings = (int)$this->db->fetchOne("SELECT COUNT(*) FROM facilities WHERE owner_id = ? AND operating_status <> 'suspended'", [$targetId]);
                if ($listings > 0) {
                    throw AdminActionException::conflict("This owner still has {$listings} active facility listing(s). Suspend them in Facilities & Courts before removing the owner role.");
                }
            }
            $fields = match ($role) {
                'admin' => ['role' => 'admin', 'is_admin' => 1, 'is_owner' => (int)$target['is_owner']],
                'owner' => ['role' => 'owner', 'is_admin' => 0, 'is_owner' => 1],
                default => ['role' => 'player', 'is_admin' => 0, 'is_owner' => 0],
            };
            $this->accounts->update($targetId, $fields + ['is_dev' => 0]);
            $after = $role;
            $this->notifier->notify($targetId, 'Account Role Updated 🛡️', 'Your account role has been updated to ' . ucfirst($role) . ' by an administrator.', 'system');
            $this->audit->record('user.role', 'user', $targetId, ['role' => ['from' => $before, 'to' => $after]]);

            return ['user_id' => $targetId, 'new_role' => $role, 'message' => "Role updated to {$role}."];
        });
    }

    public function setPrivilege(AdminUser $actor, string $targetId, bool $grant, string $reason): array
    {
        $this->requireCapability(Capability::MANAGE_PRIVILEGE, 'user.privilege', $targetId, 'Only a privileged administrator can change privileges.');
        $reason = $this->requireReason($reason);

        return $this->db->transactional(function () use ($actor, $targetId, $grant, $reason): array {
            $target = $this->target($actor, $targetId, lock: true);
            $this->assertActive($target);
            if (!RoleMapper::isAdmin($target)) {
                throw AdminActionException::conflict('Only an administrator can be made a privileged administrator. Grant the admin role first.');
            }
            if ($grant === $target['is_privileged']) {
                throw AdminActionException::conflict($grant ? 'Already a privileged administrator.' : 'Not a privileged administrator.');
            }
            if ($grant) {
                $this->accounts->grantPrivilege($targetId, $actor->id(), $reason);
            } else {
                $this->protectLastPrivileged($target);
                $this->accounts->revokePrivilege($targetId);
            }
            $this->audit->record($grant ? 'user.privilege.grant' : 'user.privilege.revoke', 'user', $targetId, ['privileged' => ['from' => !$grant, 'to' => $grant]], $reason);

            return ['message' => $grant ? 'Privileged administrator access granted.' : 'Privileged administrator access revoked.'];
        });
    }

    public function deactivate(AdminUser $actor, string $targetId, string $reason): array
    {
        $reason = $this->requireReason($reason);

        return $this->db->transactional(function () use ($actor, $targetId, $reason): array {
            $target = $this->target($actor, $targetId, lock: true);
            $this->assertActive($target);
            if (RoleMapper::isAdmin($target)) {
                $this->requireCapability(Capability::DEACTIVATE_ADMIN, 'user.deactivate', $targetId, 'Only a privileged administrator can deactivate an administrator.');
                $this->protectLastAdmin($target, 'deactivate');
                if ($target['is_privileged']) {
                    $this->protectLastPrivileged($target);
                }
            }
            $listings = (int)$this->db->fetchOne("SELECT COUNT(*) FROM facilities WHERE owner_id = ? AND operating_status <> 'suspended'", [$targetId]);
            if ($listings > 0) {
                throw AdminActionException::conflict("This owner still has {$listings} active facility listing(s). Suspend them in Facilities & Courts first so players are not booking a venue nobody can manage.");
            }
            $upcoming = (int)$this->db->fetchOne(
                "SELECT COUNT(*) FROM bookings WHERE user_id = ? AND status IN ('pending','upcoming','confirmed') AND (booking_date IS NULL OR booking_date >= CURDATE())",
                [$targetId]
            );
            // Soft deactivation: role 'deleted' blocks sign-in and ends live sessions
            // on their next request. Email, flags, bookings, wallet and history are
            // kept so the account can be reactivated exactly as it was.
            $this->accounts->update($targetId, ['role' => 'deleted']);
            $this->audit->record('user.deactivate', 'user', $targetId, [
                'previous_role' => $target['role'],
                'upcoming_bookings_kept' => $upcoming,
            ], $reason);

            return [
                'user_id' => $targetId,
                'upcoming_bookings' => $upcoming,
                'message' => 'Account deactivated; their sessions end on the next request.' . ($upcoming > 0 ? " {$upcoming} upcoming booking(s) remain — review them in Bookings." : ''),
            ];
        });
    }

    public function reactivate(AdminUser $actor, string $targetId, string $reason = ''): array
    {
        return $this->db->transactional(function () use ($actor, $targetId, $reason): array {
            $target = $this->target($actor, $targetId, lock: true);
            if (!RoleMapper::isDeactivated($target)) {
                throw AdminActionException::conflict('Only deactivated accounts can be reactivated.');
            }
            if ((int)$target['is_admin'] === 1) {
                $this->requireCapability(Capability::DEACTIVATE_ADMIN, 'user.reactivate', $targetId, 'Only a privileged administrator can reactivate an administrator.');
            }
            $role = (int)$target['is_admin'] === 1 ? 'admin' : ((int)$target['is_owner'] === 1 ? 'owner' : 'player');
            $this->accounts->update($targetId, ['role' => $role]);
            $note = str_starts_with((string)$target['email'], 'deleted_')
                ? ' This account was deactivated by the old console, which overwrote its email; set a new email before the person can sign in.'
                : '';
            $this->notifier->notify($targetId, 'Account Reactivated 🎉', 'Your Picklers account access has been restored by an administrator.', 'system');
            $this->audit->record('user.reactivate', 'user', $targetId, ['role' => ['from' => 'deleted', 'to' => $role]], trim($reason) !== '' ? trim($reason) : null);

            return ['user_id' => $targetId, 'message' => 'Account reactivated.' . $note];
        });
    }

    public function delete(AdminUser $actor, string $targetId, string $confirm, string $reason): array
    {
        $this->requireCapability(Capability::DELETE_USER, 'user.delete', $targetId, 'Only a privileged administrator can permanently delete accounts.');
        if (strtoupper(trim($confirm)) !== 'DELETE') {
            throw AdminActionException::invalid('You must type DELETE to confirm account deletion.', 'confirm_text');
        }
        $reason = $this->requireReason($reason);

        return $this->db->transactional(function () use ($actor, $targetId, $reason): array {
            $target = $this->target($actor, $targetId, lock: true);
            if (RoleMapper::isAdmin($target)) {
                throw AdminActionException::conflict('Administrators cannot be permanently deleted. Remove admin access first.');
            }
            $counts = $this->historyCounts($targetId);
            if (array_sum($counts) > 0) {
                $parts = [];
                foreach ($counts as $what => $n) {
                    if ($n > 0) {
                        $parts[] = "{$n} {$what}";
                    }
                }
                throw AdminActionException::conflict('This account has history (' . implode(', ', $parts) . '). Deleting it would orphan financial and booking records — deactivate it instead.', ['history' => $counts]);
            }
            foreach (['notifications' => 'user_id', 'facility_favorites' => 'user_id', 'feed_likes' => 'user_id'] as $table => $col) {
                $this->db->delete($table, [$col => $targetId]);
            }
            $this->db->delete('admin_activity_seen', ['user_id' => $targetId]);
            $this->db->delete('users', ['id' => $targetId]);
            $this->audit->record('user.delete', 'user', $targetId, ['name' => $target['name'], 'email_domain' => substr(strrchr((string)$target['email'], '@') ?: '', 1)], $reason);

            return ['user_id' => $targetId, 'message' => 'Account permanently deleted.'];
        });
    }

    public function resetPassword(AdminUser $actor, string $targetId, string $newPassword, string $reason): array
    {
        if (($policy = PasswordPolicy::error($newPassword)) !== null) {
            throw AdminActionException::invalid($policy, 'new_password');
        }
        $reason = trim($reason);

        return $this->db->transactional(function () use ($actor, $targetId, $newPassword, $reason): array {
            $target = $this->target($actor, $targetId, lock: true);
            $this->assertActive($target);
            if (RoleMapper::isAdmin($target)) {
                $this->requireCapability(Capability::SET_ADMIN_ROLE, 'user.password_reset', $targetId, "Only a privileged administrator can reset another administrator's password.");
            }
            $this->accounts->update($targetId, [
                'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
                'password_changed_at' => date('Y-m-d H:i:s'),
            ]);
            $this->notifier->notify($targetId, 'Password Reset 🔒', 'Your Picklers password was reset by an administrator and every signed-in device was signed out. Use the new password you were given, then change it in Settings.', 'system');
            $this->audit->record('user.password_reset', 'user', $targetId, ['sessions_invalidated' => true], $reason !== '' ? $reason : null);

            return ['user_id' => $targetId, 'message' => 'Password reset. All of their existing sessions are signed out; share the new password with them through a verified channel.'];
        });
    }

    public function impersonate(AdminUser $actor, string $targetId, string $reason): array
    {
        $this->requireCapability(Capability::IMPERSONATE, 'impersonation.start', $targetId, 'Only a privileged administrator can view the app as another user.');
        $reason = $this->requireReason($reason);
        if ($actor->impersonating() !== null) {
            throw AdminActionException::conflict('You are already viewing as someone. Return to your account first.');
        }
        $target = $this->target($actor, $targetId);
        $this->assertActive($target);
        if (RoleMapper::isAdmin($target)) {
            throw AdminActionException::forbidden('Administrators cannot be impersonated.');
        }
        $state = $this->impersonation->start($actor, $target, $reason);

        return [
            'target' => ['id' => $target['id'], 'name' => $target['name']],
            'expires_at' => $state['expires_at'],
            'destination' => RoleMapper::isOwner($target) ? 'owner' : 'app',
            'message' => "Now viewing as {$target['name']} for up to 30 minutes. Use “Return to admin” on any page to come back.",
        ];
    }

    /** @return array<string,int> */
    private function historyCounts(string $id): array
    {
        return [
            'bookings' => (int)$this->db->fetchOne('SELECT COUNT(*) FROM bookings WHERE user_id = ?', [$id]),
            'wallet transactions' => (int)$this->db->fetchOne('SELECT COUNT(*) FROM wallet_transactions WHERE user_id = ?', [$id]),
            'facilities' => (int)$this->db->fetchOne('SELECT COUNT(*) FROM facilities WHERE owner_id = ?', [$id]),
            'applications' => (int)$this->db->fetchOne('SELECT COUNT(*) FROM owner_applications WHERE user_id = ?', [$id]),
            'promo redemptions' => (int)$this->db->fetchOne('SELECT COUNT(*) FROM promo_redemptions WHERE user_id = ?', [$id]),
            'posts' => (int)$this->db->fetchOne('SELECT COUNT(*) FROM feed_posts WHERE author_id = ?', [$id]),
        ];
    }

    private function target(AdminUser $actor, string $targetId, bool $allowSelf = false, bool $lock = false): array
    {
        if ($targetId === '') {
            throw AdminActionException::invalid('Missing user_id.');
        }
        if (!$allowSelf && $targetId === $actor->id()) {
            throw AdminActionException::forbidden('You cannot change your own account here. Ask another administrator.');
        }
        $target = $this->accounts->find($targetId, $lock);
        if ($target === null) {
            throw AdminActionException::notFound('User');
        }

        return $target;
    }

    private function assertActive(array $target): void
    {
        if (RoleMapper::isDeactivated($target)) {
            throw AdminActionException::conflict('This account is deactivated. Reactivate it first.');
        }
    }

    private function protectLastAdmin(array $target, string $verb): void
    {
        if (RoleMapper::isAdmin($target) && $this->accounts->countActiveAdmins() <= 1) {
            throw AdminActionException::conflict("You cannot {$verb} the last active administrator.");
        }
    }

    private function protectLastPrivileged(array $target): void
    {
        if ($target['is_privileged'] && $this->accounts->countActivePrivileged() <= 1) {
            throw AdminActionException::conflict('This is the last privileged administrator; grant privileged access to someone else first.');
        }
    }

    private function requireCapability(string $capability, string $action, string $targetId, string $message): void
    {
        if (!$this->security->isGranted($capability)) {
            $this->audit->recordAttempt($action, 'user', $targetId, AuditLog::DENIED, $message);
            throw AdminActionException::forbidden($message);
        }
    }

    private function requireReason(string $reason): string
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < self::PASSWORD_RESET_REASON_MIN) {
            throw AdminActionException::invalid('Give a reason (at least 5 characters); it is kept in the audit trail.', 'reason');
        }

        return mb_substr($reason, 0, 1000);
    }
}
