<?php
declare(strict_types=1);

namespace Picklers\Admin\Security;

use Symfony\Component\Security\Core\User\EquatableInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Security user built from the persisted `users` row on EVERY request (the
 * firewall is stateless), so a role change, deactivation or privilege revocation
 * takes effect on the very next request.
 */
final class AdminUser implements UserInterface, EquatableInterface
{
    /**
     * @param array<string,mixed>      $row           sanitized users row (no password hash)
     * @param array<string,mixed>|null $impersonating active impersonation target, if any
     */
    public function __construct(
        private readonly array $row,
        private readonly bool $privileged,
        private readonly ?array $impersonating = null,
    ) {
    }

    public function getUserIdentifier(): string
    {
        return (string)$this->row['id'];
    }

    public function id(): string
    {
        return (string)$this->row['id'];
    }

    public function name(): string
    {
        return (string)($this->row['name'] ?? 'Admin');
    }

    public function email(): string
    {
        return (string)($this->row['email'] ?? '');
    }

    public function avatarUrl(): string
    {
        return trim((string)($this->row['avatar_url'] ?? ''));
    }

    public function isPrivileged(): bool
    {
        return $this->privileged && RoleMapper::isAdmin($this->row);
    }

    /** @return array<string,mixed>|null */
    public function impersonating(): ?array
    {
        return $this->impersonating;
    }

    /** @return list<string> */
    public function roleLabels(): array
    {
        return RoleMapper::labels($this->row, $this->privileged);
    }

    /** @return array<string,mixed> */
    public function row(): array
    {
        return $this->row;
    }

    public function getRoles(): array
    {
        return RoleMapper::roles($this->row, $this->privileged);
    }

    public function eraseCredentials(): void
    {
    }

    public function isEqualTo(UserInterface $user): bool
    {
        return $user instanceof self
            && $user->id() === $this->id()
            && $user->getRoles() === $this->getRoles();
    }
}
