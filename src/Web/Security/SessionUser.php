<?php
declare(strict_types=1);

namespace Picklers\Web\Security;

use Picklers\Admin\Security\RoleMapper;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/** The signed-in account behind a player/owner request (the impersonated user while an admin views as them). */
final class SessionUser implements UserInterface, PasswordAuthenticatedUserInterface
{
    /** @param array<string,mixed> $row the account's users row */
    public function __construct(private readonly array $row)
    {
    }

    public function id(): string
    {
        return (string)$this->row['id'];
    }

    /** @return array<string,mixed> the row without secrets, as the legacy API exposes it */
    public function row(): array
    {
        $row = $this->row;
        unset($row['password_hash'], $row['is_dev']);

        return $row;
    }

    public function getRoles(): array
    {
        return RoleMapper::roles($this->row, false);
    }

    public function getPassword(): ?string
    {
        $hash = $this->row['password_hash'] ?? null;

        return is_string($hash) && $hash !== '' ? $hash : null;
    }

    public function getUserIdentifier(): string
    {
        return $this->id();
    }

    public function eraseCredentials(): void
    {
    }
}
