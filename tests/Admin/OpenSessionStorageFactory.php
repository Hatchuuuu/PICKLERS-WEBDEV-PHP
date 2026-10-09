<?php
declare(strict_types=1);

namespace Picklers\Tests\Admin;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Storage\PhpBridgeSessionStorage;
use Symfony\Component\HttpFoundation\Session\Storage\SessionStorageFactoryInterface;
use Symfony\Component\HttpFoundation\Session\Storage\SessionStorageInterface;

/**
 * Test-only session storage: the run's one PHP session (started in
 * tests/Admin/bootstrap.php) stays open across requests, because tests arrange
 * and assert $_SESSION between requests and legacy code would otherwise reload
 * a closed session from disk, discarding those writes.
 */
final class OpenSessionStorageFactory implements SessionStorageFactoryInterface
{
    public function createStorage(?Request $request): SessionStorageInterface
    {
        return new class extends PhpBridgeSessionStorage {
            public function save(): void
            {
            }
        };
    }
}
