<?php
declare(strict_types=1);

namespace Picklers\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Picklers\Core\Database;

/**
 * Baseline: the pre-Symfony schema.
 *
 * The 23 legacy tables are created and evolved by Picklers\Core\Database (idempotent
 * CREATE TABLE IF NOT EXISTS + column/index checks). This migration does not
 * recreate or diff them — it only asserts they exist so every later migration has
 * a known starting point. On a brand-new database, boot the application once (the
 * legacy bootstrap creates the database and its tables) before migrating.
 *
 * Rollback: nothing to undo. Never drop legacy tables from a migration.
 */
final class Version20261005000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Baseline: assert the legacy PICKLERS schema is present (no changes).';
    }

    public function up(Schema $schema): void
    {
        $missing = array_values(array_filter(
            Database::REQUIRED_TABLES,
            static fn(string $t) => !$schema->hasTable($t)
        ));
        $this->abortIf($missing !== [], 'Legacy tables missing: ' . implode(', ', $missing) . '. Boot the application once (or run php scripts/verify-schema-bootstrap.php against a disposable copy) before migrating.');
        $this->write('Legacy schema present: ' . count(Database::REQUIRED_TABLES) . ' tables verified.');
    }

    public function down(Schema $schema): void
    {
        $this->write('Baseline has nothing to roll back.');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
