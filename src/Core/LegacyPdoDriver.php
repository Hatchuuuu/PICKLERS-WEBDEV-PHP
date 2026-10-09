<?php
declare(strict_types=1);

namespace Picklers\Core;

use Doctrine\DBAL\Driver\AbstractMySQLDriver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\PDO\Connection as PdoDriverConnection;
use SensitiveParameter;

/**
 * Doctrine DBAL driver over the legacy layer's own MySQL handle.
 *
 * Used only by legacy (non-Symfony) code when it calls the shared rules in
 * Picklers\Domain or the impersonation audit: those take a DBAL connection, and
 * reusing this handle keeps a legacy caller's raw PDO transaction and the rule's
 * statements in one session. The Symfony kernel never uses it; Doctrine opens
 * its own connection there (config/symfony/packages/doctrine.php).
 */
final class LegacyPdoDriver extends AbstractMySQLDriver
{
    public function connect(#[SensitiveParameter] array $params): DriverConnection
    {
        $pdo = Database::get()->pdo();
        if ($pdo === null) {
            throw new \RuntimeException('MySQL is unavailable (JSON fallback store); this operation needs the authoritative database.');
        }

        return new PdoDriverConnection($pdo);
    }
}
