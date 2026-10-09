<?php
declare(strict_types=1);

namespace Picklers\Admin\Security;

use Picklers\Admin\Repository\AccountRepository;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * Loads AdminUser objects from the current `users` row + `admin_privileges`.
 *
 * @implements UserProviderInterface<AdminUser>
 */
final class AdminUserProvider implements UserProviderInterface
{
    public function __construct(private readonly AccountRepository $accounts)
    {
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        return $this->load($identifier, null);
    }

    /** @param array<string,mixed>|null $impersonating */
    public function load(string $identifier, ?array $impersonating): AdminUser
    {
        $row = $this->accounts->find($identifier);
        if ($row === null || RoleMapper::isDeactivated($row)) {
            $e = new UserNotFoundException('Account not found or deactivated.');
            $e->setUserIdentifier($identifier);
            throw $e;
        }

        return new AdminUser($row, (bool)$row['is_privileged'], $impersonating);
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$user instanceof AdminUser) {
            throw new UnsupportedUserException(sprintf('Unsupported user class "%s".', $user::class));
        }

        return $this->load($user->id(), $user->impersonating());
    }

    public function supportsClass(string $class): bool
    {
        return $class === AdminUser::class;
    }
}
